<?php

declare(strict_types=1);

namespace App\Service\Scm\View;

use App\Security\CurrentUser;
use App\Service\Scm\AbstractAdapter;
use App\Service\Scm\Http\RepositoryLocation;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmNotFound;
use App\Service\Scm\UnifiedDiff;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the diff page shows (repositories#diff): the differences of a path between two revisions, inline or
 * side by side as the user last chose.
 */
final class RepositoryDiff
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly RepositoryPage $page,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ScmNotFound when there is nothing to compare
     */
    public function view(RepositoryLocation $at, Request $request): array
    {
        $repository = $at->repository;
        $diffType = $this->diffType($request);
        $diff = null !== $at->rev ? $this->repos->diff($repository, $at->path, $at->rev, $at->revTo) : null;
        if (null === $diff) {
            throw new ScmNotFound(page: true);
        }
        $changeset = $this->repos->findChangesetByName($repository, $at->rev);
        $changesetTo = null !== $at->revTo ? $this->repos->findChangesetByName($repository, $at->revTo) : null;
        $vars = [
            'path' => $at->path, 'rev' => $at->rev, 'rev_to' => $at->revTo, 'changeset' => $changeset, 'changeset_to' => $changesetTo,
            'diff' => new UnifiedDiff($diff, $diffType, $this->settings->int('diff_max_lines_displayed') ?: null),
            'diff_type' => $diffType,
            'diff_format_revisions' => $this->repos->model($repository)->diffFormatRevisions($changeset, $changesetTo),
            'page_title' => AbstractAdapter::withLeadingSlash($at->path).' - Diff',
        ];
        if (null !== $changeset) {
            $vars += $this->page->changesetVars($changeset);
        }

        return $this->page->vars($at->project, $repository, 'diff', $vars);
    }

    /** The type asked (params[:type]), else the one the user prefers; the choice is remembered in the preferences */
    private function diffType(Request $request): string
    {
        $user = $this->currentUser->get();
        $diffType = (string) ($request->query->get('type') ?: ($user->isLogged() ? $user->getPref()->get('diff_type') : null) ?: 'inline');
        if (!\in_array($diffType, ['inline', 'sbs'], true)) {
            $diffType = 'inline';
        }
        if ($user->isLogged() && $diffType !== $user->getPref()->get('diff_type')) {
            $pref = $user->getPref();
            $pref->set('diff_type', $diffType);
            $this->em->persist($pref);
            $this->em->flush();
        }

        return $diffType;
    }
}
