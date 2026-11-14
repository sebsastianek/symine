<?php

declare(strict_types=1);

namespace App\Tests\Theme;

use App\Theme\CssValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CssValueTest extends TestCase
{
    #[DataProvider('colors')]
    public function testColor(string $input, ?string $expected): void
    {
        self::assertSame($expected, CssValue::color($input));
    }

    public static function colors(): iterable
    {
        yield ['#ABC', '#abc'];
        yield ['#a1b2c3', '#a1b2c3'];
        yield ['#a1b2c3d4', '#a1b2c3d4'];
        yield [' rgb(10, 20, 30) ', 'rgb(10, 20, 30)'];
        yield ['oklch(60% 0.1 200 / 50%)', 'oklch(60% 0.1 200 / 50%)'];
        yield ['#12', null];
        yield ['red', null];
        yield ['#fff; } body { display: none', null];
        yield ['url(javascript:alert(1))', null];
        yield ['rgb(0,0,0); color: red', null];
        yield ['var(--x)', null];
        yield ['expression(alert(1))', null];
    }

    public function testLength(): void
    {
        self::assertSame('14px', CssValue::length('14px', ['px']));
        self::assertSame('0.5rem', CssValue::length('0.5REM', ['px', 'rem']));
        self::assertSame('6px', CssValue::length('6', ['px']), 'a bare number takes the first unit');
        self::assertSame('0', CssValue::length('0', ['px']));
        self::assertNull(CssValue::length('6em', ['px']));
        self::assertNull(CssValue::length('calc(1px + 2px)', ['px']));
        self::assertNull(CssValue::length('1px; color: red', ['px']));
    }

    public function testStringCannotLeaveItsQuotes(): void
    {
        self::assertSame('"Hello"', CssValue::string('Hello'));
        $css = CssValue::string("a\"b\\c\n</style>{}");
        self::assertStringStartsWith('"', $css);
        self::assertStringEndsWith('"', $css);
        self::assertSame(0, substr_count(substr($css, 1, -1), '"'), 'quotes are escaped');
        self::assertStringNotContainsString("\n", $css);
        self::assertStringNotContainsString('\\c', str_replace('\\5c ', '', $css));
    }

    #[DataProvider('declarationValues')]
    public function testIsSafeDeclarationValue(string $value, bool $safe): void
    {
        self::assertSame($safe, CssValue::isSafeDeclarationValue($value));
    }

    public static function declarationValues(): iterable
    {
        yield ['12px', true];
        yield ['"IBM Plex Sans", sans-serif', true];
        yield ['color-mix(in oklch, var(--a) 10%, white)', true];
        yield ['url("data:image/svg+xml;base64,AAAA")', true];
        yield ['0 1px 2px rgba(0, 0, 0, 0.1), 0 8px 24px rgba(0, 0, 0, 0.2)', true];
        yield ['', false];
        yield ['red; } body { display: none', false];
        yield ['red; color: blue', false];
        yield ['red }', false];
        yield ['</style><script>', false];
        yield ['red /* x */', false];
        yield ['calc(1px', false];
        yield ['"unterminated', false];
    }

    public function testIsIdentifier(): void
    {
        self::assertTrue(CssValue::isIdentifier('st-accent'));
        self::assertTrue(CssValue::isIdentifier('oc-gray-9-rgb'));
        self::assertFalse(CssValue::isIdentifier('--st-accent'));
        self::assertFalse(CssValue::isIdentifier('St'));
        self::assertFalse(CssValue::isIdentifier('a b'));
        self::assertFalse(CssValue::isIdentifier('a:b'));
    }
}
