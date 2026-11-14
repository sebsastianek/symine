<?php

declare(strict_types=1);

namespace App\Theme;

/** A named set of design tokens of a theme (e.g. "light", "dark") */
final readonly class ColorScheme
{
    /**
     * @param array<string, string> $label  locale => label
     * @param array<string, string> $tokens CSS custom property (without "--") => value
     */
    public function __construct(
        public string $id,
        public array $label,
        public bool $dark,
        public array $tokens,
    ) {
    }
}
