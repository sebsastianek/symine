<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\MyAccountApiPresenter;
use App\Service\Account\My\MyAccountForm;
use App\Service\Account\My\MyPageView;
use App\Service\Account\My\OwnAccountRemoval;
use App\Service\Account\My\PasswordForm;
use App\Service\AccountService;
use App\Service\MyPageService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's MyController: My page (blocks), My account, password, API/feeds keys, account deletion. */
final class MyController extends AbstractController
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly MyPageService $myPage,
        private readonly MyPageView $pageView,
        private readonly MyAccountForm $accountForm,
        private readonly MyAccountApiPresenter $accountPresenter,
        private readonly PasswordForm $passwordForm,
        private readonly OwnAccountRemoval $removal,
        private readonly ApiRenderer $api,
    ) {
    }

    // --- My page ------------------------------------------------------------------

    #[Route('/my', name: 'my_index', methods: ['GET'])]
    #[Route('/my/page', name: 'my_page', methods: ['GET'])]
    public function page(): Response
    {
        $this->requireLogin();

        return $this->render('my/page.html.twig', $this->pageView->page($this->user()) + [
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
        $this->myPage->updateBlocksSettings($user, $settings);
        if ($request->isXmlHttpRequest()) {
            return $this->render('my/update_page.js.twig', $this->pageView->updatedBlocks($user, array_keys($settings)), new Response('', 200, ['Content-Type' => 'text/javascript; charset=utf-8']));
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
            return $this->render('my/add_block.js.twig', $this->pageView->addedBlock($user, $block), new Response('', 200, ['Content-Type' => 'text/javascript; charset=utf-8']));
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
            return $this->render('my/remove_block.js.twig', $this->pageView->removedBlock($user, $block), new Response('', 200, ['Content-Type' => 'text/javascript; charset=utf-8']));
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

    // --- My account -----------------------------------------------------------------

    #[Route('/my/account', name: 'my_account', methods: ['GET', 'PUT', 'PATCH', 'POST'])]
    #[Route('/my/account.{_format}', name: 'my_account_api', requirements: ['_format' => 'json|xml'], methods: ['GET', 'PUT', 'PATCH'])]
    public function account(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->user();
        $isApi = $this->isApi($request);
        $errors = [];

        if (!$request->isMethod('GET')) {
            $errors = $this->accountForm->submit($user, $request);
            if ([] === $errors) {
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
            return $this->api->render($request, ['user' => $this->accountPresenter->present($user)]);
        }

        return $this->render('my/account.html.twig', $this->accountForm->view($user, $request, $errors) + [
            'body_css' => 'controller-my action-account',
            'page_title' => $this->l('label_my_account'),
        ]);
    }

    #[Route('/my/account/destroy', name: 'my_account_destroy', methods: ['GET', 'POST'])]
    public function destroy(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->user();
        if (!$this->removal->isAllowed($user)) {
            return $this->redirectToRoute('my_account');
        }
        if ($request->isMethod('POST') && $request->request->get('confirm')) {
            $this->removal->remove($user, $request->getSession());
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
        if (!$this->passwordForm->isAvailable($user)) {
            $this->flashError($this->l('notice_can_t_change_password'));

            return $this->redirectToRoute('my_account');
        }
        $errors = null;
        if ($request->isMethod('POST')) {
            $errors = $this->passwordForm->submit($user, $request);
            if ($errors->isEmpty()) {
                $this->flashNotice('notice_account_password_updated');

                return $this->redirectToRoute('my_account');
            }
        }
        $response = $this->render('my/password.html.twig', $this->passwordForm->view($user, $errors) + [
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
            return $this->render('my/show_api_key.js.twig', ['api_key' => $key], new Response('', 200, ['Content-Type' => 'text/javascript; charset=utf-8']));
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
