<?php

declare(strict_types=1);

namespace App\Plugin\FieldFormat;

use App\Entity\CustomField;

/** Defaults of a string-like field format (Redmine::FieldFormat::Base) */
abstract class AbstractFieldFormat implements FieldFormatInterface
{
    public function getLabel(): string
    {
        return 'label_'.$this->getName();
    }

    public function getCustomizedClasses(): ?array
    {
        return null;
    }

    public function isMultipleSupported(): bool
    {
        return false;
    }

    public function isSearchable(): bool
    {
        return false;
    }

    public function isFilterable(): bool
    {
        return true;
    }

    public function validateValue(CustomField $field, string $value): ?string
    {
        return null;
    }

    public function formatValue(CustomField $field, string $value): string
    {
        return $value;
    }
}
