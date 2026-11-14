<?php

declare(strict_types=1);

namespace App\Service\Content\Forum;

use App\Entity\Board;
use App\Entity\Message;
use App\I18n\Localizer;
use App\Service\AttachmentService;
use App\Service\TextFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The preview of the text typed in a message form of a forum, rendered with the files of the message being
 * edited and those just uploaded in the form.
 */
final class MessagePreview
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentService $attachments,
        private readonly TextFormatter $formatter,
        private readonly Localizer $i18n,
    ) {
    }

    /** The HTML of the preview */
    public function html(Board $board, Request $request): string
    {
        $text = $request->request->get('text');
        if (!\is_string($text)) {
            $text = $request->request->all('message')['content'] ?? $request->request->all('reply')['content'] ?? null;
        }
        if (null === $text || '' === trim((string) $text)) {
            return '<p class="empty-preview">'.htmlspecialchars($this->i18n->l('label_nothing_to_preview')).'</p>';
        }

        // messages#preview: @previewed = @board.messages.find_by_id(params[:id]), plus the files uploaded in the form
        $options = [];
        $id = $request->query->get('id') ?? $request->request->get('id');
        $previewed = is_numeric($id) ? $this->em->find(Message::class, (int) $id) : null;
        if ($previewed instanceof Message && $previewed->board->id === $board->id) {
            $options['object'] = $previewed;
        }
        $uploads = $request->request->all()['attachments'] ?? [];
        foreach (\is_array($uploads) ? $uploads : [] as $upload) {
            $token = \is_array($upload) ? ($upload['token'] ?? null) : null;
            if (\is_string($token) && '' !== $token && null !== ($attachment = $this->attachments->findByToken($token))) {
                $options['attachments'][] = $attachment;
            }
        }

        return $this->formatter->format((string) $text, $board->project, $options);
    }
}
