<?php

declare(strict_types=1);

namespace App\Security\Twofa;

use App\Entity\User;
use App\Service\Account\MySidebar;

/** What the 2FA pages of My account show; each of them has the sidebar of My account. */
final class TwofaView
{
    public function __construct(
        private readonly TwofaManager $twofa,
        private readonly MySidebar $sidebar,
    ) {
    }

    /** @return array<string, mixed> */
    public function selectScheme(User $user): array
    {
        return $this->sidebar->vars($user) + [
            'schemes' => $this->twofa->availableSchemes(),
            'show_sidebar' => !$this->twofa->mustActivate($user),
        ];
    }

    /**
     * The pairing page: the secret of the pairing in progress, as a QR code and as text.
     *
     * @return array<string, mixed>
     */
    public function activateConfirm(User $user, string $scheme): array
    {
        $uri = $this->twofa->provisioningUri($user);

        return $this->sidebar->vars($user) + [
            'scheme_name' => $scheme,
            'resendable' => false,
            'provisioning_uri' => $uri,
            'qr_code_base64' => base64_encode($this->twofa->qrCodeSvg($uri)),
            'totp_key' => $this->twofa->totpKey($user),
            'show_sidebar' => !$this->twofa->mustActivate($user),
        ];
    }

    /** @return array<string, mixed> */
    public function deactivateConfirm(User $user, string $scheme): array
    {
        return $this->sidebar->vars($user) + ['scheme_name' => $scheme, 'resendable' => false];
    }

    /** @return array<string, mixed> */
    public function backupCodesConfirm(User $user): array
    {
        return $this->sidebar->vars($user) + ['resendable' => false];
    }

    /**
     * @param array{backup_codes: list<string>, created_at: \DateTimeInterface} $codes TwofaBackupCodes::reveal()
     *
     * @return array<string, mixed>
     */
    public function backupCodes(User $user, array $codes): array
    {
        return $this->sidebar->vars($user) + $codes;
    }
}
