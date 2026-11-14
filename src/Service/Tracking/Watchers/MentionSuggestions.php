<?php

declare(strict_types=1);

namespace App\Service\Tracking\Watchers;

use App\Entity\Issue;
use App\Entity\User;
use App\Service\IssueService;

/**
 * The users offered when "@" is typed in a text area (WatchersController#autocomplete_for_mention): the
 * members of the project, or the users matching what was typed, who can see the issue being edited.
 */
final class MentionSuggestions
{
    public function __construct(
        private readonly WatcherCandidates $candidates,
        private readonly IssueService $issues,
    ) {
    }

    /** @return list<array{firstname: ?string, lastname: ?string, name: string, login: ?string}> */
    public function suggest(WatcherContext $context, string $q): array
    {
        if ('' === $q && $context->project) {
            $users = \array_slice($this->candidates->ofProject($context->project), 0, 100);
        } else {
            $users = \array_slice($this->candidates->matching($q), 0, 10);
        }
        $users = array_values(array_filter($users, static fn ($u) => $u instanceof User));
        $users = array_values(array_filter($users, fn (User $u) => '' === $q || $this->candidates->matches($u, $q)));
        $issue = $context->isSingle() ? $context->first() : null;
        if ($issue instanceof Issue) {
            $users = array_values(array_filter($users, fn (User $u) => $this->issues->isVisible($issue, $u)));
        }

        return array_map(static fn (User $u) => ['firstname' => $u->firstname, 'lastname' => $u->lastname, 'name' => $u->getName(), 'login' => $u->login], $users);
    }
}
