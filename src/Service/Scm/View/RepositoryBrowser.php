<?php

declare(strict_types=1);

namespace App\Service\Scm\View;

use App\I18n\Localizer;
use App\Service\Scm\AbstractAdapter;
use App\Service\Scm\Http\RepositoryLocation;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmEntry;
use App\Service\Scm\ScmNotFound;
use App\Service\Scm\ScmTwigExtension;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the repository browser shows of a location: the content of a directory (repositories#show, #browse),
 * a file (#entry) or the changes of an entry (#changes).
 */
final class RepositoryBrowser
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly RepositoryPage $page,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /** repositories#fetch_changesets: reads the new changesets now, unless they are read each time the repository is shown */
    public function fetchChangesets(RepositoryLocation $at): void
    {
        if ($at->project->isActive() && '' === $at->path && !$this->settings->bool('autofetch_changesets')) {
            $this->repos->fetchChangesets($at->repository);
        }
    }

    /**
     * The entries of the directory. An Ajax request (a directory being expanded in the tree) gets the rows only,
     * or null when the directory cannot be listed.
     *
     * @return array<string, mixed>|null
     *
     * @throws ScmNotFound when the directory of the page does not exist
     */
    public function directory(RepositoryLocation $at, string $action, Request $request): ?array
    {
        $repository = $at->repository;
        if ('' === $at->path && $at->project->isActive() && $this->settings->bool('autofetch_changesets')) {
            $this->repos->fetchChangesets($repository);
        }
        $entries = $this->repos->entries($repository, $at->path, $at->rev);
        $changeset = $this->repos->findChangesetByName($repository, $at->rev);
        if ($request->isXmlHttpRequest()) {
            if (null === $entries) {
                return null;
            }

            return $this->page->vars($at->project, $repository, $action, [
                'path' => $at->path, 'rev' => $at->rev, 'entries' => $entries, 'depth' => (int) $request->query->get('depth', 0), 'parent_id' => (string) $request->query->get('parent_id', ''),
            ]);
        }
        if (null === $entries) {
            throw new ScmNotFound(page: true);
        }

        return $this->page->vars($at->project, $repository, $action, [
            'path' => $at->path, 'rev' => $at->rev, 'entries' => $entries, 'changeset' => $changeset, 'depth' => 0, 'parent_id' => '',
            'changesets' => $this->repos->latestChangesets($repository, $at->path, $at->rev),
            'properties' => $this->repos->properties($repository, $at->path, $at->rev),
            'repositories' => $this->repos->projectRepositories($at->project),
            'page_title' => $this->i18n->l('label_repository'),
        ]);
    }

    /** @throws ScmNotFound when there is no such entry at this revision */
    public function entry(RepositoryLocation $at): ScmEntry
    {
        return $this->repos->entry($at->repository, $at->path, $at->rev) ?? throw new ScmNotFound(page: true);
    }

    /**
     * The file, with its content when it is a text small enough to be displayed.
     *
     * @return array<string, mixed>
     *
     * @throws ScmNotFound when the content cannot be read
     */
    public function file(RepositoryLocation $at, ScmEntry $entry): array
    {
        $repository = $at->repository;
        $path = $at->path;
        $rev = $at->rev;
        // pagination from entry to entry
        $parent = implode('/', \array_slice(explode('/', $path), 0, -1));
        $siblings = array_values(array_filter($this->repos->entries($repository, $parent, $rev) ?? [], static fn ($e) => !$e->isDir()));
        $index = null;
        foreach ($siblings as $i => $e) {
            if ($e->name === $entry->name) {
                $index = $i;
                break;
            }
        }
        $maxSize = $this->settings->int('file_max_size_displayed') * 1024;
        $content = null;
        if (null === $entry->size || $entry->size <= $maxSize) {
            $c = $this->repos->cat($repository, $path, $rev);
            if (null === $c) {
                throw new ScmNotFound(page: true);
            }
            if (\strlen($c) <= $maxSize && (ScmTwigExtension::isText($path) || !AbstractAdapter::isBinary($c))) {
                // Prevent empty lines when displaying a file with Windows style eol
                $content = str_replace("\r\n", "\n", $c);
            }
        }

        return $this->page->vars($at->project, $repository, 'entry', [
            'path' => $path, 'rev' => $rev, 'entry' => $entry, 'content' => $content,
            'raw_url' => ScmTwigExtension::url('raw', $repository, ['path' => $path, 'rev' => $rev]),
            'mime' => ScmTwigExtension::mimeType($path),
            'siblings' => $siblings, 'sibling_index' => $index,
            'changeset' => $this->repos->findChangesetByName($repository, $rev),
            'page_title' => AbstractAdapter::withLeadingSlash($path),
        ]);
    }

    /**
     * The latest changesets that changed the entry.
     *
     * @return array<string, mixed>
     */
    public function changes(RepositoryLocation $at, ScmEntry $entry): array
    {
        $repository = $at->repository;

        return $this->page->vars($at->project, $repository, 'changes', [
            'path' => $at->path, 'rev' => $at->rev, 'entry' => $entry, 'changeset' => $this->repos->findChangesetByName($repository, $at->rev),
            'changesets' => $this->repos->latestChangesets($repository, $at->path, $at->rev, $this->settings->int('repository_log_display_limit') ?: 100),
            'properties' => $this->repos->properties($repository, $at->path, $at->rev),
            'page_title' => $this->i18n->l('label_change_plural'),
        ]);
    }
}
