<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Principal;
use App\Entity\User;
use App\Entity\Watcher;
use App\Twig\IssuesExtension;
use Doctrine\ORM\EntityManagerInterface;

/** Generic watchers for Issue, WikiPage, Wiki, Board, Message, News (watchable_type/id). */
final class WatcherService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ?\App\Doctrine\QueryMemo $memo = null)
    {
    }

    /** @return list<Principal> */
    public function watchers(string $type, int $id): array
    {
        $load = function () use ($type, $id): array {
            $ws = $this->em->getRepository(Watcher::class)->findBy(['watchableType' => $type, 'watchableId' => $id]);
            $out = array_values(array_filter(array_map(static fn (Watcher $w) => $w->user, $ws)));
            usort($out, static fn ($a, $b) => strcasecmp($a->getName(), $b->getName()));

            return $out;
        };

        // the sidebar, the form and the permission checks of a page ask for the same watchers
        return null === $this->memo ? $load() : $this->memo->get('watchers:'.$type.':'.$id, ['watchers', 'users'], $load);
    }

    public function isWatching(string $type, int $id, ?User $user): bool
    {
        if (null === $user || !$user->isLogged()) {
            return false;
        }

        $load = fn (): bool => null !== $this->em->getRepository(Watcher::class)->findOneBy(['watchableType' => $type, 'watchableId' => $id, 'user' => $user]);

        return null === $this->memo || null === $user->id ? $load() : $this->memo->get('watching:'.$type.':'.$id.':'.$user->id, ['watchers'], $load);
    }

    public function add(string $type, int $id, Principal $principal): void
    {
        if (null === $this->em->getRepository(Watcher::class)->findOneBy(['watchableType' => $type, 'watchableId' => $id, 'user' => $principal])) {
            $w = new Watcher();
            $w->watchableType = $type;
            $w->watchableId = $id;
            $w->user = $principal;
            $this->em->persist($w);
        }
    }

    public function remove(string $type, int $id, Principal $principal): void
    {
        foreach ($this->em->getRepository(Watcher::class)->findBy(['watchableType' => $type, 'watchableId' => $id, 'user' => $principal]) as $w) {
            $this->em->remove($w);
        }
    }

    /**
     * Makes the principal watch, or stop watching, each of the objects (Watchable#set_watcher) and saves.
     *
     * @param list<object> $watchables
     */
    public function setWatching(array $watchables, Principal $principal, bool $watching): void
    {
        foreach ($watchables as $w) {
            $type = IssuesExtension::watchableClassName($w);
            $watching ? $this->add($type, (int) $w->id, $principal) : $this->remove($type, (int) $w->id, $principal);
        }
        $this->em->flush();
    }

    public function removeAll(string $type, int $id): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Watcher w WHERE w.watchableType = :t AND w.watchableId = :id')->setParameters(['t' => $type, 'id' => $id])->execute();
    }
}
