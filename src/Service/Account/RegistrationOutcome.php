<?php

declare(strict_types=1);

namespace App\Service\Account;

/** What became of a request to the registration form (RegistrationForm::handle()). */
enum RegistrationOutcome
{
    /** The form is to be shown: first display, or submitted with errors */
    case Form;
    /** The account is active and the user is logged in (automatic activation, or user of an LDAP directory) */
    case Activated;
    /** The account waits for the user to follow the link of the activation e-mail that was sent */
    case ActivationEmailSent;
    /** The account waits for an administrator to activate it */
    case Pending;
}
