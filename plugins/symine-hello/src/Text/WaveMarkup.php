<?php

declare(strict_types=1);

namespace Symine\Hello\Text;

use App\Service\Text\MarkupConverterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Example of a plugin decorating a core service (docs/PLUGINS.md, "Decorating core services"): texts may say
 * ":hello:" for a waving hand, in Textile and in CommonMark alike.
 *
 * Where a Redmine plugin would patch the formatter class, the plugin wraps the service behind
 * MarkupConverterInterface and passes on what it does not handle. The contract of that interface holds for
 * decorators too: the result may only depend on the formatting and on the text (it is cached when the setting
 * "Cache formatted text" is on).
 */
#[AsDecorator(decorates: MarkupConverterInterface::class)]
final class WaveMarkup implements MarkupConverterInterface
{
    public const string SHORTCODE = ':hello:';

    public function __construct(#[AutowireDecorated] private readonly MarkupConverterInterface $inner)
    {
    }

    public function toHtml(string $formatting, string $text): string
    {
        return $this->inner->toHtml($formatting, str_contains($text, self::SHORTCODE) ? str_replace(self::SHORTCODE, '&#128075;', $text) : $text);
    }
}
