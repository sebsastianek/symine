<?php

declare(strict_types=1);

namespace App\Service\Tracking\Watchers;

use App\Api\ApiRenderer;
use App\Entity\Principal;
use App\Service\WatcherService;
use App\Twig\IssuesExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The "add watchers" dialog and the requests that add or remove the watchers of objects.
 *
 * Fields: watcher[user_ids][] (or watcher[user_id], user_id), q / user_search and offset for the search of the
 * dialog; the REST API sends user_id.
 */
final class WatchersForm
{
    private const int USERS_PER_PAGE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WatcherCandidates $candidates,
        private readonly WatcherService $watchers,
        private readonly IssuesExtension $helpers,
    ) {
    }

    /** Adds the submitted users and groups that may watch the objects */
    public function submit(WatcherContext $context, Request $request, bool $api): void
    {
        $userIds = [];
        if ($api) {
            $payload = ApiRenderer::payload($request);
            $userIds[] = $payload['user_id'] ?? null;
        } else {
            $w = $request->request->all('watcher');
            if ([] !== $w) {
                $userIds = (array) ($w['user_ids'] ?? $w['user_id'] ?? []);
            } else {
                $userIds[] = $request->request->get('user_id');
            }
        }
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        foreach ($this->candidates->assignable($userIds) as $u) {
            foreach ($context->watchables as $w) {
                if ($this->candidates->isValid($w, $u)) {
                    $this->watchers->add(IssuesExtension::watchableClassName($w), (int) $w->id, $u);
                }
            }
        }
        $this->em->flush();
    }

    /**
     * The users and groups chosen in the dialog opened from the new issue form, where there is nothing to watch yet.
     *
     * @return list<Principal>
     */
    public function chosen(Request $request): array
    {
        $w = $request->request->all('watcher');
        $ids = array_map('intval', (array) ($w['user_ids'] ?? [$w['user_id'] ?? null]));

        return $this->candidates->assignable(array_values(array_filter($ids)));
    }

    /**
     * Removes the user (or group) named by user_id from the watchers of the objects.
     *
     * @throws NotFoundHttpException when there is no such principal
     */
    public function remove(WatcherContext $context, Request $request): void
    {
        $userId = $request->attributes->get('user_id') ?? $request->query->get('user_id') ?? $request->request->get('user_id');
        $user = $this->em->find(Principal::class, (int) $userId) ?? throw new NotFoundHttpException();
        foreach ($context->watchables as $w) {
            $this->watchers->remove(IssuesExtension::watchableClassName($w), (int) $w->id, $user);
        }
        $this->em->flush();
    }

    /** @return array<string, mixed> what the dialog and its list of users show */
    public function view(WatcherContext $context, Request $request): array
    {
        $offset = max(0, (int) $request->query->get('offset', 0));
        $q = trim((string) ($request->query->get('q') ?? $request->query->get('user_search') ?? ''));
        $candidates = $this->candidates->search($context, $q);
        $page = \array_slice($candidates, $offset, self::USERS_PER_PAGE);
        $more = \count($candidates) > $offset + self::USERS_PER_PAGE;

        return [
            'watchables' => $context->watchables,
            'users' => $page,
            'project' => $context->project,
            'more_users' => $more,
            'next_offset' => $offset + \count($page),
            'object_type' => !$context->isEmpty() ? $this->helpers->watchableType($context->watchables[0]) : null,
            'object_ids' => array_map(static fn ($w) => $w->id, $context->watchables),
            'q' => $q,
        ];
    }
}
