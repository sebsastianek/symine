<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Entity\Attachment;
use App\Service\AttachmentService;

/**
 * Images of formatted text in PDF exports (RDMPdfEncoding.attach + ITCPDF#get_image_filename):
 * an <img> is embedded when its src is the filename of one of the object's image attachments
 * (latest one wins), /attachments/download/:id/... or /attachments/thumbnail/:id/:size.
 * Other images (remote URLs, unknown files) are dropped: no file or network access from a PDF.
 */
final class PdfImageResolver
{
    public function __construct(private readonly AttachmentService $attachments)
    {
    }

    /** @param list<Attachment> $attachments */
    public function embed(string $html, array $attachments): string
    {
        return preg_replace_callback('/<img\b[^>]*>/i', function (array $m) use ($attachments) {
            if (!preg_match('/\ssrc\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $m[0], $sm)) {
                return '';
            }
            $src = html_entity_decode('' !== ($sm[2] ?? '') ? $sm[2] : ($sm[3] ?? ''), \ENT_QUOTES);
            $file = $this->imageFile($src, $attachments);
            if (null === $file) {
                return '';
            }
            $data = @file_get_contents($file);
            if (false === $data || '' === $data) {
                return '';
            }

            return str_replace($sm[0], ' src="@'.base64_encode($data).'"', $m[0]);
        }, $html) ?? $html;
    }

    public static function stripImages(string $html): string
    {
        return preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;
    }

    /** @param list<Attachment> $attachments */
    public function imageFile(string $src, array $attachments): ?string
    {
        $found = null;
        $path = (string) (parse_url($src, \PHP_URL_PATH) ?? '');
        if (preg_match('/^[^\/"]+\.(gif|jpg|jpe|jpeg|png|webp)$/i', rawurldecode($src))) {
            $name = rawurldecode($src);
            foreach ($attachments as $a) {
                if (0 === strcasecmp($a->filename, $name) && (null === $found || ($a->createdOn ?? new \DateTimeImmutable('@0')) > ($found->createdOn ?? new \DateTimeImmutable('@0')))) {
                    $found = $a;
                }
            }
        } elseif (preg_match('#/attachments/(?:download|thumbnail)/(\d+)(?:/|$)#', $path, $m)) {
            foreach ($attachments as $a) {
                if ((string) $a->id === $m[1]) {
                    $found = $a;
                    break;
                }
            }
            if (null !== $found && !$found->isImage()) {
                $found = null;
            }
        }
        if (null === $found) {
            return null;
        }
        $file = $this->attachments->fullPath($found);

        return is_file($file) && is_readable($file) ? $file : null;
    }
}
