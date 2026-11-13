<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Security\Twofa\TwofaManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Twig helpers for the 2FA sections of My account and the user administration form. */
final class TwofaExtension extends AbstractExtension
{
    public function __construct(private readonly TwofaManager $twofa)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('twofa_enabled', fn (): bool => $this->twofa->enabled()),
            new TwigFunction('twofa_schemes', fn (): array => $this->twofa->availableSchemes()),
            new TwigFunction('twofa_active', static fn (?User $user): bool => null !== $user && TwofaManager::isActive($user)),
            new TwigFunction('twofa_has_backup_codes', fn (User $user): bool => [] !== $this->twofa->backupCodes($user)),
        ];
    }
}
