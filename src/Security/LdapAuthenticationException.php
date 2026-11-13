<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * LDAP login failures that Redmine does not report as "Invalid user or password":
 * - an AuthSourceException (server unreachable, timeout): rendered as an error page with the message;
 * - a failed on-the-fly registration ($user is the unsaved user): the registration form is shown
 *   (AccountController#onthefly_creation_failed).
 */
final class LdapAuthenticationException extends AuthenticationException
{
    public function __construct(string $message = '', public readonly ?User $unsavedUser = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getMessageKey(): string
    {
        return $this->getMessage() ?: 'notice_account_invalid_credentials';
    }
}
