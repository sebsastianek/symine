<?php

declare(strict_types=1);

namespace App\Service\Account\My;

use App\Entity\User;
use App\I18n\Localizer;
use App\Mailer\AccountMailer;
use App\Service\Account\MySidebar;
use App\Service\Account\PasswordErrors;
use App\Service\AccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * The "Change password" form of My account (MyController#password).
 *
 * Fields: password (the current one), new_password, new_password_confirmation.
 */
final class PasswordForm
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountMailer $mailer,
        private readonly MySidebar $sidebar,
        private readonly Localizer $i18n,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /** Users of an external authentication source cannot change their password here */
    public function isAvailable(User $user): bool
    {
        return $this->accounts->changePasswordAllowed($user);
    }

    /** Changes the password when the submitted values are right; nothing is changed when errors are returned */
    public function submit(User $user, Request $request): PasswordErrors
    {
        $current = (string) $request->request->get('password', '');
        $new = (string) $request->request->get('new_password', '');
        if (!$this->accounts->checkPassword($user, $current)) {
            return new PasswordErrors($this->i18n->l('notice_account_wrong_password'));
        }
        if ($current === $new) {
            return new PasswordErrors($this->i18n->l('notice_new_password_must_be_different'));
        }
        $errors = $this->accounts->passwordErrors($user, $new, (string) $request->request->get('new_password_confirmation', ''));
        if ([] !== $errors) {
            return new PasswordErrors(null, $errors);
        }
        $this->accounts->setPassword($user, $new);
        $user->mustChangePasswd = false;
        $this->em->flush();
        // keep the user logged in with the new credentials
        $this->security->login($user, 'form_login', 'main');
        $this->mailer->passwordUpdated($user, $user, $request->getClientIp());

        return new PasswordErrors();
    }

    /** @return array<string, mixed> what the page shows */
    public function view(User $user, ?PasswordErrors $errors = null): array
    {
        return [
            'user' => $user,
            'error' => $errors?->error,
            'errors' => $errors->errors ?? [],
            'must_change' => $this->accounts->mustChangePassword($user),
        ] + $this->sidebar->vars($user);
    }
}
