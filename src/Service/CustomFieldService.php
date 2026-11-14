<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CustomField;
use App\Entity\CustomValue;
use App\Entity\Issue;
use App\Entity\IssueCustomField;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\Version;
use App\I18n\Localizer;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Custom field values for any customizable object (port of Redmine::FieldFormat + acts_as_customizable).
 * Values are stored as strings in custom_values; multiple values use one row per value.
 */
final class CustomFieldService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly Localizer $i18n,
        private readonly ?\App\Plugin\FieldFormat\FieldFormatRegistry $pluginFormats = null,
        private readonly ?ProjectService $projects = null,
        private readonly ?Settings $settings = null,
        private readonly ?\App\Doctrine\QueryMemo $memo = null,
    ) {
    }

    /** @return list<IssueCustomField> custom fields available for an issue (tracker + project) */
    public function availableIssueFields(Issue $issue): array
    {
        if (!isset($issue->project, $issue->tracker)) {
            return [];
        }
        $trackerFieldIds = array_map(static fn ($f) => $f->id, $issue->tracker->customFields->toArray());
        $projectFieldIds = array_map(static fn ($f) => $f->id, $issue->project->issueCustomFields->toArray());
        $fields = $this->fieldsOf(IssueCustomField::class);

        return array_values(array_filter($fields, static fn (IssueCustomField $f) => \in_array($f->id, $trackerFieldIds, true)
            && ($f->isForAll || \in_array($f->id, $projectFieldIds, true))));
    }

    /** @return list<CustomField> fields of a given STI class, sorted */
    public function fieldsOf(string $class): array
    {
        $load = fn (): array => $this->em->getRepository($class)->findBy([], ['position' => 'ASC']);

        // the form, the list, the filters and the menu of a page all ask for the same fields
        return null === $this->memo ? $load() : $this->memo->get('custom_fields:'.$class, ['custom_fields'], $load);
    }

    /** @param list<Role> $roles */
    public function isVisibleForRoles(CustomField $field, array $roles, ?User $user = null): bool
    {
        if ($field->visible || ($user && $user->admin)) {
            return true;
        }
        $ids = array_map(static fn (Role $r) => $r->id, $field->roles->toArray());
        foreach ($roles as $r) {
            if (\in_array($r->id, $ids, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<CustomField> visible issue fields */
    public function visibleIssueFields(Issue $issue, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $roles = $this->auth->rolesForProject($issue->project, $user);

        return array_values(array_filter($this->availableIssueFields($issue), fn ($f) => $this->isVisibleForRoles($f, $roles, $user)));
    }

    /**
     * Raw values by custom field id. Multiple fields give arrays.
     *
     * @return array<int, string|list<string>|null>
     */
    public function values(string $customizedType, ?int $customizedId): array
    {
        if (null === $customizedId) {
            return [];
        }

        return null === $this->memo
            ? $this->loadValues($customizedType, $customizedId)
            : $this->memo->get(self::valuesKey($customizedType, $customizedId), self::VALUE_TABLES, fn (): array => $this->loadValues($customizedType, $customizedId));
    }

    private const array VALUE_TABLES = ['custom_values', 'custom_fields'];

    private static function valuesKey(string $customizedType, int $customizedId): string
    {
        return 'custom_values:'.$customizedType.':'.$customizedId;
    }

    /**
     * Reads the values of a list of objects in one query, so that values() of each of them has its answer.
     *
     * @param list<int> $ids
     */
    public function preloadValues(string $customizedType, array $ids): void
    {
        if (null === $this->memo || \count($ids) < 2) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $values = $this->valuesFor($customizedType, $ids);
        foreach ($ids as $id) {
            $this->memo->put(self::valuesKey($customizedType, $id), self::VALUE_TABLES, $values[$id] ?? []);
        }
    }

    /** @return array<int, string|list<string>|null> */
    private function loadValues(string $customizedType, int $customizedId): array
    {
        // the field comes with its value: it cannot be loaded lazily (the row decides its class), and would
        // otherwise be one more query per field
        $rows = $this->em->createQuery('SELECT cv, cf FROM App\Entity\CustomValue cv LEFT JOIN cv.customField cf WHERE cv.customizedType = :t AND cv.customizedId = :id ORDER BY cv.id ASC')
            ->setParameters(['t' => $customizedType, 'id' => $customizedId])->getResult();
        $out = [];
        foreach ($rows as $cv) {
            $fid = $cv->customField->id;
            if ($cv->customField->isMultiple()) {
                $out[$fid] ??= [];
                if (null !== $cv->value && '' !== $cv->value) {
                    $out[$fid][] = $cv->value;
                }
            } else {
                $out[$fid] = $cv->value;
            }
        }

        return $out;
    }

    /**
     * Values for many objects at once (lists).
     *
     * @param list<int> $ids
     *
     * @return array<int, array<int, string|list<string>|null>> customizedId => fieldId => value
     */
    public function valuesFor(string $customizedType, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $out = [];
        $rows = $this->em->createQuery('SELECT cv, cf FROM App\Entity\CustomValue cv JOIN cv.customField cf WHERE cv.customizedType = :t AND cv.customizedId IN (:ids) ORDER BY cv.id')
            ->setParameters(['t' => $customizedType, 'ids' => $ids])->getResult();
        foreach ($rows as $cv) {
            $fid = $cv->customField->id;
            if ($cv->customField->isMultiple()) {
                $out[$cv->customizedId][$fid] ??= [];
                if (null !== $cv->value && '' !== $cv->value) {
                    $out[$cv->customizedId][$fid][] = $cv->value;
                }
            } else {
                $out[$cv->customizedId][$fid] = $cv->value;
            }
        }

        return $out;
    }

    /**
     * Saves values (fieldId => string|array). Fields not in $fields are ignored.
     *
     * @param list<CustomField>             $fields
     * @param array<int|string, mixed>      $values
     *
     * @return array<int, array{0: mixed, 1: mixed}> changes fieldId => [old, new]
     */
    public function saveValues(string $customizedType, int $customizedId, array $fields, array $values): array
    {
        $current = $this->values($customizedType, $customizedId);
        $changes = [];
        foreach ($fields as $field) {
            if (!\array_key_exists($field->id, $values) && !\array_key_exists((string) $field->id, $values)) {
                continue;
            }
            $new = $this->normalize($field, $values[$field->id] ?? $values[(string) $field->id]);
            $old = $current[$field->id] ?? ($field->isMultiple() ? [] : null);
            if ($this->sameValue($old, $new)) {
                continue;
            }
            $changes[$field->id] = [$old, $new];
            foreach ($this->em->getRepository(CustomValue::class)->findBy(['customizedType' => $customizedType, 'customizedId' => $customizedId, 'customField' => $field]) as $cv) {
                $this->em->remove($cv);
            }
            foreach ((array) ($new ?? ['']) as $v) {
                if ($field->isMultiple() && '' === $v) {
                    continue;
                }
                $cv = new CustomValue();
                $cv->customizedType = $customizedType;
                $cv->customizedId = $customizedId;
                $cv->customField = $field;
                $cv->value = $v;
                $this->em->persist($cv);
            }
            if ($field->isMultiple() && [] === $new) {
                $cv = new CustomValue();
                $cv->customizedType = $customizedType;
                $cv->customizedId = $customizedId;
                $cv->customField = $field;
                $cv->value = '';
                $this->em->persist($cv);
            }
        }

        return $changes;
    }

    /** Creates default values for new objects */
    public function defaultValues(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $default = $this->defaultValue($f);
            if (null !== $default && '' !== $default) {
                $out[$f->id] = $f->isMultiple() ? [$default] : $default;
            }
        }

        return $out;
    }

    /**
     * CustomField#default_value: a date field in "date_offset" mode stores a number of days,
     * its default value is today (of the current user) + that offset.
     */
    public function defaultValue(CustomField $field): ?string
    {
        $raw = $field->defaultValue;
        if ('date' === $field->fieldFormat && 'date_offset' === $field->getFormatOption('default_value_mode') && null !== $raw && '' !== trim($raw)) {
            // Integer(value, 10) rescue nil
            if (!preg_match('/^[+-]?\d+$/', trim($raw))) {
                return null;
            }

            return $this->i18n->today()->modify(sprintf('%+d days', (int) trim($raw)))->format('Y-m-d');
        }

        return $raw;
    }

    public function deleteValues(string $customizedType, int $customizedId): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\CustomValue cv WHERE cv.customizedType = :t AND cv.customizedId = :id')
            ->setParameters(['t' => $customizedType, 'id' => $customizedId])->execute();
    }

    public function normalize(CustomField $field, mixed $value): string|array|null
    {
        if ($field->isMultiple()) {
            $vals = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), (array) $value), static fn ($v) => '' !== $v));

            return array_values(array_unique($vals));
        }
        if (\is_array($value)) {
            $value = $value[0] ?? '';
        }
        $value = null === $value ? '' : trim((string) $value);
        if ('bool' === $field->fieldFormat && '' !== $value) {
            $value = \in_array($value, ['1', 'true', 'yes'], true) ? '1' : '0';
        }

        return $value;
    }

    private function sameValue(mixed $a, mixed $b): bool
    {
        if (\is_array($a) || \is_array($b)) {
            $a = (array) $a;
            $b = (array) $b;
            sort($a);
            sort($b);

            return $a === $b;
        }

        return (string) $a === (string) $b;
    }

    /**
     * Validates a value; returns error messages (already translated).
     *
     * @return list<string>
     */
    public function validate(CustomField $field, mixed $value, ?Project $project = null, mixed $current = null): array
    {
        $errors = [];
        $values = array_values(array_filter((array) $value, static fn ($v) => null !== $v && '' !== (string) $v));
        $label = $field->name;
        if ([] === $values) {
            if ($field->isRequired) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.blank');
            }

            return $errors;
        }
        $pluginFormat = $this->pluginFormats?->get((string) $field->fieldFormat);
        // possible_custom_value_options: the possible values of the customized object plus its current values
        // (custom_value.value_was) are valid for list-like formats
        $was = array_map('strval', array_values(array_filter((array) $current, static fn ($c) => null !== $c && '' !== (string) $c)));
        $possible = null;
        $included = function (string $v) use ($field, $project, $was, &$possible): bool {
            if (\in_array($v, $was, true)) {
                return true;
            }
            $possible ??= match ($field->fieldFormat) {
                'enumeration' => array_map(static fn ($e) => (string) $e->id, array_values(array_filter($field->enumerations->toArray(), static fn ($e) => $e->active))),
                'user' => array_map(static fn (Principal $pr) => (string) $pr->id, $this->possiblePrincipals($field, $project)),
                'version' => array_map(static fn (Version $ver) => (string) $ver->id, $this->possibleVersions($field, $project)),
                default => $field->getPossibleValues(),
            };

            return \in_array($v, $possible, true);
        };
        foreach ($values as $v) {
            $v = (string) $v;
            if (null !== $pluginFormat) {
                if (null !== ($error = $pluginFormat->validateValue($field, $v))) {
                    $errors[] = $label.' '.$this->i18n->l($error);
                }
                continue;
            }
            $ok = match ($field->fieldFormat) {
                'int' => (bool) preg_match('/^[+-]?\d+$/', $v),
                'float' => is_numeric($v),
                // value.to_date: the date must exist (2030-02-30 is refused)
                'date' => 1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $dm) && checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1]),
                'list' => $included($v),
                'bool' => \in_array($v, ['0', '1'], true),
                // RecordList#validate_custom_value; without customized project only the existence can be checked
                'enumeration' => $included($v),
                'user' => null !== $project ? $included($v) : null !== $this->em->find(Principal::class, (int) $v),
                'version' => null !== $project ? $included($v) : null !== $this->em->find(Version::class, (int) $v),
                'progressbar' => ctype_digit($v) && (int) $v <= 100,
                default => true,
            };
            // Unbounded#validate_single_value (string, text, link, int, float, date): regexp, then length
            if (\in_array($field->fieldFormat, ['string', 'text', 'link', 'int', 'float', 'date'], true)) {
                if ($field->regexp && @preg_match('/'.str_replace('/', '\/', $field->regexp).'/u', $v) === 0) {
                    $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.invalid');
                }
                if ($field->minLength && mb_strlen($v) < $field->minLength) {
                    $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_short', ['count' => $field->minLength]);
                }
                if ($field->maxLength && $field->maxLength > 0 && mb_strlen($v) > $field->maxLength) {
                    $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => $field->maxLength]);
                }
            }
            if (!$ok) {
                // ListFormat / RecordList#validate_custom_value: "is not included in the list";
                // IntFormat (and ProgressbarFormat for non-digits): "is not a number"; DateFormat: "is not a valid date"
                $message = match (true) {
                    \in_array($field->fieldFormat, ['list', 'enumeration', 'user', 'version'], true) => 'activerecord.errors.messages.inclusion',
                    'int' === $field->fieldFormat, 'progressbar' === $field->fieldFormat && !ctype_digit($v) => 'activerecord.errors.messages.not_a_number',
                    'date' === $field->fieldFormat => 'activerecord.errors.messages.not_a_date',
                    default => 'activerecord.errors.messages.invalid',
                };
                $errors[] = $label.' '.$this->i18n->l($message);
            }
        }

        return array_values(array_unique($errors));
    }

    /** Human readable value (cast_value + format_object) */
    public function formatValue(CustomField $field, mixed $value): string
    {
        $values = array_values(array_filter((array) $value, static fn ($v) => null !== $v && '' !== (string) $v));
        $out = [];
        if (null !== ($pluginFormat = $this->pluginFormats?->get((string) $field->fieldFormat))) {
            return implode(', ', array_map(static fn ($v) => $pluginFormat->formatValue($field, (string) $v), $values));
        }
        foreach ($values as $v) {
            $out[] = match ($field->fieldFormat) {
                'bool' => '1' === (string) $v ? $this->i18n->l('general_text_Yes') : $this->i18n->l('general_text_No'),
                'date' => ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v)) ? $this->i18n->formatDate($d) : (string) $v,
                'user' => ($u = $this->em->find(Principal::class, (int) $v)) ? $u->getName() : (string) $v,
                'version' => ($ver = $this->em->find(Version::class, (int) $v)) ? (string) $ver->name : (string) $v,
                'enumeration' => (function () use ($field, $v) {
                    foreach ($field->enumerations as $e) {
                        if ($e->id === (int) $v) {
                            return $e->name;
                        }
                    }

                    return (string) $v;
                })(),
                // format_object(Float): sprintf('%.2f')
                'float' => sprintf('%.2f', (float) $v),
                'progressbar' => $v.'%',
                default => (string) $v,
            };
        }

        return implode(', ', $out);
    }

    /**
     * Values that Redmine renders as links (FieldFormat#formatted_value with html = true): every value of a
     * field with a URL pattern (Base, sorted by text) and the values of link fields (LinkFormat).
     * Returns null when the field is not rendered as links - the caller then formats the value as usual.
     *
     * @param object|null $customized the object holding the value (issue, project, user, version, time entry...),
     *                                for the %id%, %project_id% and %project_identifier% tokens of the pattern
     */
    public function linkedValueHtml(CustomField $field, mixed $value, ?object $customized = null, ?Project $project = null): ?string
    {
        $values = array_values(array_filter(array_map(static fn ($v) => null === $v ? '' : (string) $v, (array) $value), static fn ($v) => '' !== $v));
        $format = (string) $field->fieldFormat;
        $pattern = (string) $field->getFormatOption('url_pattern', '');
        if ([] === $values || ('link' !== $format && ('' === $pattern || \in_array($format, ['text', 'progressbar', 'attachment'], true)))) {
            return null;
        }
        if ('link' === $format) {
            return implode(', ', array_map(function (string $v) use ($field, $pattern, $customized, $project): string {
                $url = '' !== $pattern ? $this->urlFromPattern($field, $v, $customized, $project) : (preg_match('#^[a-z]+://#i', $v) ? $v : 'http://'.$v);

                return self::safeLink(mb_strlen($v) > 40 ? mb_substr($v, 0, 37).'...' : $v, $url, preg_match('#^[a-z]+://#i', $url) ? 'external' : null);
            }, $values));
        }
        $links = [];
        foreach ($values as $v) {
            $links[] = [$this->formatValue($field, $v), $this->urlFromPattern($field, $this->castedValueString($field, $v), $customized, $project)];
        }
        usort($links, static fn (array $a, array $b) => strcmp($a[0], $b[0]));

        return implode(', ', array_map(static fn (array $l) => self::safeLink($l[0], $l[1]), $links));
    }

    /** link_to + sanitize: no link for script-like URLs */
    private static function safeLink(string $text, string $url, ?string $class = null): string
    {
        if (preg_match('#^\s*(javascript|vbscript|data)\s*:#i', $url)) {
            return htmlspecialchars($text);
        }

        return '<a'.(null !== $class ? ' class="'.$class.'"' : '').' href="'.htmlspecialchars($url).'">'.htmlspecialchars($text).'</a>';
    }

    /** to_s of the casted value (FieldFormat#cast_single_value), used for the %value% token */
    private function castedValueString(CustomField $field, string $v): string
    {
        return match ($field->fieldFormat) {
            'int' => (string) (int) $v,
            'float' => is_numeric(trim($v)) ? (floor((float) $v) === (float) $v && abs((float) $v) < 1e15 ? sprintf('%.1f', (float) $v) : (string) (float) $v) : $v,
            'bool' => '1' === $v ? 'true' : 'false',
            'enumeration', 'user', 'version' => $this->formatValue($field, $v),
            default => $v,
        };
    }

    /**
     * FieldFormat::Base#url_from_pattern: replaces %value%, %id%, %project_id%, %project_identifier%
     * and %m1%, %m2%... (groups of the regular expression of the field matched against the value).
     */
    public function urlFromPattern(CustomField $field, string $value, ?object $customized, ?Project $project = null): string
    {
        // Addressable::URI.encode_component: reserved and unreserved characters are kept
        $encode = static fn (string $s): string => (string) preg_replace_callback('/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=]/', static fn (array $m) => rawurlencode($m[0]), $s);
        // customized.project (Project#project is the project itself)
        $customizedProject = $customized instanceof Project ? $customized
            : (\is_object($customized) && isset($customized->project) && $customized->project instanceof Project ? $customized->project : null);
        $customizedProject ??= $project;
        $url = strtr((string) $field->getFormatOption('url_pattern', ''), [
            '%value%' => $encode($value),
            '%id%' => $encode((string) (\is_object($customized) && isset($customized->id) ? $customized->id : '')),
            '%project_id%' => $encode((string) ($customizedProject?->id ?? '')),
            '%project_identifier%' => $encode((string) ($customizedProject?->identifier ?? '')),
        ]);
        if ('' !== (string) $field->regexp) {
            $matches = null;
            $url = (string) preg_replace_callback('/%m(\d+)%/', static function (array $m) use (&$matches, $field, $value, $encode): string {
                if (null === $matches) {
                    $matches = [];
                    @preg_match('/'.str_replace('/', '\/', (string) $field->regexp).'/u', $value, $matches);
                }

                return $encode((string) ($matches[(int) $m[1]] ?? ''));
            }, $url);
        }

        return $url;
    }

    /**
     * Possible values for list-like formats: list of [value, label]
     * (FieldFormat#possible_values_options; user fields start with "<< me >>" like UserFormat#principals_options).
     */
    public function possibleValuesOptions(CustomField $field, ?Project $project = null, ?Issue $issue = null): array
    {
        return match ($field->fieldFormat) {
            'list' => array_map(static fn ($v) => [$v, $v], $field->getPossibleValues()),
            'bool' => [['1', $this->i18n->l('general_text_Yes')], ['0', $this->i18n->l('general_text_No')]],
            'enumeration' => array_values(array_map(static fn ($e) => [(string) $e->id, $e->name], array_filter($field->enumerations->toArray(), static fn ($e) => $e->active))),
            'user' => $project ? $this->principalsOptions($field, $this->possiblePrincipals($field, $project)) : [],
            'version' => array_map(static fn (Version $v) => [(string) $v->id, (string) $v->name], $this->possibleVersions($field, $project)),
            default => [],
        };
    }

    /**
     * UserFormat#possible_values_records: active principals that are members of the project, of the types
     * selected by the "possible_principals" option (users, users and groups, groups) and with one of the
     * roles of the "user_role" option; users first, sorted by name.
     *
     * @return list<Principal>
     */
    public function possiblePrincipals(CustomField $field, ?Project $project): array
    {
        if (null === $project || null === $project->id) {
            return [];
        }
        $classes = match ((string) $field->getFormatOption('possible_principals', '')) {
            'user_group' => [User::class, \App\Entity\Group::class],
            'group' => [\App\Entity\Group::class],
            default => [User::class],
        };
        $roleIds = array_values(array_filter(array_map('intval', (array) $field->getFormatOption('user_role', []))));
        $dql = 'SELECT DISTINCT pr FROM App\Entity\Principal pr JOIN pr.memberships m WHERE m.project = :p AND pr.status = 1';
        $params = ['p' => $project->id];
        if ([] !== $roleIds) {
            $dql .= ' AND EXISTS (SELECT mr.id FROM App\Entity\MemberRole mr WHERE mr.member = m AND IDENTITY(mr.role) IN (:roles))';
            $params['roles'] = $roleIds;
        }
        // exact types like Redmine's where(type: [...]): builtin groups are not selectable
        $principals = array_values(array_filter(
            $this->em->createQuery($dql)->setParameters($params)->getResult(),
            static fn (Principal $pr) => \in_array($pr::class, $classes, true),
        ));
        // Principal.sorted: users before groups, then by the fields of the name format
        $key = fn (Principal $pr): array => [$pr instanceof User ? 0 : 1, ...$this->nameSortKey($pr), (int) $pr->id];
        usort($principals, static fn (Principal $a, Principal $b) => $key($a) <=> $key($b));

        return $principals;
    }

    /** @return list<string> lower-cased fields of User.name_formatter[:order] followed by the lastname */
    private function nameSortKey(Principal $principal): array
    {
        if (!$principal instanceof User) {
            return [mb_strtolower((string) $principal->lastname)];
        }
        $format = $this->settings?->string('user_format') ?: 'firstname_lastname';
        $fields = match (true) {
            'username' === $format => ['login'],
            'lastname' === $format => ['lastname'],
            'firstname' === $format => ['firstname'],
            str_starts_with($format, 'lastname') => ['lastname', 'firstname'],
            default => ['firstname', 'lastname'],
        };
        $fields[] = 'lastname';

        return array_map(static fn (string $f) => mb_strtolower((string) $principal->{$f}), array_values(array_unique($fields)));
    }

    /**
     * UserFormat#principals_options: "<< me >>" (single-valued fields, when the current user is selectable)
     * followed by the principals.
     *
     * @param list<Principal> $principals
     *
     * @return list<array{0: string, 1: string}>
     */
    public function principalsOptions(CustomField $field, array $principals): array
    {
        $options = [];
        if (null !== ($me = $this->meOption($field, $principals))) {
            $options[] = $me;
        }
        foreach ($principals as $pr) {
            $options[] = [(string) $pr->id, $pr->getName()];
        }

        return $options;
    }

    /**
     * UserFormat#me_options.
     *
     * @param list<Principal> $principals
     *
     * @return array{0: string, 1: string}|null
     */
    public function meOption(CustomField $field, array $principals): ?array
    {
        $current = $this->auth->user();
        if ($field->isMultiple() || null === $current->id) {
            return null;
        }
        foreach ($principals as $pr) {
            if ($pr->id === $current->id) {
                return [(string) $current->id, '<< '.$this->i18n->l('label_me').' >>'];
            }
        }

        return null;
    }

    /**
     * VersionFormat#possible_values_records: the versions shared with the project (Project#shared_versions),
     * or the system-wide shared versions without project, restricted to the statuses of the field.
     *
     * @return list<Version>
     */
    public function possibleVersions(CustomField $field, ?Project $project): array
    {
        if (null !== $project && null !== $this->projects) {
            $versions = $this->projects->sharedVersions($project);
        } elseif (null !== $project && null !== $project->id) {
            $versions = $this->em->createQuery('SELECT v FROM App\Entity\Version v WHERE v.project = :p ORDER BY v.effectiveDate, v.name')->setParameter('p', $project->id)->getResult();
        } elseif (null === $project) {
            $visible = $this->auth->allowedToCondition('view_issues', 'IDENTITY(v.project)');
            $versions = $this->em->createQuery("SELECT v, p FROM App\Entity\Version v JOIN v.project p WHERE v.sharing = 'system' AND ($visible)")->getResult();
            $versions = null !== $this->projects ? $this->projects->sortVersions($versions) : $versions;
        } else {
            return [];
        }
        $statuses = array_values(array_filter(array_map('strval', (array) $field->getFormatOption('version_status', [])), static fn ($s) => '' !== $s));

        return array_values(array_filter($versions, static fn (Version $v) => [] === $statuses || \in_array($v->status, $statuses, true)));
    }
}
