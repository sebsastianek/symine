<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\I18n\Localizer;
use App\Mailer\AccountMailer;
use App\Service\AccountService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lost password (AccountController#lost_password): a user asks for an e-mail with a recovery link, then chooses
 * a new password on the page the link leads to.
 *
 * Fields: mail, then token (link or session), new_password, new_password_confirmation.
 */
final class PasswordRecovery
{
    private const string SESSION_TOKEN = 'password_recovery_token';

    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountMailer $mailer,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool('lost_password');
    }

    /** Whether the request is about choosing the new password (it comes with a recovery token) */
    public function hasToken(Request $request): bool
    {
        $value = self::tokenValue($request);

        return null !== $value && '' !== $value;
    }

    /** Second part: the page of the new password, reached with the token of the e-mail */
    public function reset(Request $request): PasswordRecoveryResult
    {
        $session = $request->getSession();
        $token = $this->accounts->findToken('recovery', (string) self::tokenValue($request));
        if (null === $token) {
            return new PasswordRecoveryResult(PasswordRecoveryStep::Invalid);
        }
        if ($this->accounts->isTokenExpired($token)) {
            $session->remove(self::SESSION_TOKEN);

            return new PasswordRecoveryResult(PasswordRecoveryStep::Expired);
        }
        // the token is moved from the URL to the session, then the user is redirected
        if ($request->query->has('token') && '' !== $request->query->get('token')) {
            $session->set(self::SESSION_TOKEN, $token->value);

            return new PasswordRecoveryResult(PasswordRecoveryStep::TokenStored);
        }
        $user = $token->user;
        if (!$user->isActive()) {
            return new PasswordRecoveryResult(PasswordRecoveryStep::Invalid);
        }
        $errors = [];
        $error = null;
        if ($request->isMethod('POST')) {
            $new = (string) $request->request->get('new_password', '');
            if ($this->accounts->mustChangePassword($user) && $this->accounts->checkPassword($user, $new)) {
                $error = $this->i18n->l('notice_new_password_must_be_different');
            } else {
                $errors = $this->accounts->passwordErrors($user, $new, (string) $request->request->get('new_password_confirmation', ''));
                if ([] === $errors) {
                    $this->accounts->setPassword($user, $new);
                    $user->mustChangePasswd = false;
                    $this->em->remove($token);
                    $this->em->flush();
                    $session->remove(self::SESSION_TOKEN);
                    $this->mailer->passwordUpdated($user, $user, $request->getClientIp());

                    return new PasswordRecoveryResult(PasswordRecoveryStep::Done);
                }
            }
        }

        return new PasswordRecoveryResult(PasswordRecoveryStep::Form, null, ['token' => $token, 'errors' => $errors, 'error' => $error]);
    }

    /** First part: sends the recovery link to the address that was submitted */
    public function request(Request $request): PasswordRecoveryResult
    {
        $email = trim((string) $request->request->get('mail', ''));
        $user = $this->accounts->findByMail($email);
        if (null === $user) {
            return new PasswordRecoveryResult(PasswordRecoveryStep::Sent);
        }
        if (!$user->isActive()) {
            return new PasswordRecoveryResult($user->isRegistered() ? PasswordRecoveryStep::Pending : PasswordRecoveryStep::Locked, $user);
        }
        if (!$this->accounts->changePasswordAllowed($user)) {
            return new PasswordRecoveryResult(PasswordRecoveryStep::Refused, $user);
        }
        $token = $this->accounts->createToken($user, 'recovery');
        $recipient = $user->getMail();
        foreach ($user->emailAddresses as $a) {
            if (0 === strcasecmp($a->address, $email)) {
                $recipient = $a->address;
            }
        }
        $this->mailer->lostPassword($user, $token, $recipient);

        return new PasswordRecoveryResult(PasswordRecoveryStep::Sent, $user);
    }

    private static function tokenValue(Request $request): mixed
    {
        return $request->request->get('token') ?? $request->query->get('token') ?? $request->getSession()->get(self::SESSION_TOKEN);
    }
}
