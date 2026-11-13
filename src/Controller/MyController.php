<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\IssuePriority;
use App\Entity\User;
use App\Event\ContentEvent;
use App\Mailer\AccountMailer;
use App\Service\AccountService;
use App\Service\CustomFieldService;
use App\Service\MyPageService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's MyController: My page (blocks), My account, password, API/feeds keys, account deletion. */
final class MyController extends AbstractController
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly MyPageService $myPage,
        private readonly CustomFieldService $customFields,
        private readonly AccountMailer $mailer,
        private readonly ApiRenderer $api,
        private readonly Security $security,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    // --- My page ------------------------------------------------------------------

    #[Route('/my', name: 'my_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page();
    }

    #[Route('/my/page', name: 'my_page', methods: ['GET'])]
    public function page(): Response
    {
        $this->requireLogin();
        $user = $this->user();
        $layout = $this->myPage->layout($user);
        $blocks = [];
        foreach (MyPageService::GROUPS as $group) {
            $blocks[$group] = [];
            foreach ($layout[$group] ?? [] as $block) {
                if ($data = $this->myPage->blockData($user, $block)) {
                    $blocks[$group][] = $data;
                }
            }
        }

        return $this->render('my/page.html.twig', [
            'groups' => MyPageService::GROUPS,
            'blocks' => $blocks,
            'block_options' => $this->myPage->blockOptions($this->myPage->blocksInUse($user)),
            'body_css' => 'controller-my action-page',
            'page_title' => $this->l('label_my_page'),
        ]);
    }

    #[Route('/my/page', name: 'my_update_page', methods: ['POST'])]
    public function updatePage(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->user();
        $settings = $request->request->all('settings') ?: $request->query->all('settings');
        foreach ($settings as $block => $s) {
            if (\is_array($s)) {
                $this->myPage->updateBlockSettings($user, (string) $block, $s);
            }
        }
        $this->myPage->save($user);
        if ($request->isXmlHttpRequest()) {
            $js = '';
            foreach (array_keys($settings) as $block) {
                $html = $this->renderMyBlock($user, (string) $block);
                $js .= '$("#block-'.$this->escapeJs((string) $block).'").replaceWith("'.$this->escapeJs($html).'");'."\n";
            }

            return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->redirectToRoute('my_page');
    }

    #[Route('/my/add_block', name: 'my_add_block', methods: ['POST'])]
    public function addBlock(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->user();
        $block = (string) $request->request->get('block', $request->query->get('block', ''));
        if (!$this->myPage->addBlock($user, $block)) {
            return new Response('', 422);
        }
        $this->myPage->save($user);
        if ($request->isXmlHttpRequest()) {
            $js = '$("#block-'.$this->escapeJs($block).'").remove();'."\n";
            $js .= '$("#list-top").prepend("'.$this->escapeJs($this->renderMyBlock($user, $block)).'");'."\n";
            $js .= '$("#block-select").replaceWith("'.$this->escapeJs($this->blockSelectTag($user)).'");'."\n";

            return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->redirectToRoute('my_page');
    }

    #[Route('/my/remove_block', name: 'my_remove_block', methods: ['POST'])]
    public function removeBlock(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->user();
        $block = (string) $request->request->get('block', $request->query->get('block', ''));
        $this->myPage->removeBlock($user, $block);
        $this->myPage->save($user);
        if ($request->isXmlHttpRequest()) {
            $js = '$("#block-'.$this->escapeJs($block).'").remove();'."\n";
            $js .= '$("#block-select").replaceWith("'.$this->escapeJs($this->blockSelectTag($user)).'");'."\n";

            return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->redirectToRoute('my_page');
    }

    #[Route('/my/order_blocks', name: 'my_order_blocks', methods: ['POST'])]
    public function orderBlocks(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->user();
        $blocks = $request->request->all()['blocks'] ?? [];
        $this->myPage->orderBlocks($user, (string) $request->request->get('group', ''), array_values((array) $blocks));
        $this->myPage->save($user);

        return new Response('', 200);
    }

    private function renderMyBlock(User $user, string $block): string
    {
        $data = $this->myPage->blockData($user, $block);

        return null === $data ? '' : $this->renderView('my/_block.html.twig', ['b' => $data]);
    }

    private function blockSelectTag(User $user): string
    {
        return $this->renderView('my/_block_select.html.twig', ['block_options' => $this->myPage->blockOptions($this->myPage->blocksInUse($user))]);
    }

    private function escapeJs(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '</' => '<\/', "\r\n" => '\n', "\n" => '\n', "\r" => '\n', '"' => '\"', "'" => "\\'"]);
    }

    // --- My account -----------------------------------------------------------------

    #[Route('/my/account', name: 'my_account', methods: ['GET', 'PUT', 'PATCH', 'POST'])]
    #[Route('/my/account.{_format}', name: 'my_account_api', requirements: ['_format' => 'json|xml'], methods: ['GET', 'PUT', 'PATCH'])]
    public function account(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->user();
        $isApi = $this->isApi($request);
        $fields = $this->accounts->editableUserCustomFields($user);
        $cfValues = $this->customFields->values('Principal', $user->id);
        $errors = [];

        if (!$request->isMethod('GET')) {
            if ($isApi) {
                $payload = ApiRenderer::payload($request);
                $params = (array) ($payload['user'] ?? []);
                $prefParams = (array) ($payload['pref'] ?? []);
            } else {
                $params = $request->request->all('user');
                $prefParams = $request->request->all('pref');
            }
            $oldMail = (string) $user->getMail();
            foreach (['firstname', 'lastname'] as $attr) {
                if (\array_key_exists($attr, $params)) {
                    $user->{$attr} = trim((string) $params[$attr]);
                }
            }
            if (\array_key_exists('mail', $params)) {
                $user->setMail(trim((string) $params['mail']));
            }
            if (\array_key_exists('language', $params)) {
                $user->language = (string) $params['language'];
            }
            if (\array_key_exists('mail_notification', $params)) {
                $user->mailNotification = (string) $params['mail_notification'];
            }
            // custom fields: hash {id => value} or API list [{id:, value:}]
            $submitted = (array) ($params['custom_field_values'] ?? []);
            foreach ((array) ($params['custom_fields'] ?? []) as $cf) {
                if (\is_array($cf) && isset($cf['id'])) {
                    $submitted[(int) $cf['id']] = $cf['value'] ?? null;
                }
            }
            $cfValues = array_replace($cfValues, $submitted);
            $mailChanged = $oldMail !== (string) $user->getMail();

            $errors = $this->accounts->userErrors($user, false, $mailChanged);
            $errors = array_merge($errors, $this->accounts->customFieldErrors(array_values(array_filter($fields, static fn ($f) => \array_key_exists($f->id, $submitted) || $f->isRequired)), $cfValues));
            if ([] === $errors) {
                $this->applyPreferences($user, $prefParams);
                $user->updatedOn = new \DateTimeImmutable();
                $pref = $user->getPref();
                if (null === $pref->id) {
                    $this->em->persist($pref);
                }
                $pref->others = \is_array($pref->others) ? [...$pref->others] : $pref->others;
                $this->em->flush();
                if ([] !== $submitted) {
                    $this->customFields->saveValues('Principal', (int) $user->id, $fields, $submitted);
                }
                if (\array_key_exists('notified_project_ids', $params) || \array_key_exists('mail_notification', $params)) {
                    $ids = \array_key_exists('notified_project_ids', $params) ? (array) $params['notified_project_ids'] : $this->accounts->notifiedProjectIds($user);
                    $this->accounts->updateNotifiedProjectIds($user, $ids);
                }
                $this->em->flush();
                if ($mailChanged) {
                    // EmailAddress#destroy_tokens
                    $this->accounts->deleteLoginTokens($user);
                }
                $this->dispatcher->dispatch(new ContentEvent('user', 'updated', $user, $user));
                if ($isApi) {
                    return $this->api->noContent();
                }
                $this->flashNotice('notice_account_updated');

                return $this->redirectToRoute('my_account');
            }
            if ($isApi) {
                return $this->api->errors($request, $errors);
            }
        }

        if ($isApi) {
            $data = [
                'id' => $user->id,
                'login' => $user->login,
                'admin' => $user->admin,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'mail' => $user->getMail(),
                'created_on' => ApiRenderer::time($user->createdOn),
                'last_login_on' => ApiRenderer::time($user->lastLoginOn),
                'api_key' => $this->accounts->apiKey($user),
            ];
            $visible = array_values(array_filter($this->customFields->fieldsOf(\App\Entity\UserCustomField::class), static fn ($f) => $f->visible || $user->admin));
            if ([] !== $visible) {
                $data['custom_fields'] = array_map(fn ($f) => ['id' => $f->id, 'name' => $f->name] + ($f->isMultiple() ? ['multiple' => true] : []) + ['value' => $cfValues[$f->id] ?? ($f->isMultiple() ? [] : null)], $visible);
            }

            return $this->api->render($request, ['user' => $data]);
        }

        return $this->render('my/account.html.twig', $this->accountViewVars($user, $errors, $fields, $cfValues));
    }

    /** @param array<string, mixed> $p */
    private function applyPreferences(User $user, array $p): void
    {
        $pref = $user->getPref();
        if (\array_key_exists('hide_mail', $p)) {
            $pref->hideMail = \in_array($p['hide_mail'], ['1', 1, true, 'true'], true);
        }
        if (\array_key_exists('time_zone', $p)) {
            $tz = (string) $p['time_zone'];
            $pref->timeZone = '' === $tz || null !== \App\I18n\RailsTimeZones::toIana($tz) ? $tz : $pref->timeZone;
        }
        foreach (['comments_sorting', 'warn_on_leaving_unsaved', 'no_self_notified', 'notify_about_high_priority_issues', 'textarea_font', 'history_default_tab', 'default_issue_query', 'default_project_query'] as $k) {
            if (\array_key_exists($k, $p)) {
                $pref->set($k, \is_scalar($p[$k]) ? (string) $p[$k] : '');
            }
        }
        if (\array_key_exists('recently_used_projects', $p)) {
            $pref->set('recently_used_projects', (int) $p['recently_used_projects']);
        }
        if (\array_key_exists('toolbar_language_options', $p)) {
            $langs = array_filter(explode(',', str_replace(' ', '', (string) $p['toolbar_language_options'])), static fn ($l) => '' !== $l && preg_match('/\A[a-z0-9_+#.-]+\z/i', $l));
            $pref->set('toolbar_language_options', implode(',', $langs));
        }
        if (\array_key_exists('auto_watch_on', $p)) {
            $pref->set('auto_watch_on', array_values(array_filter(array_map('strval', (array) $p['auto_watch_on']), static fn ($v) => \in_array($v, AccountService::AUTO_WATCH_ON_OPTIONS, true))));
        }
    }

    /** @return array<string, mixed> */
    private function accountViewVars(User $user, array $errors, array $fields, array $cfValues): array
    {
        $pref = $user->getPref();
        $conn = $this->em->getConnection();
        $issueQueries = ['public' => [], 'mine' => []];
        foreach ($conn->fetchAllAssociative("SELECT id, name, visibility, user_id FROM queries WHERE type = 'IssueQuery' AND project_id IS NULL ORDER BY name, id") as $q) {
            if (2 === (int) $q['visibility']) {
                $issueQueries['public'][] = $q;
            } elseif ((int) $q['user_id'] === $user->id) {
                $issueQueries['mine'][] = $q;
            }
        }
        $projectQueries = ['public' => [], 'mine' => []];
        foreach ($conn->fetchAllAssociative("SELECT id, name, visibility, user_id FROM queries WHERE type = 'ProjectQuery' ORDER BY name, id") as $q) {
            if (2 === (int) $q['visibility']) {
                $projectQueries['public'][] = $q;
            } elseif ((int) $q['user_id'] === $user->id) {
                $projectQueries['mine'][] = $q;
            }
        }
        // IssuePriority.default_or_middle and the next higher priority
        $priorities = $this->em->getRepository(IssuePriority::class)->findBy(['active' => true], ['position' => 'ASC']);
        $highPriority = null;
        if ([] !== $priorities) {
            $default = null;
            foreach ($priorities as $p) {
                if ($p->isDefault) {
                    $default = $p;
                }
            }
            $default ??= $priorities[intdiv(\count($priorities) - 1, 2)];
            foreach ($priorities as $p) {
                if ($p->position > $default->position) {
                    $highPriority = $p;
                    break;
                }
            }
        }
        $memberships = $this->accounts->memberships($user);

        return [
            'user' => $user,
            'pref' => $pref,
            'errors' => $errors,
            'custom_fields' => $fields,
            'custom_values' => $cfValues,
            'languages' => $this->accounts->languageOptions(),
            'mail_notification_options' => $this->accounts->mailNotificationOptions($user),
            'notified_project_ids' => $this->accounts->notifiedProjectIds($user),
            'member_projects' => array_map(static fn ($m) => $m->project, $memberships),
            'high_priority' => $highPriority,
            'issue_queries' => $issueQueries,
            'project_queries' => $projectQueries,
            'auto_watch_on' => (array) $pref->get('auto_watch_on', []),
            'auto_watch_on_options' => AccountService::AUTO_WATCH_ON_OPTIONS,
            'textarea_font_options' => AccountService::TEXTAREA_FONT_OPTIONS,
            'toolbar_language_options' => (string) ($pref->get('toolbar_language_options') ?: implode(',', AccountService::DEFAULT_TOOLBAR_LANGUAGE_OPTIONS)),
            'atom_token' => $this->accounts->tokenOf($user, 'feeds'),
            'api_token' => $this->accounts->tokenOf($user, 'api'),
            'deletable' => $this->accounts->ownAccountDeletable($user),
            'change_password_allowed' => $this->accounts->changePasswordAllowed($user),
            'body_css' => 'controller-my action-account',
            'page_title' => $this->l('label_my_account'),
        ];
    }

    #[Route('/my/account/destroy', name: 'my_account_destroy', methods: ['GET', 'POST'])]
    public function destroy(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->user();
        if (!$this->accounts->ownAccountDeletable($user)) {
            return $this->redirectToRoute('my_account');
        }
        if ($request->isMethod('POST') && $request->request->get('confirm')) {
            $this->dispatcher->dispatch(new ContentEvent('user', 'deleted', $user, $user));
            $this->accounts->destroyUser($user);
            $this->security->logout(false);
            $request->getSession()->invalidate();
            $this->flashNotice('notice_account_deleted');

            return $this->redirectToRoute('home');
        }
        if ($request->isMethod('POST')) {
            return $this->redirectToRoute('home');
        }

        return $this->render('my/destroy.html.twig', ['user' => $user, 'body_css' => 'controller-my action-destroy']);
    }

    #[Route('/my/password', name: 'my_password', methods: ['GET', 'POST'])]
    public function password(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->user();
        if (!$this->accounts->changePasswordAllowed($user)) {
            $this->flashError($this->l('notice_can_t_change_password'));

            return $this->redirectToRoute('my_account');
        }
        $error = null;
        $errors = [];
        if ($request->isMethod('POST')) {
            $current = (string) $request->request->get('password', '');
            $new = (string) $request->request->get('new_password', '');
            if (!$this->accounts->checkPassword($user, $current)) {
                $error = $this->l('notice_account_wrong_password');
            } elseif ($current === $new) {
                $error = $this->l('notice_new_password_must_be_different');
            } else {
                $errors = $this->accounts->passwordErrors($user, $new, (string) $request->request->get('new_password_confirmation', ''));
                if ([] === $errors) {
                    $this->accounts->setPassword($user, $new);
                    $user->mustChangePasswd = false;
                    $this->em->flush();
                    // keep the user logged in with the new credentials
                    $this->security->login($user, 'form_login', 'main');
                    $this->mailer->passwordUpdated($user, $user, $request->getClientIp());
                    $this->flashNotice('notice_account_password_updated');

                    return $this->redirectToRoute('my_account');
                }
            }
        }
        $response = $this->render('my/password.html.twig', [
            'user' => $user,
            'error' => $error,
            'errors' => $errors,
            'must_change' => $this->accounts->mustChangePassword($user),
            'atom_token' => $this->accounts->tokenOf($user, 'feeds'),
            'api_token' => $this->accounts->tokenOf($user, 'api'),
            'deletable' => $this->accounts->ownAccountDeletable($user),
            'body_css' => 'controller-my action-password',
            'page_title' => $this->l('button_change_password'),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/my/api_key', name: 'my_api_key', methods: ['GET'])]
    public function showApiKey(Request $request): Response
    {
        $this->requireLogin();
        $key = $this->accounts->apiKey($this->user());
        if ($request->isXmlHttpRequest()) {
            $js = "\$('#api-access-key').html('".$this->escapeJs(htmlspecialchars($key))."').toggle();\n\n"
                ."if (\$('#api-access-key').is(':visible')) {\n  \$('.api-key-actions .copy-api-key-link').show();\n} else {\n  \$('.api-key-actions .copy-api-key-link').hide();\n}\n";

            return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('my/show_api_key.html.twig', ['api_key' => $key, 'body_css' => 'controller-my action-show_api_key']);
    }

    #[Route('/my/api_key', name: 'my_api_key_reset', methods: ['POST'])]
    public function resetApiKey(): Response
    {
        $this->requireLogin();
        $this->accounts->createToken($this->user(), 'api');
        $this->flashNotice('notice_api_access_key_reseted');

        return $this->redirectToRoute('my_account');
    }

    #[Route('/my/atom_key', name: 'my_atom_key', methods: ['POST'])]
    #[Route('/my/rss_key', name: 'my_rss_key', methods: ['POST'])]
    public function resetAtomKey(): Response
    {
        $this->requireLogin();
        $this->accounts->createToken($this->user(), 'feeds');
        $this->flashNotice('notice_feeds_access_key_reseted');

        return $this->redirectToRoute('my_account');
    }
}
