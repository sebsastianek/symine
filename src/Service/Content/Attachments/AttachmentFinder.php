<?php

declare(strict_types=1);

namespace App\Service\Content\Attachments;

use App\Entity\Attachment;
use App\Service\AttachmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the attachments an action works on: one by its id, or those of a container the user may edit or
 * download together (404 when there is nothing to work on, 403 when the container is not visible).
 */
final class AttachmentFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentService $attachments,
        private readonly AttachmentContainers $containers,
        private readonly AttachmentAccess $access,
    ) {
    }

    /** @param string|null $filename the file name of the URL, which must be the one of the attachment when given */
    public function find(int $id, ?string $filename = null): Attachment
    {
        $a = $this->em->find(Attachment::class, $id);
        if (null === $a || (null !== $filename && '' !== $filename && $filename !== $a->filename)) {
            throw new NotFoundHttpException();
        }

        return $a;
    }

    /**
     * The attachments of an object that the user may edit (attachments#edit_all).
     *
     * @return non-empty-list<Attachment>
     */
    public function editable(string $objectType, int $objectId): array
    {
        $type = $this->containers->type($objectType);
        $atts = $this->attachments->forContainer($type, $objectId);
        if ([] === $atts) {
            $this->containers->mustExist($type, $objectId);
            throw new NotFoundHttpException();
        }
        $this->access->requireVisible($atts[0]);
        $atts = array_values(array_filter($atts, $this->access->isEditable(...)));
        if ([] === $atts) {
            throw new NotFoundHttpException();
        }

        return $atts;
    }

    /**
     * The attachments of an object whose file can be downloaded (attachments#download_all).
     *
     * @return list<Attachment>
     */
    public function downloadable(string $objectType, int $objectId): array
    {
        $type = $this->containers->type($objectType);
        $this->containers->mustExist($type, $objectId);
        $atts = $this->attachments->forContainer($type, $objectId);
        if ([] === $atts) {
            throw new NotFoundHttpException();
        }
        $this->access->requireVisible($atts[0]);

        return array_values(array_filter($atts, fn (Attachment $a) => is_file($this->attachments->fullPath($a))));
    }
}
