<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Attachment;
use App\Service\AttachmentService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the REST API structures of an attachment listed with what it is attached to (news, documents):
 * render_api_attachment_attributes, with URLs on the host of the request; and of a file just uploaded
 * (attachments/upload.api.rsb).
 */
final class AttachmentApiPresenter
{
    public function __construct(private readonly AttachmentService $attachments)
    {
    }

    /** @return array<string, mixed> */
    public function attributes(Request $request, Attachment $a): array
    {
        $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();
        $data = [
            'id' => $a->id, 'filename' => $a->filename, 'filesize' => $a->getFilesize(), 'content_type' => $a->contentType,
            'description' => $a->description, 'content_url' => $base.'/attachments/download/'.$a->id.'/'.rawurlencode($a->filename),
        ];
        if ($a->isImage()) {
            $data['thumbnail_url'] = $base.'/attachments/thumbnail/'.$a->id;
        }
        $data['author'] = ['id' => $a->author->id, 'name' => $a->author->getName()];
        $data['created_on'] = ApiRenderer::time($a->createdOn);

        return $data;
    }

    /** @return array{upload: array{id: int|null, token: string}} the token to give when attaching the uploaded file */
    public function upload(Attachment $a): array
    {
        return ['upload' => ['id' => $a->id, 'token' => $this->attachments->token($a)]];
    }
}
