<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Controller\AbstractController;
use App\Entity\Enumeration;
use App\Service\Admin\EnumerationManager;
use App\Service\CustomFieldService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's EnumerationsController (issue priorities, time entry activities, document categories) */
final class EnumerationsController extends AbstractController
{
    public function __construct(
        private readonly EnumerationManager $manager,
        private readonly CustomFieldService $customFields,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/enumerations', name: 'enumerations_index', methods: ['GET'])]
    #[Route('/enumerations/{type}', name: 'enumerations_index_type', requirements: ['type' => 'issue_priorities|time_entry_activities|document_categories'], methods: ['GET'], priority: -10)]
    public function index(): Response
    {
        $this->requireAdmin();
        $sections = [];
        foreach (EnumerationManager::CLASSES as $type => $class) {
            $sections[] = [
                'type' => $type,
                'option_name' => EnumerationManager::OPTION_NAMES[$type],
                'enumerations' => $this->manager->shared($type),
            ];
        }

        return $this->render('admin/enumerations/index.html.twig', [
            'sections' => $sections,
            'admin_menu_selected' => 'enumerations',
            'page_title' => $this->l('label_enumerations'),
            'body_css' => 'controller-enumerations action-index',
        ]);
    }

    /** GET /enumerations/:type.json (public API, like require_admin_or_api_request) */
    #[Route('/enumerations/{type}.{_format}', name: 'enumerations_index_api', requirements: ['type' => '[a-z_]+', '_format' => 'json|xml'], methods: ['GET'])]
    public function apiIndex(Request $request, string $type): Response
    {
        $class = EnumerationManager::typeFromPlural($type);
        if (null === $class) {
            throw $this->createNotFoundException();
        }
        $enums = $this->manager->shared($class);
        $fields = $this->manager->availableCustomFields($class);
        $values = $this->customFields->valuesFor('Enumeration', array_map(static fn ($e) => $e->id, $enums));
        $items = [];
        foreach ($enums as $e) {
            $item = ['id' => $e->id, 'name' => $e->name, 'is_default' => $e->isDefault, 'active' => $e->active];
            $cfs = [];
            foreach ($fields as $cf) {
                if (!$cf->visible) {
                    continue;
                }
                $attrs = ['id' => $cf->id, 'name' => $cf->name];
                if ($cf->isMultiple()) {
                    $attrs['multiple'] = true;
                }
                $v = $values[$e->id][$cf->id] ?? ($cf->isMultiple() ? [] : null);
                $attrs['value'] = \is_array($v) ? array_values(array_filter($v, static fn ($x) => '' !== (string) $x)) : $v;
                $cfs[] = $attrs;
            }
            if ([] !== $cfs) {
                $item['custom_fields'] = $cfs;
            }
            $items[] = $item;
        }

        return $this->api->render($request, [$type => $items]);
    }

    #[Route('/enumerations/new', name: 'enumeration_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();
        $type = (string) ($request->query->all('enumeration')['type'] ?? $request->query->get('type', ''));
        if (!isset(EnumerationManager::CLASSES[$type])) {
            throw $this->createNotFoundException();
        }
        $class = EnumerationManager::CLASSES[$type];
        $e = new $class();

        return $this->renderForm($e);
    }

    #[Route('/enumerations', name: 'enumeration_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $params = $request->request->all('enumeration');
        $type = (string) ($params['type'] ?? $request->query->get('type', ''));
        if (!isset(EnumerationManager::CLASSES[$type])) {
            throw $this->createNotFoundException();
        }
        $class = EnumerationManager::CLASSES[$type];
        $e = new $class();
        $this->manager->assign($e, $params);
        $errors = $this->manager->validate($e);
        $cfValues = (array) ($params['custom_field_values'] ?? []);
        $errors = [...$errors, ...$this->validateCustomValues($type, $cfValues)];
        if ([] !== $errors) {
            return $this->renderForm($e, $errors, $cfValues, 422);
        }
        $this->manager->create($e);
        $this->saveCustomValues($e, $cfValues);
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('enumerations_index');
    }

    #[Route('/enumerations/{id}/edit', name: 'enumeration_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();

        return $this->renderForm($this->find($id));
    }

    #[Route('/enumerations/{id}', name: 'enumeration_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH', 'POST'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $e = $this->find($id);
        $params = $request->request->all('enumeration');
        $before = ['position' => $e->position, 'active' => $e->active, 'isDefault' => $e->isDefault, 'name' => $e->name];
        $this->manager->assign($e, $params);
        $errors = $this->manager->validate($e);
        $cfValues = (array) ($params['custom_field_values'] ?? []);
        $errors = [...$errors, ...$this->validateCustomValues($e::typeName(), $cfValues)];
        if ([] !== $errors) {
            if ($request->isXmlHttpRequest()) {
                return new Response('', 422);
            }

            return $this->renderForm($e, $errors, $cfValues, 422);
        }
        $this->manager->update($e, $before);
        $this->saveCustomValues($e, $cfValues);
        if ($request->isXmlHttpRequest()) {
            return new Response('', 200);
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('enumerations_index');
    }

    #[Route('/enumerations/{id}', name: 'enumeration_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $e = $this->find($id);
        $count = $this->manager->objectsCount($e);
        if (0 === $count) {
            $this->manager->destroy($e);

            return $this->redirectToRoute('enumerations_index');
        }
        $reassignId = (string) $request->request->get('reassign_to_id', $request->query->get('reassign_to_id', ''));
        if ('' !== $reassignId) {
            $to = $this->em->find($e::class, (int) $reassignId);
            if (null !== $to && $to->id !== $e->id) {
                $this->manager->destroy($e, $to);

                return $this->redirectToRoute('enumerations_index');
            }
        }
        $others = array_values(array_filter($this->manager->shared($e::typeName()), static fn ($x) => $x->id !== $e->id));

        return $this->render('admin/enumerations/destroy.html.twig', [
            'enumeration' => $e,
            'option_name' => EnumerationManager::OPTION_NAMES[$e::typeName()],
            'objects_count' => $count,
            'enumerations' => $others,
            'admin_menu_selected' => 'enumerations',
            'body_css' => 'controller-enumerations action-destroy',
        ]);
    }

    private function find(int $id): Enumeration
    {
        $e = $this->em->find(Enumeration::class, $id);
        if (null === $e) {
            throw $this->createNotFoundException();
        }

        return $e;
    }

    /** @return list<string> */
    private function validateCustomValues(string $type, array $values): array
    {
        $errors = [];
        foreach ($this->manager->availableCustomFields($type) as $cf) {
            if (\array_key_exists($cf->id, $values) || \array_key_exists((string) $cf->id, $values)) {
                $errors = [...$errors, ...$this->customFields->validate($cf, $values[$cf->id] ?? null)];
            }
        }

        return $errors;
    }

    private function saveCustomValues(Enumeration $e, array $values): void
    {
        $fields = $this->manager->availableCustomFields($e::typeName());
        if ([] === $fields) {
            return;
        }
        $this->customFields->saveValues('Enumeration', $e->id, $fields, $values);
        $this->em->flush();
    }

    /** @param list<string> $errors */
    private function renderForm(Enumeration $e, array $errors = [], ?array $postedValues = null, int $status = 200): Response
    {
        $type = $e::typeName();
        $fields = $this->manager->availableCustomFields($type);
        $values = null !== $postedValues ? $postedValues : (null !== $e->id ? $this->customFields->values('Enumeration', $e->id) : $this->customFields->defaultValues($fields));
        $cfs = [];
        foreach ($fields as $cf) {
            $cfs[] = [
                'field' => $cf,
                'value' => $values[$cf->id] ?? ($cf->isMultiple() ? [] : ''),
                'options' => $this->customFields->possibleValuesOptions($cf),
            ];
        }

        return $this->render($e->id ? 'admin/enumerations/edit.html.twig' : 'admin/enumerations/new.html.twig', [
            'enumeration' => $e,
            'type' => $type,
            'option_name' => EnumerationManager::OPTION_NAMES[$type],
            'custom_field_values' => $cfs,
            'errors' => $errors,
            'admin_menu_selected' => 'enumerations',
            'body_css' => 'controller-enumerations action-'.($e->id ? 'edit' : 'new'),
        ], new Response('', $status));
    }
}
