<?php

declare(strict_types=1);

namespace App\Service\Admin\Webhooks;

use App\Entity\Webhook;

/** The outcome of submitting the webhook form: the webhook, what was wrong, and what to show again. */
final class WebhookSubmission
{
    /**
     * @param list<string>              $errors    full error messages; empty when the webhook was saved
     * @param array<string, mixed>|null $submitted the refused webhook[...] values of an existing webhook, which
     *                                             itself is back to what is stored
     */
    public function __construct(
        public readonly Webhook $webhook,
        public readonly array $errors,
        public readonly ?array $submitted = null,
    ) {
    }

    public function isSaved(): bool
    {
        return [] === $this->errors;
    }
}
