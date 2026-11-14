<?php

declare(strict_types=1);

namespace App\Service\Admin\Webhooks;

use App\Entity\Webhook;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\Admin\WebhookManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who may use /webhooks and on which webhook (the before_actions of Redmine's WebhooksController): webhooks
 * enabled, the use_webhooks permission, and a webhook the current user may edit.
 */
final class WebhookAccess
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebhookManager $webhooks,
        private readonly AccessGuard $guard,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * require_login + check_enabled(_or_admin) + authorize(:use_webhooks globally)
     *
     * @param bool $adminBypassesDisabled administrators keep access to existing webhooks while webhooks are disabled
     */
    public function check(bool $adminBypassesDisabled): void
    {
        $this->guard->requireLogin();
        $user = $this->currentUser->get();
        if (!$this->webhooks->enabled() && !($adminBypassesDisabled && $user->admin)) {
            throw new HttpException(403, $this->i18n->l('notice_not_authorized'));
        }
        if (!$this->auth->allowedTo('use_webhooks', null, null, true)) {
            $this->guard->deny();
        }
    }

    /** The webhook, when the current user may edit it: 404 otherwise */
    public function find(int $id): Webhook
    {
        $webhook = $this->em->find(Webhook::class, $id);
        if (null === $webhook || !$this->webhooks->isEditable($webhook, $this->currentUser->get())) {
            throw new NotFoundHttpException();
        }

        return $webhook;
    }
}
