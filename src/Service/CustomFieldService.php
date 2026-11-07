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
        $fields = $this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']);

        return array_values(array_filter($fields, static fn (IssueCustomField $f) => \in_array($f->id, $trackerFieldIds, true)
            && ($f->isForAll || \in_array($f->id, $projectFieldIds, true))));
    }

    /** @return list<CustomField> fields of a given STI class, sorted */
    public function fieldsOf(string $class): array
    {
        return $this->em->getRepository($class)->findBy([], ['position' => 'ASC']);
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
        $rows = $this->em->getRepository(CustomValue::class)->findBy(['customizedType' => $customizedType, 'customizedId' => $customizedId], ['id' => 'ASC']);
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
            if (null !== $f->defaultValue && '' !== $f->defaultValue) {
                $out[$f->id] = $f->isMultiple() ? [$f->defaultValue] : $f->defaultValue;
            }
        }

        return $out;
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
    public function validate(CustomField $field, mixed $value, ?Project $project = null): array
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
        foreach ($values as $v) {
            $v = (string) $v;
            $ok = match ($field->fieldFormat) {
                'int' => (bool) preg_match('/^[+-]?\d+$/', $v),
                'float' => is_numeric($v),
                'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $v),
                'list' => \in_array($v, $field->getPossibleValues(), true),
                'bool' => \in_array($v, ['0', '1'], true),
                'enumeration' => \in_array((int) $v, array_map(static fn ($e) => $e->id, $field->enumerations->toArray()), true),
                'user' => null !== $this->em->find(Principal::class, (int) $v),
                'version' => null !== $this->em->find(Version::class, (int) $v),
                'progressbar' => ctype_digit($v) && (int) $v <= 100,
                default => true,
            };
            if (!$ok) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.invalid');
                continue;
            }
            if (\in_array($field->fieldFormat, ['string', 'text', 'link'], true)) {
                if ($field->minLength && mb_strlen($v) < $field->minLength) {
                    $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_short', ['count' => $field->minLength]);
                }
                if ($field->maxLength && mb_strlen($v) > $field->maxLength) {
                    $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => $field->maxLength]);
                }
                if ($field->regexp && @preg_match('/'.str_replace('/', '\/', $field->regexp).'/u', $v) === 0) {
                    $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.invalid');
                }
            }
        }

        return $errors;
    }

    /** Human readable value (cast_value + format_object) */
    public function formatValue(CustomField $field, mixed $value): string
    {
        $values = array_values(array_filter((array) $value, static fn ($v) => null !== $v && '' !== (string) $v));
        $out = [];
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
                'float' => (string) (float) $v,
                'progressbar' => $v.'%',
                default => (string) $v,
            };
        }

        return implode(', ', $out);
    }

    /** Possible values for list-like formats: list of [value, label] */
    public function possibleValuesOptions(CustomField $field, ?Project $project = null, ?Issue $issue = null): array
    {
        return match ($field->fieldFormat) {
            'list' => array_map(static fn ($v) => [$v, $v], $field->getPossibleValues()),
            'bool' => [['1', $this->i18n->l('general_text_Yes')], ['0', $this->i18n->l('general_text_No')]],
            'enumeration' => array_values(array_map(static fn ($e) => [(string) $e->id, $e->name], array_filter($field->enumerations->toArray(), static fn ($e) => $e->active))),
            'user' => $project ? array_map(static fn ($u) => [(string) $u->id, $u->getName()], $this->userOptions($field, $project)) : [],
            'version' => $project ? $this->versionOptions($field, $project) : [],
            default => [],
        };
    }

    /** @return list<Principal> */
    private function userOptions(CustomField $field, Project $project): array
    {
        $roleIds = array_map('intval', (array) $field->getFormatOption('user_role', []));
        $dql = 'SELECT DISTINCT u FROM App\Entity\User u JOIN u.memberships m JOIN m.memberRoles mr WHERE m.project = :p AND u.status = 1';
        $params = ['p' => $project->id];
        if ([] !== array_filter($roleIds)) {
            $dql .= ' AND IDENTITY(mr.role) IN (:roles)';
            $params['roles'] = array_filter($roleIds);
        }
        $users = $this->em->createQuery($dql.' ORDER BY u.firstname, u.lastname')->setParameters($params)->getResult();

        return $users;
    }

    private function versionOptions(CustomField $field, Project $project): array
    {
        $statuses = (array) $field->getFormatOption('version_status', []);
        $versions = $this->em->createQuery('SELECT v FROM App\Entity\Version v WHERE v.project = :p ORDER BY v.effectiveDate, v.name')->setParameter('p', $project->id)->getResult();
        $out = [];
        foreach ($versions as $v) {
            if ([] === array_filter($statuses) || \in_array($v->status, $statuses, true)) {
                $out[] = [(string) $v->id, (string) $v->name];
            }
        }

        return $out;
    }
}
