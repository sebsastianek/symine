<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** The sets of projects a search can be limited to: all, mine, my bookmarks, a project with or without its subprojects. */
final class SearchProjects
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * The projects to search for params[:scope], null for all of them.
     *
     * @return list<Project>|null
     */
    public function toSearch(string $scopeParam, ?Project $project): ?array
    {
        return match ($scopeParam) {
            'all' => null,
            'my_projects' => $this->mine(),
            'bookmarks' => $this->bookmarked(),
            'subprojects' => null !== $project ? $project->getSelfAndDescendants() : null,
            default => null !== $project ? [$project] : null,
        };
    }

    /** SearchHelper#project_select_tag options @return list<array{0: string, 1: string}> */
    public function scopeOptions(?Project $project): array
    {
        $options = [[$this->i18n->l('label_project_all'), 'all']];
        if ([] !== $this->mine()) {
            $options[] = [$this->i18n->l('label_my_projects'), 'my_projects'];
        }
        if ([] !== $this->bookmarkedIds()) {
            $options[] = [$this->i18n->l('label_my_bookmarks'), 'bookmarks'];
        }
        if (null !== $project) {
            $activeDescendants = array_filter($project->getSelfAndDescendants(), static fn (Project $p) => $p !== $project && $p->isActive());
            if ([] !== $activeDescendants) {
                $options[] = [$this->i18n->l('label_and_its_subprojects', ['value' => $project->name]), 'subprojects'];
            }
            $options[] = [$project->name, ''];
        }

        return \count($options) > 1 ? $options : [];
    }

    /** @return list<Project> User#projects (memberships) */
    private function mine(): array
    {
        $user = $this->currentUser->get();
        if (!$user->isLogged()) {
            return [];
        }

        return array_values(array_map(static fn ($m) => $m->project, $this->auth->membershipsOf($user)));
    }

    /** @return list<int> */
    private function bookmarkedIds(): array
    {
        $user = $this->currentUser->get();
        if (!$user->isLogged()) {
            return [];
        }
        $v = $user->getPref()->get('bookmarked_project_ids');
        $ids = \is_array($v) ? $v : explode(',', (string) $v);

        return array_values(array_filter(array_map('intval', $ids)));
    }

    /** @return list<Project> */
    private function bookmarked(): array
    {
        $ids = $this->bookmarkedIds();

        return [] === $ids ? [] : $this->em->getRepository(Project::class)->findBy(['id' => $ids]);
    }
}
