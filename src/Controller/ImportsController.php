<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Import;
use App\Import\AbstractImporter;
use App\Import\ImporterRegistry;
use App\Import\ImportFileException;
use App\Import\IssueImporter;
use App\Import\TimeEntryImporter;
use App\Import\UserImporter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of Redmine's ImportsController: CSV import wizard (upload → settings → mapping → run → results)
 * for issues, time entries and users. Imports are identified in URLs by their random filename.
 */
final class ImportsController extends AbstractController
{
    private const int MAX_ITEMS_PER_REQUEST = 5;
    private const float MAX_TIME = 10.0;

    public function __construct(private readonly ImporterRegistry $registry)
    {
    }

    #[Route('/issues/imports/new', name: 'new_issues_import', defaults: ['type' => 'IssueImport'], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/issues/imports/new', name: 'new_project_issues_import', defaults: ['type' => 'IssueImport'], methods: ['GET'], priority: 10)]
    #[Route('/time_entries/imports/new', name: 'new_time_entries_import', defaults: ['type' => 'TimeEntryImport'], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/time_entries/imports/new', name: 'new_project_time_entries_import', defaults: ['type' => 'TimeEntryImport'], methods: ['GET'], priority: 10)]
    #[Route('/users/imports/new', name: 'new_users_import', defaults: ['type' => 'UserImport'], methods: ['GET'], priority: 10)]
    public function new(Request $request, string $type, ?string $project_id = null): Response
    {
        $importer = $this->authorizeImport($type);
        $import = $importer->newImport($this->user());

        return $this->renderImport('imports/new.html.twig', $importer, $import, 'new', [
            'project_id' => $project_id ?? (string) $request->query->get('project_id', ''),
        ]);
    }

    #[Route('/imports', name: 'imports', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $type = (string) $request->request->get('type', '');
        $importer = $this->authorizeImport($type);
        $import = $importer->newImport($this->user());
        $file = $request->files->get('file');
        $projectId = (string) $request->request->get('project_id', '');
        if ($file instanceof UploadedFile && $importer->storeFile($import, $file)) {
            $importer->setDefaultSettings($import, '' !== $projectId ? $projectId : null);
            $this->em->persist($import);
            $this->em->flush();

            return $this->redirectToRoute('import_settings', ['id' => $import->filename]);
        }

        return $this->renderImport('imports/new.html.twig', $importer, $import, 'new', ['project_id' => $projectId]);
    }

    #[Route('/imports/{id}', name: 'import', methods: ['GET'])]
    public function show(string $id): Response
    {
        [$import, $importer] = $this->findImport($id, 'show');

        return $this->renderImport('imports/show.html.twig', $importer, $import, 'show', [
            'saved_objects' => $importer->savedObjects($import),
            'saved_count' => $importer->savedItemsCount($import),
            'unsaved_items' => $importer->unsavedItems($import),
        ]);
    }

    #[Route('/imports/{id}/settings', name: 'import_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, string $id): Response
    {
        $found = $this->findImport($id, 'settings');
        if ($found instanceof Response) {
            return $found;
        }
        [$import, $importer] = $found;
        if ($request->isMethod('POST')) {
            $this->updateFromParams($request, $importer, $import);
            try {
                if (0 === $importer->parseFile($import)) {
                    $this->addFlash('error', $this->l('error_no_data_in_file'));
                } else {
                    return $this->redirectToRoute('import_mapping', ['id' => $import->filename]);
                }
            } catch (ImportFileException $e) {
                $this->addFlash('error', match ($e->kind) {
                    ImportFileException::MALFORMED => $this->l('error_invalid_csv_file_or_settings', ['value' => $e->getMessage()]),
                    ImportFileException::ENCODING => $this->l('error_invalid_file_encoding', ['encoding' => (string) ($importer->settingsOf($import)['encoding'] ?? '')]),
                    default => $this->l('error_can_not_read_import_file'),
                });
            }
        }

        return $this->renderImport('imports/settings.html.twig', $importer, $import, 'settings', [
            'encodings' => AbstractImporter::ENCODINGS,
            'date_formats' => array_map(static fn (string $f) => [strtr(str_replace('%', '', $f), ['d' => 'DD', 'm' => 'MM', 'Y' => 'YYYY']), $f], AbstractImporter::DATE_FORMATS),
        ]);
    }

    #[Route('/imports/{id}/mapping', name: 'import_mapping', methods: ['GET', 'POST'])]
    #[Route('/imports/{id}/mapping.{_format}', name: 'import_mapping_js', requirements: ['_format' => 'js'], methods: ['POST'])]
    public function mapping(Request $request, string $id, string $_format = 'html'): Response
    {
        $found = $this->findImport($id, 'mapping');
        if ($found instanceof Response) {
            return $found;
        }
        [$import, $importer] = $found;
        if ($request->isMethod('POST')) {
            $this->updateFromParams($request, $importer, $import);
            if ('js' === $_format) {
                // updates the mapping form on project or tracker change
                $html = $this->renderView('imports/_'.$importer::partialPrefix().'_fields_mapping.html.twig', $this->mappingVars($importer, $import));

                return new Response('$(\'#fields-mapping\').html('.json_encode($html, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP).');', 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
            }
            if ($request->request->has('previous')) {
                return $this->redirectToRoute('import_settings', ['id' => $import->filename]);
            }

            return $this->redirectToRoute('import_run', ['id' => $import->filename]);
        }
        $importer->autoMapFields($import);
        try {
            $rows = $importer->firstRows($import);
        } catch (ImportFileException) {
            $rows = [];
        }

        return $this->renderImport('imports/mapping.html.twig', $importer, $import, 'mapping', $this->mappingVars($importer, $import) + [
            'rows' => $rows,
        ]);
    }

    #[Route('/imports/{id}/run', name: 'import_run', methods: ['GET', 'POST'])]
    #[Route('/imports/{id}/run.{_format}', name: 'import_run_js', requirements: ['_format' => 'js'], methods: ['POST'])]
    public function run(Request $request, string $id, string $_format = 'html'): Response
    {
        $found = $this->findImport($id, 'run');
        if ($found instanceof Response) {
            return $found;
        }
        [$import, $importer] = $found;
        if ($request->isMethod('POST')) {
            $this->updateFromParams($request, $importer, $import);
            $current = $importer->run($import, ['max_items' => self::MAX_ITEMS_PER_REQUEST, 'max_time' => self::MAX_TIME]);
            if ('js' === $_format) {
                $js = \sprintf("\$('#import-progress').progressbar({value: %d});\n\$('#progress-label').text(%s);\n", $current, json_encode($current.' / '.(int) $import->totalItems));
                if ($import->finished) {
                    $js .= 'window.location.href='.json_encode($this->generateUrl('import', ['id' => $import->filename])).";\n";
                } else {
                    $js .= '$.ajax({url: '.json_encode($this->generateUrl('import_run_js', ['id' => $import->filename, '_format' => 'js'])).", type: 'post', headers: {'X-CSRF-Token': $('meta[name=\"csrf-token\"]').attr('content')}});\n";
                }

                return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
            }

            return $import->finished
                ? $this->redirectToRoute('import', ['id' => $import->filename])
                : $this->redirectToRoute('import_run', ['id' => $import->filename]);
        }

        return $this->renderImport('imports/run.html.twig', $importer, $import, 'run');
    }

    // ------------------------------------------------------------------------------------------

    private function authorizeImport(?string $type): AbstractImporter
    {
        $importer = $this->registry->get($type);
        if (null === $importer) {
            throw $this->createNotFoundException();
        }
        if (!$importer->isAuthorized($this->user())) {
            $this->deny();
        }

        return $importer;
    }

    /** ImportsController#find_import (+ authorize_import) @return array{0: Import, 1: AbstractImporter}|Response */
    private function findImport(string $filename, string $action): array|Response
    {
        $import = $this->user()->isLogged()
            ? $this->em->getRepository(Import::class)->findOneBy(['user' => $this->user(), 'filename' => $filename])
            : null;
        $importer = null !== $import ? $this->registry->for($import) : null;
        if (null === $import || null === $importer) {
            if (!$this->user()->isLogged()) {
                $this->deny();
            }
            throw $this->createNotFoundException();
        }
        if (!$importer->isAuthorized($this->user())) {
            $this->deny();
        }
        if ($import->finished && 'show' !== $action) {
            return $this->redirectToRoute('import', ['id' => $import->filename]);
        }

        return [$import, $importer];
    }

    private function updateFromParams(Request $request, AbstractImporter $importer, Import $import): void
    {
        $posted = $request->request->all('import_settings');
        if ([] !== $posted) {
            $importer->updateSettings($import, $posted);
            $this->em->flush();
        }
    }

    /** @return array<string, mixed> */
    private function mappingVars(AbstractImporter $importer, Import $import): array
    {
        $headers = $importer->headers($import);
        $vars = [
            'columns' => array_map(static fn ($h, $i) => [(string) $h, $i], $headers, array_keys($headers)),
            'mapping' => $importer->mapping($import),
            'custom_fields' => $importer->mappableCustomFields($import),
        ];
        if ($importer instanceof IssueImporter) {
            $project = $importer->project($import);
            $vars += [
                'project_options' => AbstractImporter::projectTreeOptions($importer->allowedTargetProjects($import)),
                'selected_project_id' => $project?->id,
                'trackers' => array_map(static fn ($t) => [$t->name, $t->id], $importer->allowedTargetTrackers($import)),
                'can_manage_categories' => null !== $project && $importer->canManageCategories($import),
                'can_manage_versions' => null !== $project && $importer->canManageVersions($import),
                'create_categories' => $importer->createCategories($import),
                'create_versions' => $importer->createVersions($import),
            ];
        } elseif ($importer instanceof TimeEntryImporter) {
            $project = $importer->project($import);
            $vars += [
                'project_options' => AbstractImporter::projectTreeOptions($importer->allowedTargetProjects($import)),
                'selected_project_id' => $project?->id,
                'activities' => array_map(static fn ($a) => [$a->name, $a->id], $importer->allowedTargetActivities($import)),
                'can_log_for_others' => null !== $project && $importer->canLogTimeForOthers($import),
                'users' => array_map(static fn ($u) => [$u->getName(), $u->id], $importer->allowedTargetUsers($import)),
            ];
        } elseif ($importer instanceof UserImporter) {
            // only custom fields
        }

        return $vars;
    }

    /** @param array<string, mixed> $vars */
    private function renderImport(string $template, AbstractImporter $importer, Import $import, string $action, array $vars = []): Response
    {
        $isAdmin = 'admin' === $importer->layout();
        if ($isAdmin) {
            $vars['admin_menu_selected'] = $importer->menuItem();
        }

        return $this->render($template, $vars + [
            'project' => null,
            'import' => $import,
            'importer' => $importer,
            'import_title' => $this->l($importer->titleKey()),
            'partial_prefix' => $importer::partialPrefix(),
            'layout' => $isAdmin ? 'admin/layout.html.twig' : 'base.html.twig',
            'main_menu_selected' => $isAdmin ? null : $importer->menuItem(),
            'page_title' => $this->l($importer->titleKey()),
            'body_css' => 'controller-imports action-'.$action,
        ]);
    }
}
