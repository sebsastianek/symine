<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\Twofa\TwofaLoginSubscriber;
use App\Security\Twofa\TwofaManager;
use App\Security\Twofa\TwofaVerifiedBadge;
use App\Service\AccountService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Two-factor authentication: ports of Redmine's TwofaController (pairing / unpairing / admin
 * deactivation), TwofaBackupCodesController and the 2FA actions of AccountController (second
 * login step, see TwofaLoginSubscriber).
 */
final class TwofaController extends AbstractController
{
    private const string FLASH_BACKUP_TOKEN_IDS = 'twofa_backup_token_ids';

    public function __construct(
        private readonly TwofaManager $twofa,
        private readonly TwofaLoginSubscriber $loginSteps,
        private readonly AccountService $accounts,
        private readonly Security $security,
    ) {
    }

    // --- TwofaController -------------------------------------------------------------

    #[Route('/my/twofa/select_scheme', name: 'twofa_select_scheme', methods: ['GET'])]
    public function selectScheme(): Response
    {
        $user = $this->setup();
        if ($r = $this->ensureUserHasNoTwofa($user)) {
            return $r;
        }

        return $this->render('twofa/select_scheme.html.twig', $this->myVars($user) + [
            'schemes' => $this->twofa->availableSchemes(),
            'show_sidebar' => !$this->twofa->mustActivate($user),
            'page_title' => $this->l('twofa_label_setup'),
            'body_css' => 'controller-twofa action-select_scheme',
        ]);
    }

    #[Route('/my/twofa/activate/init', name: 'twofa_activate_init_default', methods: ['POST'])]
    #[Route('/my/twofa/{scheme}/activate/init', name: 'twofa_activate_init', requirements: ['scheme' => '[^/]+'], methods: ['POST'])]
    public function activateInit(Request $request, ?string $scheme = null): Response
    {
        $user = $this->setup();
        if ($r = $this->ensureUserHasNoTwofa($user)) {
            return $r;
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
        $user = $this->setup();
        if ($r = $this->ensureUserHasNoTwofa($user)) {
            return $r;
        }
        if (!$this->twofa->isScheme($scheme)) {
            return $this->redirectToRoute('my_account');
        }
        if (null === $this->twofa->totpKey($user)) {
            $this->twofa->initPairing($user);
        }
        $uri = $this->twofa->provisioningUri($user);
        $response = $this->render('twofa/activate_confirm.html.twig', $this->myVars($user) + [
            'scheme_name' => $scheme,
            'resendable' => false,
            'provisioning_uri' => $uri,
            'qr_code_base64' => base64_encode($this->twofa->qrCodeSvg($uri)),
            'totp_key' => $this->twofa->totpKey($user),
            'show_sidebar' => !$this->twofa->mustActivate($user),
            'page_title' => $this->l('twofa_label_setup'),
            'body_css' => 'controller-twofa action-activate_confirm',
        ]);

        return $this->noStore($response);
    }

    #[Route('/my/twofa/{scheme}/activate', name: 'twofa_activate', requirements: ['scheme' => '[^/]+'], methods: ['GET', 'POST'])]
    public function activate(Request $request, string $scheme): Response
    {
        $user = $this->setup();
        if ($r = $this->ensureUserHasNoTwofa($user)) {
            return $r;
        }
        if (!$this->twofa->isScheme($scheme)) {
            return $this->redirectToRoute('my_account');
        }
        if ($this->twofa->confirmPairing($user, $scheme, $this->code($request))) {
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
        $user = $this->setup();
        if ($scheme !== (string) $user->twofaScheme) {
            return $this->redirectToRoute('my_account');
        }

        return $this->redirectToRoute('twofa_deactivate_confirm', ['scheme' => $scheme]);
    }

    #[Route('/my/twofa/{scheme}/deactivate/confirm', name: 'twofa_deactivate_confirm', requirements: ['scheme' => '[^/]+'], methods: ['GET'])]
    public function deactivateConfirm(string $scheme): Response
    {
        $user = $this->setup();
        if ($scheme !== (string) $user->twofaScheme) {
            return $this->redirectToRoute('my_account');
        }

        return $this->render('twofa/deactivate_confirm.html.twig', $this->myVars($user) + [
            'scheme_name' => $scheme,
            'resendable' => false,
            'page_title' => $this->l('twofa_label_deactivation_confirmation'),
            'body_css' => 'controller-twofa action-deactivate_confirm',
        ]);
    }

    #[Route('/my/twofa/{scheme}/deactivate', name: 'twofa_deactivate', requirements: ['scheme' => '[^/]+'], methods: ['GET', 'POST'])]
    public function deactivate(Request $request, string $scheme): Response
    {
        $user = $this->setup();
        if ($scheme !== (string) $user->twofaScheme) {
            return $this->redirectToRoute('my_account');
        }
        if ($this->twofa->destroyPairing($user, $this->code($request))) {
            $this->flashNotice('twofa_deactivated');

            return $this->redirectToRoute('my_account');
        }
        $this->flashError($this->l('twofa_invalid_code'));

        return $this->redirectToRoute('twofa_deactivate_confirm', ['scheme' => $scheme]);
    }

    #[Route('/users/{user_id}/twofa/deactivate', name: 'twofa_admin_deactivate', requirements: ['user_id' => '\d+'], methods: ['POST'])]
    public function adminDeactivate(int $user_id): Response
    {
        $this->requireLogin();
        $this->requireAdmin();
        $this->requireActiveTwofa();
        $user = $this->em->find(User::class, $user_id);
        if (!$user instanceof User || !$user->isLogged()) {
            throw $this->createNotFoundException();
        }
        // do not allow administrators to unpair 2FA without confirmation for themselves
        if ($user->id === $this->user()->id) {
            $this->deny();
        }
        if (TwofaManager::isActive($user)) {
            $this->twofa->destroyPairingWithoutVerify($user);
        }
        $this->flashNotice('twofa_deactivated');

        return $this->redirectToRoute('user_edit', ['id' => $user->id]);
    }

    // --- TwofaBackupCodesController -----------------------------------------------------

    #[Route('/my/twofa/backup_codes/init', name: 'twofa_backup_codes_init', methods: ['POST'])]
    public function backupCodesInit(): Response
    {
        $user = $this->backupCodesSetup();
        if ($user instanceof Response) {
            return $user;
        }

        return $this->redirectToRoute('twofa_backup_codes_confirm');
    }

    #[Route('/my/twofa/backup_codes/confirm', name: 'twofa_backup_codes_confirm', methods: ['GET'])]
    public function backupCodesConfirm(): Response
    {
        $user = $this->backupCodesSetup();
        if ($user instanceof Response) {
            return $user;
        }

        return $this->noStore($this->render('twofa_backup_codes/confirm.html.twig', $this->myVars($user) + [
            'resendable' => false,
            'page_title' => $this->l('twofa_generate_backup_codes'),
            'body_css' => 'controller-twofa_backup_codes action-confirm',
        ]));
    }

    #[Route('/my/twofa/backup_codes/create', name: 'twofa_backup_codes_create', methods: ['GET', 'POST'])]
    public function backupCodesCreate(Request $request): Response
    {
        $user = $this->backupCodesSetup();
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->twofa->verify($user, $this->code($request))) {
            $this->flashError($this->l('twofa_invalid_code'));

            return $this->redirectToRoute('twofa_backup_codes_confirm');
        }
        $existing = $this->twofa->backupCodes($user);
        if ([] !== $existing) {
            $time = max(array_map(static fn ($t) => $t->createdOn, $existing));
            $this->addFlash('warning', $this->l('twofa_warning_backup_codes_generated_invalidated', ['time' => $this->i18n->formatTime($time)]));
        } else {
            $this->flashNotice('twofa_notice_backup_codes_generated');
        }
        $tokens = $this->twofa->initBackupCodes($user);
        $request->getSession()->set(self::FLASH_BACKUP_TOKEN_IDS, array_map(static fn ($t) => $t->id, $tokens));

        return $this->redirectToRoute('twofa_backup_codes');
    }

    #[Route('/my/twofa/backup_codes', name: 'twofa_backup_codes', methods: ['GET'])]
    public function backupCodesShow(Request $request): Response
    {
        $user = $this->backupCodesSetup();
        if ($user instanceof Response) {
            return $user;
        }
        $session = $request->getSession();
        // flash[:twofa_backup_token_ids]: the codes are shown only once
        $ids = array_map('intval', (array) $session->get(self::FLASH_BACKUP_TOKEN_IDS, []));
        $session->remove(self::FLASH_BACKUP_TOKEN_IDS);
        $tokens = array_values(array_filter($this->twofa->backupCodes($user), static fn ($t) => \in_array($t->id, $ids, true)));
        $createdAt = [] === $tokens ? null : max(array_map(static fn ($t) => $t->createdOn, $tokens));
        if (null === $createdAt || $createdAt <= new \DateTimeImmutable('-5 minutes')) {
            $this->addFlash('warning_html', $this->l('twofa_backup_codes_already_shown', ['bc_path' => $this->generateUrl('twofa_backup_codes_init')]));

            return $this->redirectToRoute('my_account');
        }

        return $this->noStore($this->render('twofa_backup_codes/show.html.twig', $this->myVars($user) + [
            'backup_codes' => array_map(static fn ($t) => $t->value, $tokens),
            'created_at' => $createdAt,
            'page_title' => $this->l('twofa_label_backup_codes'),
            'body_css' => 'controller-twofa_backup_codes action-show',
        ]));
    }

    // --- AccountController (second login step) --------------------------------------------

    #[Route('/account/twofa/confirm', name: 'twofa_confirm', methods: ['GET'])]
    public function loginConfirm(Request $request): Response
    {
        $this->requireActiveTwofa();
        $session = $request->getSession();
        $user = $this->loginSetup($session);
        if (null === $user) {
            return $this->redirectToRoute('home');
        }

        return $this->noStore($this->render('account/twofa_confirm.html.twig', [
            'resendable' => false,
            'page_title' => $this->l('setting_twofa'),
            'body_css' => 'controller-account action-twofa_confirm',
        ]));
    }

    #[Route('/account/twofa/resend', name: 'twofa_resend', methods: ['POST'])]
    public function loginResend(Request $request): Response
    {
        $this->requireActiveTwofa();
        $session = $request->getSession();
        $user = $this->loginSetup($session);
        if (null === $user) {
            return $this->redirectToRoute('home');
        }
        $this->renewLoginSession($session, $user);
        // otp resends count toward the maximum of 3 otp entry tries per password entry
        if ((int) $session->get(TwofaLoginSubscriber::SESSION_TRIES) > 3) {
            $this->loginSteps->destroySession($session);
            $this->flashError($this->l('twofa_too_many_tries'));

            return $this->redirectToRoute('home');
        }

        // TOTP does not send codes
        return $this->redirectToRoute('twofa_confirm');
    }

    #[Route('/account/twofa', name: 'twofa_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $this->requireActiveTwofa();
        $session = $request->getSession();
        $user = $this->loginSetup($session);
        if (null === $user) {
            return $this->redirectToRoute('home');
        }
        // prevent_twofa_session_replay
        $this->renewLoginSession($session, $user);
        if ($this->twofa->verify($user, $this->code($request))) {
            $backUrl = $session->get(TwofaLoginSubscriber::SESSION_BACK_URL);
            $autologin = null !== $session->get(TwofaLoginSubscriber::SESSION_AUTOLOGIN) && $this->settings->int('autologin') > 0;
            $this->loginSteps->destroySession($session);
            // handle_active_user / successful_authentication
            $badges = [new TwofaVerifiedBadge()];
            if ($autologin) {
                $badges[] = (new RememberMeBadge())->enable();
            }
            $this->security->login($user, 'form_login', 'main', $badges);
            if ($this->twofa->mustActivate($user)) {
                $session->set(TwofaLoginSubscriber::SESSION_MUST_ACTIVATE, '1');
            }

            return $this->redirectBack($request, \is_string($backUrl) ? $backUrl : null);
        }
        // allow at most 3 otp entry tries per successful password entry
        if ((int) $session->get(TwofaLoginSubscriber::SESSION_TRIES) > 3) {
            $this->loginSteps->destroySession($session);
            $this->flashError($this->l('twofa_too_many_tries'));

            return $this->redirectToRoute('home');
        }
        $this->flashError($this->l('twofa_invalid_code'));

        return $this->redirectToRoute('twofa_confirm');
    }

    // ------------------------------------------------------------------------------------

    /** before_action :require_login, :require_active_twofa (+ @user = User.current) */
    private function setup(): User
    {
        $this->requireLogin();
        $this->requireActiveTwofa();

        return $this->user();
    }

    /** Redmine::Twofa.for_user is nil without an active scheme: such requests go back to My account */
    private function backupCodesSetup(): User|Response
    {
        $user = $this->setup();

        return TwofaManager::isActive($user) ? $user : $this->redirectToRoute('my_account');
    }

    /** TwofaHelper#require_active_twofa: Setting.twofa? ? true : deny_access */
    private function requireActiveTwofa(): void
    {
        if (!$this->twofa->enabled()) {
            $this->deny();
        }
    }

    private function ensureUserHasNoTwofa(User $user): ?Response
    {
        if (!TwofaManager::isActive($user)) {
            return null;
        }
        $this->addFlash('warning', $this->l('twofa_already_setup'));

        return $this->redirectToRoute('my_account');
    }

    /** AccountController#twofa_setup: the pending user (2 minutes validity), or null after cleanup */
    private function loginSetup(SessionInterface $session): ?User
    {
        $user = $this->twofa->findSessionUser((string) $session->get(TwofaLoginSubscriber::SESSION_TOKEN, ''));
        if (null === $user) {
            $this->loginSteps->destroySession($session);

            return null;
        }
        if (null !== $user->language && '' !== $user->language) {
            $this->i18n->setLocale($user->language);
        }

        return $user;
    }

    /** AccountController#renew_twofa_session: each twofa session token is used for one request only */
    private function renewLoginSession(SessionInterface $session, User $user): void
    {
        $tries = (int) $session->get(TwofaLoginSubscriber::SESSION_TRIES, 0) + 1;
        $backUrl = $session->get(TwofaLoginSubscriber::SESSION_BACK_URL);
        $autologin = $session->get(TwofaLoginSubscriber::SESSION_AUTOLOGIN);
        $this->loginSteps->destroySession($session);
        $this->loginSteps->setupSession($session, $user, $tries, $backUrl, \is_string($autologin) ? $autologin : null);
    }

    private function code(Request $request): string
    {
        $code = $request->request->get('twofa_code', $request->query->get('twofa_code', ''));

        return \is_scalar($code) ? (string) $code : '';
    }

    /** redirect_back_or_default(my_page_path) */
    private function redirectBack(Request $request, ?string $back): Response
    {
        if (null !== $back && '' !== $back) {
            $parts = parse_url($back);
            if (false !== $parts) {
                $local = !isset($parts['host']) && str_starts_with($back, '/') && !str_starts_with($back, '//');
                $sameHost = isset($parts['host']) && strtolower($parts['host']) === strtolower($request->getHost()) && \in_array($parts['scheme'] ?? '', ['http', 'https'], true);
                $path = $parts['path'] ?? '/';
                if (($local || $sameHost) && !preg_match('#/(login|account/register|account/lost_password)\b#', $path)) {
                    return new RedirectResponse($back);
                }
            }
        }

        return $this->redirectToRoute('my_page');
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** Variables of the my/_sidebar partial */
    private function myVars(User $user): array
    {
        return [
            'user' => $user,
            'deletable' => $this->accounts->ownAccountDeletable($user),
            'atom_token' => $this->accounts->tokenOf($user, 'feeds'),
            'api_token' => $this->accounts->tokenOf($user, 'api'),
        ];
    }
}
