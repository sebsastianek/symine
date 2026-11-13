<?php

declare(strict_types=1);

namespace App\Security\Twofa;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/** Marks a login completed after the second factor was verified (AccountController#twofa). */
final class TwofaVerifiedBadge implements BadgeInterface
{
    public function isResolved(): bool
    {
        return true;
    }
}
