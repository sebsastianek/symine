<?php

declare(strict_types=1);

namespace App\MailHandler;

use App\Security\CurrentUser;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * The incoming e-mails web service used by extra/mail_handler/rdm-mailhandler.rb (MailHandlerController):
 * enabled by Setting.mail_handler_api_enabled, protected by Setting.mail_handler_api_key, it hands the
 * submitted e-mail to the mail handler with the options of the request.
 */
final class MailHandlerWebService
{
    /** Body of the 403 answered to a refused request (text/plain) */
    public const string DENIED = 'Access denied. Incoming emails WS is disabled or key is invalid.';

    public function __construct(
        private readonly MailHandler $handler,
        private readonly Settings $settings,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /** Whether the request may use the web service (check_credential), which always acts as the anonymous user */
    public function granted(Request $request): bool
    {
        $this->currentUser->set(null);
        // Rails also reads the parameters from a JSON body (Content-Type: application/json)
        if ($request->isMethod('POST') && 0 === $request->request->count() && str_contains(strtolower((string) $request->headers->get('Content-Type')), 'json')) {
            $json = json_decode($request->getContent(), true);
            if (\is_array($json)) {
                $toString = static fn ($v) => \is_bool($v) ? ($v ? 'true' : 'false') : (\is_scalar($v) ? (string) $v : $v);
                foreach ($json as $k => $v) {
                    $json[$k] = \is_array($v) ? array_map($toString, $v) : $toString($v);
                }
                $request->request->replace($json);
            }
        }
        $key = $this->key($request);
        $apiKey = $this->settings->string('mail_handler_api_key');

        return $this->settings->bool('mail_handler_api_enabled') && '' !== $apiKey && hash_equals($apiKey, $key);
    }

    /** The key given with the request ('' when there is none) */
    public function key(Request $request): string
    {
        $key = $request->request->get('key') ?? $request->query->get('key') ?? '';

        return \is_scalar($key) ? (string) $key : '';
    }

    /**
     * Receives the e-mail of the request (the "email" parameter or uploaded file) with its options: those of
     * the mail handler and the issue attributes (issue[...]).
     *
     * @return bool false when there is no e-mail or when it could not be handled
     */
    public function receive(Request $request): bool
    {
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
            return false;
        }

        return false !== $this->handler->safeReceive($email, $options);
    }
}
