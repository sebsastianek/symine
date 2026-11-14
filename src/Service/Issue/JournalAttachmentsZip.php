<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Attachment;
use App\Entity\Journal;
use App\Service\AttachmentService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * AttachmentsController#download_all for a journal (attachments/journals/:id/download): a zip of the
 * attachments that were added with the journal.
 */
final class JournalAttachmentsZip
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentService $attachments,
        private readonly Settings $settings,
    ) {
    }

    /**
     * The files added with the journal that still exist.
     *
     * @return list<Attachment>
     */
    public function attachments(Journal $journal): array
    {
        $issue = $journal->issue;
        $ids = [];
        foreach ($journal->details as $d) {
            if ('attachment' === $d->property && null !== $d->value && '' !== $d->value) {
                $ids[] = (int) $d->propKey;
            }
        }
        $atts = [];
        foreach ($ids as $aid) {
            $a = $this->em->find(Attachment::class, $aid);
            if (null !== $a && 'Issue' === $a->containerType && $a->containerId === $issue->id && is_file($this->attachments->fullPath($a))) {
                $atts[] = $a;
            }
        }

        return $atts;
    }

    /** The size, in bytes, the files of an archive may not exceed ("Maximum total size for bulk download" setting) */
    public function maxSize(): int
    {
        return ($this->settings->int('bulk_download_max_size') ?: 102400) * 1024;
    }

    /** @param list<Attachment> $attachments */
    public function isTooBig(array $attachments): bool
    {
        return array_sum(array_map(static fn (Attachment $a) => $a->getFilesize(), $attachments)) > $this->maxSize();
    }

    /**
     * The archive, sent as a download (404 when there is no file to put in it).
     *
     * @param list<Attachment> $attachments
     */
    public function download(Journal $journal, array $attachments): Response
    {
        if ([] === $attachments || !class_exists(\ZipArchive::class)) {
            throw new NotFoundHttpException();
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'symine-zip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $names = [];
        foreach ($attachments as $a) {
            // rename the file if a file with the same name already exists in the archive
            $name = $a->filename;
            $base = pathinfo($name, \PATHINFO_FILENAME);
            $ext = pathinfo($name, \PATHINFO_EXTENSION);
            $n = 1;
            while (isset($names[$name])) {
                $name = $base.'('.$n++.')'.('' !== $ext ? '.'.$ext : '');
            }
            $names[$name] = true;
            $zip->addFile($this->attachments->fullPath($a), $name);
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        $fileName = 'journal-'.$journal->id.'-attachments.zip';

        return new Response($data, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$fileName.'"']);
    }
}
