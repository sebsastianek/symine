<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\Token;
use App\Entity\User;
use App\Service\AccountService;

/** The variables of the my/_sidebar partial, shown beside every page of the My account area. */
final class MySidebar
{
    public function __construct(private readonly AccountService $accounts)
    {
    }

    /** @return array{user: User, deletable: bool, atom_token: ?Token, api_token: ?Token} */
    public function vars(User $user): array
    {
        return [
            'user' => $user,
            'deletable' => $this->accounts->ownAccountDeletable($user),
            'atom_token' => $this->accounts->tokenOf($user, 'feeds'),
            'api_token' => $this->accounts->tokenOf($user, 'api'),
        ];
    }
}
