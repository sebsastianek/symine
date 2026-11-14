<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

/** What became of a request to delete a user account. */
enum RemovalOutcome
{
    /** nothing was done: the login of the account has to be typed to confirm */
    case Unconfirmed;
    /** "Lock" was chosen on the confirmation page instead of deleting */
    case Locked;
    case Deleted;
}
