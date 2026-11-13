<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Attachment;
use App\Entity\CustomField;
use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\DocumentCustomField;
use App\Entity\Project;
use App\Event\ContentEvent;
use App\Service\AttachmentService;
use App\Service\CustomFieldService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of DocumentsController (documents_controller.rb). */
final class DocumentsController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly AttachmentService $attachments,
        private readonly CustomFieldService $customFields,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    #[Route('/projects/{project_id}/documents.{_format}', name: 'project_documents', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function index(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_documents', $project);

        if ($this->isApi($request)) {
            $count = (int) $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\Document d WHERE d.project = :p')->setParameter('p', $project->id)->getSingleScalarResult();
            $pager = $this->paginate($request, $count);
            $documents = $this->em->createQuery('SELECT d FROM App\Entity\Document d WHERE d.project = :p ORDER BY d.createdOn DESC, d.id DESC')
                ->setParameter('p', $project->id)->setFirstResult($pager['offset'])->setMaxResults($pager['limit'])->getResult();

            return $this->api->render($request, [
                'documents' => array_map(fn (Document $d) => $this->documentApi($request, $d, false), $documents),
                'total_count' => $count, 'offset' => $pager['offset'], 'limit' => $pager['limit'],
            ]);
        }

        $sortBy = \in_array($request->query->get('sort_by'), ['category', 'date', 'title', 'author'], true) ? (string) $request->query->get('sort_by') : 'category';
        $documents = $this->em->getRepository(Document::class)->findBy(['project' => $project], ['id' => 'ASC']);
        $atts = $this->attachmentsByDocument($documents);
        $groups = [];
        switch ($sortBy) {
            case 'date':
                usort($documents, fn (Document $a, Document $b) => $this->updatedOn($b, $atts) <=> $this->updatedOn($a, $atts));
                foreach ($documents as $d) {
                    $local = $this->updatedOn($d, $atts)?->setTimezone($this->i18n->userTimezone());
                    $key = $local?->format('Y-m-d') ?? '';
                    // format_activity_day
                    $day = $key === $this->i18n->today()->format('Y-m-d') ? ucwords($this->l('label_today')) : $this->i18n->formatDate($local);
                    $groups[$key] ??= ['name' => $day, 'documents' => []];
                    $groups[$key]['documents'][] = $d;
                }
                krsort($groups);
                break;
            case 'title':
                foreach ($documents as $d) {
                    $key = mb_strtoupper(mb_substr($d->title, 0, 1));
                    $groups[$key] ??= ['name' => $key, 'documents' => []];
                    $groups[$key]['documents'][] = $d;
                }
                ksort($groups, \SORT_STRING);
                break;
            case 'author':
                foreach ($documents as $d) {
                    $list = $atts[$d->id] ?? [];
                    if ([] === $list) {
                        continue;
                    }
                    $author = end($list)->author;
                    $key = $author->getName();
                    $groups[$key] ??= ['name' => $key, 'documents' => []];
                    $groups[$key]['documents'][] = $d;
                }
                ksort($groups, \SORT_STRING | \SORT_FLAG_CASE);
                break;
            default:
                foreach ($documents as $d) {
                    $key = \sprintf('%08d-%08d', $d->category->position ?? 0, $d->category->id);
                    $groups[$key] ??= ['name' => $d->category->name, 'documents' => []];
                    $groups[$key]['documents'][] = $d;
                }
                ksort($groups, \SORT_STRING);
        }

        $document = $this->newDocument($project);

        return $this->render('documents/index.html.twig', [
            'project' => $project, 'groups' => $groups, 'sort_by' => $sortBy, 'updated_on' => array_combine(
                array_map(static fn (Document $d) => $d->id, $documents),
                array_map(fn (Document $d) => $this->updatedOn($d, $atts), $documents)
            ) ?: [],
        ] + $this->formVars($document, []) + [
            'main_menu_selected' => 'documents', 'page_title' => $this->l('label_document_plural'),
            'body_css' => 'controller-documents action-index',
        ]);
    }

    #[Route('/projects/{project_id}/documents/new', name: 'document_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('add_documents', $project);
        $document = $this->newDocument($project);
        $values = $this->assign($document, (array) $request->query->all('document'));

        return $this->render('documents/new.html.twig', ['project' => $project] + $this->formVars($document, [], $values) + [
            'main_menu_selected' => 'documents', 'page_title' => $this->l('label_document_new'),
            'body_css' => 'controller-documents action-new',
        ]);
    }

    #[Route('/projects/{project_id}/documents.{_format}', name: 'document_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('add_documents', $project);
        $isApi = $this->isApi($request);
        $attrs = $this->documentParams($request);
        $document = $this->newDocument($project);
        $values = $this->assign($document, $attrs);
        $errors = $this->validate($document, $values);
        if ([] !== $errors) {
            if ($isApi) {
                return $this->api->errors($request, $errors);
            }

            return $this->render('documents/new.html.twig', ['project' => $project] + $this->formVars($document, $errors, $values) + [
                'main_menu_selected' => 'documents', 'page_title' => $this->l('label_document_new'),
                'body_css' => 'controller-documents action-new',
            ], new Response(null, 422));
        }
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->user(), $isApi ? ($attrs['uploads'] ?? null) : null);
        $this->em->persist($document);
        $this->em->flush();
        $this->saveCustomValues($document, $values);
        $this->attachments->attachTo($atts, 'Document', (int) $document->id);
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('document', 'created', $document, $this->user(), ['attachments' => $atts]));
        if ($isApi) {
            $response = $this->api->render($request, ['document' => $this->documentApi($request, $document, true, true)], 201);
            $response->headers->set('Location', $this->generateUrl('document_show', ['id' => $document->id], 0));

            return $response;
        }
        $this->attachmentWarning($attErrors);
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('project_documents', ['project_id' => $project->identifier]);
    }

    #[Route('/documents/{id}.{_format}', name: 'document_show', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function show(Request $request, int $id): Response
    {
        $document = $this->findDocument($id);
        $this->authorize('view_documents', $document->project);
        if ($this->isApi($request)) {
            $include = array_map('trim', explode(',', (string) $request->query->get('include', '')));

            return $this->api->render($request, ['document' => $this->documentApi($request, $document, true, \in_array('attachments', $include, true))]);
        }

        return $this->render('documents/show.html.twig', [
            'project' => $document->project, 'document' => $document,
            'attachments' => $this->attachments->forContainer('Document', $document->id),
            'custom_values' => $this->formattedCustomValues($document),
            'can_add_attachment' => $this->auth->allowedTo('add_documents', $document->project) || $this->auth->allowedTo('edit_documents', $document->project),
            'main_menu_selected' => 'documents', 'page_title' => $document->title,
            'body_css' => 'controller-documents action-show',
        ]);
    }

    #[Route('/documents/{id}/edit', name: 'document_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $document = $this->findDocument($id);
        $this->authorize('edit_documents', $document->project);

        return $this->render('documents/edit.html.twig', ['project' => $document->project] + $this->formVars($document, []) + [
            'main_menu_selected' => 'documents', 'page_title' => $document->title,
            'body_css' => 'controller-documents action-edit',
        ]);
    }

    #[Route('/documents/{id}.{_format}', name: 'document_update', methods: ['PUT', 'PATCH'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function update(Request $request, int $id): Response
    {
        $document = $this->findDocument($id);
        $this->authorize('edit_documents', $document->project);
        $isApi = $this->isApi($request);
        $attrs = $this->documentParams($request);
        $values = $this->assign($document, $attrs);
        $errors = $this->validate($document, $values);
        if ([] !== $errors) {
            if ($isApi) {
                return $this->api->errors($request, $errors);
            }

            return $this->render('documents/edit.html.twig', ['project' => $document->project] + $this->formVars($document, $errors, $values) + [
                'main_menu_selected' => 'documents', 'page_title' => $document->title,
                'body_css' => 'controller-documents action-edit',
            ], new Response(null, 422));
        }
        $atts = [];
        if ($isApi) {
            [$atts] = $this->attachments->fromRequest($request, $this->user(), $attrs['uploads'] ?? []);
            $this->attachments->attachTo($atts, 'Document', (int) $document->id);
        }
        $this->saveCustomValues($document, $values);
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('document', 'updated', $document, $this->user(), ['attachments' => $atts]));
        if ($isApi) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('document_show', ['id' => $document->id]);
    }

    #[Route('/documents/{id}.{_format}', name: 'document_destroy', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function destroy(Request $request, int $id): Response
    {
        $document = $this->findDocument($id);
        $project = $document->project;
        $this->authorize('delete_documents', $project);
        $this->events->dispatch(new ContentEvent('document', 'deleted', $document, $this->user()));
        $this->attachments->deleteForContainer('Document', (int) $document->id);
        $this->customFields->deleteValues('Document', (int) $document->id);
        $this->em->remove($document);
        $this->em->flush();
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');

        return $this->redirectToRoute('project_documents', ['project_id' => $project->identifier]);
    }

    #[Route('/documents/{id}/add_attachment', name: 'document_add_attachment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addAttachment(Request $request, int $id): Response
    {
        $document = $this->findDocument($id);
        if (!$this->auth->allowedTo('add_documents', $document->project) && !$this->auth->allowedTo('edit_documents', $document->project)) {
            $this->deny();
        }
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->user());
        $this->attachments->attachTo($atts, 'Document', (int) $document->id);
        $this->em->flush();
        $this->attachmentWarning($attErrors);
        if ([] !== $atts) {
            $this->events->dispatch(new ContentEvent('attachment', 'created', $atts[0], $this->user(), ['container' => $document, 'attachments' => $atts]));
        }

        return $this->redirectToRoute('document_show', ['id' => $document->id]);
    }

    // --- helpers -----------------------------------------------------------------

    private function findDocument(int $id): Document
    {
        $document = $this->em->find(Document::class, $id);
        if (null === $document) {
            throw $this->createNotFoundException();
        }
        $this->findProject((int) $document->project->id);

        return $document;
    }

    private function newDocument(Project $project): Document
    {
        $d = new Document();
        $d->project = $project;
        $default = $this->em->getRepository(DocumentCategory::class)->findOneBy(['isDefault' => true, 'project' => null]);
        if (null !== $default) {
            $d->category = $default;
        }

        return $d;
    }

    /** @return list<DocumentCategory> DocumentCategory.active */
    private function categories(): array
    {
        return $this->em->getRepository(DocumentCategory::class)->findBy(['active' => true, 'project' => null], ['position' => 'ASC']);
    }

    /** @return list<CustomField> */
    private function documentFields(): array
    {
        return $this->customFields->fieldsOf(DocumentCustomField::class);
    }

    /** @return array<string, mixed> */
    private function formVars(Document $document, array $errors, ?array $values = null): array
    {
        $fields = $this->documentFields();
        if (null === $values) {
            $values = null === $document->id ? $this->customFields->defaultValues($fields) : $this->customFields->values('Document', $document->id);
        }
        $cf = [];
        foreach ($fields as $f) {
            $cf[] = ['field' => $f, 'value' => $values[$f->id] ?? null, 'options' => $this->customFields->possibleValuesOptions($f, $document->project)];
        }

        return ['document' => $document, 'errors' => $errors, 'categories' => $this->categories(), 'custom_field_values' => $cf];
    }

    /** @return array<string, mixed> */
    private function documentParams(Request $request): array
    {
        if ($this->isApi($request)) {
            return (array) (ApiRenderer::payload($request)['document'] ?? []);
        }

        return (array) $request->request->all('document');
    }

    /**
     * safe_attributes 'category_id', 'title', 'description', 'custom_field_values'.
     *
     * @return array<int, mixed> custom field values (merged with current ones)
     */
    private function assign(Document $document, array $attrs): array
    {
        if (isset($attrs['category_id']) && !\is_array($attrs['category_id'])) {
            $cat = $this->em->find(DocumentCategory::class, (int) $attrs['category_id']);
            if ($cat instanceof DocumentCategory) {
                $document->category = $cat;
            }
        }
        if (\array_key_exists('title', $attrs) && !\is_array($attrs['title'])) {
            $document->title = (string) $attrs['title'];
        }
        if (\array_key_exists('description', $attrs) && !\is_array($attrs['description'])) {
            $document->description = null === $attrs['description'] ? null : (string) $attrs['description'];
        }
        $fields = $this->documentFields();
        $values = null === $document->id ? $this->customFields->defaultValues($fields) : $this->customFields->values('Document', $document->id);
        if (isset($attrs['custom_field_values']) && \is_array($attrs['custom_field_values'])) {
            foreach ($attrs['custom_field_values'] as $fid => $v) {
                $values[(int) $fid] = $v;
            }
        }
        if (isset($attrs['custom_fields']) && \is_array($attrs['custom_fields'])) {
            foreach ($attrs['custom_fields'] as $cf) {
                if (\is_array($cf) && isset($cf['id'])) {
                    $values[(int) $cf['id']] = $cf['value'] ?? null;
                }
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function validate(Document $document, array $values): array
    {
        $errors = [];
        if (!isset($document->category)) {
            $errors[] = $this->l('field_category').' '.$this->l('activerecord.errors.messages.blank');
        }
        if ('' === trim($document->title)) {
            $errors[] = $this->l('field_title').' '.$this->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($document->title) > 255) {
            $errors[] = $this->l('field_title').' '.$this->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        foreach ($this->documentFields() as $f) {
            array_push($errors, ...$this->customFields->validate($f, $values[$f->id] ?? null, $document->project));
        }

        return $errors;
    }

    private function saveCustomValues(Document $document, array $values): void
    {
        $fields = $this->documentFields();
        if ([] !== $fields) {
            $this->customFields->saveValues('Document', (int) $document->id, $fields, $values);
        }
    }

    /** @return list<array{name: string, value: string}> */
    private function formattedCustomValues(Document $document): array
    {
        $values = $this->customFields->values('Document', $document->id);
        $out = [];
        foreach ($this->documentFields() as $f) {
            if (!$f->visible && !$this->user()->admin) {
                continue;
            }
            $out[] = ['name' => $f->name, 'value' => $this->customFields->formatValue($f, $values[$f->id] ?? null)];
        }

        return $out;
    }

    /**
     * @param list<Document> $documents
     *
     * @return array<int, list<Attachment>>
     */
    private function attachmentsByDocument(array $documents): array
    {
        $ids = array_map(static fn (Document $d) => $d->id, $documents);
        if ([] === $ids) {
            return [];
        }
        $out = [];
        $rows = $this->em->createQuery("SELECT a FROM App\Entity\Attachment a WHERE a.containerType = 'Document' AND a.containerId IN (:ids) ORDER BY a.createdOn ASC, a.id ASC")
            ->setParameter('ids', $ids)->getResult();
        foreach ($rows as $a) {
            $out[$a->containerId][] = $a;
        }

        return $out;
    }

    /** Document#updated_on: last attachment's created_on or created_on */
    private function updatedOn(Document $d, array $atts): ?\DateTimeImmutable
    {
        $list = $atts[$d->id] ?? [];

        return [] !== $list ? end($list)->createdOn : $d->createdOn;
    }

    /** @param list<string> $errors */
    private function attachmentWarning(array $errors): void
    {
        if ([] !== $errors) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => \count($errors)]));
        }
    }

    /** @return array<string, mixed> */
    private function documentApi(Request $request, Document $d, bool $show, bool $withAttachments = false): array
    {
        $atts = $this->attachments->forContainer('Document', $d->id);
        $updated = [] !== $atts ? end($atts)->createdOn : $d->createdOn;
        $data = [
            'id' => $d->id,
            'project' => ['id' => $d->project->id, 'name' => $d->project->name],
            'category' => ['id' => $d->category->id, 'name' => $d->category->name],
            'title' => $d->title,
            'description' => $d->description,
            'created_on' => ApiRenderer::time($d->createdOn),
            'updated_on' => ApiRenderer::time($updated),
        ];
        $fields = $this->documentFields();
        if ([] !== $fields) {
            $values = $this->customFields->values('Document', $d->id);
            $data['custom_fields'] = [];
            foreach ($fields as $f) {
                if (!$f->visible && !$this->user()->admin) {
                    continue;
                }
                $item = ['id' => $f->id, 'name' => $f->name];
                if ($f->isMultiple()) {
                    $item['multiple'] = true;
                }
                $item['value'] = $values[$f->id] ?? ($f->isMultiple() ? [] : null);
                $data['custom_fields'][] = $item;
            }
        }
        if ($show && $withAttachments) {
            $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();
            $data['attachments'] = array_map(static function (Attachment $a) use ($base) {
                $x = [
                    'id' => $a->id, 'filename' => $a->filename, 'filesize' => $a->getFilesize(), 'content_type' => $a->contentType,
                    'description' => $a->description, 'content_url' => $base.'/attachments/download/'.$a->id.'/'.rawurlencode($a->filename),
                ];
                if ($a->isImage()) {
                    $x['thumbnail_url'] = $base.'/attachments/thumbnail/'.$a->id;
                }
                $x['author'] = ['id' => $a->author->id, 'name' => $a->author->getName()];
                $x['created_on'] = ApiRenderer::time($a->createdOn);

                return $x;
            }, $atts);
        }

        return $data;
    }
}
