<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Changeset;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Repository;
use App\Entity\User;
use App\Import\AbstractImporter;
use App\Service\AtomFeed;
use App\Service\IssueService;
use App\Service\Scm\AbstractAdapter;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmCommandFailed;
use App\Service\Scm\ScmConfiguration;
use App\Service\Scm\ScmRevision;
use App\Service\Scm\ScmTwigExtension;
use App\Service\Scm\UnifiedDiff;
use App\Service\SyntaxHighlighting;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Port of RepositoriesController (Subversion, Mercurial, CVS, Bazaar, Git, Filesystem; read-only). */
final class RepositoriesController extends AbstractController
{
    private const string REV_PARAM_RE = '/\A[a-f0-9]*\z/i';

    public function __construct(
        private readonly RepositoryService $repos,
        private readonly IssueService $issueService,
        private readonly AtomFeed $atom,
        private readonly SyntaxHighlighting $highlighter,
    ) {
    }

    // ---------------------------------------------------------------- settings (manage_repository)

    #[Route('/projects/{project_id}/repositories/new', name: 'repository_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_repository', $project);
        $repository = $this->buildRepository($request, $project);
        $repository->isDefault = null === $this->repos->defaultRepository($project);
        if ($request->isXmlHttpRequest()) {
            // new.js.erb: the SCM select reloads the form
            $html = $this->renderView('repositories/_new_content.html.twig', $this->formVars($project, $repository, []));

            return new Response("$('#content').html('".self::escapeJs($html)."');", 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->renderForm('repositories/new.html.twig', $project, $repository, []);
    }

    #[Route('/projects/{project_id}/repositories', name: 'repository_create', methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_repository', $project);
        $repository = $this->buildRepository($request, $project);
        $this->assignAttributes($repository, (array) $request->request->all('repository'), true);
        $errors = $this->repos->validate($repository, true);
        if ([] !== $errors) {
            return $this->renderForm('repositories/new.html.twig', $project, $repository, $errors);
        }
        $this->repos->save($repository);

        return $this->redirect($this->settingsUrl($project));
    }

    #[Route('/repositories/{id}/edit', name: 'repository_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $repository = $this->findRepository($id);

        return $this->renderForm('repositories/edit.html.twig', $repository->project, $repository, []);
    }

    #[Route('/repositories/{id}', name: 'repository_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $repository = $this->findRepository($id);
        $this->assignAttributes($repository, (array) $request->request->all('repository'), false);
        $errors = $this->repos->validate($repository, false);
        if ([] !== $errors) {
            return $this->renderForm('repositories/edit.html.twig', $repository->project, $repository, $errors);
        }
        $this->repos->save($repository);

        return $this->redirect($this->settingsUrl($repository->project));
    }

    #[Route('/repositories/{id}', name: 'repository_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $repository = $this->findRepository($id);
        $project = $repository->project;
        $this->repos->destroy($repository);

        return $this->redirect($this->settingsUrl($project));
    }

    #[Route('/repositories/{id}/committers', name: 'repository_committers', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function committers(Request $request, int $id): Response
    {
        $repository = $this->findRepository($id);
        $project = $repository->project;
        $committers = $this->repos->committers($repository);
        if ($request->isMethod('POST') && [] !== $request->request->all('committers')) {
            $map = [];
            foreach ($request->request->all('committers') as $pair) {
                $pair = array_values((array) $pair);
                if (isset($pair[0])) {
                    $map[(string) $pair[0]] = (string) ($pair[\count($pair) - 1] ?? '');
                }
            }
            $this->repos->setCommitterIds($repository, $map);
            $this->flashNotice('notice_successful_update');

            return $this->redirect($this->settingsUrl($project));
        }
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

        return $this->render('repositories/committers.html.twig', [
            'project' => $project, 'repository' => $repository, 'committers' => $committers, 'users' => $users,
            'main_menu_selected' => 'settings', 'body_css' => 'controller-repositories action-committers', 'page_title' => $this->l('label_repository'),
        ]);
    }

    // ---------------------------------------------------------------- browsing

    #[Route('/projects/{id}/repository/{repository_id}/statistics', name: 'repository_stats', methods: ['GET'])]
    public function stats(Request $request, string $id, string $repository_id): Response
    {
        return $this->guard(function () use ($request, $id, $repository_id) {
            [$project, $repository] = $this->findProjectRepository($id, $repository_id, 'browse_repository', $request);

            return $this->render('repositories/stats.html.twig', $this->vars($project, $repository, 'stats', ['page_title' => $this->l('label_statistics')]));
        });
    }

    #[Route('/projects/{id}/repository/{repository_id}/graph', name: 'repository_graph', methods: ['GET'])]
    public function graph(Request $request, string $id, string $repository_id): Response
    {
        return $this->guard(function () use ($request, $id, $repository_id) {
            [, $repository] = $this->findProjectRepository($id, $repository_id, 'browse_repository', $request);

            return match ($request->query->get('graph')) {
                'commits_per_month' => new JsonResponse($this->repos->graphCommitsPerMonth($repository)),
                'commits_per_author' => new JsonResponse($this->repos->graphCommitsPerAuthor($repository)),
                default => throw $this->createNotFoundException(),
            };
        });
    }

    #[Route('/projects/{id}/repository/{repository_id}/fetch_changesets', name: 'repository_fetch_changesets', methods: ['POST'])]
    public function fetchChangesets(Request $request, string $id, string $repository_id): Response
    {
        return $this->guard(function () use ($request, $id, $repository_id) {
            [$project, $repository, $path] = $this->findProjectRepository($id, $repository_id, 'manage_repository', $request);
            if ($project->isActive() && '' === $path && !$this->settings->bool('autofetch_changesets')) {
                $this->repos->fetchChangesets($repository);
            }

            return $this->redirect(ScmTwigExtension::url('show', $repository));
        });
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions.{_format}', name: 'repository_revisions_atom', requirements: ['_format' => 'atom'], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}/revisions', name: 'repository_revisions', methods: ['GET'])]
    public function revisions(Request $request, string $id, string $repository_id): Response
    {
        return $this->guard(function () use ($request, $id, $repository_id) {
            [$project, $repository] = $this->findProjectRepository($id, $repository_id, 'view_changesets', $request);
            $count = $this->repos->changesetCount($repository);
            if ('atom' === $request->attributes->get('_format')) {
                $changesets = $this->repos->changesets($repository, $this->settings->int('feeds_limit') ?: 15);
                $items = array_map(fn (Changeset $c) => [
                    'title' => $this->repos->title($c),
                    'url' => ScmTwigExtension::url('revision', $repository, ['rev' => RepositoryService::identifier($c)]),
                    'updated' => $c->committedOn,
                    'author' => $c->user,
                    'content' => RepositoryService::splitComments($c->comments)[1],
                    'project' => $project,
                ], $changesets);

                return $this->atom->render($request, $project->name.': '.$this->l('label_revision_plural'), $items, $project);
            }
            $pager = $this->paginate($request, $count);
            $changesets = $this->repos->changesets($repository, $pager['limit'], $pager['offset']);
            $vars = $this->vars($project, $repository, 'revisions', [
                'changesets' => $changesets, 'pager' => $pager, 'page_title' => $this->l('label_revision_plural'),
            ]);
            if ($request->isXmlHttpRequest()) {
                return $this->render('repositories/_revisions.html.twig', $vars + ['path' => '', 'revisions' => $changesets]);
            }

            return $this->render('repositories/revisions.html.twig', $vars);
        });
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}', name: 'repository_revision', requirements: ['rev' => '[^/]+'], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}/revision', name: 'repository_revision_param', methods: ['GET'])]
    public function revision(Request $request, string $id, string $repository_id, ?string $rev = null): Response
    {
        return $this->guard(function () use ($request, $id, $repository_id, $rev) {
            [$project, $repository, , $rev] = $this->findProjectRepository($id, $repository_id, 'view_changesets', $request, $rev);
            $changeset = '' !== (string) $rev ? $this->repos->findChangesetByName($repository, $rev) : null;
            if (null === $changeset) {
                return $this->notFound();
            }

            return $this->render('repositories/revision.html.twig', $this->vars($project, $repository, 'revision', [
                'rev' => $rev, 'changeset' => $changeset] + $this->changesetVars($changeset) + [
                'page_title' => $this->l('label_revision').' '.$this->repos->model($repository)->formatChangesetIdentifier($changeset).' - '.mb_strimwidth((string) $changeset->comments, 0, 80, '...'),
            ]));
        });
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/issues.{_format}', name: 'repository_add_related_issue', requirements: ['rev' => '[^/]+', '_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['POST'])]
    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/issues', name: 'repository_add_related_issue_html', requirements: ['rev' => '[^/]+'], methods: ['POST'])]
    public function addRelatedIssue(Request $request, string $id, string $repository_id, string $rev): Response
    {
        [, $repository] = $this->findProjectRepository($id, $repository_id, 'manage_related_issues', $request, $rev);
        $changeset = $this->repos->findChangesetByName($repository, $rev) ?? throw $this->createNotFoundException();
        $issueId = ltrim(trim((string) ($request->request->get('issue_id') ?? \App\Api\ApiRenderer::payload($request)['issue_id'] ?? '')), '#');
        $issue = '' !== $issueId ? $this->repos->findReferencedIssue($changeset, $issueId) : null;
        if (null !== $issue && (!$this->issueService->isVisible($issue) || $changeset->issues->contains($issue))) {
            $issue = null;
        }
        if (null !== $issue) {
            $changeset->issues->add($issue);
            $this->em->flush();
        }
        if ($this->isApi($request)) {
            return null !== $issue ? new Response('', 204) : $this->apiErrors([$this->l('label_issue').' '.$this->l('activerecord.errors.messages.invalid')]);
        }
        if (null === $issue) {
            $this->flashError($this->l('label_issue').' '.$this->l('activerecord.errors.messages.invalid'));
        }

        return $this->redirect(ScmTwigExtension::url('revision', $repository, ['rev' => RepositoryService::identifier($changeset)]));
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/issues/{issue_id}.{_format}', name: 'repository_remove_related_issue', requirements: ['rev' => '[^/]+', 'issue_id' => '\d+', '_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/issues/{issue_id}', name: 'repository_remove_related_issue_html', requirements: ['rev' => '[^/]+', 'issue_id' => '\d+'], methods: ['DELETE'])]
    public function removeRelatedIssue(Request $request, string $id, string $repository_id, string $rev, int $issue_id): Response
    {
        [, $repository] = $this->findProjectRepository($id, $repository_id, 'manage_related_issues', $request, $rev);
        $changeset = $this->repos->findChangesetByName($repository, $rev) ?? throw $this->createNotFoundException();
        $issue = $this->em->find(Issue::class, $issue_id);
        if (null !== $issue && $this->issueService->isVisible($issue)) {
            $changeset->issues->removeElement($issue);
            $this->em->flush();
        }
        if ($this->isApi($request)) {
            return new Response('', 204);
        }

        return $this->redirect(ScmTwigExtension::url('revision', $repository, ['rev' => RepositoryService::identifier($changeset)]));
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/diff/{path}', name: 'repository_rev_diff', requirements: ['rev' => '[a-z0-9.\-_]+', 'path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}/diff/{path}', name: 'repository_diff', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    public function diff(Request $request, string $id, string $repository_id, string $path = '', ?string $rev = null): Response
    {
        return $this->guard(function () use ($request, $id, $repository_id, $path, $rev) {
            [$project, $repository, $path, $rev, $revTo] = $this->findProjectRepository($id, $repository_id, 'browse_repository', $request, $rev, $path);
            if ('diff' === $request->query->get('format')) {
                $diff = null !== $rev ? $this->repos->diff($repository, $path, $rev, $revTo) : null;
                if (null === $diff) {
                    return $this->notFound();
                }
                $filename = 'changeset_r'.$rev.(null !== $revTo ? '_r'.$revTo : '');
                $response = new Response(implode('', $diff), 200, ['Content-Type' => 'text/x-patch']);
                $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename.'.diff', (string) preg_replace('/[^\x20-\x7e]/', '_', $filename.'.diff')));

                return $response;
            }
            $user = $this->user();
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
            $diff = null !== $rev ? $this->repos->diff($repository, $path, $rev, $revTo) : null;
            if (null === $diff) {
                return $this->notFound();
            }
            $changeset = $this->repos->findChangesetByName($repository, $rev);
            $changesetTo = null !== $revTo ? $this->repos->findChangesetByName($repository, $revTo) : null;
            $vars = [
                'path' => $path, 'rev' => $rev, 'rev_to' => $revTo, 'changeset' => $changeset, 'changeset_to' => $changesetTo,
                'diff' => new UnifiedDiff($diff, $diffType, $this->settings->int('diff_max_lines_displayed') ?: null),
                'diff_type' => $diffType,
                'diff_format_revisions' => $this->repos->model($repository)->diffFormatRevisions($changeset, $changesetTo),
                'page_title' => AbstractAdapter::withLeadingSlash($path).' - Diff',
            ];
            if (null !== $changeset) {
                $vars += $this->changesetVars($changeset);
            }

            return $this->render('repositories/diff.html.twig', $this->vars($project, $repository, 'diff', $vars));
        });
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/{action}/{path}', name: 'repository_rev_action', requirements: ['rev' => '[a-z0-9.\-_]+', 'action' => 'browse|show|entry|raw|annotate', 'path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}/{action}/{path}', name: 'repository_action', requirements: ['action' => 'browse|entry|raw|changes|annotate', 'path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    public function action(Request $request, string $id, string $repository_id, string $action, string $path = '', ?string $rev = null): Response
    {
        return $this->guard(fn () => match ($action) {
            'browse', 'show' => $this->show($request, $id, $repository_id, $path, $rev, $action),
            'entry' => $this->entryOrRaw($request, $id, $repository_id, $path, $rev, false),
            'raw' => $this->entryOrRaw($request, $id, $repository_id, $path, $rev, true),
            'changes' => $this->changes($request, $id, $repository_id, $path),
            'annotate' => $this->annotate($request, $id, $repository_id, $path, $rev),
            default => throw $this->createNotFoundException(),
        });
    }

    #[Route('/projects/{id}/repository/{repository_id}/show/{path}', name: 'repository_show_path', requirements: ['path' => '.*'], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}', name: 'repository_show_repo', requirements: ['repository_id' => '[^/]+'], methods: ['GET'])]
    #[Route('/projects/{id}/repository', name: 'repository_show', methods: ['GET'])]
    public function showAction(Request $request, string $id, ?string $repository_id = null, string $path = ''): Response
    {
        return $this->guard(fn () => $this->show($request, $id, $repository_id, $path));
    }

    private function show(Request $request, string $id, ?string $repositoryId = null, string $path = '', ?string $rev = null, string $action = 'show'): Response
    {
        [$project, $repository, $path, $rev] = $this->findProjectRepository($id, $repositoryId, ['view_changesets', 'browse_repository'], $request, $rev, $path);
        if ('' === $path && $project->isActive() && $this->settings->bool('autofetch_changesets')) {
            $this->repos->fetchChangesets($repository);
        }
        $entries = $this->repos->entries($repository, $path, $rev);
        $changeset = $this->repos->findChangesetByName($repository, $rev);
        if ($request->isXmlHttpRequest()) {
            if (null === $entries) {
                return new Response('');
            }

            return $this->render('repositories/_dir_list_content.html.twig', $this->vars($project, $repository, $action, [
                'path' => $path, 'rev' => $rev, 'entries' => $entries, 'depth' => (int) $request->query->get('depth', 0), 'parent_id' => (string) $request->query->get('parent_id', ''),
            ]));
        }
        if (null === $entries) {
            return $this->notFound();
        }

        return $this->render('repositories/show.html.twig', $this->vars($project, $repository, $action, [
            'path' => $path, 'rev' => $rev, 'entries' => $entries, 'changeset' => $changeset, 'depth' => 0, 'parent_id' => '',
            'changesets' => $this->repos->latestChangesets($repository, $path, $rev),
            'properties' => $this->repos->properties($repository, $path, $rev),
            'repositories' => $this->repos->projectRepositories($project),
            'page_title' => $this->l('label_repository'),
        ]));
    }

    private function changes(Request $request, string $id, string $repositoryId, string $path): Response
    {
        [$project, $repository, $path, $rev] = $this->findProjectRepository($id, $repositoryId, 'browse_repository', $request, null, $path);
        $entry = $this->repos->entry($repository, $path, $rev);
        if (null === $entry) {
            return $this->notFound();
        }

        return $this->render('repositories/changes.html.twig', $this->vars($project, $repository, 'changes', [
            'path' => $path, 'rev' => $rev, 'entry' => $entry, 'changeset' => $this->repos->findChangesetByName($repository, $rev),
            'changesets' => $this->repos->latestChangesets($repository, $path, $rev, $this->settings->int('repository_log_display_limit') ?: 100),
            'properties' => $this->repos->properties($repository, $path, $rev),
            'page_title' => $this->l('label_change_plural'),
        ]));
    }

    private function entryOrRaw(Request $request, string $id, string $repositoryId, string $path, ?string $rev, bool $raw): Response
    {
        [$project, $repository, $path, $rev] = $this->findProjectRepository($id, $repositoryId, 'browse_repository', $request, $rev, $path);
        $entry = $this->repos->entry($repository, $path, $rev);
        if (null === $entry) {
            return $this->notFound();
        }
        // If the entry is a dir, show the browser
        if ($entry->isDir()) {
            return $this->show($request, $id, $repositoryId, $path, $rev);
        }
        if ($raw) {
            $content = $this->repos->cat($repository, $path, $rev);
            if (null === $content) {
                return $this->notFound();
            }
            $name = basename($path);
            $type = ScmTwigExtension::mimeType($path);
            if ('application/javascript' === $type) {
                $type = 'text/plain';
            }
            $response = new Response($content, 200, ['Content-Type' => $type ?? 'application/octet-stream']);
            $disposition = 'application/pdf' === $type ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
            $response->headers->set('Content-Disposition', $response->headers->makeDisposition($disposition, $name, preg_replace('/[^\x20-\x7e]/', '_', $name) ?: 'file'));
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        }
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
                return $this->notFound();
            }
            if (\strlen($c) <= $maxSize && (ScmTwigExtension::isText($path) || !AbstractAdapter::isBinary($c))) {
                // Prevent empty lines when displaying a file with Windows style eol
                $content = str_replace("\r\n", "\n", $c);
            }
        }

        return $this->render('repositories/entry.html.twig', $this->vars($project, $repository, 'entry', [
            'path' => $path, 'rev' => $rev, 'entry' => $entry, 'content' => $content,
            'raw_url' => ScmTwigExtension::url('raw', $repository, ['path' => $path, 'rev' => $rev]),
            'mime' => ScmTwigExtension::mimeType($path),
            'siblings' => $siblings, 'sibling_index' => $index,
            'changeset' => $this->repos->findChangesetByName($repository, $rev),
            'page_title' => AbstractAdapter::withLeadingSlash($path),
        ]));
    }

    private function annotate(Request $request, string $id, string $repositoryId, string $path, ?string $rev): Response
    {
        [$project, $repository, $path, $rev] = $this->findProjectRepository($id, $repositoryId, 'browse_repository', $request, $rev, $path);
        $entry = $this->repos->entry($repository, $path, $rev);
        if (null === $entry) {
            return $this->notFound();
        }
        $annotate = $this->repos->annotate($repository, $path, $rev);
        $error = null;
        if (null === $annotate || $annotate->isEmpty()) {
            $annotate = null;
            $error = $this->l('error_scm_annotate');
        } elseif (array_sum(array_map('strlen', $annotate->lines)) > $this->settings->int('file_max_size_displayed') * 1024) {
            $annotate = null;
            $error = $this->l('error_scm_annotate_big_text_file');
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

        return $this->render('repositories/annotate.html.twig', $this->vars($project, $repository, 'annotate', [
            'path' => $path, 'rev' => $rev, 'entry' => $entry, 'rows' => $rows, 'error_message' => $error,
            'has_previous' => null !== $annotate && $annotate->hasPrevious(),
            'changeset' => $this->repos->findChangesetByName($repository, $rev),
            'page_title' => $this->l('button_annotate'),
        ]));
    }

    // ---------------------------------------------------------------- helpers

    /** rescue_from Redmine::Scm::Adapters::CommandFailed => show_error_command_failed */
    private function guard(callable $action): Response
    {
        try {
            return $action();
        } catch (ScmCommandFailed $e) {
            return $this->render('common/error.html.twig', ['status' => 500, 'message' => $this->l('error_scm_command_failed', ['value' => $e->getMessage()])], new Response('', 500));
        }
    }

    /**
     * find_project_repository + authorize + rev/rev_to validation.
     *
     * @param string|list<string> $permission
     *
     * @return array{0: Project, 1: Repository, 2: string, 3: ?string, 4: ?string}
     */
    private function findProjectRepository(string $id, ?string $repositoryId, string|array $permission, ?Request $request = null, ?string $rev = null, string $path = ''): array
    {
        $project = $this->findProject($id);
        if (null !== $repositoryId && '' !== $repositoryId) {
            $repository = $this->repos->findByIdentifierParam($project, $repositoryId);
        } else {
            $repository = $this->repos->defaultRepository($project) ?? ($this->repos->projectRepositories($project)[0] ?? null);
        }
        if (null === $repository || !$this->repos->adapterEnabled($repository)) {
            throw $this->createNotFoundException();
        }
        $allowed = false;
        foreach ((array) $permission as $perm) {
            if ($this->auth->allowedTo($perm, $project)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $this->deny();
        }
        $path = implode('/', array_filter(preg_split('#[/\\\\]#', $path) ?: [], static fn ($p) => '' !== trim($p)));
        $request ??= $this->requestStack->getCurrentRequest();
        $rev = trim((string) ($rev ?? $request?->query->get('rev') ?? ''));
        // branch / tag selectors of the navigation form
        if ('' === $rev) {
            $rev = trim((string) ($request?->query->get('branch') ?: $request?->query->get('tag') ?: ''));
        }
        try {
            if ('' === $rev) {
                $rev = $this->repos->defaultBranch($repository);
            }
            $revTo = trim((string) ($request?->query->get('rev_to') ?? ''));
            $revTo = '' === $revTo ? null : $revTo;
            $adapter = $this->repos->adapter($repository);
            foreach ([$rev, $revTo] as $r) {
                if (null !== $r && !preg_match(self::REV_PARAM_RE, $r) && !$adapter->isValidName($r)) {
                    throw new \InvalidArgumentException();
                }
            }
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException($this->l('error_scm_not_found'));
        } catch (ScmCommandFailed $e) {
            throw new HttpException(500, $this->l('error_scm_command_failed', ['value' => $e->getMessage()]));
        }

        return [$project, $repository, $path, $rev, $revTo];
    }

    private function vars(Project $project, Repository $repository, string $action, array $vars = []): array
    {
        $adapter = $this->repos->adapter($repository);
        $model = $this->repos->model($repository);

        return $vars + [
            'project' => $project,
            'repository' => $repository,
            'action_name' => $action,
            'main_menu_selected' => 'repository',
            'body_css' => 'controller-repositories action-'.$action,
            'path' => '',
            'rev' => null,
            'entry' => null,
            'changeset' => null,
            'properties' => null,
            'branches' => $adapter->branches() ?? [],
            'tags' => $adapter->tags() ?? [],
            'report_last_commit' => $model->reportLastCommit($repository),
            'supports_cat' => $model->supportsCat($repository),
            'supports_annotate' => $model->supportsAnnotate($repository),
            'supports_history' => $model->supportsHistory(),
            'supports_directory_revisions' => $model->supportsDirectoryRevisions(),
            'supports_revision_graph' => $model->supportsRevisionGraph(),
            'can_browse' => $this->auth->allowedTo('browse_repository', $project),
            'can_view_changesets' => $this->auth->allowedTo('view_changesets', $project),
            'can_manage' => $this->auth->allowedTo('manage_repository', $project),
            'autofetch' => $this->settings->bool('autofetch_changesets'),
        ];
    }

    private function changesetVars(Changeset $changeset): array
    {
        $issues = array_values(array_filter($changeset->issues->toArray(), fn (Issue $i) => $this->issueService->isVisible($i)));

        return [
            'previous' => $this->repos->previousChangeset($changeset),
            'next' => $this->repos->nextChangeset($changeset),
            'children' => $this->repos->children($changeset),
            'related_issues' => $issues,
            'has_changes' => [] !== $this->repos->changes($changeset, 1),
            'can_manage_related' => $this->auth->allowedTo('manage_related_issues', $changeset->repository->project),
        ];
    }

    private function notFound(): Response
    {
        return $this->render('common/error.html.twig', ['status' => 404, 'message' => $this->l('error_scm_not_found')], new Response('', 404));
    }

    private function findRepository(int $id): Repository
    {
        $repository = $this->em->find(Repository::class, $id) ?? throw $this->createNotFoundException();
        $this->findProject((string) $repository->project->id);
        $this->authorize('manage_repository', $repository->project);

        return $repository;
    }

    /** build_new_repository_from_params */
    private function buildRepository(Request $request, Project $project): Repository
    {
        $param = (string) ($request->request->get('repository_scm') ?? $request->query->get('repository_scm', ''));
        $enabled = $this->repos->enabledScms();
        $scm = '' !== $param ? (\in_array($param, $enabled, true) ? $param : null) : ($enabled[0] ?? null);
        $scm = ScmConfiguration::normalizeName($scm);
        if (null === $scm) {
            throw $this->createNotFoundException();
        }
        $repository = $this->repos->build($scm);
        $repository->project = $project;
        $this->assignAttributes($repository, (array) $request->query->all('repository'), true);

        return $repository;
    }

    /**
     * safe_attributes: identifier (unless frozen), login, password, path_encoding, log_encoding, is_default,
     * url (new only), root_url (CVS, new only), report_last_commit (Git).
     */
    private function assignAttributes(Repository $repository, array $attrs, bool $isNew): void
    {
        if (\array_key_exists('identifier', $attrs) && ($isNew || null === $repository->identifier || '' === $repository->identifier)) {
            $repository->identifier = trim((string) $attrs['identifier']);
        }
        if ($isNew && \array_key_exists('url', $attrs)) {
            $repository->url = trim((string) $attrs['url']);
        }
        if ($isNew && \array_key_exists('root_url', $attrs) && 'Repository::Cvs' === $repository->type) {
            $repository->rootUrl = trim((string) $attrs['root_url']);
        }
        if (\array_key_exists('login', $attrs)) {
            $repository->login = (string) $attrs['login'];
        }
        if (\array_key_exists('password', $attrs)) {
            $repository->password = (string) $attrs['password'];
        }
        if (\array_key_exists('is_default', $attrs)) {
            $repository->isDefault = \in_array((string) $attrs['is_default'], ['1', 'true'], true);
        }
        if (\array_key_exists('path_encoding', $attrs)) {
            $repository->pathEncoding = '' === (string) $attrs['path_encoding'] ? null : (string) $attrs['path_encoding'];
        }
        if (\array_key_exists('log_encoding', $attrs)) {
            $repository->logEncoding = '' === (string) $attrs['log_encoding'] ? null : (string) $attrs['log_encoding'];
        }
        if (\array_key_exists('report_last_commit', $attrs) && $this->repos->isGit($repository)) {
            $this->repos->mergeExtraInfo($repository, ['extra_report_last_commit' => \in_array((string) $attrs['report_last_commit'], ['1', 'true'], true) ? '1' : '0']);
        }
    }

    private function formVars(Project $project, Repository $repository, array $errors): array
    {
        $config = $this->repos->configuration();
        $scm = $this->repos->scm($repository) ?? 'Git';
        $enabled = $this->repos->enabledScms();
        $options = [];
        foreach ($config->all() as $name) {
            if (\in_array($name, $enabled, true) || $name === $scm) {
                $options[$name] = $config->scmName($name);
            }
        }

        return [
            'project' => $project, 'repository' => $repository, 'errors' => $errors,
            'scm' => $scm, 'scm_options' => $options,
            'scm_available' => $this->repos->scmAvailable($scm),
            'scm_path_info' => $config->pathInfo($scm) ?? $this->scmNote($scm),
            'identifier_frozen' => null !== $repository->id && null !== $repository->identifier && '' !== $repository->identifier,
            'report_last_commit' => $this->repos->reportLastCommit($repository),
            'encodings' => AbstractImporter::ENCODINGS,
            'main_menu_selected' => 'settings', 'body_css' => 'controller-repositories action-'.(null === $repository->id ? 'new' : 'edit'),
            'page_title' => $this->l('label_repository'),
        ];
    }

    /** l("text_#{scm_name}_repository_note", :default => '') */
    private function scmNote(string $scm): string
    {
        $key = 'text_'.strtolower($this->repos->configuration()->scmName($scm)).'_repository_note';
        $text = $this->l($key);

        return $text === $key ? '' : $text;
    }

    private function renderForm(string $template, Project $project, Repository $repository, array $errors): Response
    {
        return $this->render($template, $this->formVars($project, $repository, $errors), new Response('', [] === $errors ? 200 : 422));
    }

    private function settingsUrl(Project $project): string
    {
        return '/projects/'.$project->identifier.'/settings/repositories';
    }

    /** ActionView::Helpers::JavaScriptHelper#escape_javascript */
    private static function escapeJs(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '</' => '<\/', "\r\n" => '\n', "\n" => '\n', "\r" => '\n', '"' => '\\"', "'" => "\\'", "\u{2028}" => '&#x2028;', "\u{2029}" => '&#x2029;']);
    }
}
