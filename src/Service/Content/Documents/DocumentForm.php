<?php

declare(strict_types=1);

namespace App\Service\Content\Documents;

use App\Api\ApiRenderer;
use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\I18n\Localizer;
use App\Security\ApiRequestMatcher;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\CustomFieldService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The document form, submitted by the browser (document[category_id], document[title], document[description],
 * document[custom_field_values][..], attachments[..]) or through the REST API ({"document": {...}}), and the
 * "add files" form of a document.
 */
final class DocumentForm
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly AttachmentService $attachments,
        private readonly CustomFieldService $customFields,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Fills a new document with the document[..] parameters of the URL of the form.
     *
     * @return array<int, mixed> the custom field values to show
     */
    public function prefill(Document $document, Request $request): array
    {
        return $this->assign($document, (array) $request->query->all('document'));
    }

    /** Saves a new document when what was submitted is valid */
    public function create(Document $document, Request $request): DocumentSubmission
    {
        $isApi = ApiRequestMatcher::isApiRequest($request);
        $attrs = $this->params($request);
        $values = $this->assign($document, $attrs);
        $errors = $this->validate($document, $values);
        if ([] !== $errors) {
            return new DocumentSubmission($errors, $values);
        }
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->currentUser->get(), $isApi ? ($attrs['uploads'] ?? null) : null);
        $this->documents->create($document, $values, $atts);

        return new DocumentSubmission([], $values, \count($attErrors));
    }

    /** Saves the changes submitted for a document when they are valid */
    public function update(Document $document, Request $request): DocumentSubmission
    {
        $attrs = $this->params($request);
        $values = $this->assign($document, $attrs);
        $errors = $this->validate($document, $values);
        if ([] !== $errors) {
            return new DocumentSubmission($errors, $values);
        }
        $atts = [];
        if (ApiRequestMatcher::isApiRequest($request)) {
            [$atts] = $this->attachments->fromRequest($request, $this->currentUser->get(), $attrs['uploads'] ?? []);
            $this->attachments->attachTo($atts, 'Document', (int) $document->id);
        }
        $this->documents->update($document, $values, $atts);

        return new DocumentSubmission([], $values);
    }

    /**
     * Adds the files submitted with the "new file" form of a document.
     *
     * @return int number of files that could not be saved
     */
    public function addAttachments(Document $document, Request $request): int
    {
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->currentUser->get());
        $this->documents->addAttachments($document, $atts);

        return \count($attErrors);
    }

    /**
     * @param list<string>           $errors
     * @param array<int, mixed>|null $values the custom field values to show, the saved (or default) ones when null
     *
     * @return array<string, mixed>
     */
    public function view(Document $document, array $errors = [], ?array $values = null): array
    {
        $fields = $this->documents->fields();
        if (null === $values) {
            $values = null === $document->id ? $this->customFields->defaultValues($fields) : $this->customFields->values('Document', $document->id);
        }
        $cf = [];
        foreach ($fields as $f) {
            $cf[] = ['field' => $f, 'value' => $values[$f->id] ?? null, 'options' => $this->customFields->possibleValuesOptions($f, $document->project)];
        }

        return ['document' => $document, 'errors' => $errors, 'categories' => $this->documents->categories(), 'custom_field_values' => $cf];
    }

    /** @return array<string, mixed> */
    private function params(Request $request): array
    {
        if (ApiRequestMatcher::isApiRequest($request)) {
            return (array) (ApiRenderer::payload($request)['document'] ?? []);
        }

        return (array) $request->request->all('document');
    }

    /**
     * safe_attributes 'category_id', 'title', 'description', 'custom_field_values'.
     *
     * @param array<string, mixed> $attrs
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
        $fields = $this->documents->fields();
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

    /**
     * @param array<int, mixed> $values
     *
     * @return list<string>
     */
    private function validate(Document $document, array $values): array
    {
        $errors = [];
        if (!isset($document->category)) {
            $errors[] = $this->i18n->l('field_category').' '.$this->i18n->l('activerecord.errors.messages.blank');
        }
        if ('' === trim($document->title)) {
            $errors[] = $this->i18n->l('field_title').' '.$this->i18n->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($document->title) > 255) {
            $errors[] = $this->i18n->l('field_title').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        foreach ($this->documents->fields() as $f) {
            array_push($errors, ...$this->customFields->validate($f, $values[$f->id] ?? null, $document->project, null === ($document->id ?? null) ? null : ($this->customFields->values('Document', (int) $document->id)[$f->id] ?? null)));
        }

        return $errors;
    }
}
