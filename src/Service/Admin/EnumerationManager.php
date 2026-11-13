<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\CustomField;
use App\Entity\DocumentCategory;
use App\Entity\Enumeration;
use App\Entity\IssuePriority;
use App\Entity\TimeEntryActivity;
use App\I18n\Localizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enumeration model logic (Redmine's Enumeration, IssuePriority, TimeEntryActivity, DocumentCategory):
 * positions scoped by type/project/parent, single default per type, reassign on destroy,
 * IssuePriority.compute_position_names.
 */
final class EnumerationManager
{
    /** Same order as Enumeration.get_subclasses in Redmine */
    public const array CLASSES = [
        'DocumentCategory' => DocumentCategory::class,
        'IssuePriority' => IssuePriority::class,
        'TimeEntryActivity' => TimeEntryActivity::class,
    ];

    public const array OPTION_NAMES = [
        'DocumentCategory' => 'enumeration_doc_categories',
        'IssuePriority' => 'enumeration_issue_priorities',
        'TimeEntryActivity' => 'enumeration_activities',
    ];

    /** API collection name => type */
    public const array PLURALS = [
        'issue_priorities' => 'IssuePriority',
        'time_entry_activities' => 'TimeEntryActivity',
        'document_categories' => 'DocumentCategory',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListPositioner $positioner,
        private readonly Localizer $i18n,
    ) {
    }

    public static function typeFromPlural(string $plural): ?string
    {
        return self::PLURALS[$plural] ?? null;
    }

    /** @return list<Enumeration> system (project_id NULL) values of a type, sorted */
    public function shared(string $type): array
    {
        $class = self::CLASSES[$type];

        return $this->em->createQuery("SELECT e FROM $class e WHERE e.project IS NULL ORDER BY e.position, e.id")->getResult();
    }

    /** @return list<CustomField> custom fields of "<Type>CustomField" */
    public function availableCustomFields(string $type): array
    {
        $class = CustomField::TYPES[$type.'CustomField'] ?? null;
        if (null === $class) {
            return [];
        }

        return $this->em->createQuery("SELECT cf FROM $class cf ORDER BY cf.position")->getResult();
    }

    /** enumeration_params: name, active, is_default, position */
    public function assign(Enumeration $e, array $params): void
    {
        if (\array_key_exists('name', $params)) {
            $e->name = trim((string) $params['name']);
        }
        foreach (['active' => 'active', 'is_default' => 'isDefault'] as $k => $prop) {
            if (\array_key_exists($k, $params)) {
                $v = \is_array($params[$k]) ? end($params[$k]) : $params[$k];
                $e->$prop = \in_array((string) $v, ['1', 'true'], true);
            }
        }
        if (\array_key_exists('position', $params) && '' !== (string) $params['position']) {
            $e->position = (int) $params['position'];
        }
    }

    /** @return list<string> */
    public function validate(Enumeration $e): array
    {
        $errors = [];
        $label = $this->i18n->l('field_name');
        if ('' === $e->name) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($e->name) > 30) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 30]);
        } else {
            $class = $e::class;
            $qb = $this->em->createQueryBuilder()->select('COUNT(x.id)')->from($class, 'x')->where('x.name = :n')->setParameter('n', $e->name);
            if (null === $e->project) {
                $qb->andWhere('x.project IS NULL');
            } else {
                $qb->andWhere('x.project = :p')->setParameter('p', $e->project);
            }
            if (null !== $e->id) {
                $qb->andWhere('x.id <> :id')->setParameter('id', $e->id);
            }
            if ((int) $qb->getQuery()->getSingleScalarResult() > 0) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.taken');
            }
        }

        return $errors;
    }

    /** @return array<string, int|string|null> */
    private function scope(Enumeration $e): array
    {
        return ['type' => $e::typeName(), 'project_id' => $e->project?->id, 'parent_id' => $e->parent?->id];
    }

    public function create(Enumeration $e): void
    {
        $explicit = null !== $e->position;
        if (!$explicit) {
            $e->position = null !== $e->parent ? $e->parent->position : $this->positioner->nextPosition('enumerations', $this->scope($e));
        }
        if ($e->isDefault) {
            $this->clearDefault($e);
        }
        $this->em->persist($e);
        $this->em->flush();
        if ($explicit) {
            $this->positioner->inserted('enumerations', $this->scope($e), $e->id, $e->position);
        }
        $this->afterSave($e, true, true);
    }

    /** @param array{position: ?int, active: bool, isDefault: bool, name: string} $before */
    public function update(Enumeration $e, array $before): void
    {
        if ($e->isDefault && !$before['isDefault']) {
            $this->clearDefault($e);
        }
        $this->em->flush();
        $positionChanged = $before['position'] !== $e->position;
        if ($positionChanged && null !== $e->position) {
            if (null === $before['position']) {
                $this->positioner->inserted('enumerations', $this->scope($e), $e->id, $e->position);
            } else {
                $this->positioner->moved('enumerations', $this->scope($e), $e->id, $before['position'], $e->position);
            }
            if (null === $e->parent) {
                // overrides get the same position as the overridden enumeration
                $this->em->getConnection()->executeStatement('UPDATE enumerations SET position = COALESCE((SELECT p.position FROM (SELECT id, position FROM enumerations) p WHERE enumerations.parent_id = p.id), 1) WHERE parent_id IS NOT NULL');
            }
        }
        if ($before['name'] !== $e->name && null === $e->parent) {
            $this->em->getConnection()->executeStatement('UPDATE enumerations SET name = ? WHERE name = ? AND parent_id = ?', [$e->name, $before['name'], $e->id]);
        }
        $this->afterSave($e, $positionChanged && null !== $e->position, $before['active'] !== $e->active || $before['isDefault'] !== $e->isDefault);
    }

    public function objectsCount(Enumeration $e): int
    {
        $db = $this->em->getConnection();

        return match (true) {
            $e instanceof IssuePriority => (int) $db->fetchOne('SELECT COUNT(*) FROM issues WHERE priority_id = ?', [$e->id]),
            $e instanceof DocumentCategory => (int) $db->fetchOne('SELECT COUNT(*) FROM documents WHERE category_id = ?', [$e->id]),
            $e instanceof TimeEntryActivity => (int) $db->fetchOne('SELECT COUNT(*) FROM time_entries WHERE activity_id IN (SELECT id FROM enumerations WHERE id = ? OR parent_id = ?)', [$e->id, $e->id]),
            default => 0,
        };
    }

    /** Destroys the enumeration, reassigning its objects to $reassignTo (transfer_relations) */
    public function destroy(Enumeration $e, ?Enumeration $reassignTo = null): void
    {
        $db = $this->em->getConnection();
        if (null !== $reassignTo) {
            match (true) {
                $e instanceof IssuePriority => $db->executeStatement('UPDATE issues SET priority_id = ? WHERE priority_id = ?', [$reassignTo->id, $e->id]),
                $e instanceof DocumentCategory => $db->executeStatement('UPDATE documents SET category_id = ? WHERE category_id = ?', [$reassignTo->id, $e->id]),
                $e instanceof TimeEntryActivity => $db->executeStatement('UPDATE time_entries SET activity_id = ? WHERE activity_id IN (SELECT id FROM enumerations WHERE id = ? OR parent_id = ?)', [$reassignTo->id, $e->id, $e->id]),
                default => null,
            };
        } elseif ($this->objectsCount($e) > 0) {
            throw new \RuntimeException('Cannot delete enumeration');
        }
        $scope = $this->scope($e);
        $id = (int) $e->id;
        $position = $e->position;
        $hasParent = null !== $e->parent;
        $db->executeStatement("DELETE FROM custom_values WHERE customized_type = 'Enumeration' AND customized_id = ?", [$id]);
        $this->em->remove($e);
        $this->em->flush();
        if (!$hasParent) {
            $this->positioner->removed('enumerations', $scope, $id, $position);
        }
        if ($e instanceof IssuePriority) {
            $this->computePositionNames();
        }
    }

    private function clearDefault(Enumeration $e): void
    {
        $qb = $this->em->getConnection()->createQueryBuilder()->update('enumerations')->set('is_default', ':f')
            ->where('type = :t')->setParameter('f', false, \Doctrine\DBAL\ParameterType::BOOLEAN)->setParameter('t', $e::typeName());
        if (null !== $e->id) {
            $qb->andWhere('id <> :id')->setParameter('id', $e->id);
        }
        $qb->executeStatement();
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[Enumeration::class] ?? [] as $other) {
            if ($other !== $e && $other::typeName() === $e::typeName()) {
                $other->isDefault = false;
            }
        }
    }

    private function afterSave(Enumeration $e, bool $positionChanged, bool $flagsChanged): void
    {
        if ($e instanceof IssuePriority && ($positionChanged || $flagsChanged)) {
            $this->computePositionNames();
        }
    }

    /** IssuePriority.compute_position_names */
    public function computePositionNames(): void
    {
        $db = $this->em->getConnection();
        $rows = $db->fetchAllAssociative("SELECT id, position, is_default FROM enumerations WHERE type = 'IssuePriority' AND active = ? ORDER BY position", [true], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
        if ([] === $rows) {
            return;
        }
        $default = null;
        foreach ($db->fetchAllAssociative("SELECT id, position FROM enumerations WHERE type = 'IssuePriority' AND is_default = ? ORDER BY position", [true], [\Doctrine\DBAL\ParameterType::BOOLEAN]) as $d) {
            $default = $d;
            break;
        }
        $default ??= $rows[intdiv(\count($rows) - 1, 2)];
        $defaultPosition = (int) $default['position'];
        $n = \count($rows);
        foreach ($rows as $index => $p) {
            $pos = (int) $p['position'];
            if ($pos === $defaultPosition) {
                $name = 'default';
            } elseif ($pos < $defaultPosition) {
                $name = 0 === $index ? 'lowest' : 'low'.($index + 1);
            } else {
                $name = $index === $n - 1 ? 'highest' : 'high'.($n - $index);
            }
            $db->executeStatement('UPDATE enumerations SET position_name = ? WHERE id = ?', [$name, $p['id']]);
        }
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[Enumeration::class] ?? [] as $e) {
            if ($e instanceof IssuePriority) {
                $this->em->refresh($e);
            }
        }
    }
}
