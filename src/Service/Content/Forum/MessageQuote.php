<?php

declare(strict_types=1);

namespace App\Service\Content\Forum;

use App\Entity\Message;
use App\Service\ForumService;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * The script answering the "Quote" link of a forum message (messages/quote.js.erb): it fills the reply form
 * with the subject and the quoted text (the selected part of the message when the request carries one).
 */
final class MessageQuote
{
    public function __construct(
        private readonly ForumService $forums,
        private readonly Settings $settings,
    ) {
    }

    public function script(Message $message, Request $request): string
    {
        $subject = $message->subject;
        if (!str_starts_with($subject, 'RE:')) {
            $subject = 'RE: '.$subject;
        }
        $partial = $request->request->get('quote') ?? $request->query->get('quote');
        $content = $this->forums->quote($message, \is_string($partial) ? $partial : null, $this->settings->string('default_language') ?: 'en');

        return '$(\'#message_subject\').val('.json_encode($subject, \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG).");\n"
            .'$(\'#message_content\').val('.json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG).");\n"
            ."showAndScrollTo(\"reply\", \"message_content\");\n"
            ."\$('#message_content').scrollTop = \$('#message_content').scrollHeight - \$('#message_content').clientHeight;\n";
    }
}
