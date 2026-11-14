<?php

declare(strict_types=1);

namespace App\Service\Scm\View;

use App\I18n\Localizer;
use App\Service\Scm\AbstractAdapter;
use App\Service\Scm\Http\RepositoryLocation;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmEntry;
use App\Service\Scm\ScmRevision;
use App\Service\Scm\ScmTwigExtension;
use App\Service\Settings;
use App\Service\SyntaxHighlighting;

/**
 * What the annotated view of a file shows (repositories#annotate): each line with the revision and the author
 * that last changed it, the lines of a same revision sharing a colour.
 */
final class RepositoryAnnotation
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly RepositoryPage $page,
        private readonly SyntaxHighlighting $highlighter,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(RepositoryLocation $at, ScmEntry $entry): array
    {
        $repository = $at->repository;
        $path = $at->path;
        $rev = $at->rev;
        $annotate = $this->repos->annotate($repository, $path, $rev);
        $error = null;
        if (null === $annotate || $annotate->isEmpty()) {
            $annotate = null;
            $error = $this->i18n->l('error_scm_annotate');
        } elseif (array_sum(array_map('strlen', $annotate->lines)) > $this->settings->int('file_max_size_displayed') * 1024) {
            $annotate = null;
            $error = $this->i18n->l('error_scm_annotate_big_text_file');
        }
        $rows = [];
        if (null !== $annotate) {
            $logEncoding = $this->repos->model($repository)->repoLogEncoding($repository);
            $colors = [];
            /** @var ScmRevision|null $prev */
            $prev = null;
            foreach ($this->highlighter->highlightLines($path, $annotate->content()) as $i => $line) {
                $revision = $annotate->revisions[$i] ?? null;
                $key = null !== $revision ? ($revision->identifier ?? $revision->revision) : null;
                if (null !== $revision && !\array_key_exists((string) $key, $colors)) {
                    $colors[(string) $key] = \count($colors) % 12;
                }
                $changed = null !== $revision && !$revision->equals($prev);
                $previous = $annotate->previousAnnotations[$i] ?? null;
                $prevParts = null !== $previous ? preg_split('/\s+/', trim($previous)) : [];
                $rows[] = [
                    'num' => $i + 1, 'line' => $line, 'revision' => $revision,
                    'color' => null === $revision ? 0 : $colors[(string) $key],
                    'bloc_change' => null !== $prev && null !== $revision && !$revision->equals($prev),
                    'show_rev' => $changed,
                    'author' => null !== $revision ? explode('<', (string) AbstractAdapter::toUtf8((string) $revision->author, $logEncoding))[0] : '',
                    'previous_url' => $changed && [] !== $prevParts ? ScmTwigExtension::url('annotate', $repository, ['path' => $prevParts[1] ?? $path, 'rev' => $prevParts[0]]) : null,
                ];
                $prev = $revision;
            }
        }

        return $this->page->vars($at->project, $repository, 'annotate', [
            'path' => $path, 'rev' => $rev, 'entry' => $entry, 'rows' => $rows, 'error_message' => $error,
            'has_previous' => null !== $annotate && $annotate->hasPrevious(),
            'changeset' => $this->repos->findChangesetByName($repository, $rev),
            'page_title' => $this->i18n->l('button_annotate'),
        ]);
    }
}
