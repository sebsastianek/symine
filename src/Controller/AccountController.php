<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Account\AccountActivation;
use App\Service\Account\PasswordRecovery;
use App\Service\Account\PasswordRecoveryStep;
use App\Service\Account\RegistrationForm;
use App\Service\Account\RegistrationOutcome;
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
        private readonly RegistrationForm $registration,
        private readonly AccountActivation $activation,
        private readonly PasswordRecovery $recovery,
    ) {
    }

    #[Route('/account/register', name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if (!$this->registration->isOpen($request->getSession())) {
            return $this->redirectToRoute('home');
        }
        $result = $this->registration->handle($request);
        switch ($result->outcome) {
            case RegistrationOutcome::Activated:
                $this->flashNotice('notice_account_activated');

                return $this->redirectToRoute('my_account');
            case RegistrationOutcome::ActivationEmailSent:
                $this->addFlash('notice', $this->l('notice_account_register_done', ['email' => (string) $result->user->getMail()]));

                return $this->redirectToRoute('signin');
            case RegistrationOutcome::Pending:
                $this->addFlash(...$this->activation->pendingNotice($result->user, $request->getSession()));

                return $this->redirectToRoute('signin');
            case RegistrationOutcome::Form:
                break;
        }

        $response = $this->render('account/register.html.twig', $result->view + [
            'body_css' => 'controller-account action-register',
            'page_title' => $this->l('label_register'),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/account/activate', name: 'account_activate', methods: ['GET'])]
    public function activate(Request $request): Response
    {
        if (!$this->activation->activate((string) $request->query->get('token', ''))) {
            return $this->redirectToRoute('home');
        }
        $this->flashNotice('notice_account_activated');

        return $this->redirectToRoute('signin');
    }

    #[Route('/account/activation_email', name: 'account_activation_email', methods: ['GET'])]
    public function activationEmail(Request $request): Response
    {
        $user = $this->activation->resendEmail($request->getSession());
        if (null === $user) {
            return $this->redirectToRoute('home');
        }
        $this->addFlash('notice', $this->l('notice_account_register_done', ['email' => (string) $user->getMail()]));

        return $this->redirectToRoute('signin');
    }

    #[Route('/account/lost_password', name: 'lost_password', methods: ['GET', 'POST'])]
    public function lostPassword(Request $request): Response
    {
        if (!$this->recovery->isEnabled()) {
            return $this->redirectToRoute('home');
        }
        if ($this->recovery->hasToken($request)) {
            $reset = $this->recovery->reset($request);
            switch ($reset->step) {
                case PasswordRecoveryStep::Invalid:
                    return $this->redirectToRoute('home');
                case PasswordRecoveryStep::Expired:
                    $this->flashError($this->l('error_token_expired'));

                    return $this->redirectToRoute('lost_password');
                case PasswordRecoveryStep::TokenStored:
                    return $this->redirectToRoute('lost_password');
                case PasswordRecoveryStep::Done:
                    $this->flashNotice('notice_account_password_updated');

                    return $this->redirectToRoute('signin');
                default:
                    $response = $this->render('account/password_recovery.html.twig', $reset->view + [
                        'body_css' => 'controller-account action-lost_password',
                        'page_title' => $this->l('label_password_lost'),
                    ]);
                    $response->headers->set('Cache-Control', 'no-store');

                    return $response;
            }
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $sent = $this->recovery->request($request);
            switch ($sent->step) {
                case PasswordRecoveryStep::Pending:
                    $this->addFlash(...$this->activation->pendingNotice($sent->user, $request->getSession()));

                    return $this->redirectToRoute('lost_password');
                case PasswordRecoveryStep::Locked:
                    $this->flashError($this->l('notice_account_locked'));

                    return $this->redirectToRoute('lost_password');
                case PasswordRecoveryStep::Refused:
                    $error = $this->l('notice_can_t_change_password');
                    break;
                default:
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
}
