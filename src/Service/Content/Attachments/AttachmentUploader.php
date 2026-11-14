<?php

declare(strict_types=1);

namespace App\Service\Content\Attachments;

use App\Entity\Attachment;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives the files uploaded ahead of a form or of an API request (POST /uploads, body = the file,
 * Content-Type: application/octet-stream): they are stored attached to nothing and referred to by a token.
 */
final class AttachmentUploader
{
    /** The permissions that let a user attach files to something */
    private const array PERMISSIONS = ['add_issues', 'edit_issues', 'add_issue_notes', 'edit_wiki_pages', 'add_documents', 'edit_documents', 'add_messages', 'manage_news', 'manage_files', 'edit_own_issues', 'comment_news'];

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly Settings $settings,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly AccessGuard $guard,
        private readonly Localizer $i18n,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** authorize_global: the user must be allowed to upload somewhere */
    public function requireAllowed(): void
    {
        $allowed = $this->currentUser->get()->admin;
        foreach (self::PERMISSIONS as $perm) {
            if ($allowed) {
                break;
            }
            $allowed = $this->auth->allowedToGlobally($perm);
        }
        if (!$allowed) {
            $this->guard->deny();
        }
    }

    /** Whether the request sends its body as a raw file (anything else is answered 406) */
    public function accepts(Request $request): bool
    {
        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));

        return 'application/octet-stream' === $mediaType;
    }

    /**
     * Stores the body of the request as a new attachment, named by the "filename" parameter.
     *
     * @return array{0: Attachment|null, 1: list<string>} the attachment, or the errors that refused the file
     */
    public function upload(Request $request): array
    {
        $content = (string) $request->getContent();
        $filename = (string) ($request->query->get('filename') ?: bin2hex(random_bytes(16)));
        $errors = [];
        // Attachment#validate_max_file_size / validate_file_extension: errors on :base (no attribute prefix)
        if (\strlen($content) > $this->attachments->maxSize()) {
            $errors[] = $this->i18n->l('error_attachment_too_big', ['max_size' => $this->settings->int('attachment_max_size') * 1024]);
        } elseif (!$this->attachments->isExtensionAllowed($filename)) {
            $ext = pathinfo($filename, \PATHINFO_EXTENSION);
            $errors[] = $this->i18n->l('error_attachment_extension_not_allowed', ['extension' => '' === $ext ? '' : '.'.$ext]);
        }
        $attachment = null;
        if ([] === $errors) {
            // AttachmentsController#upload: the content type is detected on save, params[:content_type] is ignored
            $attachment = $this->attachments->createFromContent($content, $filename, $this->currentUser->get());
            $this->em->flush();
        }

        return [$attachment, $errors];
    }
}
