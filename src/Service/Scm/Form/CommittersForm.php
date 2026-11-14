<?php

declare(strict_types=1);

namespace App\Service\Scm\Form;

use App\Entity\Repository;
use App\Entity\User;
use App\I18n\Localizer;
use App\Service\ProjectService;
use App\Service\Scm\RepositoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form mapping the committers of a repository to users (repositories/committers).
 *
 * Fields: committers[<n>][] = committer name, user id.
 */
final class CommittersForm
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly ProjectService $projects,
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
    ) {
    }

    /** Saves the submitted mapping; false when the request carries none */
    public function submit(Repository $repository, Request $request): bool
    {
        if ([] === $request->request->all('committers')) {
            return false;
        }
        $map = [];
        foreach ($request->request->all('committers') as $pair) {
            $pair = array_values((array) $pair);
            if (isset($pair[0])) {
                $map[(string) $pair[0]] = (string) ($pair[\count($pair) - 1] ?? '');
            }
        }
        $this->repos->setCommitterIds($repository, $map);

        return true;
    }

    /**
     * The committers with the users they can be mapped to: the members of the project and the users already mapped.
     *
     * @return array<string, mixed>
     */
    public function view(Repository $repository): array
    {
        $project = $repository->project;
        $committers = $this->repos->committers($repository);
        $users = [];
        foreach ($this->projects->members($project) as $p) {
            if ($p instanceof User) {
                $users[$p->id] = $p;
            }
        }
        foreach ($committers as [, $uid]) {
            if ($uid && !isset($users[$uid]) && ($u = $this->em->find(User::class, $uid)) instanceof User) {
                $users[$uid] = $u;
            }
        }
        $users = array_values($users);
        usort($users, static fn (User $a, User $b) => strcasecmp($a->getName(), $b->getName()));

        return [
            'project' => $project, 'repository' => $repository, 'committers' => $committers, 'users' => $users,
            'main_menu_selected' => 'settings', 'body_css' => 'controller-repositories action-committers', 'page_title' => $this->i18n->l('label_repository'),
        ];
    }
}
