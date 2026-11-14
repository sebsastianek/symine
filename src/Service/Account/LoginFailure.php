<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\I18n\Localizer;
use App\Service\Settings;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/** What the login page tells a user whose last login attempt failed (AccountController#invalid_credentials, #account_pending...). */
final class LoginFailure
{
    public function __construct(
        private readonly AuthenticationUtils $authUtils,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The message of the last failed login, which is then forgotten.
     *
     * @return array{error: ?string, flash_html: ?string} error: shown above the form; flash_html: HTML message
     *                                                    (it has a link) to show as an error flash instead
     */
    public function last(): array
    {
        $error = $this->authUtils->getLastAuthenticationError();
        if (null === $error) {
            return ['error' => null, 'flash_html' => null];
        }
        $key = $error->getMessageKey();
        if ('notice_account_not_activated_yet' === $key && '1' !== $this->settings->selfRegistration()) {
            // AccountController#account_pending: the activation e-mail can only be sent again in
            // "account activation by email" mode (session[:registered_user_id] is set by the UserChecker)
            $key = 'notice_account_pending';
        }
        if ('notice_account_not_activated_yet' === $key) {
            // the message contains the link to AccountController#activation_email (flashes are html_safe in Redmine)
            return ['error' => null, 'flash_html' => $this->i18n->l($key, ['url' => htmlspecialchars($this->urls->generate('account_activation_email'))])];
        }

        return [
            'error' => match (true) {
                str_starts_with($key, 'notice_') => $this->i18n->l($key),
                str_contains($key, 'Too many') => $this->i18n->l('notice_account_invalid_credentials').' (rate limit)',
                default => $this->i18n->l('notice_account_invalid_credentials'),
            },
            'flash_html' => null,
        ];
    }
}
