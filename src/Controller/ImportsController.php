<?php

declare(strict_types=1);

namespace App\Controller;

use App\Import\ImportFinder;
use App\Import\ImportJs;
use App\Import\ImportView;
use App\Import\ImportWizard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of Redmine's ImportsController: CSV import wizard (upload → settings → mapping → run → results)
 * for issues, time entries and users. Imports are identified in URLs by their random filename.
 * A finished import only has its results page: the other steps redirect to it.
 */
final class ImportsController extends AbstractController
{
    public function __construct(
        private readonly ImportFinder $finder,
        private readonly ImportWizard $wizard,
        private readonly ImportView $view,
    ) {
    }

    #[Route('/issues/imports/new', name: 'new_issues_import', defaults: ['type' => 'IssueImport'], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/issues/imports/new', name: 'new_project_issues_import', defaults: ['type' => 'IssueImport'], methods: ['GET'], priority: 10)]
    #[Route('/time_entries/imports/new', name: 'new_time_entries_import', defaults: ['type' => 'TimeEntryImport'], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/time_entries/imports/new', name: 'new_project_time_entries_import', defaults: ['type' => 'TimeEntryImport'], methods: ['GET'], priority: 10)]
    #[Route('/users/imports/new', name: 'new_users_import', defaults: ['type' => 'UserImport'], methods: ['GET'], priority: 10)]
    public function new(Request $request, string $type, ?string $project_id = null): Response
    {
        $new = $this->wizard->begin($this->finder->importer($type));

        return $this->render('imports/new.html.twig', $this->view->page($new, 'new', [
            'project_id' => $project_id ?? (string) $request->query->get('project_id', ''),
        ]));
    }

    #[Route('/imports', name: 'imports', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $new = $this->wizard->begin($this->finder->importer((string) $request->request->get('type', '')));
        $projectId = (string) $request->request->get('project_id', '');
        if ($this->wizard->upload($new, $request->files->get('file'), $projectId)) {
            return $this->redirectToRoute('import_settings', ['id' => $new->import->filename]);
        }

        return $this->render('imports/new.html.twig', $this->view->page($new, 'new', ['project_id' => $projectId]));
    }

    #[Route('/imports/{id}', name: 'import', methods: ['GET'])]
    public function show(string $id): Response
    {
        $found = $this->finder->find($id);

        return $this->render('imports/show.html.twig', $this->view->page($found, 'show', $this->view->results($found)));
    }

    #[Route('/imports/{id}/settings', name: 'import_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, string $id): Response
    {
        $found = $this->finder->find($id);
        if ($found->import->finished) {
            return $this->redirectToRoute('import', ['id' => $found->import->filename]);
        }
        if ($request->isMethod('POST')) {
            $this->wizard->updateSettings($found, $request);
            $error = $this->wizard->parse($found);
            if (null === $error) {
                return $this->redirectToRoute('import_mapping', ['id' => $found->import->filename]);
            }
            $this->addFlash('error', $error);
        }

        return $this->render('imports/settings.html.twig', $this->view->page($found, 'settings', $this->view->settings()));
    }

    #[Route('/imports/{id}/mapping', name: 'import_mapping', methods: ['GET', 'POST'])]
    #[Route('/imports/{id}/mapping.{_format}', name: 'import_mapping_js', requirements: ['_format' => 'js'], methods: ['POST'])]
    public function mapping(Request $request, string $id, string $_format = 'html'): Response
    {
        $found = $this->finder->find($id);
        if ($found->import->finished) {
            return $this->redirectToRoute('import', ['id' => $found->import->filename]);
        }
        if ($request->isMethod('POST')) {
            $this->wizard->updateSettings($found, $request);
            if ('js' === $_format) {
                $html = $this->renderView('imports/_'.$found->importer::partialPrefix().'_fields_mapping.html.twig', $this->view->mappingFields($found));

                return new Response(ImportJs::fieldsMapping($html), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
            }
            if ($request->request->has('previous')) {
                return $this->redirectToRoute('import_settings', ['id' => $found->import->filename]);
            }

            return $this->redirectToRoute('import_run', ['id' => $found->import->filename]);
        }

        return $this->render('imports/mapping.html.twig', $this->view->page($found, 'mapping', $this->view->mapping($found)));
    }

    #[Route('/imports/{id}/run', name: 'import_run', methods: ['GET', 'POST'])]
    #[Route('/imports/{id}/run.{_format}', name: 'import_run_js', requirements: ['_format' => 'js'], methods: ['POST'])]
    public function run(Request $request, string $id, string $_format = 'html'): Response
    {
        $found = $this->finder->find($id);
        $import = $found->import;
        if ($import->finished) {
            return $this->redirectToRoute('import', ['id' => $import->filename]);
        }
        if ($request->isMethod('POST')) {
            $this->wizard->updateSettings($found, $request);
            $current = $this->wizard->run($found);
            if ('js' === $_format) {
                $next = $import->finished
                    ? $this->generateUrl('import', ['id' => $import->filename])
                    : $this->generateUrl('import_run_js', ['id' => $import->filename, '_format' => 'js']);

                return new Response(ImportJs::progress($current, (int) $import->totalItems, $import->finished, $next), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
            }

            return $import->finished
                ? $this->redirectToRoute('import', ['id' => $import->filename])
                : $this->redirectToRoute('import_run', ['id' => $import->filename]);
        }

        return $this->render('imports/run.html.twig', $this->view->page($found, 'run'));
    }
}
