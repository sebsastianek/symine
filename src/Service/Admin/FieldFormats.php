<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\CustomField;
use App\I18n\Localizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Custom field formats metadata (port of the class attributes of Redmine::FieldFormat::*)
 * and the admin-side validation / attribute assignment of custom fields.
 */
final class FieldFormats
{
    /**
     * Registration order of Redmine::FieldFormat.all.
     *
     * @var array<string, array{label: string, multiple: bool, searchable: bool, filter: bool, classes: ?list<string>, partial: string}>
     */
    public const array FORMATS = [
        'string' => ['label' => 'label_string', 'multiple' => false, 'searchable' => true, 'filter' => true, 'classes' => null, 'partial' => 'string'],
        'text' => ['label' => 'label_text', 'multiple' => false, 'searchable' => true, 'filter' => true, 'classes' => null, 'partial' => 'text'],
        'link' => ['label' => 'label_link', 'multiple' => false, 'searchable' => false, 'filter' => true, 'classes' => null, 'partial' => 'link'],
        'int' => ['label' => 'label_integer', 'multiple' => false, 'searchable' => false, 'filter' => true, 'classes' => null, 'partial' => 'numeric'],
        'float' => ['label' => 'label_float', 'multiple' => false, 'searchable' => false, 'filter' => true, 'classes' => null, 'partial' => 'numeric'],
        'date' => ['label' => 'label_date', 'multiple' => false, 'searchable' => false, 'filter' => true, 'classes' => null, 'partial' => 'date'],
        'list' => ['label' => 'label_list', 'multiple' => true, 'searchable' => true, 'filter' => true, 'classes' => null, 'partial' => 'list'],
        'bool' => ['label' => 'label_boolean', 'multiple' => false, 'searchable' => false, 'filter' => true, 'classes' => null, 'partial' => 'bool'],
        'enumeration' => ['label' => 'label_field_format_enumeration', 'multiple' => true, 'searchable' => false, 'filter' => true, 'classes' => null, 'partial' => 'enumeration'],
        'user' => ['label' => 'label_user', 'multiple' => true, 'searchable' => false, 'filter' => true, 'classes' => ['Issue', 'TimeEntry', 'Version', 'Document', 'Project'], 'partial' => 'user'],
        'version' => ['label' => 'label_version', 'multiple' => true, 'searchable' => false, 'filter' => true, 'classes' => ['Issue', 'TimeEntry', 'Version', 'Document', 'Project'], 'partial' => 'version'],
        'attachment' => ['label' => 'label_attachment', 'multiple' => false, 'searchable' => false, 'filter' => false, 'classes' => null, 'partial' => 'attachment'],
        'progressbar' => ['label' => 'label_progressbar', 'multiple' => false, 'searchable' => false, 'filter' => true, 'classes' => null, 'partial' => 'progressbar'],
    ];

    /** Keys stored in custom_fields.format_store (CustomField.store_accessor) */
    public const array STORE_KEYS = ['url_pattern', 'full_width_layout', 'text_formatting', 'thousands_delimiter', 'default_value_mode',
        'edit_tag_style', 'user_role', 'possible_principals', 'version_status', 'extensions_allowed', 'ratio_interval'];

    /** CustomFieldsHelper::CUSTOM_FIELDS_TABS */
    public const array TABS = [
        'IssueCustomField' => 'label_issue_plural',
        'TimeEntryCustomField' => 'label_spent_time',
        'ProjectCustomField' => 'label_project_plural',
        'VersionCustomField' => 'label_version_plural',
        'DocumentCustomField' => 'label_document_plural',
        'UserCustomField' => 'label_user_plural',
        'GroupCustomField' => 'label_group_plural',
        'TimeEntryActivityCustomField' => 'enumeration_activities',
        'IssuePriorityCustomField' => 'enumeration_issue_priorities',
        'DocumentCategoryCustomField' => 'enumeration_doc_categories',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
        private readonly \App\Service\Settings $settings,
        private readonly ?\App\Plugin\FieldFormat\FieldFormatRegistry $pluginFormats = null,
    ) {
    }

    /**
     * Core formats followed by the formats registered by plugins (Redmine::FieldFormat.all).
     *
     * @return array<string, array{label: string, multiple: bool, searchable: bool, filter: bool, classes: ?list<string>, partial: string}>
     */
    public function all(): array
    {
        $formats = self::FORMATS;
        foreach ($this->pluginFormats?->all() ?? [] as $name => $f) {
            $formats[$name] ??= \App\Plugin\FieldFormat\FieldFormatRegistry::describe($f);
        }

        return $formats;
    }

    /** Format metadata, including plugin formats */
    public function info(string $name): array
    {
        return $this->all()[$name] ?? self::FORMATS['string'];
    }

    /** "Issue" for IssueCustomField (CustomField.customized_class) */
    public static function customizedClass(string $type): string
    {
        return substr($type, 0, -\strlen('CustomField'));
    }

    /** customized_type of the API ("issue", "time_entry_activity"...) */
    public static function customizedTypeUnderscore(string $type): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', self::customizedClass($type)));
    }

    public static function format(string $name): array
    {
        return self::FORMATS[$name] ?? self::FORMATS['string'];
    }

    /** FieldFormat.as_select(class): [label, value] sorted by label */
    public function formatsForSelect(string $type): array
    {
        $class = self::customizedClass($type);
        $out = [];
        foreach ($this->all() as $name => $f) {
            if (null === $f['classes'] || \in_array($class, $f['classes'], true)) {
                $out[] = [$this->i18n->l($f['label']), $name];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($a[0], $b[0]));

        return $out;
    }

    public static function typeExists(?string $type): bool
    {
        return null !== $type && isset(CustomField::TYPES[$type]);
    }

    public static function newInstance(string $type): CustomField
    {
        $class = CustomField::TYPES[$type];

        return new $class();
    }

    /**
     * possible_values_options(custom_field) without object, as [label, value] pairs (value null = same as label).
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public function possibleValuesOptions(CustomField $field): array
    {
        switch ($field->fieldFormat) {
            case 'list':
                return array_map(static fn ($v) => [$v, null], $field->getPossibleValues());
            case 'bool':
                return [[$this->i18n->l('general_text_Yes'), '1'], [$this->i18n->l('general_text_No'), '0']];
            case 'enumeration':
                $out = [];
                foreach ($field->enumerations as $e) {
                    if ($e->active) {
                        $out[] = [$e->name, (string) $e->id];
                    }
                }

                return $out;
            case 'version':
                $statuses = array_values(array_filter(array_map('strval', (array) $field->getFormatOption('version_status', [])), static fn ($s) => '' !== $s));
                $versions = $this->em->createQuery("SELECT v FROM App\Entity\Version v WHERE v.sharing = 'system' ORDER BY v.effectiveDate, v.name")->getResult();
                $out = [];
                foreach ($versions as $v) {
                    if ([] === $statuses || \in_array($v->status, $statuses, true)) {
                        $out[] = [(string) $v->name, (string) $v->id];
                    }
                }

                return $out;
            default:
                return [];
        }
    }

    /**
     * Assigns posted attributes (CustomField#safe_attributes=) - the format can only be set on new records.
     *
     * @param array<string, mixed> $attrs
     */
    public function assign(CustomField $field, array $attrs): void
    {
        if (null === $field->id && isset($attrs['field_format']) && isset($this->all()[(string) $attrs['field_format']])) {
            $field->fieldFormat = (string) $attrs['field_format'];
        }
        if (\array_key_exists('name', $attrs)) {
            $field->name = trim((string) $attrs['name']);
        }
        if (\array_key_exists('description', $attrs)) {
            $field->description = (string) $attrs['description'];
        }
        if (\array_key_exists('possible_values', $attrs)) {
            $v = $attrs['possible_values'];
            $lines = \is_array($v) ? $v : (preg_split('/[\n\r]+/', (string) $v) ?: []);
            $field->possibleValues = array_values(array_filter(array_map(static fn ($s) => trim((string) $s), $lines), static fn ($s) => '' !== $s));
        }
        if (\array_key_exists('regexp', $attrs)) {
            $field->regexp = (string) $attrs['regexp'];
        }
        foreach (['min_length' => 'minLength', 'max_length' => 'maxLength'] as $k => $prop) {
            if (\array_key_exists($k, $attrs)) {
                $field->$prop = '' === trim((string) $attrs[$k]) ? null : (int) $attrs[$k];
            }
        }
        foreach (['is_required' => 'isRequired', 'is_for_all' => 'isForAll', 'is_filter' => 'isFilter', 'searchable' => 'searchable', 'editable' => 'editable', 'visible' => 'visible', 'multiple' => 'multiple'] as $k => $prop) {
            if (\array_key_exists($k, $attrs)) {
                $field->$prop = \in_array((string) (\is_array($attrs[$k]) ? end($attrs[$k]) : $attrs[$k]), ['1', 'true'], true);
            }
        }
        if (\array_key_exists('default_value', $attrs)) {
            $dv = $attrs['default_value'];
            $field->defaultValue = \is_array($dv) ? (string) end($dv) : (null === $dv ? null : (string) $dv);
        }
        if (\array_key_exists('position', $attrs) && '' !== (string) $attrs['position']) {
            $field->position = (int) $attrs['position'];
        }
        if (\array_key_exists('role_ids', $attrs)) {
            $ids = array_map('intval', array_filter((array) $attrs['role_ids'], static fn ($v) => '' !== (string) $v));
            $field->roles->clear();
            foreach ($ids ? $this->em->getRepository(\App\Entity\Role::class)->findBy(['id' => $ids]) : [] as $role) {
                $field->roles->add($role);
            }
        }
        if ($field instanceof \App\Entity\IssueCustomField && \array_key_exists('tracker_ids', $attrs)) {
            $ids = array_map('intval', array_filter((array) $attrs['tracker_ids'], static fn ($v) => '' !== (string) $v));
            $field->trackers->clear();
            foreach ($ids ? $this->em->getRepository(\App\Entity\Tracker::class)->findBy(['id' => $ids]) : [] as $t) {
                $field->trackers->add($t);
            }
        }
        foreach (self::STORE_KEYS as $key) {
            if (\array_key_exists($key, $attrs)) {
                $value = $attrs[$key];
                if (\is_array($value)) {
                    $value = array_values(array_map('strval', $value));
                } else {
                    $value = null === $value ? null : (string) $value;
                }
                $field->setFormatOption($key, $value);
            }
        }
    }

    /** before_validation / before_save callbacks (set_searchable + format.before_custom_field_save) */
    public function normalize(CustomField $field): void
    {
        $f = self::format($field->fieldFormat);
        if (!$f['searchable']) {
            $field->searchable = false;
        }
        if (!$f['multiple']) {
            $field->multiple = false;
        }
        switch ($field->fieldFormat) {
            case 'date':
                $mode = 'date_offset' === $field->getFormatOption('default_value_mode') ? 'date_offset' : 'fixed_date';
                $field->setFormatOption('default_value_mode', $mode);
                if ('date_offset' === $mode) {
                    $field->defaultValue = trim((string) $field->defaultValue);
                }
                break;
            case 'user':
                if (\is_array($field->getFormatOption('user_role'))) {
                    $field->setFormatOption('user_role', array_values(array_filter(array_map('strval', $field->getFormatOption('user_role')), static fn ($v) => '' !== $v)));
                }
                break;
            case 'version':
                if (\is_array($field->getFormatOption('version_status'))) {
                    $field->setFormatOption('version_status', array_values(array_filter(array_map('strval', $field->getFormatOption('version_status')), static fn ($v) => '' !== $v)));
                }
                break;
            case 'progressbar':
                if ('' === (string) $field->getFormatOption('ratio_interval', '')) {
                    $field->setFormatOption('ratio_interval', (string) ($this->settings->int('issue_done_ratio_interval') ?: 10));
                }
                break;
        }
    }

    /**
     * CustomField validations; returns full error messages.
     *
     * @return list<string>
     */
    public function validate(CustomField $field): array
    {
        $errors = [];
        $label = fn (string $attr) => $this->i18n->l('field_'.$attr);
        $msg = fn (string $key, array $p = []) => $this->i18n->l('activerecord.errors.messages.'.$key, $p);
        if ('' === trim($field->name)) {
            $errors[] = $label('name').' '.$msg('blank');
        } elseif (mb_strlen($field->name) > 30) {
            $errors[] = $label('name').' '.$msg('too_long', ['count' => 30]);
        } else {
            $dup = $this->em->createQuery('SELECT COUNT(cf.id) FROM '.$field::class.' cf WHERE cf.name = :n'.($field->id ? ' AND cf.id <> :id' : ''))
                ->setParameters($field->id ? ['n' => $field->name, 'id' => $field->id] : ['n' => $field->name])->getSingleScalarResult();
            if ($dup > 0) {
                $errors[] = $label('name').' '.$msg('taken');
            }
        }
        if (mb_strlen((string) $field->regexp) > 255) {
            $errors[] = $label('regexp').' '.$msg('too_long', ['count' => 255]);
        }
        // format validations
        $pattern = (string) $field->getFormatOption('url_pattern', '');
        if ('' !== $pattern) {
            $url = (string) preg_replace('/%[^%]+%/', '', $pattern);
            $scheme = parse_url($url, \PHP_URL_SCHEME);
            if (\is_string($scheme) && !\in_array(strtolower($scheme), ['http', 'https', 'ftp', 'mailto', 'tel', 'news', 'irc', 'ircs', 'ssh', 'file', 'nntp', 'feed'], true)) {
                $errors[] = $this->i18n->l('label_link_values_to').' '.$msg('invalid');
            }
        }
        if ('list' === $field->fieldFormat && [] === $field->getPossibleValues()) {
            $errors[] = $label('possible_values').' '.$msg('blank');
        }
        $regexpOk = true;
        if ('' !== (string) $field->regexp) {
            if (false === @preg_match('/'.str_replace('/', '\/', (string) $field->regexp).'/u', '')) {
                $errors[] = $label('regexp').' '.$msg('invalid');
                $regexpOk = false;
            }
        }
        $dv = (string) $field->defaultValue;
        if ('date' === $field->fieldFormat && 'date_offset' === $field->getFormatOption('default_value_mode')) {
            if ('' !== trim($dv) && !preg_match('/^[+-]?\d+$/', trim($dv))) {
                $errors[] = $label('default_value').' '.$msg('not_a_number');
            }
        } elseif ('' !== $dv && $regexpOk) {
            foreach ($this->validateValue($field, $dv) as $m) {
                $errors[] = $label('default_value').' '.$m;
            }
        }
        if ($field instanceof \App\Entity\IssueCustomField && !$field->visible && 0 === $field->roles->count()) {
            $errors[] = $this->i18n->l('label_role_plural').' '.$msg('blank');
        }

        return $errors;
    }

    /** validate_field_value for a single (default) value; returns messages without the attribute name */
    public function validateValue(CustomField $field, string $value): array
    {
        $errs = [];
        $msg = fn (string $key, array $p = []) => $this->i18n->l('activerecord.errors.messages.'.$key, $p);
        if (\in_array($field->fieldFormat, ['string', 'text', 'link', 'int', 'float', 'date'], true)) {
            if ('' !== (string) $field->regexp && !preg_match('/'.str_replace('/', '\/', (string) $field->regexp).'/u', $value)) {
                $errs[] = $msg('invalid');
            }
            if ($field->minLength && mb_strlen($value) < $field->minLength) {
                $errs[] = $msg('too_short', ['count' => $field->minLength]);
            }
            if ($field->maxLength && $field->maxLength > 0 && mb_strlen($value) > $field->maxLength) {
                $errs[] = $msg('too_long', ['count' => $field->maxLength]);
            }
        }
        switch ($field->fieldFormat) {
            case 'int':
                if (!preg_match('/^[+-]?\d+$/', trim($value))) {
                    $errs[] = $msg('not_a_number');
                }
                break;
            case 'float':
                if (!is_numeric(trim($value))) {
                    $errs[] = $msg('invalid');
                }
                break;
            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || false === \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) {
                    $errs[] = $msg('not_a_date');
                }
                break;
            case 'list':
                if (!\in_array($value, $field->getPossibleValues(), true)) {
                    $errs[] = $msg('inclusion');
                }
                break;
            case 'progressbar':
                if (!preg_match('/^\d*$/', trim($value))) {
                    $errs[] = $msg('not_a_number');
                } elseif ((int) $value < 0 || (int) $value > 100) {
                    $errs[] = $msg('invalid');
                }
                break;
        }

        return array_values(array_unique($errs));
    }
}
