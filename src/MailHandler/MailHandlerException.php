<?php

declare(strict_types=1);

namespace App\MailHandler;

/** Errors of MailHandler (UnauthorizedAction, NotAllowedInProject, InsufficientPermissions, LockedTopic, MissingInformation, MissingContainer) */
final class MailHandlerException extends \RuntimeException
{
    public const string NOT_ALLOWED_IN_PROJECT = 'not_allowed_in_project';
    public const string INSUFFICIENT_PERMISSIONS = 'insufficient_permissions';
    public const string LOCKED_TOPIC = 'locked_topic';
    public const string MISSING_INFORMATION = 'missing_information';
    public const string MISSING_CONTAINER = 'missing_container';
    public const string INVALID = 'invalid';

    public function __construct(string $message = '', public readonly string $kind = self::INVALID)
    {
        parent::__construct($message);
    }
}
