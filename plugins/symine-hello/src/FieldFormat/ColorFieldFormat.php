<?php

declare(strict_types=1);

namespace Symine\Hello\FieldFormat;

use App\Entity\CustomField;
use App\Plugin\FieldFormat\AbstractFieldFormat;

/** Redmine::FieldFormat.add 'hello_color', ColorFormat: an hexadecimal color (#rrggbb) */
final class ColorFieldFormat extends AbstractFieldFormat
{
    public function getName(): string
    {
        return 'hello_color';
    }

    public function getLabel(): string
    {
        return 'label_hello_color';
    }

    public function validateValue(CustomField $field, string $value): ?string
    {
        return preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) ? null : 'activerecord.errors.messages.invalid';
    }

    public function formatValue(CustomField $field, string $value): string
    {
        return strtolower($value);
    }
}
