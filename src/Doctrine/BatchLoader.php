<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Principal;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

/**
 * Loads in one query what the ORM would otherwise load one row at a time while a list is rendered (N+1):
 * the lazy collections of the entities of a list, and the principals a list refers to.
 *
 * Nothing here changes what is loaded, only how many round trips it takes: a collection filled by this class
 * holds the same entities, in the same order, as if it had been left to load itself.
 */
final class BatchLoader
{
    /** Entities per query, and the most siblings loaded along with one entity */
    private const int BATCH = 250;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Loads a one-to-many collection of the entity and, in the same query, of the other entities of its class
     * that are already in memory and have not loaded theirs: the second issue of a list asking for its children
     * finds them there. Costs nothing when the collection is loaded.
     */
    public function collection(object $entity, string $association): void
    {
        $collection = $entity->{$association} ?? null;
        if (!$collection instanceof PersistentCollection || $collection->isInitialized()) {
            return;
        }
        $uow = $this->em->getUnitOfWork();
        $meta = $this->em->getClassMetadata($entity::class);
        $owners = [$entity];
        foreach ($uow->getIdentityMap()[$meta->rootEntityName] ?? [] as $other) {
            if (\count($owners) >= self::BATCH) {
                break;
            }
            if ($other !== $entity && $other instanceof $entity && !$uow->isUninitializedObject($other)) {
                $owners[] = $other;
            }
        }
        $this->collections($owners, $association);
    }

    /**
     * Loads a self-referencing one-to-many collection (children) of the entity and of everything below it,
     * one query per level of the tree instead of one per node.
     */
    public function descendants(object $entity, string $association): void
    {
        $this->collection($entity, $association);
        $level = $entity->{$association}->toArray();
        while ([] !== $level) {
            $this->collections($level, $association);
            $next = [];
            foreach ($level as $child) {
                array_push($next, ...$child->{$association}->toArray());
            }
            $level = $next;
        }
    }

    /**
     * Loads a one-to-many collection of all the given entities (of one class) that have not loaded it yet.
     *
     * @param iterable<object> $entities
     */
    public function collections(iterable $entities, string $association): void
    {
        $pending = [];
        $class = null;
        foreach ($entities as $entity) {
            $collection = $entity->{$association} ?? null;
            // a collection changed in memory before being loaded is left to the ORM, which knows how to merge
            if ($collection instanceof PersistentCollection && !$collection->isInitialized() && !$collection->isDirty() && null !== $entity->id) {
                $pending[(int) $entity->id] = $collection;
                $class ??= $entity::class;
            }
        }
        if (\count($pending) < 2) {
            // nothing to gain: a single collection loads itself when (and if) it is read
            return;
        }
        $mapping = $this->em->getClassMetadata($class)->getAssociationMapping($association);
        if (!$mapping->isOneToMany()) {
            throw new \LogicException(sprintf('%s::$%s is not a one-to-many association.', $class, $association));
        }
        $inverse = $mapping->mappedBy;
        // the owner is taken from the row, as when the collection loads itself, not from the entity in memory
        $dql = 'SELECT c, IDENTITY(c.'.$inverse.') AS owner_id FROM '.$mapping->targetEntity.' c WHERE IDENTITY(c.'.$inverse.') IN (:ids)';
        $order = [];
        foreach ($mapping->orderBy() as $field => $direction) {
            $order[] = 'c.'.$field.' '.self::direction($direction);
        }
        if ([] !== $order) {
            $dql .= ' ORDER BY '.implode(', ', $order);
        }
        foreach (array_chunk(array_keys($pending), self::BATCH) as $ids) {
            foreach ($ids as $id) {
                $pending[$id]->unwrap()->clear();
            }
            foreach ($this->em->createQuery($dql)->setParameter('ids', $ids)->getResult() as $row) {
                // what the ORM does with each row of a collection it loads (ObjectHydrator)
                $pending[(int) $row['owner_id']]->hydrateAdd($row[0]);
            }
            foreach ($ids as $id) {
                $pending[$id]->setInitialized(true);
                $pending[$id]->takeSnapshot();
            }
        }
    }

    /**
     * Loads the entities of a list that are still references (an issue known by its id only) in one query per
     * class, instead of one query each when the list is rendered.
     *
     * @param iterable<object|null> $entities
     */
    public function references(iterable $entities): void
    {
        $uow = $this->em->getUnitOfWork();
        $pending = [];
        foreach ($entities as $entity) {
            if (null !== $entity && $uow->isUninitializedObject($entity)) {
                $id = $uow->getEntityIdentifier($entity);
                if (1 === \count($id)) {
                    $pending[$this->em->getClassMetadata($entity::class)->name][(int) reset($id)] = true;
                }
            }
        }
        foreach ($pending as $class => $ids) {
            if (\count($ids) < 2) {
                continue;
            }
            $ids = array_keys($ids);
            if (\App\Entity\Issue::class === $class) {
                $this->issuePrincipals($ids);
            }
            foreach (array_chunk($ids, self::BATCH) as $chunk) {
                $this->em->createQuery('SELECT e FROM '.$class.' e WHERE e.id IN (:ids)')->setParameter('ids', $chunk)->getResult();
            }
        }
    }

    /**
     * Loads the authors and assignees of issues that are about to be loaded (see principals()).
     *
     * @param list<int> $issueIds
     */
    public function issuePrincipals(array $issueIds): void
    {
        if (\count($issueIds) < 2) {
            return;
        }
        $principals = [];
        foreach (array_chunk($issueIds, self::BATCH) as $chunk) {
            $rows = $this->em->getConnection()->fetchAllNumeric('SELECT DISTINCT author_id, assigned_to_id FROM issues WHERE id IN ('.implode(',', array_map('intval', $chunk)).')');
            foreach ($rows as [$author, $assignee]) {
                $principals[] = $author;
                $principals[] = $assignee;
            }
        }
        $this->principals($principals);
    }

    /** Direction of an #[OrderBy] mapping: the ORM gives it as a string or as an enum, depending on its version */
    private static function direction(mixed $direction): string
    {
        if ($direction instanceof \UnitEnum) {
            $direction = $direction instanceof \BackedEnum ? (string) $direction->value : $direction->name;
        }

        return str_starts_with(strtoupper((string) $direction), 'DESC') ? 'DESC' : 'ASC';
    }

    /**
     * Loads the users and groups with these ids that are not in memory yet, users with their preferences.
     * Associations to a principal cannot be lazy (the row decides the class): without this, hydrating a list
     * loads every author and assignee with one or two queries each.
     *
     * @param iterable<int|string|null> $ids
     */
    public function principals(iterable $ids): void
    {
        $uow = $this->em->getUnitOfWork();
        $missing = [];
        foreach ($ids as $id) {
            if (null !== $id && (int) $id > 0 && false === $uow->tryGetById((int) $id, Principal::class)) {
                $missing[(int) $id] = (int) $id;
            }
        }
        if (\count($missing) < 2) {
            return;
        }
        foreach (array_chunk(array_values($missing), self::BATCH) as $chunk) {
            $users = $this->em->createQuery('SELECT u, p FROM '.User::class.' u LEFT JOIN u.preference p WHERE u.id IN (:ids)')
                ->setParameter('ids', $chunk)->getResult();
            foreach ($users as $user) {
                unset($missing[(int) $user->id]);
            }
        }
        if ([] !== $missing) {
            // what is left are groups (or ids that do not exist)
            foreach (array_chunk(array_values($missing), self::BATCH) as $chunk) {
                $this->em->createQuery('SELECT g FROM '.Principal::class.' g WHERE g.id IN (:ids)')->setParameter('ids', $chunk)->getResult();
            }
        }
    }
}
