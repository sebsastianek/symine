<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\Principal;
use App\Entity\User;
use App\I18n\Localizer;
use App\Mailer\AccountMailer;
use App\Service\AccountService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Activation of the accounts users register themselves (AccountController#activate, #activation_email,
 * #register_by_email_activation and #account_pending).
 */
final class AccountActivation
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountMailer $mailer,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** Activates the registered account the token of an activation e-mail belongs to; false when there is none */
    public function activate(string $tokenValue): bool
    {
        $mode = $this->settings->selfRegistration();
        if ('0' === $mode || '' === $mode || '' === $tokenValue) {
            return false;
        }
        $token = $this->accounts->findToken('register', $tokenValue);
        if (null === $token || $this->accounts->isTokenExpired($token)) {
            return false;
        }
        $user = $token->user;
        if (!$user->isRegistered()) {
            return false;
        }
        $user->status = Principal::STATUS_ACTIVE;
        $this->em->remove($token);
        $this->em->flush();

        return true;
    }

    /** register_by_email_activation: sends the e-mail with the activation link */
    public function sendEmail(User $user): void
    {
        $token = $this->accounts->createToken($user, 'register');
        $this->mailer->register($user, $token);
    }

    /**
     * AccountController#activation_email: sends the activation e-mail again to the user who was told that the
     * account is not activated yet (session[:registered_user_id]).
     *
     * @return User|null the user the e-mail was sent to
     */
    public function resendEmail(SessionInterface $session): ?User
    {
        if ($session->has('registered_user_id') && '1' === $this->settings->selfRegistration()) {
            $userId = (int) $session->remove('registered_user_id');
            $user = $this->em->find(User::class, $userId);
            if ($user instanceof User && $user->isRegistered()) {
                $this->sendEmail($user);

                return $user;
            }
        }

        return null;
    }

    /**
     * AccountController#account_pending: what a user whose account is not active yet is told. When accounts are
     * activated by e-mail the message has a link to get the e-mail again, and the user is remembered for it.
     *
     * @return array{0: string, 1: string} flash type and message
     */
    public function pendingNotice(User $user, SessionInterface $session): array
    {
        if ('1' === $this->settings->selfRegistration()) {
            $session->set('registered_user_id', $user->id);

            return ['error_html', $this->i18n->l('notice_account_not_activated_yet', ['url' => htmlspecialchars($this->urls->generate('account_activation_email'))])];
        }

        return ['error', $this->i18n->l('notice_account_pending')];
    }
}
