<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Service\Settings;
use App\Service\Text\CachedMarkupConverter;
use App\Service\Text\MarkupConverter;
use App\Service\Text\MarkupConverterInterface;
use App\Service\TextFormatter;
use App\Tests\Integration\SymineTestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * The setting "Cache formatted text" (CachedMarkupConverter, Redmine::WikiFormatting.to_html): off by default;
 * on, the conversion of long texts is kept, and pages are the same to the byte, for every reader.
 */
final class CachedMarkupConverterTest extends SymineTestCase
{
    private function longText(string $start = ''): string
    {
        return $start."\n\n".str_repeat("A paragraph with *emphasis*, `code` and a list:\n\n* one\n* two\n\n", 60);
    }

    private function enable(bool $on = true): void
    {
        $this->service(Settings::class)->setMany(['cache_formatted_text' => $on ? '1' : '0']);
    }

    private function pool(): ArrayAdapter
    {
        $pool = self::getContainer()->get('cache.formatted_text');
        while ($pool instanceof \Symfony\Component\Cache\Adapter\TraceableAdapter) {
            $pool = $pool->getPool();
        }
        self::assertInstanceOf(ArrayAdapter::class, $pool, 'the pool of the test environment lives and dies with the kernel');

        return $pool;
    }

    /** A converter that counts what it is asked, around the real one */
    private function counting(): MarkupConverterInterface
    {
        return new class implements MarkupConverterInterface {
            public int $calls = 0;
            private MarkupConverter $real;

            public function toHtml(string $formatting, string $text): string
            {
                ++$this->calls;

                return ($this->real ??= new MarkupConverter())->toHtml($formatting, $text);
            }
        };
    }

    private function decorator(MarkupConverterInterface $inner, CacheItemPoolInterface $pool, string $build = 'build-1'): CachedMarkupConverter
    {
        $container = new Container(new ParameterBag(['container.build_id' => $build]));

        return new CachedMarkupConverter($inner, $this->service(Settings::class), $pool, new ContainerBag($container));
    }

    public function testOffByDefaultNothingIsKept(): void
    {
        self::assertFalse($this->service(Settings::class)->bool('cache_formatted_text'));
        $pool = new ArrayAdapter();
        $inner = $this->counting();
        $converter = $this->decorator($inner, $pool);
        $text = $this->longText();

        self::assertSame((new MarkupConverter())->toHtml('common_mark', $text), $converter->toHtml('common_mark', $text));
        $converter->toHtml('common_mark', $text);
        self::assertSame(2, $inner->calls);
        self::assertSame([], $pool->getValues());
        self::assertSame([], $this->pool()->getValues());
    }

    public function testOnLongTextsAreConvertedOnce(): void
    {
        $this->enable();
        $pool = new ArrayAdapter();
        $inner = $this->counting();
        $converter = $this->decorator($inner, $pool);
        $text = $this->longText();
        $plain = new MarkupConverter();

        foreach (['common_mark', 'textile'] as $n => $formatting) {
            $first = $converter->toHtml($formatting, $text);
            self::assertSame($plain->toHtml($formatting, $text), $first);
            self::assertSame($first, $converter->toHtml($formatting, $text));
            self::assertSame($first, $this->decorator($inner, $pool)->toHtml($formatting, $text), 'another request');
            self::assertSame($n + 1, $inner->calls, $formatting.': one conversion, whatever the number of readers');
        }
        self::assertCount(2, $pool->getValues(), 'one entry per formatting');
    }

    public function testShortTextsAreNotWorthKeeping(): void
    {
        $this->enable();
        $pool = new ArrayAdapter();
        $inner = $this->counting();
        $converter = $this->decorator($inner, $pool);

        // Redmine: text.size > 2.kilobytes, in characters
        $short = str_repeat('é', CachedMarkupConverter::MIN_LENGTH);
        $converter->toHtml('common_mark', $short);
        $converter->toHtml('common_mark', $short);
        self::assertSame(2, $inner->calls);
        self::assertSame([], $pool->getValues());

        $converter->toHtml('common_mark', $short.'é');
        $converter->toHtml('common_mark', $short.'é');
        self::assertSame(3, $inner->calls);
    }

    public function testAnEditedTextIsConvertedAgain(): void
    {
        $this->enable();
        $pool = new ArrayAdapter();
        $inner = $this->counting();
        $converter = $this->decorator($inner, $pool);
        $text = $this->longText('First version');
        $edited = $this->longText('Second version');

        self::assertStringContainsString('First version', $converter->toHtml('common_mark', $text));
        $html = $converter->toHtml('common_mark', $edited);
        self::assertStringContainsString('Second version', $html);
        self::assertStringNotContainsString('First version', $html);
        self::assertSame(2, $inner->calls);
        // and going back to the first text finds it again
        self::assertStringContainsString('First version', $converter->toHtml('common_mark', $text));
        self::assertSame(2, $inner->calls);
    }

    public function testADeploymentDoesNotMeetTheOutputOfThePreviousBuild(): void
    {
        $this->enable();
        $pool = new ArrayAdapter();
        $inner = $this->counting();
        $text = $this->longText();

        $this->decorator($inner, $pool, 'build-1')->toHtml('textile', $text);
        $this->decorator($inner, $pool, 'build-1')->toHtml('textile', $text);
        self::assertSame(1, $inner->calls);
        $this->decorator($inner, $pool, 'build-2')->toHtml('textile', $text);
        self::assertSame(2, $inner->calls, 'the converter may have changed with the code');
    }

    public function testTurningTheSettingOffStopsReadingTheCache(): void
    {
        $this->enable();
        $pool = new ArrayAdapter();
        $inner = $this->counting();
        $converter = $this->decorator($inner, $pool);
        $text = $this->longText();
        $converter->toHtml('common_mark', $text);

        $this->enable(false);
        $converter->toHtml('common_mark', $text);
        self::assertSame(2, $inner->calls);
    }

    public function testACacheThatFailsDoesNotHideTheText(): void
    {
        $this->enable();
        $broken = $this->createStub(CacheItemPoolInterface::class);
        $broken->method('getItem')->willThrowException(new \RuntimeException('cache down'));
        $text = $this->longText();

        self::assertSame((new MarkupConverter())->toHtml('common_mark', $text), $this->decorator($this->counting(), $broken)->toHtml('common_mark', $text));
    }

    public function testPagesAreTheSameWithAndWithoutTheCacheForEveryReader(): void
    {
        $formatter = $this->service(TextFormatter::class);
        // #18 is an issue of the private project "internal": jsmith is a member, rhill is not
        $text = $this->longText("See #18 and #13, [[Wiki]], user:jsmith and <script>alert(1)</script>\n\n{{macro_list}}");
        $project = $this->em->getRepository(\App\Entity\Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $render = function (string $login, string $formatting) use ($formatter, $text, $project): string {
            $this->actAs($this->user($login));
            $formatter->reset();

            return $formatter->format($text, $project, ['formatting' => $formatting]);
        };

        foreach (['common_mark', 'textile'] as $formatting) {
            $this->enable(false);
            $expected = ['jsmith' => $render('jsmith', $formatting), 'rhill' => $render('rhill', $formatting)];
            self::assertStringContainsString('Internal tool crash', $expected['jsmith'], 'a member reads the subject of the linked issue');
            self::assertStringNotContainsString('Internal tool crash', $expected['rhill'], 'somebody else does not');
            self::assertStringNotContainsString('<script>', $expected['jsmith']);
            self::assertSame([], $this->pool()->getValues());

            $this->enable();
            // the member comes first and fills the cache: the other reader must not get his page
            self::assertSame($expected['jsmith'], $render('jsmith', $formatting), $formatting.' miss');
            self::assertNotSame([], $this->pool()->getValues());
            self::assertSame($expected['rhill'], $render('rhill', $formatting), $formatting.' hit, other reader');
            self::assertSame($expected['jsmith'], $render('jsmith', $formatting), $formatting.' hit');
            foreach ($this->pool()->getValues() as $cached) {
                self::assertStringNotContainsString('Internal tool crash', (string) $cached, 'nothing about a reader is kept');
            }
            $this->pool()->clear();
        }
    }

    /** The placeholder macros are replaced by before the conversion is derived from the text: a text with macros is a hit too */
    public function testATextWithMacrosIsConvertedOnce(): void
    {
        $this->enable();
        $formatter = $this->service(TextFormatter::class);
        $this->actAs($this->user('jsmith'));
        $text = $this->longText("{{macro_list}}\n\nand {{hello_world}}");
        $first = $formatter->format($text, null, ['formatting' => 'common_mark']);
        self::assertCount(1, $this->pool()->getValues());
        $formatter->reset();
        self::assertSame($first, $formatter->format($text, null, ['formatting' => 'common_mark']));
        self::assertCount(1, $this->pool()->getValues(), 'the second render found the first');
        self::assertStringNotContainsString('xmacro', $first);
    }

    public function testTheCacheIsTheOutermostDecoratorOfTheConverter(): void
    {
        // what plugins add to the conversion (the example plugin: ":hello:") is cached with it
        self::assertInstanceOf(CachedMarkupConverter::class, self::getContainer()->get(MarkupConverterInterface::class));
        $this->enable();
        $text = $this->longText('Say :hello: to everybody');
        $html = self::getContainer()->get(MarkupConverterInterface::class)->toHtml('common_mark', $text);
        self::assertStringContainsString('Say 👋 to everybody', html_entity_decode($html));
        $kept = array_values($this->pool()->getValues());
        self::assertCount(1, $kept);
        self::assertStringContainsString('Say 👋 to everybody', html_entity_decode((string) $kept[0]));
        self::assertSame($html, self::getContainer()->get(MarkupConverterInterface::class)->toHtml('common_mark', $text));
    }
}
