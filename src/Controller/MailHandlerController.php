<?php

declare(strict_types=1);

namespace App\Controller;

use App\MailHandler\MailHandler;
use App\Security\CurrentUser;
use App\Service\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as BaseController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of MailHandlerController: incoming e-mails web service used by extra/mail_handler/rdm-mailhandler.rb
 * (POST /mail_handler with key, email and options), enabled by Setting.mail_handler_api_enabled and
 * protected by Setting.mail_handler_api_key. GET /mail_handler shows a test submission form.
 * The route is exempt from CSRF checks and from login_required.
 */
final class MailHandlerController extends BaseController
{
    public function __construct(
        private readonly MailHandler $handler,
        private readonly Settings $settings,
        private readonly CurrentUser $currentUser,
    ) {
    }

    #[Route('/mail_handler', name: 'mail_handler', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->currentUser->set(null);
        $key = (string) ($request->request->get('key') ?? $request->query->get('key') ?? '');
        $apiKey = $this->settings->string('mail_handler_api_key');
        if (!$this->settings->bool('mail_handler_api_enabled') || '' === $apiKey || !hash_equals($apiKey, $key)) {
            return new Response('Access denied. Incoming emails WS is disabled or key is invalid.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($request->isMethod('GET')) {
            return $this->render('mail_handler/new.html.twig', ['key' => $key]);
        }
        $p = $request->request;
        $options = [];
        foreach (['allow_override', 'unknown_user', 'default_group', 'no_account_notice', 'no_notification', 'no_permission_check', 'project_from_subaddress'] as $k) {
            if ($p->has($k) && \is_string($p->get($k))) {
                $options[$k] = $p->get($k);
            }
        }
        $issue = $p->all('issue');
        $options['issue'] = [];
        foreach (['project', 'status', 'tracker', 'category', 'priority', 'assigned_to', 'fixed_version', 'is_private'] as $k) {
            if (isset($issue[$k]) && \is_string($issue[$k])) {
                $options['issue'][$k] = $issue[$k];
            }
        }
        if (!\array_key_exists('is_private', $options['issue']) && isset($issue['private']) && '1' === (string) $issue['private']) {
            $options['issue']['is_private'] = '1';
        }
        $email = $p->get('email');
        if (!\is_string($email) && ($file = $request->files->get('email'))) {
            $email = (string) file_get_contents($file->getPathname());
        }
        if (!\is_string($email) || '' === $email) {
            return new Response('', 422);
        }

        return new Response('', false !== $this->handler->safeReceive($email, $options) ? 201 : 422);
    }
}
