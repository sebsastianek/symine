<?php

declare(strict_types=1);

namespace App\Plugin\Hook;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * controller_account_success_authentication_after (AccountController#successful_authentication):
 * called after an interactive login on the main firewall.
 */
final class AuthenticationHookSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Hooks $hooks)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => ['onLoginSuccess', -10]];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->hooks->call('controller_account_success_authentication_after', ['user' => $user, 'request' => $event->getRequest()]);
        }
    }
}
