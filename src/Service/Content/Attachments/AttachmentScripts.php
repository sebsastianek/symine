<?php

declare(strict_types=1);

namespace App\Service\Content\Attachments;

use App\Entity\Attachment;
use App\Service\AttachmentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The scripts answering the file fields of the forms (attachments/upload.js.erb and destroy.js.erb), which
 * upload each chosen file on its own: they update the row of the file named by the "attachment_id" parameter.
 */
final class AttachmentScripts
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** The file was stored: its token goes in the form and its "remove" link now deletes it */
    public function uploaded(Request $request, Attachment $attachment): string
    {
        $aid = $this->rowId($request);
        $token = $this->attachments->token($attachment);
        $url = $this->urls->generate('attachment_destroy', ['id' => $attachment->id, '_format' => 'js', 'attachment_id' => $aid]);

        return "var fileSpan = $('#attachments_{$aid}');\nfileSpan.find('input.token').val(".json_encode($token).");\nfileSpan.find('a.remove-upload').attr({\"data-remote\": true, \"data-method\": 'delete', href: ".json_encode($url)."}).off('click');";
    }

    /**
     * The file was refused: its row is hidden and the reasons are shown.
     *
     * @param list<string> $errors
     */
    public function refused(Request $request, array $errors): string
    {
        $aid = $this->rowId($request);

        return "var fileSpan = $('#attachments_{$aid}');\nfileSpan.hide();\nalert(".json_encode(implode(', ', $errors)).');';
    }

    /** The uploaded file was deleted: its row goes away and a file can be added again */
    public function removed(Request $request): string
    {
        $aid = $this->rowId($request);

        return "$('#attachments_{$aid}').closest('.attachments_form').find('.add_attachment').show();\n$('#attachments_{$aid}').remove();";
    }

    private function rowId(Request $request): string
    {
        return (string) preg_replace('/[^\w]/', '', (string) $request->query->get('attachment_id', ''));
    }
}
