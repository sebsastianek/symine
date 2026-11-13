<?php

declare(strict_types=1);

namespace App\Service\Scm;

use App\Entity\Changeset;
use App\Entity\Repository;
use App\I18n\Localizer;
use App\Service\Settings;
use App\Service\SyntaxHighlighting;
use App\Service\TextFormatter;
use App\Twig\AppExtension;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Port of RepositoriesHelper and the SCM bits of ApplicationHelper / IconsHelper. */
final class ScmTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RepositoryService $repositories,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly TextFormatter $formatter,
        private readonly AppExtension $app,
        private readonly SyntaxHighlighting $highlighter,
        private readonly ScmConfiguration $scmConfig,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('repo_url', self::url(...)),
            new TwigFunction('repository_name', $this->repositories->name(...)),
            new TwigFunction('repository_identifier_param', RepositoryService::identifierParam(...)),
            new TwigFunction('format_revision', self::formatRevision(...)),
            new TwigFunction('link_to_revision', $this->linkToRevision(...), $safe),
            new TwigFunction('changeset_identifier', RepositoryService::identifier(...)),
            new TwigFunction('changeset_short_comments', static fn (Changeset $c) => RepositoryService::splitComments($c->comments)[0]),
            new TwigFunction('format_changeset_comments', $this->formatChangesetComments(...), $safe),
            new TwigFunction('truncate_at_line_break', self::truncateAtLineBreak(...)),
            new TwigFunction('scm_change_icon', $this->scmChangeIcon(...), $safe),
            new TwigFunction('scm_file_icon', $this->fileIcon(...), $safe),
            new TwigFunction('scm_lines', self::lines(...)),
            new TwigFunction('render_changeset_changes', $this->renderChangesetChanges(...), $safe),
            new TwigFunction('with_leading_slash', static fn (?string $p) => str_starts_with((string) $p, '/') ? (string) $p : '/'.$p),
            new TwigFunction('scm_mime_type', self::mimeType(...)),
            new TwigFunction('scm_tr_id', static fn (string $p) => substr(hash('sha256', $p), 0, 32)),
            new TwigFunction('syntax_highlight_lines', $this->highlighter->highlightLines(...)),
            new TwigFunction('index_commits', $this->indexCommits(...)),
            new TwigFunction('repository_scm_name', $this->repositories->scmName(...)),
            new TwigFunction('enabled_scms', $this->repositories->enabledScms(...)),
            new TwigFunction('scm_settings_rows', $this->scmSettingsRows(...)),
            new TwigFunction('scm_environment', $this->scmEnvironment(...)),
        ];
    }

    /**
     * Redmine repositories routes:
     *   show/browse/entry/raw/annotate with a revision matching [a-z0-9.\-_]+ => .../revisions/{rev}/{action}/{path}
     *   diff with such a revision => .../revisions/{rev}/diff/{path}; revision => .../revisions/{rev}
     *   otherwise .../{action}/{path}?rev=...
     *
     * @param array<string, mixed> $params path, rev, plus extra query parameters
     */
    public static function url(string $action, Repository $repository, array $params = []): string
    {
        $base = '/projects/'.rawurlencode((string) $repository->project->identifier).'/repository/'.rawurlencode(RepositoryService::identifierParam($repository));
        $path = trim((string) ($params['path'] ?? ''), '/');
        $rev = isset($params['rev']) && '' !== (string) $params['rev'] ? (string) $params['rev'] : null;
        unset($params['path'], $params['rev']);
        $encPath = '' === $path ? '' : '/'.implode('/', array_map('rawurlencode', explode('/', $path)));
        $revOk = null !== $rev && preg_match('/^[a-z0-9.\-_]+$/', $rev);
        $query = $params;
        switch ($action) {
            case 'revision':
                if (null !== $rev && $revOk) {
                    $url = $base.'/revisions/'.rawurlencode($rev);
                } else {
                    $url = $base.'/revision';
                    if (null !== $rev) {
                        $query = ['rev' => $rev] + $query;
                    }
                }
                break;
            case 'revisions':
            case 'statistics':
            case 'graph':
            case 'fetch_changesets':
                $url = $base.'/'.$action;
                break;
            case 'show':
            case 'browse':
            case 'entry':
            case 'raw':
            case 'annotate':
            case 'diff':
            case 'changes':
                if ($revOk && 'changes' !== $action) {
                    $url = $base.'/revisions/'.rawurlencode($rev).'/'.$action.$encPath;
                } else {
                    if ('show' === $action) {
                        $url = '' === $encPath ? $base : $base.'/show'.$encPath;
                    } else {
                        $url = $base.'/'.$action.$encPath;
                    }
                    if (null !== $rev) {
                        $query = ['rev' => $rev] + $query;
                    }
                }
                break;
            default:
                $url = $base;
        }
        $query = array_filter($query, static fn ($v) => null !== $v && '' !== $v);

        return $url.([] !== $query ? '?'.http_build_query($query) : '');
    }

    public static function formatRevision(mixed $revision): string
    {
        if ($revision instanceof Changeset) {
            return RepositoryService::formatIdentifier($revision);
        }
        if ($revision instanceof ScmRevision) {
            return $revision->formatIdentifier();
        }

        return (string) $revision;
    }

    /** @param array{text?: string, accesskey?: string} $options */
    public function linkToRevision(mixed $revision, Repository $repository, array $options = []): string
    {
        $text = $options['text'] ?? self::formatRevision($revision);
        $rev = $revision instanceof Changeset ? RepositoryService::identifier($revision) : ($revision instanceof ScmRevision ? (string) $revision->identifier : (string) $revision);

        return '<a title="'.htmlspecialchars($this->i18n->l('label_revision_id', ['value' => self::formatRevision($revision)])).'"'
            .(isset($options['accesskey']) ? ' accesskey="'.htmlspecialchars($options['accesskey']).'"' : '')
            .' href="'.htmlspecialchars(self::url('revision', $repository, ['rev' => $rev])).'">'.htmlspecialchars($text).'</a>';
    }

    public function formatChangesetComments(Changeset $c, bool $short = false, ?string $text = null): string
    {
        $text ??= $short ? RepositoryService::splitComments($c->comments)[0] : (string) $c->comments;
        if ($this->settings->bool('commit_logs_formatting')) {
            return $this->formatter->format($text, $c->repository->project);
        }

        return '<p>'.nl2br(htmlspecialchars($text), false).'</p>';
    }

    public static function truncateAtLineBreak(?string $text, int $length = 255): string
    {
        if (null === $text) {
            return '';
        }

        return preg_replace('/^(.{'.$length.'}[^\n]*)\n.+$/s', '$1...', $text) ?? $text;
    }

    public function scmChangeIcon(string $action, string $name): string
    {
        $icon = match ($action) {
            'A' => 'add',
            'D' => 'circle-minus',
            default => 'circle-dot-filled',
        };

        return $this->app->spriteIcon($icon, $name, 14);
    }

    public function fileIcon(ScmEntry $entry, string $name): string
    {
        if ($entry->isDir()) {
            return $this->app->spriteIcon('folder', $name);
        }
        $mime = self::mimeType($name);
        $icon = match (true) {
            null === $mime => 'file',
            str_starts_with($mime, 'image/') => 'photo',
            'application/pdf' === $mime => 'application-pdf',
            'application/zip' === $mime => 'application-zip',
            'application/gzip' === $mime => 'application-gzip',
            'application/javascript' === $mime => 'application-javascript',
            'text/css' === $mime => 'text-css',
            'text/html' === $mime => 'text-html',
            'text/x-php' === $mime => 'text-x-php',
            'text/x-ruby' === $mime => 'text-x-ruby',
            'text/x-java' === $mime => 'text-x-java',
            'text/x-c' === $mime => 'text-x-c',
            'application/xml' === $mime, 'text/xml' === $mime => 'text-xml',
            str_starts_with($mime, 'text/'), 'application/x-sh' === $mime, 'application/sql' === $mime => 'text-plain',
            str_starts_with($mime, 'audio/') => 'file-music',
            str_starts_with($mime, 'video/') => 'movie',
            default => 'file',
        };

        return $this->app->spriteIcon($icon, $name);
    }

    /** Subset of Redmine::MimeType.of */
    public static function mimeType(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        return match ($ext) {
            'txt', 'log', 'ini', 'conf', 'cfg', 'properties', 'csv', 'tsv', 'diff', 'patch' => 'text/plain',
            'md', 'markdown' => 'text/markdown',
            'textile' => 'text/x-textile',
            'html', 'htm', 'xhtml' => 'text/html',
            'css', 'scss', 'sass' => 'text/css',
            'js', 'mjs' => 'application/javascript',
            'json' => 'application/json',
            'xml', 'xsd', 'xsl' => 'application/xml',
            'yml', 'yaml' => 'text/x-yaml',
            'php' => 'text/x-php',
            'rb', 'rbw', 'rake', 'erb' => 'text/x-ruby',
            'py' => 'text/x-python',
            'java' => 'text/x-java',
            'c', 'h', 'cpp', 'cc', 'hpp' => 'text/x-c',
            'cs' => 'text/x-csharp',
            'go' => 'text/x-go',
            'rs' => 'text/x-rust',
            'ts' => 'text/x-typescript',
            'sh', 'bash' => 'application/x-sh',
            'sql' => 'application/sql',
            'twig', 'tpl' => 'text/plain',
            'png' => 'image/png', 'gif' => 'image/gif', 'jpg', 'jpeg', 'jpe' => 'image/jpeg', 'bmp' => 'image/bmp', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip', 'gz', 'tgz' => 'application/gzip',
            'mp3' => 'audio/mpeg', 'ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            default => '' === $ext && \in_array(strtolower(basename($name)), ['makefile', 'readme', 'license', 'gemfile', 'rakefile', 'dockerfile'], true) ? 'text/plain' : null,
        };
    }

    public static function isText(string $name): bool
    {
        $m = self::mimeType($name);

        return null !== $m && (str_starts_with($m, 'text/') || \in_array($m, ['application/javascript', 'application/json', 'application/xml', 'application/x-sh', 'application/sql'], true));
    }

    /** syntax_highlight_lines without a highlighter: escaped lines */
    public static function lines(?string $content): array
    {
        $content = (string) $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
        $lines = preg_split('/(?<=\n)/', $content, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(static fn ($l) => "\n" === $l || "\r\n" === $l ? $l : htmlspecialchars(rtrim($l, "\r\n"), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), $lines);
    }

    /**
     * RepositoriesHelper#index_commits: graph data of the revisions (drawn by revision_graph.js).
     *
     * @param list<Changeset> $commits
     *
     * @return array{0: string, 1: int, 2: array<string, array<string, mixed>>}|null json, graph space, commits by scmid
     */
    public function indexCommits(array $commits, Repository $repository): ?array
    {
        if ([] === $commits) {
            return null;
        }
        $heads = $this->repositories->adapter($repository)->branches() ?? [];
        $refsMap = [];
        foreach ($heads as $head) {
            $refsMap[$head->scmid][] = $head->name;
        }
        $byScmid = [];
        foreach (array_reverse($commits) as $index => $commit) {
            $scmid = (string) $commit->scmid;
            $byScmid[$scmid] = [
                'parent_scmids' => array_values(array_map(static fn (Changeset $p) => (string) $p->scmid, $commit->parents->toArray())),
                'rdmid' => $index,
                'refs' => isset($refsMap[$scmid]) ? implode(' ', $refsMap[$scmid]) : null,
                'scmid' => $scmid,
                'href' => self::url('revision', $repository, ['rev' => $scmid]),
            ];
        }
        usort($heads, static fn (ScmBranch $a, ScmBranch $b) => strcmp($a->name, $b->name));
        // Process commits starting from the latest
        $space = $this->indexHead(0, (string) $commits[0]->scmid, $byScmid);
        // Process commits from heads
        foreach ($heads as $head) {
            if (isset($byScmid[$head->scmid]) && !isset($byScmid[$head->scmid]['space'])) {
                $space = $this->indexHead($space + 1, $head->scmid, $byScmid);
            }
        }
        // Process orphan commits
        foreach ($commits as $commit) {
            if (!isset($byScmid[(string) $commit->scmid]['space'])) {
                $space = $this->indexHead($space + 1, (string) $commit->scmid, $byScmid);
            }
        }
        // vertical_children flag for commits that have children in the same column (S-style connections)
        foreach ($byScmid as $commit) {
            foreach ($commit['parent_scmids'] as $p) {
                if (isset($byScmid[$p]) && ($byScmid[$p]['space'] ?? null) === ($commit['space'] ?? null)) {
                    $byScmid[$p]['vertical_children'] = true;
                }
            }
        }

        return [json_encode((object) $byScmid, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), $space, $byScmid];
    }

    /** RepositoriesHelper#index_head */
    private function indexHead(int $space, string $scmid, array &$byScmid): int
    {
        $stack = [[$space, $scmid]];
        $maxSpace = $space;
        while ([] !== $stack) {
            [$space, $id] = array_pop($stack);
            if (!isset($byScmid[$id]['space'])) {
                $byScmid[$id]['space'] = $space;
            }
            --$space;
            foreach ($byScmid[$id]['parent_scmids'] as $parent) {
                if (isset($byScmid[$parent]) && !isset($byScmid[$parent]['space'])) {
                    array_unshift($stack, [++$space, $parent]);
                }
            }
            if ($maxSpace < $space) {
                $maxSpace = $space;
            }
        }

        return $maxSpace;
    }

    /**
     * Rows of the "enabled SCM" table of the administration settings (settings/_repositories.html.erb).
     *
     * @return list<array{name: string, enabled: bool, available: bool, client_available: bool, command: string, version: string, path_regexp: ?string}>
     */
    public function scmSettingsRows(array $enabledScm = []): array
    {
        $rows = [];
        foreach ($this->scmConfig->all() as $scm) {
            $client = $this->scmConfig->clientAvailable($scm);
            $rows[] = [
                'name' => $scm,
                'enabled' => \in_array($scm, $enabledScm, true),
                'available' => $this->scmConfig->available($scm),
                'client_available' => $client,
                'command' => $this->scmConfig->command($scm),
                'version' => $client ? $this->scmConfig->versionString($scm) : '',
                'path_regexp' => $this->scmConfig->pathRegexp($scm),
            ];
        }

        return $rows;
    }

    /** "SCM:" section of Redmine::Info.environment */
    public function scmEnvironment(): string
    {
        $s = "SCM:\n";
        foreach ($this->scmConfig->all() as $scm) {
            if (!$this->scmConfig->clientAvailable($scm)) {
                continue;
            }
            $re = $this->scmConfig->pathRegexp($scm);
            $paths = null !== $re ? 'Allowed Paths: '.$re : 'DISABLED: scm_'.strtolower($scm).'_path_regexp missing in configuration.yml';
            $s .= \sprintf("  %-30s %-20s %s\n", $scm, $this->scmConfig->versionString($scm), $paths);
        }

        return $s;
    }

    /** RepositoriesHelper#render_changeset_changes */
    public function renderChangesetChanges(Changeset $changeset): string
    {
        $all = $this->repositories->changes($changeset);
        $changes = [];
        foreach ($all as $change) {
            $action = $change->action;
            if ('A' === $action && null !== $change->fromPath && '' !== $change->fromPath) {
                $renamed = false;
                foreach ($all as $c) {
                    if ('D' === $c->action && $c->path === $change->fromPath) {
                        $renamed = true;
                    }
                }
                $action = $renamed ? 'R' : 'C';
            } elseif ('D' === $action) {
                foreach ($all as $c) {
                    if ($c->fromPath === $change->path) {
                        continue 2;
                    }
                }
            }
            $changes[] = [$change, $action];
        }
        $tree = [];
        foreach ($changes as [$change, $action]) {
            $node = &$tree;
            $path = '';
            foreach (array_filter(explode('/', $change->path), static fn ($d) => '' !== trim($d)) as $dir) {
                $path .= '/'.$dir;
                $node['s'] ??= [];
                $node = &$node['s'];
                $node[$path] ??= [];
                $node = &$node[$path];
            }
            $node['c'] = [$change, $action];
            unset($node);
        }

        return $this->renderChangesTree($tree['s'] ?? null, $changeset);
    }

    private function renderChangesTree(?array $tree, Changeset $changeset): string
    {
        if (null === $tree) {
            return '';
        }
        $repo = $changeset->repository;
        $rev = RepositoryService::identifier($changeset);
        $model = $this->repositories->model($repo);
        $out = '<ul>';
        $keys = array_keys($tree);
        sort($keys, \SORT_STRING);
        foreach ($keys as $file) {
            $file = (string) $file;
            $text = htmlspecialchars(basename($file));
            if (isset($tree[$file]['s'])) {
                $link = '<a href="'.htmlspecialchars(self::url('show', $repo, ['path' => ltrim($model->relativePath($repo, $file), '/'), 'rev' => $rev])).'">'.$this->app->spriteIcon('folder-open', basename($file)).'</a>';
                $out .= "<li class='change folder'>".$link.$this->renderChangesTree($tree[$file]['s'], $changeset).'</li>';
            } elseif (isset($tree[$file]['c'])) {
                [$c, $action] = $tree[$file]['c'];
                $pathParam = ltrim($model->relativePath($repo, $c->path), '/');
                $t = 'D' === $action ? $text : '<a href="'.htmlspecialchars(self::url('entry', $repo, ['path' => $pathParam, 'rev' => $rev])).'">'.$this->scmChangeIcon($action, basename($file)).'</a>';
                if (null !== $c->revision && '' !== $c->revision) {
                    $t .= ' - '.htmlspecialchars($c->revision);
                }
                if ('M' === $action) {
                    $t .= ' (<a href="'.htmlspecialchars(self::url('diff', $repo, ['path' => $pathParam, 'rev' => $rev])).'">'.htmlspecialchars($this->i18n->l('label_diff')).'</a>) ';
                }
                if (null !== $c->fromPath && '' !== $c->fromPath) {
                    $t .= ' <span class="copied-from">'.htmlspecialchars($c->fromPath).'</span>';
                }
                $out .= "<li class='change change-".$action."'>".$t.'</li>';
            }
        }

        return $out.'</ul>';
    }
}
