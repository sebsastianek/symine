<?php

declare(strict_types=1);

namespace App\Service\Text;

use App\Service\Settings;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * The setting "Cache formatted text" (Administration » Settings » General, off by default), as in
 * Redmine::WikiFormatting.to_html: the conversion of texts longer than 2 KB is kept in the application cache.
 *
 * What is kept is the output of the converter for a formatting and a text, under a digest of both: an entry
 * can only ever be the conversion of exactly that text, whoever asks. It holds nothing about a reader or a
 * project — sanitisation, attachments, macros and links are applied by TextFormatter to what comes out of here,
 * cached or not, so a hit and a miss give the same page. Editing a text changes its digest; entries that are no
 * longer asked for expire (pool cache.formatted_text). Entries are also tied to the build of the application,
 * so a deployment or a plugin that changes the converter never meets the output of the previous one.
 *
 * The priority puts this decorator outside the ones plugins add (the lowest priority is applied last): what a
 * plugin adds to the conversion is cached with it.
 */
#[AsDecorator(decorates: MarkupConverterInterface::class, priority: -1024)]
final class CachedMarkupConverter implements MarkupConverterInterface
{
    /** Redmine: text.size > 2.kilobytes */
    public const int MIN_LENGTH = 2048;

    public function __construct(
        #[AutowireDecorated]
        private readonly MarkupConverterInterface $inner,
        private readonly Settings $settings,
        #[Autowire(service: 'cache.formatted_text')]
        private readonly CacheItemPoolInterface $cache,
        private readonly ?ContainerBagInterface $parameters = null,
    ) {
    }

    /** Identifies the build of the application: it changes whenever the container is rebuilt (deployment, plugin) */
    private function build(): string
    {
        try {
            return (string) $this->parameters?->get('container.build_id');
        } catch (\Throwable) {
            return '';
        }
    }

    public function toHtml(string $formatting, string $text): string
    {
        if (mb_strlen($text) <= self::MIN_LENGTH || !$this->settings->bool('cache_formatted_text')) {
            return $this->inner->toHtml($formatting, $text);
        }
        try {
            $item = $this->cache->getItem(hash('sha256', $this->build()."\0".$formatting."\0".$text));
            if ($item->isHit() && \is_string($html = $item->get())) {
                return $html;
            }
        } catch (\Throwable) {
            // a cache that cannot be reached is no reason not to show the text
            return $this->inner->toHtml($formatting, $text);
        }
        $html = $this->inner->toHtml($formatting, $text);
        try {
            $this->cache->save($item->set($html));
        } catch (\Throwable) {
        }

        return $html;
    }
}
