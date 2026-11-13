<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Entity\Attachment;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\Project;
use App\Service\AttachmentService;
use App\Service\IssueService;
use App\Service\TextFormatter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's AttachmentsController: preview, download, thumbnails, uploads, edit and delete. */
final class AttachmentsController extends AbstractController
{
    /** container_type => [view, edit, delete] permissions (acts_as_attachable options) */
    private const array PERMISSIONS = [
        'Issue' => ['view_issues', 'edit_issues', 'edit_issues'],
        'WikiPage' => ['view_wiki_pages', 'edit_wiki_pages', 'delete_wiki_pages_attachments'],
        'Document' => ['view_documents', 'edit_documents', 'delete_documents'],
        'Message' => ['view_messages', 'edit_messages', 'delete_messages'],
        'News' => ['view_news', 'manage_news', 'manage_news'],
        'Project' => ['view_files', 'manage_files', 'manage_files'],
        'Version' => ['view_files', 'manage_files', 'manage_files'],
        'Journal' => ['view_issues', 'edit_issues', 'edit_issues'],
    ];

    /** object_type route param (plural underscore) => container type */
    private const array OBJECT_TYPES = ['issues' => 'Issue', 'wiki_pages' => 'WikiPage', 'documents' => 'Document', 'messages' => 'Message', 'news' => 'News', 'projects' => 'Project', 'versions' => 'Version'];

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly IssueService $issues,
        private readonly ApiRenderer $api,
        private readonly IssueApiPresenter $presenter,
        private readonly TextFormatter $formatter,
    ) {
    }

    // ------------------------------------------------------------------------------------------

    #[Route('/attachments/{id}/{filename}', name: 'attachment_show', requirements: ['id' => '\d+', 'filename' => '.+'], defaults: ['filename' => null], methods: ['GET'])]
    public function show(Request $request, int $id, ?string $filename = null): Response
    {
        $a = $this->findAttachment($id, $filename);
        $this->readAuthorize($a);
        $path = $this->attachments->fullPath($a);
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }
        $container = $this->container($a);
        $siblings = $a->containerType && $a->containerId ? $this->attachments->forContainer((string) $a->containerType, $a->containerId) : [$a];
        $index = 0;
        foreach ($siblings as $i => $s) {
            if ($s->id === $a->id) {
                $index = $i;
            }
        }
        $kind = 'other';
        $content = null;
        if ($a->isImage()) {
            $kind = 'image';
        } elseif ($a->isText() && $a->getFilesize() <= ($this->settings->int('file_max_size_displayed') ?: 512) * 1024) {
            $kind = preg_match('/\.(md|markdown)$/i', $a->filename) || preg_match('/\.textile$/i', $a->filename) ? 'markup' : 'file';
            $content = (string) file_get_contents($path);
            if (!mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
            }
        } elseif (preg_match('/\.(mp4|webm|ogv|mov)$/i', $a->filename)) {
            $kind = 'video';
        } elseif (preg_match('/\.(mp3|ogg|oga|wav|m4a|flac)$/i', $a->filename)) {
            $kind = 'audio';
        } elseif ($a->isPdf()) {
            $kind = 'pdf';
        }
        $markup = null;
        if ('markup' === $kind) {
            $markup = $this->formatter->format($content, $this->projectOf($a));
        }

        return $this->render('attachments/show.html.twig', [
            'attachment' => $a,
            'project' => $this->projectOf($a),
            'container' => $container,
            'container_link' => $this->containerLink($a),
            'kind' => $kind,
            'content_lines' => null !== $content ? preg_split('/\r\n|\r|\n/', $content) : [],
            'content' => $content,
            'markup' => $markup,
            'siblings' => $siblings,
            'index' => $index,
            'main_menu_selected' => $this->menuItem($a),
            'page_title' => $a->filename,
            'body_css' => 'controller-attachments action-show',
        ]);
    }

    #[Route('/attachments/{id}.{_format}', name: 'attachment_api_show', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function apiShow(Request $request, int $id): Response
    {
        $a = $this->findAttachment($id);
        $this->readAuthorize($a);

        return $this->api->render($request, ['attachment' => $this->presenter->attachment($a)]);
    }

    #[Route('/attachments/download/{id}/{filename}', name: 'attachment_download', requirements: ['id' => '\d+', 'filename' => '.+'], defaults: ['filename' => null], methods: ['GET'])]
    public function download(Request $request, int $id, ?string $filename = null): Response
    {
        $a = $this->findAttachment($id, $filename);
        $this->readAuthorize($a);
        if (!is_file($this->attachments->fullPath($a))) {
            throw $this->createNotFoundException();
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

    #[Route('/attachments/thumbnail/{id}/{size}', name: 'attachment_thumbnail', requirements: ['id' => '\d+', 'size' => '\d+'], defaults: ['size' => null], methods: ['GET'])]
    public function thumbnail(Request $request, int $id, ?int $size = null): Response
    {
        $a = $this->findAttachment($id);
        $this->readAuthorize($a);
        $path = $this->attachments->fullPath($a);
        if (!$a->isImage() || !is_file($path)) {
            throw $this->createNotFoundException();
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

    #[Route('/attachments/{id}.{_format}', name: 'attachment_update', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['PATCH', 'PUT'])]
    public function update(Request $request, int $id): Response
    {
        $a = $this->findAttachment($id);
        if (!$this->isEditable($a)) {
            $this->deny();
        }
        $attrs = (array) (ApiRenderer::payload($request)['attachment'] ?? []);
        $errors = $this->applyAttributes($a, $attrs);
        if ([] !== $errors) {
            return $this->api->errors($request, $errors);
        }
        $this->em->flush();

        return $this->api->noContent();
    }

    #[Route('/attachments/{id}.{_format}', name: 'attachment_destroy', requirements: ['id' => '\d+', '_format' => 'html|js|json|xml'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id, string $_format = 'html'): Response
    {
        $a = $this->findAttachment($id);
        if (!$this->isDeletable($a)) {
            $this->deny();
        }
        $project = $this->projectOf($a);
        $container = $this->container($a);
        if ($container instanceof Issue) {
            $j = new Journal();
            $j->issue = $container;
            $j->user = $this->user();
            $j->addDetail('attachment', (string) $a->id, $a->filename, null);
            $this->em->persist($j);
        }
        $this->attachments->delete($a);
        $this->em->flush();

        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        if ('js' === $_format || $request->isXmlHttpRequest()) {
            $aid = preg_replace('/[^\w]/', '', (string) $request->query->get('attachment_id', ''));

            return new Response("$('#attachments_{$aid}').closest('.attachments_form').find('.add_attachment').show();\n$('#attachments_{$aid}').remove();", 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }
        $ref = (string) $request->headers->get('referer');
        if ('' !== $ref) {
            return $this->redirect($ref);
        }

        return $this->redirect($project ? $this->safeUrl('project_show', ['id' => $project->identifier]) : $this->generateUrl('home'));
    }

    // ------------------------------------------------------------------------------------------
    // Edit all / download all
    // ------------------------------------------------------------------------------------------

    #[Route('/attachments/{object_type}/{object_id}/edit', name: 'attachments_edit_all', requirements: ['object_type' => 'issues|wiki_pages|documents|messages|news|projects|versions', 'object_id' => '\d+'], methods: ['GET'])]
    public function editAll(Request $request, string $object_type, int $object_id): Response
    {
        [$type, $atts] = $this->editableAttachments($object_type, $object_id);

        return $this->renderEditAll($request, $object_type, $object_id, $atts, []);
    }

    #[Route('/attachments/{object_type}/{object_id}', name: 'attachments_update_all', requirements: ['object_type' => 'issues|wiki_pages|documents|messages|news|projects|versions', 'object_id' => '\d+'], methods: ['PATCH', 'POST'])]
    public function updateAll(Request $request, string $object_type, int $object_id): Response
    {
        [$type, $atts] = $this->editableAttachments($object_type, $object_id);
        $params = $request->request->all('attachments');
        $errors = [];
        foreach ($atts as $a) {
            if (isset($params[$a->id]) && \is_array($params[$a->id])) {
                array_push($errors, ...$this->applyAttributes($a, $params[$a->id]));
            }
        }
        if ([] === $errors) {
            $this->em->flush();
            $first = $atts[0];

            return $this->redirectBackOr($request, $this->containerUrl($first) ?? $this->generateUrl('home'));
        }

        return $this->renderEditAll($request, $object_type, $object_id, $atts, $errors);
    }

    private function renderEditAll(Request $request, string $objectType, int $objectId, array $atts, array $errors): Response
    {
        return $this->render('attachments/edit_all.html.twig', [
            'attachments' => $atts,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'container_link' => $this->containerLink($atts[0]),
            'project' => $this->projectOf($atts[0]),
            'errors' => $errors,
            'back_url' => $request->query->get('back_url') ?? $request->request->get('back_url') ?? $this->containerUrl($atts[0]),
            'main_menu_selected' => $this->menuItem($atts[0]),
            'page_title' => $this->l('label_edit_attachments'),
            'body_css' => 'controller-attachments action-edit_all',
        ]);
    }

    /** @return array{0: string, 1: list<Attachment>} */
    private function editableAttachments(string $objectType, int $objectId): array
    {
        $type = self::OBJECT_TYPES[$objectType] ?? throw $this->createNotFoundException();
        $atts = $this->attachments->forContainer($type, $objectId);
        if ([] === $atts) {
            $this->checkContainerExists($type, $objectId);
            throw $this->createNotFoundException();
        }
        $this->readAuthorize($atts[0]);
        $atts = array_values(array_filter($atts, $this->isEditable(...)));
        if ([] === $atts) {
            throw $this->createNotFoundException();
        }

        return [$type, $atts];
    }

    private function checkContainerExists(string $type, int $id): void
    {
        $class = 'App\\Entity\\'.$type;
        if (!class_exists($class) || null === $this->em->find($class, $id)) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('/attachments/{object_type}/{object_id}/download', name: 'attachments_download_all', requirements: ['object_type' => 'issues|wiki_pages|documents|messages|news|projects|versions', 'object_id' => '\d+'], methods: ['GET'])]
    public function downloadAll(Request $request, string $object_type, int $object_id): Response
    {
        $type = self::OBJECT_TYPES[$object_type] ?? throw $this->createNotFoundException();
        $this->checkContainerExists($type, $object_id);
        $atts = $this->attachments->forContainer($type, $object_id);
        if ([] === $atts) {
            throw $this->createNotFoundException();
        }
        $this->readAuthorize($atts[0]);
        $atts = array_values(array_filter($atts, fn (Attachment $a) => is_file($this->attachments->fullPath($a))));
        $max = ($this->settings->int('bulk_download_max_size') ?: 102400) * 1024;
        if (array_sum(array_map(static fn (Attachment $a) => $a->getFilesize(), $atts)) > $max) {
            $this->flashError($this->l('error_bulk_download_size_too_big', ['max_size' => $max]));

            return $this->redirect($this->containerUrl($atts[0]) ?? $this->generateUrl('home'));
        }
        if (!class_exists(\ZipArchive::class) || [] === $atts) {
            throw $this->createNotFoundException();
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
        $fileName = strtolower($type).'-'.$object_id.'-attachments.zip';

        return new Response($data, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$fileName.'"']);
    }

    // ------------------------------------------------------------------------------------------
    // Upload
    // ------------------------------------------------------------------------------------------

    #[Route('/uploads.{_format}', name: 'uploads', requirements: ['_format' => 'json|xml|js'], defaults: ['_format' => 'json'], methods: ['POST'])]
    public function upload(Request $request, string $_format = 'json'): Response
    {
        $user = $this->user();
        // authorize_global: the user must be allowed to upload somewhere
        $allowed = $user->admin;
        foreach (['add_issues', 'edit_issues', 'add_issue_notes', 'edit_wiki_pages', 'add_documents', 'edit_documents', 'add_messages', 'manage_news', 'manage_files', 'edit_own_issues', 'comment_news'] as $perm) {
            if ($allowed) {
                break;
            }
            $allowed = $this->auth->allowedToGlobally($perm);
        }
        if (!$allowed) {
            $this->deny();
        }
        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if ('application/octet-stream' !== $mediaType) {
            return new Response('', 406);
        }
        $content = (string) $request->getContent();
        $filename = (string) ($request->query->get('filename') ?: bin2hex(random_bytes(16)));
        $errors = [];
        // Attachment#validate_max_file_size / validate_file_extension: errors on :base (no attribute prefix)
        if (\strlen($content) > $this->attachments->maxSize()) {
            $errors[] = $this->l('error_attachment_too_big', ['max_size' => $this->settings->int('attachment_max_size') * 1024]);
        } elseif (!$this->attachments->isExtensionAllowed($filename)) {
            $ext = pathinfo($filename, \PATHINFO_EXTENSION);
            $errors[] = $this->l('error_attachment_extension_not_allowed', ['extension' => '' === $ext ? '' : '.'.$ext]);
        }
        $attachment = null;
        if ([] === $errors) {
            // AttachmentsController#upload: the content type is detected on save, params[:content_type] is ignored
            $attachment = $this->attachments->createFromContent($content, $filename, $user);
            $this->em->flush();
        }
        if ('js' === $_format) {
            $aid = preg_replace('/[^\w]/', '', (string) $request->query->get('attachment_id', ''));
            if (null === $attachment) {
                return $this->js("var fileSpan = $('#attachments_{$aid}');\nfileSpan.hide();\nalert(".json_encode(implode(', ', $errors)).');');
            }
            $token = $this->attachments->token($attachment);
            $url = $this->generateUrl('attachment_destroy', ['id' => $attachment->id, '_format' => 'js', 'attachment_id' => $aid]);

            return $this->js("var fileSpan = $('#attachments_{$aid}');\nfileSpan.find('input.token').val(".json_encode($token).");\nfileSpan.find('a.remove-upload').attr({\"data-remote\": true, \"data-method\": 'delete', href: ".json_encode($url)."}).off('click');");
        }
        if (null === $attachment) {
            return $this->api->errors($request, $errors);
        }

        return $this->api->render($request, ['upload' => ['id' => $attachment->id, 'token' => $this->attachments->token($attachment)]], 201);
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    private function findAttachment(int $id, ?string $filename = null): Attachment
    {
        $a = $this->em->find(Attachment::class, $id);
        if (null === $a || (null !== $filename && '' !== $filename && $filename !== $a->filename)) {
            throw $this->createNotFoundException();
        }

        return $a;
    }

    private function container(Attachment $a): ?object
    {
        if (null === $a->containerType || null === $a->containerId) {
            return null;
        }
        $class = 'App\\Entity\\'.$a->containerType;

        return class_exists($class) ? $this->em->find($class, $a->containerId) : null;
    }

    private function projectOf(Attachment $a): ?Project
    {
        $c = $this->container($a);

        return match (true) {
            null === $c => null,
            $c instanceof Project => $c,
            $c instanceof Issue => $c->project,
            $c instanceof Journal => $c->issue->project,
            $c instanceof \App\Entity\WikiPage => $c->wiki->project,
            $c instanceof \App\Entity\Message => $c->board->project,
            property_exists($c, 'project') => $c->project,
            default => null,
        };
    }

    private function isVisible(Attachment $a): bool
    {
        $c = $this->container($a);
        if (null === $c) {
            return $a->author->id === $this->user()->id;
        }
        if ($c instanceof Issue && !$this->issues->isVisible($c)) {
            return false;
        }
        if ($c instanceof Journal && !$this->issues->isVisible($c->issue)) {
            return false;
        }
        $perm = self::PERMISSIONS[$a->containerType][0] ?? null;

        return null === $perm || $this->auth->allowedTo($perm, $this->projectOf($a));
    }

    private function isEditable(Attachment $a): bool
    {
        $c = $this->container($a);
        if (null === $c) {
            return $a->author->id === $this->user()->id;
        }
        if ($c instanceof Issue) {
            return $this->issues->attachmentsDeletable($c);
        }

        return $this->isVisible($a) && $this->auth->allowedTo(self::PERMISSIONS[$a->containerType][1] ?? 'admin', $this->projectOf($a));
    }

    private function isDeletable(Attachment $a): bool
    {
        $c = $this->container($a);
        if (null === $c) {
            return $a->author->id === $this->user()->id;
        }
        if ($c instanceof Issue) {
            return $this->issues->attachmentsDeletable($c);
        }

        return $this->isVisible($a) && $this->auth->allowedTo(self::PERMISSIONS[$a->containerType][2] ?? 'admin', $this->projectOf($a));
    }

    private function readAuthorize(Attachment $a): void
    {
        if (!$this->isVisible($a)) {
            $this->deny();
        }
    }

    /** @return list<string> */
    private function applyAttributes(Attachment $a, array $attrs): array
    {
        $errors = [];
        if (\array_key_exists('filename', $attrs)) {
            $name = trim((string) $attrs['filename']);
            if ('' === $name) {
                $errors[] = $this->l('field_filename').' '.$this->l('activerecord.errors.messages.blank');
            } elseif (!$this->attachments->isExtensionAllowed($name)) {
                $errors[] = $this->l('field_filename').' '.$this->l('error_attachment_extension_not_allowed', ['extension' => pathinfo($name, \PATHINFO_EXTENSION)]);
            } else {
                $a->filename = AttachmentService::sanitizeFilename($name);
            }
        }
        if (\array_key_exists('description', $attrs)) {
            $a->description = mb_substr(trim((string) $attrs['description']), 0, 255);
        }

        return $errors;
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

    private function containerLink(Attachment $a): ?string
    {
        $c = $this->container($a);
        $url = $this->containerUrl($a);
        $label = match (true) {
            $c instanceof Issue => $c->tracker->name.' #'.$c->id.': '.$c->subject,
            $c instanceof Project => $this->l('label_file_plural'),
            $c instanceof \App\Entity\WikiPage => $c->title,
            $c instanceof \App\Entity\Message => $c->subject,
            $c instanceof \App\Entity\News => $c->title,
            $c instanceof \App\Entity\Document => $c->title ?? '',
            $c instanceof \App\Entity\Version => $c->name,
            default => null,
        };
        if (null === $label) {
            return null;
        }

        return null !== $url ? '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($label).'</a>' : htmlspecialchars($label);
    }

    private function containerUrl(Attachment $a): ?string
    {
        $c = $this->container($a);

        return match (true) {
            $c instanceof Issue => $this->generateUrl('issue_show', ['id' => $c->id]),
            $c instanceof Project => $this->safeUrl('project_files', ['project_id' => $c->identifier]),
            $c instanceof \App\Entity\Version => $this->safeUrl('project_files', ['project_id' => $c->project->identifier]),
            $c instanceof \App\Entity\WikiPage => $this->safeUrl('wiki_show', ['project_id' => $c->wiki->project->identifier, 'id' => $c->title]),
            $c instanceof \App\Entity\News => $this->safeUrl('news_show', ['id' => $c->id]),
            $c instanceof \App\Entity\Document => $this->safeUrl('document_show', ['id' => $c->id]),
            $c instanceof \App\Entity\Message => $this->safeUrl('board_message_show', ['project_id' => $c->board->project->identifier, 'board_id' => $c->board->id, 'id' => $c->parent?->id ?? $c->id]),
            default => null,
        };
    }

    private function safeUrl(string $route, array $params): ?string
    {
        try {
            return $this->generateUrl($route, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    private function menuItem(Attachment $a): ?string
    {
        return match ($a->containerType) {
            'WikiPage' => 'wiki',
            'Message' => 'boards',
            'Project', 'Version' => 'files',
            'Issue', 'Journal' => 'issues',
            'News' => 'news',
            'Document' => 'documents',
            default => null,
        };
    }

    private function js(string $js): Response
    {
        return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }
}
