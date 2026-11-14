<?php

declare(strict_types=1);

namespace App\Service\Account;

/** Where a request to the lost password pages stands (PasswordRecovery). */
enum PasswordRecoveryStep
{
    // --- asking for the e-mail ---
    /** The e-mail was sent, or the address is unknown (which is not revealed) */
    case Sent;
    /** The account of this address is not activated yet */
    case Pending;
    /** The account of this address is locked */
    case Locked;
    /** The user of this address cannot change their password (external authentication) */
    case Refused;

    // --- choosing the new password with the token of the e-mail ---
    /** Unknown token, or token of a user who is not active */
    case Invalid;
    case Expired;
    /** The token of the link was moved to the session: the page is to be loaded again without it */
    case TokenStored;
    /** The form of the new password is to be shown: first display, or submitted with errors */
    case Form;
    /** The password was changed */
    case Done;
}
