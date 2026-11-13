<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Controller\AbstractController;
use App\Entity\CustomField;
use App\Entity\IssueCustomField;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\Tracker;
use App\Service\Admin\FieldFormats;
use App\Service\Admin\ListPositioner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's CustomFieldsController */
final class CustomFieldsController extends AbstractController
{
    public function __construct(
        private readonly FieldFormats $formats,
        private readonly ListPositioner $positioner,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/custom_fields', name: 'custom_fields_index', methods: ['GET'])]
    #[Route('/custom_fields.{_format}', name: 'custom_fields_index_api', requirements: ['_format' => 'json|xml'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin();
        /** @var list<CustomField> $all */
        $all = $this->em->createQuery('SELECT cf FROM App\Entity\CustomField cf ORDER BY cf.position, cf.id')->getResult();
        if ($this->isApi($request)) {
            return $this->api->render($request, ['custom_fields' => array_map($this->apiField(...), $this->sortById($all))]);
        }
        $byType = [];
        foreach ($all as $cf) {
            $byType[$cf::typeName()][] = $cf;
        }
        $projectsCount = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT custom_field_id, COUNT(*) c FROM custom_fields_projects GROUP BY custom_field_id') as $row) {
            $projectsCount[(int) $row['custom_field_id']] = (int) $row['c'];
        }
        $tabs = [];
        foreach (FieldFormats::TABS as $name => $label) {
            if (isset($byType[$name])) {
                $tabs[] = ['name' => $name, 'label' => $label, 'template' => 'admin/custom_fields/_index.html.twig'];
            }
        }

        return $this->render('admin/custom_fields/index.html.twig', [
            'tabs' => $tabs,
            'custom_fields_by_type' => $byType,
            'projects_count' => $projectsCount,
            'admin_menu_selected' => 'custom_fields',
            'page_title' => $this->l('label_custom_field_plural'),
            'body_css' => 'controller-custom_fields action-index',
        ]);
    }

    /** @param list<CustomField> $fields @return list<CustomField> */
    private function sortById(array $fields): array
    {
        usort($fields, static fn ($a, $b) => $a->id <=> $b->id);

        return $fields;
    }

    private function apiField(CustomField $field): array
    {
        $type = $field::typeName();
        $out = [
            'id' => $field->id,
            'name' => $field->name,
            'description' => $field->description,
            'customized_type' => FieldFormats::customizedTypeUnderscore($type),
            'field_format' => $field->fieldFormat,
            'regexp' => $field->regexp,
            'min_length' => $field->minLength,
            'max_length' => $field->maxLength,
            'is_required' => $field->isRequired,
            'is_for_all' => $field->isForAll,
            'is_filter' => $field->isFilter,
            'searchable' => (bool) $field->searchable,
            'multiple' => (bool) $field->multiple,
            'default_value' => $field->defaultValue,
        ];
        if ('date' === $field->fieldFormat) {
            $out['default_value_mode'] = (string) ($field->getFormatOption('default_value_mode') ?: 'fixed_date');
        }
        $out['visible'] = $field->visible;
        $out['editable'] = (bool) $field->editable;
        $values = $this->formats->possibleValuesOptions($field);
        if ([] !== $values) {
            $out['possible_values'] = array_map(static fn ($v) => ['value' => $v[1] ?? $v[0], 'label' => $v[0]], $values);
        }
        if ($field instanceof IssueCustomField) {
            $projects = $field->projects->toArray();
            usort($projects, static fn ($a, $b) => $a->id <=> $b->id);
            $out['projects'] = array_map(static fn ($p) => ['id' => $p->id, 'name' => $p->name], $projects);
            $trackers = $field->trackers->toArray();
            usort($trackers, static fn ($a, $b) => $a->id <=> $b->id);
            $out['trackers'] = array_map(static fn ($t) => ['id' => $t->id, 'name' => $t->name], $trackers);
        }
        if (\in_array($type, ['IssueCustomField', 'TimeEntryCustomField', 'ProjectCustomField', 'VersionCustomField'], true)) {
            $out['roles'] = array_map(static fn ($r) => ['id' => $r->id, 'name' => $r->name], $field->roles->toArray());
        }

        return $out;
    }

    #[Route('/custom_fields/new', name: 'custom_field_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();
        $type = (string) $request->query->get('type', '');
        if (!FieldFormats::typeExists($type)) {
            $tab = (string) $request->query->get('tab', '');

            return $this->render('admin/custom_fields/select_type.html.twig', [
                'type_options' => array_map(fn ($name) => [$this->l(FieldFormats::TABS[$name]), $name], array_keys(FieldFormats::TABS)),
                'default_type' => isset(FieldFormats::TABS[$tab]) ? $tab : 'IssueCustomField',
                'admin_menu_selected' => 'custom_fields',
                'body_css' => 'controller-custom_fields action-new',
            ]);
        }
        $field = FieldFormats::newInstance($type);
        $copyFrom = null;
        if ($request->query->get('copy') && ($copyFrom = $this->em->find(CustomField::class, (int) $request->query->get('copy')))) {
            $this->copyFrom($field, $copyFrom);
        }
        $this->formats->assign($field, (array) $request->query->all('custom_field'));
        if ('' === $field->fieldFormat) {
            $field->fieldFormat = 'string';
        }
        if (null === $copyFrom) {
            $field->defaultValue = $request->query->all('custom_field')['default_value'] ?? null;
        }

        return $this->renderForm($field, [], $copyFrom);
    }

    #[Route('/custom_fields', name: 'custom_field_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $type = (string) $request->request->get('type', '');
        if (!FieldFormats::typeExists($type)) {
            return $this->redirectToRoute('custom_field_new');
        }
        $field = FieldFormats::newInstance($type);
        $copyFrom = null;
        if ($request->request->get('copy') && ($copyFrom = $this->em->find(CustomField::class, (int) $request->request->get('copy')))) {
            $this->copyFrom($field, $copyFrom);
        }
        $attrs = $request->request->all('custom_field');
        $this->formats->assign($field, $attrs);
        $this->formats->normalize($field);
        $errors = $this->formats->validate($field);
        if ([] !== $errors) {
            return $this->renderForm($field, $errors, $copyFrom, 422);
        }
        $scope = ['type' => $type];
        $explicit = null !== $field->position;
        $field->position ??= $this->positioner->nextPosition('custom_fields', $scope);
        $this->em->persist($field);
        $this->em->flush();
        if ($explicit) {
            $this->positioner->inserted('custom_fields', $scope, $field->id, $field->position);
        }
        if ($field instanceof IssueCustomField && \array_key_exists('project_ids', $attrs)) {
            $this->setProjectIds($field, (array) $attrs['project_ids']);
        }
        $this->callHook('controller_custom_fields_new_after_save', ['params' => $request->request->all(), 'custom_field' => $field]);
        $this->flashNotice('notice_successful_create');
        if ($request->request->has('continue')) {
            return $this->redirectToRoute('custom_field_new', ['type' => $type]);
        }

        return $this->redirectToRoute('custom_fields_index', ['tab' => $type]);
    }

    #[Route('/custom_fields/{id}/edit', name: 'custom_field_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();

        return $this->renderForm($this->findField($id));
    }

    #[Route('/custom_fields/{id}', name: 'custom_field_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH', 'POST'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $field = $this->findField($id);
        $attrs = $request->request->all('custom_field');
        $oldPosition = $field->position;
        $wasMultiple = $field->isMultiple();
        $wasVisible = $field->visible;
        $this->formats->assign($field, $attrs);
        $this->formats->normalize($field);
        $errors = $this->formats->validate($field);
        if ([] !== $errors) {
            if ($request->isXmlHttpRequest()) {
                return new Response('', 422);
            }

            return $this->renderForm($field, $errors, null, 422);
        }
        if ($field->visible && !$wasVisible) {
            $field->roles->clear();
        }
        $this->em->flush();
        if (null !== $oldPosition && null !== $field->position && $oldPosition !== $field->position) {
            $this->positioner->moved('custom_fields', ['type' => $field::typeName()], $field->id, $oldPosition, $field->position);
        } elseif (null === $oldPosition && null !== $field->position) {
            $this->positioner->inserted('custom_fields', ['type' => $field::typeName()], $field->id, $field->position);
        }
        if ($field instanceof IssueCustomField && \array_key_exists('project_ids', $attrs)) {
            $this->setProjectIds($field, (array) $attrs['project_ids']);
        }
        $this->callHook('controller_custom_fields_edit_after_save', ['params' => $request->request->all(), 'custom_field' => $field]);
        if ($wasMultiple && !$field->isMultiple()) {
            $this->handleMultiplicityChange($field);
        }
        if ($request->isXmlHttpRequest()) {
            return new Response('', 200);
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectBackOr($request, $this->generateUrl('custom_field_edit', ['id' => $field->id]));
    }

    #[Route('/custom_fields/{id}', name: 'custom_field_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        $field = $this->findField($id);
        $type = $field::typeName();
        $position = $field->position;
        $db = $this->em->getConnection();
        try {
            $db->beginTransaction();
            $db->executeStatement('DELETE FROM custom_values WHERE custom_field_id = ?', [$id]);
            $db->executeStatement('DELETE FROM custom_field_enumerations WHERE custom_field_id = ?', [$id]);
            $db->executeStatement('DELETE FROM custom_fields_roles WHERE custom_field_id = ?', [$id]);
            $db->executeStatement('DELETE FROM custom_fields_trackers WHERE custom_field_id = ?', [$id]);
            $db->executeStatement('DELETE FROM custom_fields_projects WHERE custom_field_id = ?', [$id]);
            if ($field instanceof IssueCustomField) {
                $db->executeStatement("DELETE FROM workflows WHERE type = 'WorkflowPermission' AND field_name = ?", [(string) $id]);
            }
            $db->executeStatement('DELETE FROM custom_fields WHERE id = ?', [$id]);
            $this->positioner->removed('custom_fields', ['type' => $type], $id, $position);
            $db->commit();
            $this->em->clear();
            $this->flashNotice('notice_successful_delete');
        } catch (\Throwable) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $this->flashError($this->l('error_can_not_delete_custom_field'));
        }

        return $this->redirectToRoute('custom_fields_index', ['tab' => $type]);
    }

    private function findField(int $id): CustomField
    {
        $field = $this->em->find(CustomField::class, $id);
        if (null === $field) {
            throw $this->createNotFoundException();
        }

        return $field;
    }

    /** CustomField#copy_from */
    private function copyFrom(CustomField $field, CustomField $src): void
    {
        foreach (['fieldFormat', 'possibleValues', 'regexp', 'minLength', 'maxLength', 'isRequired', 'isForAll', 'isFilter', 'searchable', 'defaultValue', 'editable', 'visible', 'multiple', 'formatStore', 'description'] as $p) {
            $field->$p = $src->$p;
        }
        foreach ($src->enumerations as $e) {
            $ne = new \App\Entity\CustomFieldEnumeration();
            $ne->customField = $field;
            $ne->name = $e->name;
            $ne->active = $e->active;
            $ne->position = $e->position;
            $field->enumerations->add($ne);
        }
        if ($src->enumerations->count() > 0) {
            $field->defaultValue = null;
        }
        if (\in_array($field::typeName(), ['IssueCustomField', 'TimeEntryCustomField', 'ProjectCustomField', 'VersionCustomField'], true)) {
            foreach ($src->roles as $r) {
                $field->roles->add($r);
            }
        }
        if ($field instanceof IssueCustomField && $src instanceof IssueCustomField) {
            foreach ($src->trackers as $t) {
                $field->trackers->add($t);
            }
            foreach ($src->projects as $p) {
                $field->projects->add($p);
            }
        }
    }

    /** @param list<string|int> $ids */
    private function setProjectIds(IssueCustomField $field, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn ($v) => '' !== (string) $v))));
        $db = $this->em->getConnection();
        $db->executeStatement('DELETE FROM custom_fields_projects WHERE custom_field_id = ?', [$field->id]);
        foreach ($ids as $pid) {
            if ($db->fetchOne('SELECT 1 FROM projects WHERE id = ?', [$pid])) {
                $db->executeStatement('INSERT INTO custom_fields_projects (custom_field_id, project_id) VALUES (?, ?)', [$field->id, $pid]);
            }
        }
        $this->em->refresh($field);
    }

    /** handle_multiplicity_change: keeps only the first value of each customized object */
    private function handleMultiplicityChange(CustomField $field): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM custom_values WHERE custom_field_id = ? AND id NOT IN (SELECT MIN(id) FROM custom_values WHERE custom_field_id = ? GROUP BY customized_type, customized_id)',
            [$field->id, $field->id]
        );
    }

    /** @param list<string> $errors */
    private function renderForm(CustomField $field, array $errors = [], ?CustomField $copyFrom = null, int $status = 200): Response
    {
        $type = $field::typeName();
        $projectIds = [];
        if ($field instanceof IssueCustomField) {
            if (null !== $field->id) {
                $projectIds = array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT project_id FROM custom_fields_projects WHERE custom_field_id = ?', [$field->id]));
            } else {
                $projectIds = array_map(static fn ($p) => $p->id, $field->projects->toArray());
            }
        }
        $projects = $this->em->createQuery('SELECT p FROM App\Entity\Project p ORDER BY p.lft')->getResult();
        $projectList = $this->nestedProjects($projects, static fn (Project $p) => '<label><input type="checkbox" name="custom_field[project_ids][]" value="'.$p->id.'"'.(\in_array($p->id, $projectIds, true) ? ' checked="checked"' : '').' /> '.htmlspecialchars($p->name).'</label>');

        return $this->render($field->id ? 'admin/custom_fields/edit.html.twig' : 'admin/custom_fields/new.html.twig', [
            'custom_field' => $field,
            'type' => $type,
            'type_label' => FieldFormats::TABS[$type] ?? 'label_custom_field_plural',
            'format' => $this->formats->info((string) $field->fieldFormat),
            'format_options' => $this->formats->formatsForSelect($type),
            'possible_values_options' => $this->formats->possibleValuesOptions($field),
            'givable_roles' => $this->em->getRepository(Role::class)->findBy(['builtin' => 0], ['position' => 'ASC']),
            'trackers' => $this->em->getRepository(Tracker::class)->findBy([], ['position' => 'ASC']),
            'role_ids' => array_map(static fn ($r) => $r->id, $field->roles->toArray()),
            'tracker_ids' => $field instanceof IssueCustomField ? array_map(static fn ($t) => $t->id, $field->trackers->toArray()) : [],
            'project_nested_list' => $projectList,
            'errors' => $errors,
            'copy_from' => $copyFrom,
            'active_enumerations' => array_values(array_filter($field->enumerations->toArray(), static fn ($e) => $e->active)),
            'version_statuses' => ['open', 'locked', 'closed'],
            'admin_menu_selected' => 'custom_fields',
            'body_css' => 'controller-custom_fields action-'.($field->id ? 'edit' : 'new'),
        ], new Response('', $status));
    }

    /**
     * Port of ApplicationHelper#render_project_nested_lists.
     *
     * @param list<Project> $projects sorted by lft
     */
    private function nestedProjects(array $projects, callable $label): string
    {
        $s = '';
        $ancestors = [];
        $isDescendant = static fn (Project $p, Project $a) => $p->lft > $a->lft && $p->rgt < $a->rgt;
        foreach ($projects as $project) {
            if ([] === $ancestors || $isDescendant($project, end($ancestors))) {
                $s .= "<ul class='projects ".([] === $ancestors ? 'root' : '')."'>\n";
            } else {
                array_pop($ancestors);
                $s .= '</li>';
                while ([] !== $ancestors && !$isDescendant($project, end($ancestors))) {
                    array_pop($ancestors);
                    $s .= "</ul></li>\n";
                }
            }
            $classes = [] === $ancestors ? 'root' : 'child';
            if ($project->isArchived()) {
                $classes .= ' archived';
            }
            $s .= "<li class='$classes'><div class='$classes'>".$label($project)."</div>\n";
            $ancestors[] = $project;
        }
        $s .= str_repeat("</li></ul>\n", \count($ancestors));

        return $s;
    }
}
