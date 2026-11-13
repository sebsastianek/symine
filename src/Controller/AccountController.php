<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Principal;
use App\Entity\User;
use App\Mailer\AccountMailer;
use App\Service\AccountService;
use App\Service\CustomFieldService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of Redmine's AccountController (account lifecycle): self registration, account activation,
 * activation e-mail resend and password recovery. Login/logout are handled by SecurityController.
 */
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountMailer $mailer,
        private readonly CustomFieldService $customFields,
        private readonly Security $security,
    ) {
    }

    #[Route('/account/register', name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $session = $request->getSession();
        $authSourceRegistration = $session->get('auth_source_registration');
        if (('0' === $this->settings->selfRegistration() || '' === $this->settings->selfRegistration()) && !\is_array($authSourceRegistration)) {
            return $this->redirectToRoute('home');
        }
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
            $user->firstname = trim((string) ($params['firstname'] ?? ''));
            $user->lastname = trim((string) ($params['lastname'] ?? ''));
            $user->setMail(trim((string) ($params['mail'] ?? '')));
            if (isset($params['language']) && !$this->settings->bool('force_default_language_for_loggedin')) {
                $user->language = (string) $params['language'];
            }
            $user->admin = false;
            $user->status = Principal::STATUS_REGISTERED;
            $pref = $request->request->all('pref');
            if (\array_key_exists('hide_mail', $pref)) {
                $user->getPref()->hideMail = '1' === (string) $pref['hide_mail'];
            }
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
                $this->em->persist($user);
                $this->em->flush();
                $this->customFields->saveValues('Principal', (int) $user->id, $fields, $cfValues);
                $this->em->flush();

                switch ($mode) {
                    case '1':
                        return $this->registerByEmailActivation($user);
                    case '3':
                        $this->security->login($user, 'form_login', 'main');
                        $this->flashNotice('notice_account_activated');

                        return $this->redirectToRoute('my_account');
                    default:
                        $this->mailer->accountActivationRequest($user);

                        return $this->accountPending($request, $user);
                }
            }
        }

        $response = $this->render('account/register.html.twig', [
            'reg_user' => $user, // "user" is the current user in base.html.twig
            'errors' => $errors,
            'custom_fields' => $fields,
            'custom_values' => $cfValues,
            'languages' => $this->accounts->languageOptions(),
            'body_css' => 'controller-account action-register',
            'page_title' => $this->l('label_register'),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** Registration of an LDAP user whose on-the-fly creation failed (AccountController#register with session[:auth_source_registration]) */
    private function registerFromAuthSource(Request $request, User $user, array $registration, array $fields, array $cfValues): Response
    {
        $params = $request->request->all('user');
        $user->login = (string) $registration['login'];
        $user->authSourceId = (int) $registration['auth_source_id'];
        $user->firstname = trim((string) ($params['firstname'] ?? ''));
        $user->lastname = trim((string) ($params['lastname'] ?? ''));
        $user->setMail(trim((string) ($params['mail'] ?? '')));
        if (isset($params['language']) && !$this->settings->bool('force_default_language_for_loggedin')) {
            $user->language = (string) $params['language'];
        }
        $user->admin = false;
        $user->status = Principal::STATUS_ACTIVE;
        $user->hashedPassword = '';
        $pref = $request->request->all('pref');
        if (\array_key_exists('hide_mail', $pref)) {
            $user->getPref()->hideMail = '1' === (string) $pref['hide_mail'];
        }
        $cfValues = array_replace($cfValues, (array) ($params['custom_field_values'] ?? []));
        $errors = array_merge($this->accounts->userErrors($user, true, true), $this->accounts->customFieldErrors($fields, $cfValues));
        if ([] === $errors) {
            $this->em->persist($user);
            $this->em->flush();
            $this->customFields->saveValues('Principal', (int) $user->id, $fields, $cfValues);
            $this->em->flush();
            $request->getSession()->remove('auth_source_registration');
            $this->security->login($user, 'form_login', 'main');
            $this->flashNotice('notice_account_activated');

            return $this->redirectToRoute('my_account');
        }

        $response = $this->render('account/register.html.twig', [
            'reg_user' => $user, // "user" is the current user in base.html.twig
            'errors' => $errors,
            'custom_fields' => $fields,
            'custom_values' => $cfValues,
            'languages' => $this->accounts->languageOptions(),
            'body_css' => 'controller-account action-register',
            'page_title' => $this->l('label_register'),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/account/activate', name: 'account_activate', methods: ['GET'])]
    public function activate(Request $request): Response
    {
        $value = (string) $request->query->get('token', '');
        $mode = $this->settings->selfRegistration();
        if ('0' === $mode || '' === $mode || '' === $value) {
            return $this->redirectToRoute('home');
        }
        $token = $this->accounts->findToken('register', $value);
        if (null === $token || $this->accounts->isTokenExpired($token)) {
            return $this->redirectToRoute('home');
        }
        $user = $token->user;
        if (!$user->isRegistered()) {
            return $this->redirectToRoute('home');
        }
        $user->status = Principal::STATUS_ACTIVE;
        $this->em->remove($token);
        $this->em->flush();
        $this->flashNotice('notice_account_activated');

        return $this->redirectToRoute('signin');
    }

    #[Route('/account/activation_email', name: 'account_activation_email', methods: ['GET'])]
    public function activationEmail(Request $request): Response
    {
        $session = $request->getSession();
        if ($session->has('registered_user_id') && '1' === $this->settings->selfRegistration()) {
            $userId = (int) $session->remove('registered_user_id');
            $user = $this->em->find(User::class, $userId);
            if ($user instanceof User && $user->isRegistered()) {
                return $this->registerByEmailActivation($user);
            }
        }

        return $this->redirectToRoute('home');
    }

    #[Route('/account/lost_password', name: 'lost_password', methods: ['GET', 'POST'])]
    public function lostPassword(Request $request): Response
    {
        if (!$this->settings->bool('lost_password')) {
            return $this->redirectToRoute('home');
        }
        $session = $request->getSession();
        $tokenValue = $request->request->get('token') ?? $request->query->get('token') ?? $session->get('password_recovery_token');
        if (null !== $tokenValue && '' !== $tokenValue) {
            $token = $this->accounts->findToken('recovery', (string) $tokenValue);
            if (null === $token) {
                return $this->redirectToRoute('home');
            }
            if ($this->accounts->isTokenExpired($token)) {
                $session->remove('password_recovery_token');
                $this->flashError($this->l('error_token_expired'));

                return $this->redirectToRoute('lost_password');
            }
            // the token is moved from the URL to the session, then the user is redirected
            if ($request->query->has('token') && '' !== $request->query->get('token')) {
                $session->set('password_recovery_token', $token->value);

                return $this->redirectToRoute('lost_password');
            }
            $user = $token->user;
            if (!$user->isActive()) {
                return $this->redirectToRoute('home');
            }
            $errors = [];
            $error = null;
            if ($request->isMethod('POST')) {
                $new = (string) $request->request->get('new_password', '');
                if ($this->accounts->mustChangePassword($user) && $this->accounts->checkPassword($user, $new)) {
                    $error = $this->l('notice_new_password_must_be_different');
                } else {
                    $errors = $this->accounts->passwordErrors($user, $new, (string) $request->request->get('new_password_confirmation', ''));
                    if ([] === $errors) {
                        $this->accounts->setPassword($user, $new);
                        $user->mustChangePasswd = false;
                        $this->em->remove($token);
                        $this->em->flush();
                        $session->remove('password_recovery_token');
                        $this->mailer->passwordUpdated($user, $user, $request->getClientIp());
                        $this->flashNotice('notice_account_password_updated');

                        return $this->redirectToRoute('signin');
                    }
                }
            }
            $response = $this->render('account/password_recovery.html.twig', [
                'token' => $token,
                'errors' => $errors,
                'error' => $error,
                'body_css' => 'controller-account action-lost_password',
                'page_title' => $this->l('label_password_lost'),
            ]);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('mail', ''));
            $user = $this->accounts->findByMail($email);
            if (null === $user) {
                $this->flashNotice('notice_account_lost_email_sent');

                return $this->redirectToRoute('signin');
            }
            if (!$user->isActive()) {
                return $this->handleInactiveUser($request, $user, 'lost_password');
            }
            if (!$this->accounts->changePasswordAllowed($user)) {
                $error = $this->l('notice_can_t_change_password');
            } else {
                $token = $this->accounts->createToken($user, 'recovery');
                $recipient = $user->getMail();
                foreach ($user->emailAddresses as $a) {
                    if (0 === strcasecmp($a->address, $email)) {
                        $recipient = $a->address;
                    }
                }
                $this->mailer->lostPassword($user, $token, $recipient);
                $this->flashNotice('notice_account_lost_email_sent');

                return $this->redirectToRoute('signin');
            }
        }

        return $this->render('account/lost_password.html.twig', [
            'error' => $error,
            'body_css' => 'controller-account action-lost_password',
            'page_title' => $this->l('label_password_lost'),
        ]);
    }

    private function registerByEmailActivation(User $user): Response
    {
        $token = $this->accounts->createToken($user, 'register');
        $this->mailer->register($user, $token);
        $this->addFlash('notice', $this->l('notice_account_register_done', ['email' => (string) $user->getMail()]));

        return $this->redirectToRoute('signin');
    }

    private function handleInactiveUser(Request $request, User $user, string $redirectRoute = 'signin'): Response
    {
        if ($user->isRegistered()) {
            return $this->accountPending($request, $user, $redirectRoute);
        }
        $this->flashError($this->l('notice_account_locked'));

        return $this->redirectToRoute($redirectRoute);
    }

    private function accountPending(Request $request, User $user, string $redirectRoute = 'signin'): Response
    {
        if ('1' === $this->settings->selfRegistration()) {
            $this->flashError(strip_tags($this->l('notice_account_not_activated_yet', ['url' => $this->generateUrl('account_activation_email')])));
            $request->getSession()->set('registered_user_id', $user->id);
        } else {
            $this->flashError($this->l('notice_account_pending'));
        }

        return $this->redirectToRoute($redirectRoute);
    }
}
