<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\Principal;
use App\Entity\User;
use App\I18n\Localizer;
use App\Mailer\AccountMailer;
use App\Service\AccountService;
use App\Service\CustomFieldService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The registration form (AccountController#register): a visitor creates their account, which is activated
 * according to the "self_registration" setting (1: by e-mail, 2: by an administrator, 3: automatically).
 * It also completes the account of an LDAP user whose on-the-fly creation failed (session[:auth_source_registration]).
 *
 * Fields: user[login|firstname|lastname|mail|language|password|password_confirmation|custom_field_values],
 * pref[hide_mail].
 */
final class RegistrationForm
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountActivation $activation,
        private readonly AccountMailer $mailer,
        private readonly CustomFieldService $customFields,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /** Whether visitors may register, or this visitor is an LDAP user completing their account */
    public function isOpen(SessionInterface $session): bool
    {
        $mode = $this->settings->selfRegistration();

        return ('0' !== $mode && '' !== $mode) || \is_array($session->get('auth_source_registration'));
    }

    public function handle(Request $request): RegistrationResult
    {
        $session = $request->getSession();
        $authSourceRegistration = $session->get('auth_source_registration');
        $user = $this->accounts->newUser();
        $user->language = $this->i18n->locale();
        $fields = $this->accounts->editableUserCustomFields($user, true);
        $cfValues = $this->customFields->defaultValues($fields);
        $errors = [];
        if (!$request->isMethod('POST') && \is_array($prefill = $session->remove('auth_source_registration_user')) && \is_array($authSourceRegistration)) {
            // onthefly_creation_failed: LDAP attributes that could not be saved
            $user->login = (string) $authSourceRegistration['login'];
            $user->authSourceId = (int) $authSourceRegistration['auth_source_id'];
            $user->firstname = (string) ($prefill['firstname'] ?? '');
            $user->lastname = (string) ($prefill['lastname'] ?? '');
            $user->setMail((string) ($prefill['mail'] ?? ''));
            $errors = $this->accounts->userErrors($user, true, true);
        } elseif (!$request->isMethod('POST')) {
            $session->remove('auth_source_registration');
        } elseif (\is_array($authSourceRegistration)) {
            return $this->registerFromAuthSource($request, $user, $authSourceRegistration, $fields, $cfValues);
        }
        if ($request->isMethod('POST')) {
            $params = $request->request->all('user');
            $user->login = trim((string) ($params['login'] ?? ''));
            $this->assign($user, $request);
            $user->status = Principal::STATUS_REGISTERED;
            $cfValues = array_replace($cfValues, (array) ($params['custom_field_values'] ?? []));
            $password = (string) ($params['password'] ?? '');
            $confirmation = (string) ($params['password_confirmation'] ?? '');

            $errors = $this->accounts->userErrors($user, true, true);
            $errors = array_merge($errors, $this->accounts->passwordErrors($user, $password, $confirmation));
            $errors = array_merge($errors, $this->accounts->customFieldErrors($fields, $cfValues));
            if ([] === $errors) {
                $this->accounts->setPassword($user, $password);
                $mode = $this->settings->selfRegistration();
                if ('3' === $mode) {
                    // automatic activation
                    $user->status = Principal::STATUS_ACTIVE;
                    $user->lastLoginOn = new \DateTimeImmutable();
                }
                $this->save($user, $fields, $cfValues);

                switch ($mode) {
                    case '1':
                        $this->activation->sendEmail($user);

                        return new RegistrationResult(RegistrationOutcome::ActivationEmailSent, $user);
                    case '3':
                        $this->security->login($user, 'form_login', 'main');

                        return new RegistrationResult(RegistrationOutcome::Activated, $user);
                    default:
                        $this->mailer->accountActivationRequest($user);

                        return new RegistrationResult(RegistrationOutcome::Pending, $user);
                }
            }
        }

        return $this->form($user, $errors, $fields, $cfValues);
    }

    /**
     * Registration of an LDAP user whose on-the-fly creation failed (AccountController#register with
     * session[:auth_source_registration])
     *
     * @param array<string, mixed>     $registration
     * @param list<mixed>              $fields
     * @param array<int|string, mixed> $cfValues
     */
    private function registerFromAuthSource(Request $request, User $user, array $registration, array $fields, array $cfValues): RegistrationResult
    {
        $params = $request->request->all('user');
        $user->login = (string) $registration['login'];
        $user->authSourceId = (int) $registration['auth_source_id'];
        $this->assign($user, $request);
        $user->status = Principal::STATUS_ACTIVE;
        $user->hashedPassword = '';
        $cfValues = array_replace($cfValues, (array) ($params['custom_field_values'] ?? []));
        $errors = array_merge($this->accounts->userErrors($user, true, true), $this->accounts->customFieldErrors($fields, $cfValues));
        if ([] === $errors) {
            $this->save($user, $fields, $cfValues);
            $request->getSession()->remove('auth_source_registration');
            $this->security->login($user, 'form_login', 'main');

            return new RegistrationResult(RegistrationOutcome::Activated, $user);
        }

        return $this->form($user, $errors, $fields, $cfValues);
    }

    /** The attributes a visitor may set on their account, whatever the kind of registration */
    private function assign(User $user, Request $request): void
    {
        $params = $request->request->all('user');
        $user->firstname = trim((string) ($params['firstname'] ?? ''));
        $user->lastname = trim((string) ($params['lastname'] ?? ''));
        $user->setMail(trim((string) ($params['mail'] ?? '')));
        if (isset($params['language']) && !$this->settings->bool('force_default_language_for_loggedin')) {
            $user->language = (string) $params['language'];
        }
        $user->admin = false;
        $pref = $request->request->all('pref');
        if (\array_key_exists('hide_mail', $pref)) {
            $user->getPref()->hideMail = '1' === (string) $pref['hide_mail'];
        }
    }

    /**
     * @param list<mixed>              $fields
     * @param array<int|string, mixed> $cfValues
     */
    private function save(User $user, array $fields, array $cfValues): void
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->customFields->saveValues('Principal', (int) $user->id, $fields, $cfValues);
        $this->em->flush();
    }

    /**
     * @param list<string>             $errors
     * @param list<mixed>              $fields
     * @param array<int|string, mixed> $cfValues
     */
    private function form(User $user, array $errors, array $fields, array $cfValues): RegistrationResult
    {
        return new RegistrationResult(RegistrationOutcome::Form, $user, [
            'reg_user' => $user, // "user" is the current user in base.html.twig
            'errors' => $errors,
            'custom_fields' => $fields,
            'custom_values' => $cfValues,
            'languages' => $this->accounts->languageOptions(),
        ]);
    }
}
