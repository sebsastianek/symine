<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Service\AtomFeed;
use App\Service\Scm\ChangesetIssueLinks;
use App\Service\Scm\Form\CommittersForm;
use App\Service\Scm\Form\RepositoryForm;
use App\Service\Scm\Http\RepositoryDownloads;
use App\Service\Scm\Http\RepositoryFinder;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmTwigExtension;
use App\Service\Scm\View\RepositoryAnnotation;
use App\Service\Scm\View\RepositoryBrowser;
use App\Service\Scm\View\RepositoryDiff;
use App\Service\Scm\View\RepositoryPage;
use App\Service\Scm\View\RepositoryRevisions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of RepositoriesController (Subversion, Mercurial, CVS, Bazaar, Git, Filesystem; read-only).
 *
 * The browsing actions answer an entry or a revision that does not exist, and an SCM command that fails, with
 * the error pages of App\Service\Scm\Http\ScmErrorSubscriber.
 */
final class RepositoriesController extends AbstractController
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly RepositoryFinder $finder,
        private readonly RepositoryForm $form,
        private readonly CommittersForm $committersForm,
        private readonly RepositoryPage $page,
        private readonly RepositoryBrowser $browser,
        private readonly RepositoryRevisions $revisionsView,
        private readonly RepositoryDiff $diffView,
        private readonly RepositoryAnnotation $annotation,
        private readonly RepositoryDownloads $downloads,
        private readonly ChangesetIssueLinks $issueLinks,
        private readonly AtomFeed $atom,
        private readonly ApiRenderer $api,
    ) {
    }

    // ---------------------------------------------------------------- settings (manage_repository)

    #[Route('/projects/{project_id}/repositories/new', name: 'repository_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_repository', $project);
        $repository = $this->form->blank($request, $project);
        if ($request->isXmlHttpRequest()) {
            return new Response(RepositoryForm::reloadScript($this->renderView('repositories/_new_content.html.twig', $this->form->view($repository))), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('repositories/new.html.twig', $this->form->view($repository));
    }

    #[Route('/projects/{project_id}/repositories', name: 'repository_create', methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_repository', $project);
        $repository = $this->form->build($request, $project);
        $errors = $this->form->submit($repository, $request);
        if ([] !== $errors) {
            return $this->render('repositories/new.html.twig', $this->form->view($repository, $errors), new Response('', 422));
        }

        return $this->redirect(RepositoryForm::settingsUrl($project));
    }

    #[Route('/repositories/{id}/edit', name: 'repository_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        return $this->render('repositories/edit.html.twig', $this->form->view($this->finder->manageable($id)));
    }

    #[Route('/repositories/{id}', name: 'repository_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $repository = $this->finder->manageable($id);
        $errors = $this->form->submit($repository, $request);
        if ([] !== $errors) {
            return $this->render('repositories/edit.html.twig', $this->form->view($repository, $errors), new Response('', 422));
        }

        return $this->redirect(RepositoryForm::settingsUrl($repository->project));
    }

    #[Route('/repositories/{id}', name: 'repository_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $repository = $this->finder->manageable($id);
        $project = $repository->project;
        $this->repos->destroy($repository);

        return $this->redirect(RepositoryForm::settingsUrl($project));
    }

    #[Route('/repositories/{id}/committers', name: 'repository_committers', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function committers(Request $request, int $id): Response
    {
        $repository = $this->finder->manageable($id);
        if ($request->isMethod('POST') && $this->committersForm->submit($repository, $request)) {
            $this->flashNotice('notice_successful_update');

            return $this->redirect(RepositoryForm::settingsUrl($repository->project));
        }

        return $this->render('repositories/committers.html.twig', $this->committersForm->view($repository));
    }

    // ---------------------------------------------------------------- browsing

    #[Route('/projects/{id}/repository/{repository_id}/statistics', name: 'repository_stats', methods: ['GET'])]
    public function stats(Request $request, string $id, string $repository_id): Response
    {
        $at = $this->finder->locate($id, $repository_id, 'browse_repository', $request);

        return $this->render('repositories/stats.html.twig', $this->page->vars($at->project, $at->repository, 'stats', ['page_title' => $this->l('label_statistics')]));
    }

    #[Route('/projects/{id}/repository/{repository_id}/graph', name: 'repository_graph', methods: ['GET'])]
    public function graph(Request $request, string $id, string $repository_id): Response
    {
        $at = $this->finder->locate($id, $repository_id, 'browse_repository', $request);

        return match ($request->query->get('graph')) {
            'commits_per_month' => new JsonResponse($this->repos->graphCommitsPerMonth($at->repository)),
            'commits_per_author' => new JsonResponse($this->repos->graphCommitsPerAuthor($at->repository)),
            default => throw $this->createNotFoundException(),
        };
    }

    #[Route('/projects/{id}/repository/{repository_id}/fetch_changesets', name: 'repository_fetch_changesets', methods: ['POST'])]
    public function fetchChangesets(Request $request, string $id, string $repository_id): Response
    {
        $at = $this->finder->locate($id, $repository_id, 'manage_repository', $request);
        $this->browser->fetchChangesets($at);

        return $this->redirect(ScmTwigExtension::url('show', $at->repository));
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions.{_format}', name: 'repository_revisions_atom', requirements: ['_format' => 'atom'], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}/revisions', name: 'repository_revisions', methods: ['GET'])]
    public function revisions(Request $request, string $id, string $repository_id): Response
    {
        $at = $this->finder->locate($id, $repository_id, 'view_changesets', $request);
        $pager = $this->paginate($request, $this->repos->changesetCount($at->repository));
        if ('atom' === $request->attributes->get('_format')) {
            return $this->atom->render($request, $at->project->name.': '.$this->l('label_revision_plural'), $this->revisionsView->feedItems($at, $pager), $at->project);
        }

        return $this->render(
            $request->isXmlHttpRequest() ? 'repositories/_revisions.html.twig' : 'repositories/revisions.html.twig',
            $this->revisionsView->view($at, $pager),
        );
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}', name: 'repository_revision', requirements: ['rev' => '[^/]+'], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}/revision', name: 'repository_revision_param', methods: ['GET'])]
    public function revision(Request $request, string $id, string $repository_id, ?string $rev = null): Response
    {
        $at = $this->finder->locate($id, $repository_id, 'view_changesets', $request, $rev);

        return $this->render('repositories/revision.html.twig', $this->revisionsView->revision($at));
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/issues.{_format}', name: 'repository_add_related_issue', requirements: ['rev' => '[^/]+', '_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['POST'])]
    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/issues', name: 'repository_add_related_issue_html', requirements: ['rev' => '[^/]+'], methods: ['POST'])]
    public function addRelatedIssue(Request $request, string $id, string $repository_id, string $rev): Response
    {
        $at = $this->finder->locate($id, $repository_id, 'manage_related_issues', $request, $rev);
        $changeset = $this->issueLinks->changeset($at->repository, $rev);
        $issue = $this->issueLinks->add($changeset, $request);
        $invalid = $this->l('label_issue').' '.$this->l('activerecord.errors.messages.invalid');
        if ($this->isApi($request)) {
            // render_api_errors: XML for .xml requests, JSON otherwise
            return null !== $issue ? new Response('', 204) : $this->api->errors($request, [$invalid]);
        }
        if (null === $issue) {
            $this->flashError($invalid);
        }

        return $this->redirect(ScmTwigExtension::url('revision', $at->repository, ['rev' => RepositoryService::identifier($changeset)]));
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/issues/{issue_id}.{_format}', name: 'repository_remove_related_issue', requirements: ['rev' => '[^/]+', 'issue_id' => '\d+', '_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/issues/{issue_id}', name: 'repository_remove_related_issue_html', requirements: ['rev' => '[^/]+', 'issue_id' => '\d+'], methods: ['DELETE'])]
    public function removeRelatedIssue(Request $request, string $id, string $repository_id, string $rev, int $issue_id): Response
    {
        $at = $this->finder->locate($id, $repository_id, 'manage_related_issues', $request, $rev);
        $changeset = $this->issueLinks->changeset($at->repository, $rev);
        $this->issueLinks->remove($changeset, $issue_id);
        if ($this->isApi($request)) {
            return new Response('', 204);
        }

        return $this->redirect(ScmTwigExtension::url('revision', $at->repository, ['rev' => RepositoryService::identifier($changeset)]));
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/diff/{path}', name: 'repository_rev_diff', requirements: ['rev' => '[a-z0-9.\-_]+', 'path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}/diff/{path}', name: 'repository_diff', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    public function diff(Request $request, string $id, string $repository_id, string $path = '', ?string $rev = null): Response
    {
        $at = $this->finder->locate($id, $repository_id, 'browse_repository', $request, $rev, $path);
        if ('diff' === $request->query->get('format')) {
            return $this->downloads->patch($at);
        }

        return $this->render('repositories/diff.html.twig', $this->diffView->view($at, $request));
    }

    #[Route('/projects/{id}/repository/{repository_id}/revisions/{rev}/{action}/{path}', name: 'repository_rev_action', requirements: ['rev' => '[a-z0-9.\-_]+', 'action' => 'browse|show|entry|raw|annotate', 'path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}/{action}/{path}', name: 'repository_action', requirements: ['action' => 'browse|entry|raw|changes|annotate', 'path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    public function action(Request $request, string $id, string $repository_id, string $action, string $path = '', ?string $rev = null): Response
    {
        // repositories#show is allowed by view_changesets or browse_repository, the other actions by browse_repository only
        $at = $this->finder->locate($id, $repository_id, 'show' === $action ? ['view_changesets', 'browse_repository'] : 'browse_repository', $request, $rev, $path);
        $entry = \in_array($action, ['browse', 'show'], true) ? null : $this->browser->entry($at);
        if (null === $entry || ($entry->isDir() && \in_array($action, ['entry', 'raw'], true))) {
            // If the entry is a dir, show the browser
            $vars = $this->browser->directory($at, null === $entry ? $action : 'show', $request);

            return null === $vars ? new Response('') : $this->render($request->isXmlHttpRequest() ? 'repositories/_dir_list_content.html.twig' : 'repositories/show.html.twig', $vars);
        }

        return match ($action) {
            'entry' => $this->render('repositories/entry.html.twig', $this->browser->file($at, $entry)),
            'raw' => $this->downloads->file($at),
            'changes' => $this->render('repositories/changes.html.twig', $this->browser->changes($at, $entry)),
            'annotate' => $this->render('repositories/annotate.html.twig', $this->annotation->view($at, $entry)),
            default => throw $this->createNotFoundException(),
        };
    }

    #[Route('/projects/{id}/repository/{repository_id}/show/{path}', name: 'repository_show_path', requirements: ['path' => '.*'], methods: ['GET'])]
    #[Route('/projects/{id}/repository/{repository_id}', name: 'repository_show_repo', requirements: ['repository_id' => '[^/]+'], methods: ['GET'])]
    #[Route('/projects/{id}/repository', name: 'repository_show', methods: ['GET'])]
    public function showAction(Request $request, string $id, ?string $repository_id = null, string $path = ''): Response
    {
        $at = $this->finder->locate($id, $repository_id, ['view_changesets', 'browse_repository'], $request, null, $path);
        $vars = $this->browser->directory($at, 'show', $request);

        return null === $vars ? new Response('') : $this->render($request->isXmlHttpRequest() ? 'repositories/_dir_list_content.html.twig' : 'repositories/show.html.twig', $vars);
    }
}
