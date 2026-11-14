<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Principal;
use App\Entity\User;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Account status checks of a login (AccountController#password_authentication).
 *
 * Like User.try_to_login!, the status of the account is only looked at once the credentials are known to
 * be valid: a wrong password always answers "Invalid user or password", whatever the status, so the login
 * form does not tell which accounts are locked or waiting for their activation.
 */
final class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly ?RequestStack $requestStack = null,
        private readonly ?Settings $settings = null,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }
        if (Principal::STATUS_LOCKED === $user->status) {
            throw new CustomUserMessageAccountStatusException('notice_account_locked');
        }
        if (Principal::STATUS_REGISTERED === $user->status) {
            $this->rememberPendingUser($user);

            throw new CustomUserMessageAccountStatusException('notice_account_not_activated_yet');
        }
    }

    /**
     * AccountController#account_pending: session[:registered_user_id] = user.id when accounts are activated
     * by e-mail, so that the activation e-mail can be sent again (AccountController#activation_email).
     */
    private function rememberPendingUser(User $user): void
    {
        $request = $this->requestStack?->getMainRequest();
        if (null === $request || null === $this->settings || '1' !== $this->settings->selfRegistration()) {
            return;
        }
        if (!$request->hasSession() || ApiRequestMatcher::isApiRequest($request) || 'signin' !== $request->attributes->get('_route')) {
            return;
        }
        $request->getSession()->set('registered_user_id', $user->id);
    }
}
