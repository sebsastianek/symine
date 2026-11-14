<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Attachment storage compatible with Redmine's files/ layout (YYYY/MM/<timestamp>_<filename>, SHA256 digest).
 *
 * Forms post files as attachments[N][file] + attachments[N][description] (direct upload)
 * or attachments[N][token] (pre-uploaded via POST /uploads(.json), token = "<id>.<digest>").
 */
final class AttachmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        #[Autowire('%symine.files_path%')] private readonly string $filesPath,
        private readonly ?\App\Doctrine\QueryMemo $memo = null,
    ) {
    }

    public function storagePath(): string
    {
        return rtrim($this->filesPath, '/');
    }

    public function fullPath(Attachment $a): string
    {
        return $this->storagePath().'/'.$a->relativePath();
    }

    public function maxSize(): int
    {
        return $this->settings->int('attachment_max_size') * 1024;
    }

    public function isExtensionAllowed(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        $allowed = array_filter(array_map(static fn ($e) => strtolower(trim($e, " .\t")), preg_split('/[\s,]+/', $this->settings->string('attachment_extensions_allowed')) ?: []));
        $denied = array_filter(array_map(static fn ($e) => strtolower(trim($e, " .\t")), preg_split('/[\s,]+/', $this->settings->string('attachment_extensions_denied')) ?: []));
        if ([] !== $allowed && !\in_array($ext, $allowed, true)) {
            return false;
        }

        return !\in_array($ext, $denied, true);
    }

    /** Stores raw content as a new (container-less) attachment */
    public function createFromContent(string $content, string $filename, User $author, ?string $contentType = null, ?string $description = null): Attachment
    {
        $filename = self::sanitizeFilename($filename);
        $a = new Attachment();
        $a->filename = $filename;
        $a->author = $author;
        $a->description = $description;
        $a->createdOn = new \DateTimeImmutable();
        $a->diskDirectory = $a->createdOn->format('Y/m');
        $a->diskFilename = $this->diskFilename($filename, $a->diskDirectory);
        $dir = $this->storagePath().'/'.$a->diskDirectory;
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        file_put_contents($dir.'/'.$a->diskFilename, $content);
        $a->filesize = \strlen($content);
        $a->digest = hash('sha256', $content);
        $a->contentType = $contentType ?: self::detectContentType($content, $filename);
        $this->em->persist($a);

        return $a;
    }

    public function createFromUpload(UploadedFile $file, User $author, ?string $description = null): Attachment
    {
        return $this->createFromContent((string) file_get_contents($file->getPathname()), $file->getClientOriginalName(), $author, $file->getClientMimeType(), $description);
    }

    /** Finds an attachment uploaded beforehand by its token "<id>.<digest>" */
    public function findByToken(string $token): ?Attachment
    {
        if (!preg_match('/^(\d+)\.([0-9a-f]+)$/', $token, $m)) {
            return null;
        }
        $a = $this->em->find(Attachment::class, (int) $m[1]);

        return $a && $a->digest === $m[2] && null === $a->containerId ? $a : null;
    }

    public function token(Attachment $a): string
    {
        return $a->id.'.'.$a->digest;
    }

    /**
     * Handles attachments submitted with a form or an API payload ("uploads": [{token, filename, description}]).
     *
     * @return array{0: list<Attachment>, 1: list<string>} attachments (persisted, without container) and errors
     */
    public function fromRequest(Request $request, User $author, ?array $apiUploads = null): array
    {
        $attachments = [];
        $errors = [];
        $entries = $apiUploads ?? (array) $request->request->all('attachments');
        $files = $request->files->all('attachments');
        foreach ($entries as $key => $entry) {
            $entry = (array) $entry;
            if (!empty($entry['token'])) {
                $a = $this->findByToken((string) $entry['token']);
                if ($a) {
                    if (!empty($entry['filename'])) {
                        $a->filename = self::sanitizeFilename((string) $entry['filename']);
                    }
                    if (\array_key_exists('description', $entry)) {
                        $a->description = (string) $entry['description'];
                    }
                    if (!empty($entry['content_type'])) {
                        $a->contentType = (string) $entry['content_type'];
                    }
                    $attachments[] = $a;
                } else {
                    $errors[] = 'Invalid attachment token';
                }
            }
        }
        foreach ($files as $key => $f) {
            $file = \is_array($f) ? ($f['file'] ?? null) : $f;
            if (!$file instanceof UploadedFile) {
                continue;
            }
            if (!$file->isValid()) {
                $errors[] = $file->getErrorMessage();
                continue;
            }
            if ($file->getSize() > $this->maxSize()) {
                $errors[] = $file->getClientOriginalName().': too large';
                continue;
            }
            if (!$this->isExtensionAllowed($file->getClientOriginalName())) {
                $errors[] = $file->getClientOriginalName().': extension not allowed';
                continue;
            }
            $desc = $entries[$key]['description'] ?? null;
            $attachments[] = $this->createFromUpload($file, $author, $desc);
        }

        return [$attachments, $errors];
    }

    /** @param list<Attachment> $attachments */
    public function attachTo(array $attachments, string $containerType, int $containerId): void
    {
        foreach ($attachments as $a) {
            $a->containerType = $containerType;
            $a->containerId = $containerId;
        }
    }

    /** @return list<Attachment> */
    public function forContainer(string $containerType, ?int $containerId): array
    {
        if (null === $containerId) {
            return [];
        }

        $load = fn (): array => $this->em->getRepository(Attachment::class)->findBy(['containerType' => $containerType, 'containerId' => $containerId], ['createdOn' => 'ASC', 'id' => 'ASC']);

        return null === $this->memo ? $load() : $this->memo->get('attachments:'.$containerType.':'.$containerId, ['attachments'], $load);
    }

    public function delete(Attachment $a): void
    {
        $path = $this->fullPath($a);
        $others = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attachments WHERE disk_filename = ? AND disk_directory = ? AND id <> ?', [$a->diskFilename, $a->diskDirectory, (int) $a->id]);
        if (0 === $others && is_file($path)) {
            @unlink($path);
        }
        $this->em->remove($a);
    }

    public function deleteForContainer(string $containerType, int $containerId): void
    {
        foreach ($this->forContainer($containerType, $containerId) as $a) {
            $this->delete($a);
        }
    }

    public function download(Attachment $a, bool $inline = false): BinaryFileResponse
    {
        $response = new BinaryFileResponse($this->fullPath($a));
        $response->headers->set('Content-Type', $inline && ($a->isImage() || $a->isPdf()) ? (string) $a->contentType : ($inline && $a->isText() ? 'text/plain; charset=utf-8' : (string) ($a->contentType ?: 'application/octet-stream')));
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $a->filename, preg_replace('/[^\x20-\x7e]/', '_', $a->filename) ?: 'file');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Attachment#set_content_type (Marcel::MimeType.for(io, name: filename)): binary signatures win,
     * otherwise the filename extension is used as a hint, otherwise application/octet-stream
     * (plain text cannot be identified from its contents alone).
     */
    public static function detectContentType(string $content, string $filename): string
    {
        $magic = '' === $content ? '' : (string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content);
        if ('' !== $magic && !str_starts_with($magic, 'text/') && !\in_array($magic, ['application/octet-stream', 'inode/x-empty', 'application/x-empty'], true)) {
            return $magic;
        }
        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        $byName = '' === $ext ? [] : \Symfony\Component\Mime\MimeTypes::getDefault()->getMimeTypes($ext);

        return $byName[0] ?? 'application/octet-stream';
    }

    public static function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\/\?\%\*\:\|\"\'<>\n\r\x00]+/', '_', $name) ?? $name;

        return '' === $name ? 'file' : mb_substr($name, 0, 255);
    }

    private function diskFilename(string $filename, string $dir): string
    {
        $ts = (new \DateTimeImmutable())->format('ymdHis');
        $ascii = preg_replace('/[^\w\.\-]/', '_', $filename) ?? 'file';
        if (\strlen($ascii) > 50 || $ascii !== $filename) {
            $ascii = md5($filename).(pathinfo($ascii, \PATHINFO_EXTENSION) ? '.'.pathinfo($ascii, \PATHINFO_EXTENSION) : '');
        }
        $name = $ts.'_'.$ascii;
        while (is_file($this->storagePath().'/'.$dir.'/'.$name)) {
            $ts = (string) ((int) $ts + 1);
            $name = $ts.'_'.$ascii;
        }

        return $name;
    }
}
