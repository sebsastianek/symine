<?php

declare(strict_types=1);

namespace App\Theme;

/** A theme directory that cannot be used; $errors lists every problem found */
final class InvalidThemeException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly string $themeId, public readonly array $errors)
    {
        parent::__construct(\sprintf('Theme "%s" is invalid: %s', $themeId, implode('; ', $errors)));
    }
}
