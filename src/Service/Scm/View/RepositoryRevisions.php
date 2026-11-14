<?php

declare(strict_types=1);

namespace App\Service\Scm\View;

use App\Entity\Changeset;
use App\I18n\Localizer;
use App\Service\Scm\Http\RepositoryLocation;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmNotFound;
use App\Service\Scm\ScmTwigExtension;
use App\Service\Settings;

/**
 * What the pages about the changesets of a repository show: the list of revisions (repositories#revisions),
 * its Atom feed and one revision (repositories#revision).
 */
final class RepositoryRevisions
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly RepositoryPage $page,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * The page of changesets of the list.
     *
     * @param array{offset: int, limit: int} $pager
     *
     * @return array<string, mixed>
     */
    public function view(RepositoryLocation $at, array $pager): array
    {
        $changesets = $this->repos->changesets($at->repository, $pager['limit'], $pager['offset']);

        return $this->page->vars($at->project, $at->repository, 'revisions', [
            'changesets' => $changesets, 'pager' => $pager, 'page_title' => $this->i18n->l('label_revision_plural'),
            // the list alone (repositories/_revisions), as the Ajax pagination asks for it
            'revisions' => $changesets,
        ]);
    }

    /**
     * revisions.atom: the page of changesets of the HTML list (per_page_option, params[:page]),
     * cut by render_feed to Setting.feeds_limit
     *
     * @param array{offset: int, limit: int} $pager
     *
     * @return list<array<string, mixed>> items of App\Service\AtomFeed::render()
     */
    public function feedItems(RepositoryLocation $at, array $pager): array
    {
        $changesets = \array_slice($this->repos->changesets($at->repository, $pager['limit'], $pager['offset']), 0, max(0, $this->settings->int('feeds_limit')));

        return array_map(fn (Changeset $c) => [
            'title' => $this->repos->title($c),
            'url' => ScmTwigExtension::url('revision', $at->repository, ['rev' => RepositoryService::identifier($c)]),
            'updated' => $c->committedOn,
            'author' => $c->user,
            'content' => RepositoryService::splitComments($c->comments)[1],
            'project' => $at->project,
            'object' => $c,
        ], $changesets);
    }

    /**
     * The changeset of the revision asked, with its neighbours and related issues.
     *
     * @return array<string, mixed>
     *
     * @throws ScmNotFound when the repository has no such changeset
     */
    public function revision(RepositoryLocation $at): array
    {
        $changeset = '' !== (string) $at->rev ? $this->repos->findChangesetByName($at->repository, $at->rev) : null;
        if (null === $changeset) {
            throw new ScmNotFound(page: true);
        }

        return $this->page->vars($at->project, $at->repository, 'revision', [
            'rev' => $at->rev, 'changeset' => $changeset] + $this->page->changesetVars($changeset) + [
            'page_title' => $this->i18n->l('label_revision').' '.$this->repos->model($at->repository)->formatChangesetIdentifier($changeset).' - '.mb_strimwidth((string) $changeset->comments, 0, 80, '...'),
        ]);
    }
}
