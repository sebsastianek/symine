<?php

declare(strict_types=1);

namespace App\Service\Content\Documents;

use App\Entity\Attachment;
use App\Entity\CustomField;
use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\DocumentCustomField;
use App\Entity\Project;
use App\Event\ContentEvent;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\CustomFieldService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The Document model of Redmine: the documents of a project, their categories and custom fields, and what
 * happens when a document is created, updated, deleted or given files (custom values, attachments, notifications).
 */
final class DocumentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentService $attachments,
        private readonly CustomFieldService $customFields,
        private readonly CurrentUser $currentUser,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /** A blank document of the project, in the default category */
    public function build(Project $project): Document
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
    public function categories(): array
    {
        return $this->em->getRepository(DocumentCategory::class)->findBy(['active' => true, 'project' => null], ['position' => 'ASC']);
    }

    /** @return list<CustomField> */
    public function fields(): array
    {
        return $this->customFields->fieldsOf(DocumentCustomField::class);
    }

    public function count(Project $project): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\Document d WHERE d.project = :p')->setParameter('p', $project->id)->getSingleScalarResult();
    }

    /** @return list<Document> latest first */
    public function page(Project $project, int $offset, int $limit): array
    {
        return $this->em->createQuery('SELECT d FROM App\Entity\Document d WHERE d.project = :p ORDER BY d.createdOn DESC, d.id DESC')
            ->setParameter('p', $project->id)->setFirstResult($offset)->setMaxResults($limit)->getResult();
    }

    /** @return list<Document> */
    public function all(Project $project): array
    {
        return $this->em->getRepository(Document::class)->findBy(['project' => $project], ['id' => 'ASC']);
    }

    /**
     * @param list<Document> $documents
     *
     * @return array<int, list<Attachment>> document id => its files, oldest first
     */
    public function attachmentsByDocument(array $documents): array
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

    /**
     * @param array<int, mixed> $customFieldValues
     * @param list<Attachment>  $attachments
     */
    public function create(Document $document, array $customFieldValues, array $attachments): void
    {
        $this->em->persist($document);
        $this->em->flush();
        $this->saveCustomValues($document, $customFieldValues);
        $this->attachments->attachTo($attachments, 'Document', (int) $document->id);
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('document', 'created', $document, $this->currentUser->get(), ['attachments' => $attachments]));
    }

    /**
     * @param array<int, mixed> $customFieldValues
     * @param list<Attachment>  $attachments       the files added by this update
     */
    public function update(Document $document, array $customFieldValues, array $attachments): void
    {
        $this->saveCustomValues($document, $customFieldValues);
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('document', 'updated', $document, $this->currentUser->get(), ['attachments' => $attachments]));
    }

    public function destroy(Document $document): void
    {
        $this->events->dispatch(new ContentEvent('document', 'deleted', $document, $this->currentUser->get()));
        $this->attachments->deleteForContainer('Document', (int) $document->id);
        $this->customFields->deleteValues('Document', (int) $document->id);
        $this->em->remove($document);
        $this->em->flush();
    }

    /**
     * DocumentsController#add_attachment: files are added to the document, which is notified like added files.
     *
     * @param list<Attachment> $attachments
     */
    public function addAttachments(Document $document, array $attachments): void
    {
        $this->attachments->attachTo($attachments, 'Document', (int) $document->id);
        $this->em->flush();
        if ([] !== $attachments) {
            $this->events->dispatch(new ContentEvent('attachment', 'created', $attachments[0], $this->currentUser->get(), ['container' => $document, 'attachments' => $attachments]));
        }
    }

    /** @param array<int, mixed> $values */
    private function saveCustomValues(Document $document, array $values): void
    {
        $fields = $this->fields();
        if ([] !== $fields) {
            $this->customFields->saveValues('Document', (int) $document->id, $fields, $values);
        }
    }
}
