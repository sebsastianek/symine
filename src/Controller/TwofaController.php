<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Twofa\TwofaAccess;
use App\Security\Twofa\TwofaBackupCodes;
use App\Security\Twofa\TwofaCode;
use App\Security\Twofa\TwofaLoginFlow;
use App\Security\Twofa\TwofaLoginSubscriber;
use App\Security\Twofa\TwofaManager;
use App\Security\Twofa\TwofaView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Two-factor authentication: ports of Redmine's TwofaController (pairing / unpairing / admin
 * deactivation), TwofaBackupCodesController and the 2FA actions of AccountController (second
 * login step, see TwofaLoginSubscriber).
 */
final class TwofaController extends AbstractController
{
    public function __construct(
        private readonly TwofaManager $twofa,
        private readonly TwofaAccess $access,
        private readonly TwofaView $view,
        private readonly TwofaBackupCodes $backupCodes,
        private readonly TwofaLoginFlow $loginFlow,
    ) {
    }

    // --- TwofaController -------------------------------------------------------------

    #[Route('/my/twofa/select_scheme', name: 'twofa_select_scheme', methods: ['GET'])]
    public function selectScheme(): Response
    {
        $user = $this->access->user();
        if (TwofaManager::isActive($user)) {
            $this->addFlash('warning', $this->l('twofa_already_setup'));

            return $this->redirectToRoute('my_account');
        }

        return $this->render('twofa/select_scheme.html.twig', $this->view->selectScheme($user) + [
            'page_title' => $this->l('twofa_label_setup'),
            'body_css' => 'controller-twofa action-select_scheme',
        ]);
    }

    #[Route('/my/twofa/activate/init', name: 'twofa_activate_init_default', methods: ['POST'])]
    #[Route('/my/twofa/{scheme}/activate/init', name: 'twofa_activate_init', requirements: ['scheme' => '[^/]+'], methods: ['POST'])]
    public function activateInit(Request $request, ?string $scheme = null): Response
    {
        $user = $this->access->user();
        if (TwofaManager::isActive($user)) {
            $this->addFlash('warning', $this->l('twofa_already_setup'));

            return $this->redirectToRoute('my_account');
        }
        $scheme ??= (string) $request->request->get('scheme', '');
        if (!$this->twofa->isScheme($scheme)) {
            return $this->redirectToRoute('my_account');
        }
        // init_twofa_pairing_and_send_code_for (TOTP sends no code)
        $this->twofa->initPairing($user);

        return $this->redirectToRoute('twofa_activate_confirm', ['scheme' => $scheme]);
    }

    #[Route('/my/twofa/{scheme}/activate/confirm', name: 'twofa_activate_confirm', requirements: ['scheme' => '[^/]+'], methods: ['GET'])]
    public function activateConfirm(string $scheme): Response
    {
        $user = $this->access->user();
        if (TwofaManager::isActive($user)) {
            $this->addFlash('warning', $this->l('twofa_already_setup'));

            return $this->redirectToRoute('my_account');
        }
        if (!$this->twofa->isScheme($scheme)) {
            return $this->redirectToRoute('my_account');
        }
        $this->twofa->ensurePairing($user);
        $response = $this->render('twofa/activate_confirm.html.twig', $this->view->activateConfirm($user, $scheme) + [
            'page_title' => $this->l('twofa_label_setup'),
            'body_css' => 'controller-twofa action-activate_confirm',
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/my/twofa/{scheme}/activate', name: 'twofa_activate', requirements: ['scheme' => '[^/]+'], methods: ['GET', 'POST'])]
    public function activate(Request $request, string $scheme): Response
    {
        $user = $this->access->user();
        if (TwofaManager::isActive($user)) {
            $this->addFlash('warning', $this->l('twofa_already_setup'));

            return $this->redirectToRoute('my_account');
        }
        if (!$this->twofa->isScheme($scheme)) {
            return $this->redirectToRoute('my_account');
        }
        if ($this->twofa->confirmPairing($user, $scheme, TwofaCode::fromRequest($request))) {
            $request->getSession()->remove(TwofaLoginSubscriber::SESSION_MUST_ACTIVATE);
            $this->addFlash('notice_html', $this->l('twofa_activated', ['bc_path' => $this->generateUrl('twofa_backup_codes_init')]));

            return $this->redirectToRoute('my_account');
        }
        $this->flashError($this->l('twofa_invalid_code'));

        return $this->redirectToRoute('twofa_activate_confirm', ['scheme' => $scheme]);
    }

    #[Route('/my/twofa/{scheme}/deactivate/init', name: 'twofa_deactivate_init', requirements: ['scheme' => '[^/]+'], methods: ['POST'])]
    public function deactivateInit(string $scheme): Response
    {
        $user = $this->access->user();
        if ($scheme !== (string) $user->twofaScheme) {
            return $this->redirectToRoute('my_account');
        }

        return $this->redirectToRoute('twofa_deactivate_confirm', ['scheme' => $scheme]);
    }

    #[Route('/my/twofa/{scheme}/deactivate/confirm', name: 'twofa_deactivate_confirm', requirements: ['scheme' => '[^/]+'], methods: ['GET'])]
    public function deactivateConfirm(string $scheme): Response
    {
        $user = $this->access->user();
        if ($scheme !== (string) $user->twofaScheme) {
            return $this->redirectToRoute('my_account');
        }

        return $this->render('twofa/deactivate_confirm.html.twig', $this->view->deactivateConfirm($user, $scheme) + [
            'page_title' => $this->l('twofa_label_deactivation_confirmation'),
            'body_css' => 'controller-twofa action-deactivate_confirm',
        ]);
    }

    #[Route('/my/twofa/{scheme}/deactivate', name: 'twofa_deactivate', requirements: ['scheme' => '[^/]+'], methods: ['GET', 'POST'])]
    public function deactivate(Request $request, string $scheme): Response
    {
        $user = $this->access->user();
        if ($scheme !== (string) $user->twofaScheme) {
            return $this->redirectToRoute('my_account');
        }
        if ($this->twofa->destroyPairing($user, TwofaCode::fromRequest($request))) {
            $this->flashNotice('twofa_deactivated');

            return $this->redirectToRoute('my_account');
        }
        $this->flashError($this->l('twofa_invalid_code'));

        return $this->redirectToRoute('twofa_deactivate_confirm', ['scheme' => $scheme]);
    }

    #[Route('/users/{user_id}/twofa/deactivate', name: 'twofa_admin_deactivate', requirements: ['user_id' => '\d+'], methods: ['POST'])]
    public function adminDeactivate(int $user_id): Response
    {
        $user = $this->access->userToUnpair($user_id);
        $this->twofa->unpairByAdministrator($user);
        $this->flashNotice('twofa_deactivated');

        return $this->redirectToRoute('user_edit', ['id' => $user->id]);
    }

    // --- TwofaBackupCodesController -----------------------------------------------------
    // Redmine::Twofa.for_user is nil without an active scheme: such requests go back to My account

    #[Route('/my/twofa/backup_codes/init', name: 'twofa_backup_codes_init', methods: ['POST'])]
    public function backupCodesInit(): Response
    {
        $user = $this->access->user();
        if (!TwofaManager::isActive($user)) {
            return $this->redirectToRoute('my_account');
        }

        return $this->redirectToRoute('twofa_backup_codes_confirm');
    }

    #[Route('/my/twofa/backup_codes/confirm', name: 'twofa_backup_codes_confirm', methods: ['GET'])]
    public function backupCodesConfirm(): Response
    {
        $user = $this->access->user();
        if (!TwofaManager::isActive($user)) {
            return $this->redirectToRoute('my_account');
        }
        $response = $this->render('twofa_backup_codes/confirm.html.twig', $this->view->backupCodesConfirm($user) + [
            'page_title' => $this->l('twofa_generate_backup_codes'),
            'body_css' => 'controller-twofa_backup_codes action-confirm',
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/my/twofa/backup_codes/create', name: 'twofa_backup_codes_create', methods: ['GET', 'POST'])]
    public function backupCodesCreate(Request $request): Response
    {
        $user = $this->access->user();
        if (!TwofaManager::isActive($user)) {
            return $this->redirectToRoute('my_account');
        }
        if (!$this->twofa->verify($user, TwofaCode::fromRequest($request))) {
            $this->flashError($this->l('twofa_invalid_code'));

            return $this->redirectToRoute('twofa_backup_codes_confirm');
        }
        $invalidated = $this->backupCodes->generate($user, $request->getSession());
        if (null !== $invalidated) {
            $this->addFlash('warning', $this->l('twofa_warning_backup_codes_generated_invalidated', ['time' => $this->i18n->formatTime($invalidated)]));
        } else {
            $this->flashNotice('twofa_notice_backup_codes_generated');
        }

        return $this->redirectToRoute('twofa_backup_codes');
    }

    #[Route('/my/twofa/backup_codes', name: 'twofa_backup_codes', methods: ['GET'])]
    public function backupCodesShow(Request $request): Response
    {
        $user = $this->access->user();
        if (!TwofaManager::isActive($user)) {
            return $this->redirectToRoute('my_account');
        }
        $codes = $this->backupCodes->reveal($user, $request->getSession());
        if (null === $codes) {
            $this->addFlash('warning_html', $this->l('twofa_backup_codes_already_shown', ['bc_path' => $this->generateUrl('twofa_backup_codes_init')]));

            return $this->redirectToRoute('my_account');
        }
        $response = $this->render('twofa_backup_codes/show.html.twig', $this->view->backupCodes($user, $codes) + [
            'page_title' => $this->l('twofa_label_backup_codes'),
            'body_css' => 'controller-twofa_backup_codes action-show',
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    // --- AccountController (second login step) --------------------------------------------

    #[Route('/account/twofa/confirm', name: 'twofa_confirm', methods: ['GET'])]
    public function loginConfirm(Request $request): Response
    {
        $this->access->requireActiveTwofa();
        if (null === $this->loginFlow->pendingUser($request->getSession())) {
            return $this->redirectToRoute('home');
        }
        $response = $this->render('account/twofa_confirm.html.twig', [
            'resendable' => false,
            'page_title' => $this->l('setting_twofa'),
            'body_css' => 'controller-account action-twofa_confirm',
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/account/twofa/resend', name: 'twofa_resend', methods: ['POST'])]
    public function loginResend(Request $request): Response
    {
        $this->access->requireActiveTwofa();
        $session = $request->getSession();
        $user = $this->loginFlow->pendingUser($session);
        if (null === $user) {
            return $this->redirectToRoute('home');
        }
        $this->loginFlow->renew($session, $user);
        // otp resends count toward the maximum of 3 otp entry tries per password entry
        if ($this->loginFlow->triesExhausted($session)) {
            $this->flashError($this->l('twofa_too_many_tries'));

            return $this->redirectToRoute('home');
        }

        // TOTP does not send codes
        return $this->redirectToRoute('twofa_confirm');
    }

    #[Route('/account/twofa', name: 'twofa_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $this->access->requireActiveTwofa();
        $session = $request->getSession();
        $user = $this->loginFlow->pendingUser($session);
        if (null === $user) {
            return $this->redirectToRoute('home');
        }
        // prevent_twofa_session_replay
        $this->loginFlow->renew($session, $user);
        if ($this->twofa->verify($user, TwofaCode::fromRequest($request))) {
            // redirect_back_or_default(my_page_path)
            $backUrl = $this->loginFlow->complete($request, $user);

            return null !== $backUrl ? new RedirectResponse($backUrl) : $this->redirectToRoute('my_page');
        }
        // allow at most 3 otp entry tries per successful password entry
        if ($this->loginFlow->triesExhausted($session)) {
            $this->flashError($this->l('twofa_too_many_tries'));

            return $this->redirectToRoute('home');
        }
        $this->flashError($this->l('twofa_invalid_code'));

        return $this->redirectToRoute('twofa_confirm');
    }
}
