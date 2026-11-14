<?php

declare(strict_types=1);

namespace App\Service\Tracking\Previews;

use App\Entity\Attachment;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Service\AttachmentService;
use App\Service\TextFormatter;
use Symfony\Component\HttpFoundation\Request;

/**
 * The "Preview" tab of the text areas (previews/issue, previews/news and previews/text): the submitted text,
 * formatted as it will be once saved.
 */
final class TextPreview
{
    public function __construct(
        private readonly TextFormatter $formatter,
        private readonly AttachmentService $attachments,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * Parameters: text, attachments[][token] (the files uploaded in the form being previewed).
     *
     * @param ?object $previewed the object the text belongs to, when it exists already
     */
    public function html(Request $request, ?Project $project, ?object $previewed = null): string
    {
        $text = $request->request->get('text') ?? $request->query->get('text');
        if (null === $text || '' === trim((string) $text)) {
            return '<p class="empty-preview">'.htmlspecialchars($this->i18n->l('label_nothing_to_preview')).'</p>';
        }

        return $this->formatter->format((string) $text, $project, ['attachments' => $this->pendingAttachments($request)] + (null !== $previewed ? ['object' => $previewed] : []));
    }

    /**
     * ApplicationController#find_attachments: the files uploaded in the form that is being previewed.
     *
     * @return list<Attachment>
     */
    private function pendingAttachments(Request $request): array
    {
        $found = [];
        $params = $request->request->all()['attachments'] ?? $request->query->all()['attachments'] ?? [];
        foreach (\is_array($params) ? $params : [] as $attachment) {
            $token = \is_array($attachment) ? ($attachment['token'] ?? null) : null;
            if (\is_string($token) && '' !== $token && null !== ($a = $this->attachments->findByToken($token))) {
                $found[] = $a;
            }
        }

        return $found;
    }
}
