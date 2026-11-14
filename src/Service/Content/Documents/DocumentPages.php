<?php

declare(strict_types=1);

namespace App\Service\Content\Documents;

use App\Entity\Attachment;
use App\Entity\Document;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\CustomFieldService;

/**
 * What the documents pages show: the documents of a project grouped by category, date, title or author, and
 * a document with its files and custom fields.
 */
final class DocumentPages
{
    private const array SORTS = ['category', 'date', 'title', 'author'];

    public function __construct(
        private readonly DocumentService $documents,
        private readonly AttachmentService $attachments,
        private readonly CustomFieldService $customFields,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * @param mixed $sortBy the sort_by parameter: category (default), date, title or author
     *
     * @return array<string, mixed>
     */
    public function index(Project $project, mixed $sortBy): array
    {
        $sortBy = \in_array($sortBy, self::SORTS, true) ? (string) $sortBy : 'category';
        $documents = $this->documents->all($project);
        $atts = $this->documents->attachmentsByDocument($documents);
        $groups = [];
        switch ($sortBy) {
            case 'date':
                usort($documents, fn (Document $a, Document $b) => $this->updatedOn($b, $atts) <=> $this->updatedOn($a, $atts));
                foreach ($documents as $d) {
                    $local = $this->updatedOn($d, $atts)?->setTimezone($this->i18n->userTimezone());
                    $key = $local?->format('Y-m-d') ?? '';
                    // format_activity_day
                    $day = $key === $this->i18n->today()->format('Y-m-d') ? ucwords($this->i18n->l('label_today')) : $this->i18n->formatDate($local);
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

        return [
            'project' => $project, 'groups' => $groups, 'sort_by' => $sortBy, 'updated_on' => array_combine(
                array_map(static fn (Document $d) => $d->id, $documents),
                array_map(fn (Document $d) => $this->updatedOn($d, $atts), $documents)
            ) ?: [],
        ];
    }

    /** @return array<string, mixed> */
    public function show(Document $document): array
    {
        return [
            'project' => $document->project, 'document' => $document,
            'attachments' => $this->attachments->forContainer('Document', $document->id),
            'custom_values' => $this->formattedCustomValues($document),
            'can_add_attachment' => $this->auth->allowedTo('add_documents', $document->project) || $this->auth->allowedTo('edit_documents', $document->project),
        ];
    }

    /** @return list<array{name: string, value: string}> */
    private function formattedCustomValues(Document $document): array
    {
        $values = $this->customFields->values('Document', $document->id);
        $out = [];
        foreach ($this->documents->fields() as $f) {
            if (!$f->visible && !$this->currentUser->get()->admin) {
                continue;
            }
            $out[] = ['name' => $f->name, 'value' => $this->customFields->formatValue($f, $values[$f->id] ?? null), 'field' => $f, 'raw' => $values[$f->id] ?? null];
        }

        return $out;
    }

    /**
     * Document#updated_on: last attachment's created_on or created_on
     *
     * @param array<int, list<Attachment>> $atts
     */
    private function updatedOn(Document $d, array $atts): ?\DateTimeImmutable
    {
        $list = $atts[$d->id] ?? [];

        return [] !== $list ? end($list)->createdOn : $d->createdOn;
    }
}
