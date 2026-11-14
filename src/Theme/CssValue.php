<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * Validation of the values written to generated stylesheets and to the style attribute of <html>.
 * Values entered by administrators and users only ever reach CSS through these functions.
 */
final class CssValue
{
    private const string COLOR_FUNCTIONS = 'rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch';

    /** A CSS colour in hexadecimal or functional notation; null when it is not one */
    public static function color(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)) {
            return strtolower($value);
        }
        if (preg_match('/^(?:'.self::COLOR_FUNCTIONS.')\([0-9a-z%.,\/ +-]{1,80}\)$/i', $value)) {
            return strtolower($value);
        }
        return null;
    }

    /**
     * A CSS length such as "14px" or "0.5rem" ("0" is accepted without unit); null when it is not one.
     *
     * @param list<string> $units accepted units
     */
    public static function length(string $value, array $units = ['px', 'rem', 'em', '%']): ?string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^(-?\d+(?:\.\d+)?)([a-z%]*)$/', $value, $m)) {
            return null;
        }
        if ('' === $m[2]) {
            return 0.0 === (float) $m[1] ? '0' : (isset($units[0]) ? $m[1].$units[0] : null);
        }

        return \in_array($m[2], $units, true) ? $value : null;
    }

    /** A CSS string literal */
    public static function string(string $value): string
    {
        $escaped = preg_replace_callback('/[^\x20\x21\x23-\x26\x28-\x5B\x5D-\x7E\x{A0}-\x{10FFFF}]/u', static fn (array $m) => '\\'.dechex(mb_ord($m[0])).' ', $value);

        return '"'.$escaped.'"';
    }

    /** A CSS url() of a local path */
    public static function url(string $path): string
    {
        return 'url('.self::string($path).')';
    }

    /**
     * Whether a value of a theme manifest can be written as the value of a declaration:
     * it must not be able to close the declaration block or the stylesheet.
     */
    public static function isSafeDeclarationValue(string $value): bool
    {
        if ('' === trim($value) || preg_match('/[{}<>\x00-\x08\x0B\x0C\x0E-\x1F]|\*\/|\/\*/', $value)) {
            return false;
        }
        // ";" is only allowed inside quotes or parentheses (data: URLs)
        $depth = 0;
        $quote = null;
        for ($i = 0, $n = \strlen($value); $i < $n; ++$i) {
            $c = $value[$i];
            if (null !== $quote) {
                if ('\\' === $c) {
                    ++$i;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ('"' === $c || "'" === $c) {
                $quote = $c;
            } elseif ('(' === $c) {
                ++$depth;
            } elseif (')' === $c) {
                if (--$depth < 0) {
                    return false;
                }
            } elseif (';' === $c && 0 === $depth) {
                return false;
            }
        }

        return null === $quote && 0 === $depth;
    }

    /** Whether a name can be used as a CSS custom property (without the leading "--") or attribute suffix */
    public static function isIdentifier(string $name): bool
    {
        return 1 === preg_match('/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/', $name);
    }
}
