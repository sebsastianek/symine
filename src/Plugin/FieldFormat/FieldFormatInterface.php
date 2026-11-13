<?php

declare(strict_types=1);

namespace App\Plugin\FieldFormat;

use App\Entity\CustomField;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A custom field format (Redmine::FieldFormat.add 'name', FormatClass). Registered automatically.
 *
 * Plugin formats are stored in custom_fields.field_format like the core ones; their values are edited
 * with a text field (Redmine FieldFormat::Base#edit_tag), validated with validateValue() and displayed
 * with formatValue(). Extend AbstractFieldFormat for sensible defaults.
 */
#[AutoconfigureTag(self::TAG)]
interface FieldFormatInterface
{
    public const string TAG = 'symine.field_format';

    /** Stored format name (e.g. "hello_color") */
    public function getName(): string;

    /** Translation key of the format label (e.g. "label_hello_color") */
    public function getLabel(): string;

    /** Customized classes (Issue, Project, ...) the format is available for; null = all (Redmine customized_class_names) */
    public function getCustomizedClasses(): ?array;

    public function isMultipleSupported(): bool;

    public function isSearchable(): bool;

    public function isFilterable(): bool;

    /** Error translation key ("activerecord.errors.messages.invalid"...) or null when the value is valid */
    public function validateValue(CustomField $field, string $value): ?string;

    /** Displayed value (plain text) */
    public function formatValue(CustomField $field, string $value): string;
}
