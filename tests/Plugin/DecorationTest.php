<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Service\Text\MarkupConverter;
use App\Service\Text\MarkupConverterInterface;
use Symine\Hello\Text\WaveMarkup;

/**
 * A plugin changes what a core service does by decorating it (docs/PLUGINS.md, "Decorating core services"):
 * the example plugin teaches the markup converter the ":hello:" shortcode.
 */
final class DecorationTest extends PluginWebTestCase
{
    public function testThePluginWrapsTheCoreService(): void
    {
        $converter = self::getContainer()->get(MarkupConverterInterface::class);
        $chain = [];
        for ($service = $converter; \is_object($service); $service = $inner) {
            $chain[] = $service::class;
            $inner = (new \ReflectionClass($service))->hasProperty('inner') ? (new \ReflectionProperty($service, 'inner'))->getValue($service) : null;
        }
        self::assertContains(WaveMarkup::class, $chain);
        self::assertSame(MarkupConverter::class, end($chain), 'the core converter does the work at the end of the chain');

        foreach (['common_mark', 'textile'] as $formatting) {
            $html = html_entity_decode($converter->toHtml($formatting, 'Say :hello: to *everybody*'));
            self::assertStringContainsString('Say 👋 to', $html, $formatting);
            self::assertStringContainsString('everybody</', $html, 'the rest is converted as usual');
            self::assertSame((new MarkupConverter())->toHtml($formatting, 'Nothing *special*'), $converter->toHtml($formatting, 'Nothing *special*'), 'texts without the shortcode are untouched');
        }
    }

    public function testEveryFormattedTextGoesThroughTheDecorator(): void
    {
        $this->loginAs('jsmith');
        $this->client->request('POST', '/issues/preview?project_id=ecommerce', ['text' => 'Say :hello: to #13', '_token' => $this->csrfToken()]);
        $this->assertStatus(200);
        $html = html_entity_decode($this->body());
        self::assertStringContainsString('Say 👋 to', $html);
        self::assertStringContainsString('/issues/13', $html, 'links are still resolved by the core, on the output of the decorated service');
    }
}
