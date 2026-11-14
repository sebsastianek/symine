<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Doctrine\BatchLoader;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\JournalDetail;
use App\Entity\Principal;
use App\Entity\Project;
use App\Tests\Integration\SymineTestCase;
use Doctrine\ORM\PersistentCollection;

/**
 * BatchLoader loads in one query what the ORM would load one row at a time: the result must be exactly what the
 * ORM would have loaded (same entities, same order, nothing marked as changed).
 *
 * Seeded data: issue #14 "Wishlist" has the subtasks #15 and #16; project "ecommerce" (1) has the subproject
 * "checkout" (3).
 */
final class BatchLoaderTest extends SymineTestCase
{
    /** @return list<int> */
    private static function ids(iterable $entities): array
    {
        $ids = [];
        foreach ($entities as $e) {
            $ids[] = (int) $e->id;
        }

        return $ids;
    }

    /** @return array<int, list<int>> issue id => ids of its children, as the ORM loads them */
    private function lazyChildren(): array
    {
        $expected = [];
        foreach ($this->em->getRepository(Issue::class)->findBy([], ['id' => 'ASC']) as $issue) {
            $expected[(int) $issue->id] = self::ids($issue->children);
        }
        self::assertSame([15, 16], $expected[14]);
        $this->em->clear();

        return $expected;
    }

    public function testCollectionsOfAListAreLoadedTogetherAndEqualWhatTheOrmLoads(): void
    {
        $expected = $this->lazyChildren();
        $issues = $this->em->getRepository(Issue::class)->findBy([], ['id' => 'ASC']);
        foreach ($issues as $issue) {
            self::assertFalse($issue->children->isInitialized());
        }

        $this->service(BatchLoader::class)->collections($issues, 'children');

        foreach ($issues as $issue) {
            self::assertInstanceOf(PersistentCollection::class, $issue->children);
            self::assertTrue($issue->children->isInitialized(), '#'.$issue->id);
            self::assertFalse($issue->children->isDirty(), '#'.$issue->id);
            self::assertSame($expected[(int) $issue->id], self::ids($issue->children), '#'.$issue->id);
            self::assertSame(0 === \count($expected[(int) $issue->id]), $issue->isLeaf());
            foreach ($issue->children as $child) {
                self::assertSame($issue, $child->parent);
            }
        }
        // nothing looks changed to the unit of work
        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();
        self::assertSame([], $uow->getScheduledCollectionUpdates());
        self::assertSame([], $uow->getScheduledEntityUpdates());
    }

    public function testAskingForOneEntityLoadsTheCollectionOfTheOthersInMemory(): void
    {
        $expected = $this->lazyChildren();
        $issues = $this->em->getRepository(Issue::class)->findBy([], ['id' => 'ASC']);

        $this->service(BatchLoader::class)->collection($issues[0], 'children');

        foreach ($issues as $issue) {
            self::assertTrue($issue->children->isInitialized(), '#'.$issue->id);
            self::assertSame($expected[(int) $issue->id], self::ids($issue->children));
        }
    }

    public function testOrderOfTheMappingIsKept(): void
    {
        $issue = $this->em->find(Issue::class, 14);
        $journals = [];
        foreach ([3, 1, 2] as $n) {
            $journal = new Journal();
            $journal->issue = $issue;
            $journal->user = $this->user('jsmith');
            $journal->notes = 'note '.$n;
            $journal->createdOn = new \DateTimeImmutable('2026-01-0'.$n.' 10:00:00');
            $this->em->persist($journal);
            foreach (['status_id', 'priority_id'] as $key) {
                $detail = new JournalDetail();
                $detail->journal = $journal;
                $detail->property = 'attr';
                $detail->propKey = $key;
                $detail->oldValue = '1';
                $detail->value = '2';
                $this->em->persist($detail);
            }
            $journals[] = $journal;
        }
        $this->em->flush();
        $this->em->clear();

        $lazy = [];
        foreach ($this->em->getRepository(Journal::class)->findBy(['issue' => 14]) as $journal) {
            $lazy[(int) $journal->id] = self::ids($journal->details);
        }
        $this->em->clear();

        $loaded = $this->em->getRepository(Journal::class)->findBy(['issue' => 14]);
        $this->service(BatchLoader::class)->collections($loaded, 'details');
        foreach ($loaded as $journal) {
            self::assertTrue($journal->details->isInitialized());
            self::assertSame($lazy[(int) $journal->id], self::ids($journal->details));
        }
    }

    public function testATreeIsLoadedLevelByLevel(): void
    {
        $ecommerce = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $expected = self::ids($ecommerce->getSelfAndDescendants());
        self::assertContains(3, $expected);
        $this->em->clear();

        $ecommerce = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $this->service(BatchLoader::class)->descendants($ecommerce, 'children');
        foreach ($ecommerce->getSelfAndDescendants() as $project) {
            self::assertTrue($project->children->isInitialized(), $project->identifier);
        }
        self::assertSame($expected, self::ids($ecommerce->getSelfAndDescendants()));
    }

    public function testACollectionChangedInMemoryIsLeftToTheOrm(): void
    {
        $parent = $this->em->find(Issue::class, 14);
        $other = $this->em->find(Issue::class, 13);
        $new = new Issue();
        $parent->children->add($new);
        self::assertTrue($parent->children->isDirty());

        $third = $this->em->find(Issue::class, 19);
        $this->service(BatchLoader::class)->collections([$parent, $other, $third], 'children');

        self::assertFalse($parent->children->isInitialized(), 'not touched');
        self::assertTrue($other->children->isInitialized());
        self::assertTrue($third->children->isInitialized());
        self::assertCount(3, $parent->children, 'the ORM merges what was added with what is stored');
    }

    public function testASingleCollectionIsLeftToLoadItself(): void
    {
        $issue = $this->em->find(Issue::class, 14);
        $this->service(BatchLoader::class)->collections([$issue], 'children');
        self::assertFalse($issue->children->isInitialized(), 'one query either way: only when it is read');
        self::assertSame([15, 16], self::ids($issue->children));
    }

    public function testReferencesAreLoadedTogether(): void
    {
        $uow = $this->em->getUnitOfWork();
        $references = array_map(fn (int $id) => $this->em->getReference(Issue::class, $id), [13, 14, 15]);
        foreach ($references as $reference) {
            self::assertTrue($uow->isUninitializedObject($reference));
        }

        $this->service(BatchLoader::class)->references([...$references, null]);

        foreach ($references as $reference) {
            self::assertFalse($uow->isUninitializedObject($reference));
        }
        self::assertSame('Wishlist', $references[1]->subject);
        self::assertSame($this->em->find(Issue::class, 14), $references[1]);
    }

    public function testPrincipalsAreLoadedTogetherWhateverTheirClass(): void
    {
        $uow = $this->em->getUnitOfWork();
        $ids = array_map('intval', $this->em->getConnection()->fetchFirstColumn("SELECT id FROM users WHERE type IN ('User', 'Group') ORDER BY id"));
        self::assertGreaterThan(3, \count($ids));
        foreach ($ids as $id) {
            self::assertFalse($uow->tryGetById($id, Principal::class));
        }

        $this->service(BatchLoader::class)->principals([...$ids, null, 0, 999999]);

        $classes = [];
        foreach ($ids as $id) {
            $principal = $uow->tryGetById($id, Principal::class);
            self::assertInstanceOf(Principal::class, $principal, 'principal '.$id);
            $classes[$principal::class] = true;
        }
        self::assertArrayHasKey(\App\Entity\User::class, $classes);
        self::assertArrayHasKey(\App\Entity\Group::class, $classes);
    }
}
