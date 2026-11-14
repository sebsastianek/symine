<?php

declare(strict_types=1);

namespace App\Service\Content\Attachments;

use App\Entity\Attachment;
use App\Pdf\PdfSupport;
use App\Service\AttachmentService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds the HTTP responses that send the files of attachments: a download, the thumbnail of an image and
 * the zip archive of all the files of an object.
 */
final class AttachmentResponder
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly AttachmentContainers $containers,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** attachments#download: the file itself (a PDF may be asked inline); downloads of project files are counted */
    public function download(Request $request, Attachment $a): Response
    {
        if (!is_file($this->attachments->fullPath($a))) {
            throw new NotFoundHttpException();
        }
        if (\in_array($a->containerType, ['Version', 'Project'], true)) {
            ++$a->downloads;
            $this->em->flush();
        }
        $inline = 'inline' === $request->query->get('disposition') && $a->isPdf();
        $response = new BinaryFileResponse($this->attachments->fullPath($a));
        $response->headers->set('Content-Type', $inline ? 'application/pdf' : $this->detectContentType($a));
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $a->filename, preg_replace('/[^\x20-\x7e]/', '_', $a->filename) ?: 'file');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setEtag((string) $a->digest);
        $response->isNotModified($request);

        return $response;
    }

    /** attachments#thumbnail: the image scaled down to the size asked (the thumbnails_size setting by default) */
    public function thumbnail(Attachment $a, ?int $size = null): Response
    {
        $path = $this->attachments->fullPath($a);
        if (!$a->isImage() || !is_file($path)) {
            throw new NotFoundHttpException();
        }
        $size = max(1, min(800, $size ?? ($this->settings->int('thumbnails_size') ?: 100)));
        $data = null;
        if (\function_exists('imagecreatefromstring') && !preg_match('/\.svg$/i', $a->filename)) {
            $img = @imagecreatefromstring((string) file_get_contents($path));
            if (false !== $img) {
                $w = imagesx($img);
                $h = imagesy($img);
                $ratio = min(1, $size / max($w, $h));
                $thumb = imagescale($img, max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)));
                if (false !== $thumb) {
                    ob_start();
                    imagepng($thumb);
                    $data = (string) ob_get_clean();
                }
            }
        }
        if (null === $data) {
            $response = new BinaryFileResponse($path);
            $response->headers->set('Content-Type', $this->detectContentType($a, true));
        } else {
            $response = new Response($data, 200, ['Content-Type' => 'image/png']);
        }
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $a->filename, preg_replace('/[^\x20-\x7e]/', '_', $a->filename) ?: 'file'));
        $response->setEtag($a->digest.'-'.$size);

        return $response;
    }

    /**
     * Why the files cannot be downloaded together: the maximum total size, for humans, when they exceed it
     * (the bulk_download_max_size setting); null when they can.
     *
     * @param list<Attachment> $atts
     */
    public function bulkDownloadRefusal(array $atts): ?string
    {
        $max = ($this->settings->int('bulk_download_max_size') ?: 102400) * 1024;
        if (array_sum(array_map(static fn (Attachment $a) => $a->getFilesize(), $atts)) > $max) {
            return PdfSupport::humanSize($max);
        }

        return null;
    }

    /**
     * attachments#download_all: a zip of the files (those sharing a name are numbered).
     *
     * @param list<Attachment> $atts
     */
    public function archive(array $atts, string $objectType, int $objectId): Response
    {
        if (!class_exists(\ZipArchive::class) || [] === $atts) {
            throw new NotFoundHttpException();
        }
        $tmp = tempnam(sys_get_temp_dir(), 'symine-zip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $names = [];
        foreach ($atts as $a) {
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
        $fileName = strtolower($this->containers->type($objectType)).'-'.$objectId.'-attachments.zip';

        return new Response($data, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$fileName.'"']);
    }

    private function detectContentType(Attachment $a, bool $thumb = false): string
    {
        $type = (string) $a->contentType;
        if ('' === $type || 'application/octet-stream' === $type) {
            $type = self::mimeOf($a->filename) ?? 'application/octet-stream';
        }
        if ($thumb && !str_starts_with($type, 'image/')) {
            $type = 'image/png';
        }

        return $type;
    }

    private static function mimeOf(string $filename): ?string
    {
        return match (strtolower(pathinfo($filename, \PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'gif' => 'image/gif', 'jpg', 'jpeg', 'jpe' => 'image/jpeg', 'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'txt', 'log' => 'text/plain', 'pdf' => 'application/pdf', 'zip' => 'application/zip', 'html', 'htm' => 'text/html', 'css' => 'text/css',
            'js' => 'application/javascript', 'json' => 'application/json', 'xml' => 'application/xml', 'csv' => 'text/csv', 'md' => 'text/markdown',
            default => null,
        };
    }
}
