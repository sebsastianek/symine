<?php

declare(strict_types=1);

namespace App\Import;

use App\I18n\Localizer;

/** Template variables of the pages of the import wizard (imports/*.html.twig). */
final class ImportView
{
    public function __construct(private readonly Localizer $i18n)
    {
    }

    /**
     * Variables common to every page: the import, its importer, and the layout (administration or not) it is shown in.
     *
     * @param string               $action new, show, settings, mapping or run
     * @param array<string, mixed> $vars   the variables of the page itself
     *
     * @return array<string, mixed>
     */
    public function page(LoadedImport $loaded, string $action, array $vars = []): array
    {
        $importer = $loaded->importer;
        $isAdmin = 'admin' === $importer->layout();
        if ($isAdmin) {
            $vars['admin_menu_selected'] = $importer->menuItem();
        }

        return $vars + [
            'project' => null,
            'import' => $loaded->import,
            'importer' => $importer,
            'import_title' => $this->i18n->l($importer->titleKey()),
            'partial_prefix' => $importer::partialPrefix(),
            'layout' => $isAdmin ? 'admin/layout.html.twig' : 'base.html.twig',
            'main_menu_selected' => $isAdmin ? null : $importer->menuItem(),
            'page_title' => $this->i18n->l($importer->titleKey()),
            'body_css' => 'controller-imports action-'.$action,
        ];
    }

    /** @return array<string, mixed> imports/show.html.twig: what was imported and what could not be */
    public function results(LoadedImport $loaded): array
    {
        return [
            'saved_objects' => $loaded->importer->savedObjects($loaded->import),
            'saved_count' => $loaded->importer->savedItemsCount($loaded->import),
            'unsaved_items' => $loaded->importer->unsavedItems($loaded->import),
        ];
    }

    /** @return array<string, mixed> imports/settings.html.twig: the encodings and date formats to choose from */
    public function settings(): array
    {
        return [
            'encodings' => AbstractImporter::ENCODINGS,
            'date_formats' => array_map(static fn (string $f) => [strtr(str_replace('%', '', $f), ['d' => 'DD', 'm' => 'MM', 'Y' => 'YYYY']), $f], AbstractImporter::DATE_FORMATS),
        ];
    }

    /**
     * imports/mapping.html.twig: the fields mapping, guessed from the headers of the file (ImportsController#auto_map_fields),
     * and the first rows of the file as a preview.
     *
     * @return array<string, mixed>
     */
    public function mapping(LoadedImport $loaded): array
    {
        $loaded->importer->autoMapFields($loaded->import);
        try {
            $rows = $loaded->importer->firstRows($loaded->import);
        } catch (ImportFileException) {
            $rows = [];
        }

        return $this->mappingFields($loaded) + ['rows' => $rows];
    }

    /**
     * imports/_<type>_fields_mapping.html.twig: the columns of the file and what the fields of the imported objects can be mapped to.
     *
     * @return array<string, mixed>
     */
    public function mappingFields(LoadedImport $loaded): array
    {
        $importer = $loaded->importer;
        $import = $loaded->import;
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
}
