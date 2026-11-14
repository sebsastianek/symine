<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Attachment;
use App\Entity\Document;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\Content\Documents\DocumentService;
use App\Service\CustomFieldService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the REST API structures of app/views/documents/index.api.rsb and show.api.rsb.
 */
final class DocumentApiPresenter
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly AttachmentService $attachments,
        private readonly CustomFieldService $customFields,
        private readonly AttachmentApiPresenter $attachmentPresenter,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /**
     * index.api.rsb: a page of the documents of a project.
     *
     * @param list<Document>                 $documents
     * @param array{offset: int, limit: int} $pager
     *
     * @return array<string, mixed>
     */
    public function index(array $documents, int $count, array $pager): array
    {
        return [
            'documents' => array_map(fn (Document $d) => $this->document($d), $documents),
            'total_count' => $count, 'offset' => $pager['offset'], 'limit' => $pager['limit'],
        ];
    }

    /**
     * show.api.rsb: the document, with its files when the "include" parameter asks for attachments.
     *
     * @return array<string, mixed>
     */
    public function shown(Request $request, Document $d): array
    {
        $include = array_map('trim', explode(',', (string) $request->query->get('include', '')));

        return \in_array('attachments', $include, true) ? $this->withAttachments($request, $d) : $this->document($d);
    }

    /**
     * The document and its files (what is returned for a document just created).
     *
     * @return array<string, mixed>
     */
    public function withAttachments(Request $request, Document $d): array
    {
        return $this->document($d) + [
            'attachments' => array_map(fn (Attachment $a) => $this->attachmentPresenter->attributes($request, $a), $this->attachments->forContainer('Document', $d->id)),
        ];
    }

    /** @return array<string, mixed> */
    private function document(Document $d): array
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
        $fields = $this->documents->fields();
        if ([] !== $fields) {
            $values = $this->customFields->values('Document', $d->id);
            $data['custom_fields'] = [];
            foreach ($fields as $f) {
                if (!$f->visible && !$this->currentUser->get()->admin) {
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

        return $data;
    }
}
