<?php

declare(strict_types=1);

namespace App\Security\Twofa;

use App\Entity\Group;
use App\Entity\Token;
use App\Entity\User;
use App\Mailer\NotificationMailer;
use App\Migration\RedmineMigrator;
use App\Security\CurrentUser;
use App\Service\AccountService;
use App\Service\Settings;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Port of Redmine::Twofa (lib/redmine/twofa.rb, twofa/base.rb, twofa/totp.rb) and of the
 * 2FA related User / Setting methods. Redmine ships a single scheme: "totp".
 *
 * users.twofa_totp_key holds the Base32 secret. Redmine encrypts it with Redmine::Ciphering when
 * database_cipher_key is configured; the migrator decrypts it (--cipher-key) and Symine stores it
 * in clear like the other ciphered columns. Values still encrypted ("aes-256-cbc:...") are
 * decrypted on the fly when REDMINE_CIPHER_KEY is set.
 */
final class TwofaManager
{
    public const array SCHEMES = ['totp'];

    /** Twofa::Base#allowed_drift */
    public const int ALLOWED_DRIFT = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly AccountService $accounts,
        private readonly NotificationMailer $mailer,
        private readonly CurrentUser $currentUser,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return list<string> Redmine::Twofa.available_schemes */
    public function availableSchemes(): array
    {
        return self::SCHEMES;
    }

    public function isScheme(string $name): bool
    {
        return \in_array($name, self::SCHEMES, true);
    }

    // --- Setting / User helpers --------------------------------------------------

    /** Setting.twofa? */
    public function enabled(): bool
    {
        return (int) $this->settings->string('twofa') > 0;
    }

    public function requiredForAll(): bool
    {
        return '2' === $this->settings->string('twofa');
    }

    public function requiredForAdministrators(): bool
    {
        return '3' === $this->settings->string('twofa');
    }

    public function optional(): bool
    {
        return \in_array($this->settings->string('twofa'), ['1', '3'], true);
    }

    /** User#twofa_active? */
    public static function isActive(User $user): bool
    {
        return null !== $user->twofaScheme && '' !== $user->twofaScheme;
    }

    /** User#must_activate_twofa? */
    public function mustActivate(User $user): bool
    {
        if (!$user->isLogged() || self::isActive($user)) {
            return false;
        }
        if ($this->requiredForAll()) {
            return true;
        }
        if ($this->requiredForAdministrators() && $user->admin) {
            return true;
        }
        if ($this->optional()) {
            foreach ($user->groups as $group) {
                if ($group instanceof Group && $group->twofaRequired) {
                    return true;
                }
            }
        }

        return false;
    }

    // --- TOTP key ----------------------------------------------------------------

    /** User#twofa_totp_key (Redmine::Ciphering.decrypt_text) */
    public function totpKey(User $user): ?string
    {
        $key = $user->twofaTotpKey;
        if (null !== $key && str_starts_with($key, 'aes-256-cbc:')) {
            $cipherKey = (string) ($_SERVER['REDMINE_CIPHER_KEY'] ?? $_ENV['REDMINE_CIPHER_KEY'] ?? getenv('REDMINE_CIPHER_KEY') ?: '');
            if ('' === $cipherKey) {
                return null;
            }
            $key = RedmineMigrator::decryptRedmineText($key, $cipherKey);
        }

        return '' === $key ? null : $key;
    }

    public function totp(User $user): ?Totp
    {
        $key = $this->totpKey($user);

        return null === $key ? null : new Totp($key, $this->settings->string('host_name'));
    }

    public function provisioningUri(User $user): string
    {
        return (string) $this->totp($user)?->provisioningUri($user->login);
    }

    /** QR code of the provisioning URI (Redmine renders a 280px PNG with RQRCode; SVG here) */
    public function qrCodeSvg(string $data, int $size = 280): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd()));
        $svg = $writer->writeString($data);

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    // --- Pairing -----------------------------------------------------------------

    /** Twofa::Totp#init_pairing!: generates a new secret */
    public function initPairing(User $user): void
    {
        $user->twofaTotpKey = Totp::randomSecret();
        $this->em->flush();
    }

    /** Starts a pairing unless one is already going on: the confirmation page always has a secret to show */
    public function ensurePairing(User $user): void
    {
        if (null === $this->totpKey($user)) {
            $this->initPairing($user);
        }
    }

    /** Twofa::Base#confirm_pairing!: only an OTP (no backup code) activates the scheme */
    public function confirmPairing(User $user, string $scheme, string $code): bool
    {
        if (!$this->verifyOtp($user, $code)) {
            return false;
        }
        $user->twofaScheme = $scheme;
        $this->em->flush();
        // User#destroy_tokens: login tokens are deleted when a scheme is paired
        $this->accounts->deleteLoginTokens($user);
        $this->notify($user, 'twofa_mail_body_security_notification_paired', ['field' => 'twofa__'.$scheme.'__name']);

        return true;
    }

    /** Twofa::Base#destroy_pairing! */
    public function destroyPairing(User $user, string $code): bool
    {
        if (!$this->verify($user, $code)) {
            return false;
        }
        $this->destroyPairingWithoutVerify($user);

        return true;
    }

    /** Twofa::Totp/Base#destroy_pairing_without_verify! */
    public function destroyPairingWithoutVerify(User $user): void
    {
        $user->twofaTotpKey = null;
        $user->twofaTotpLastUsedAt = null;
        $user->twofaScheme = null;
        $this->em->flush();
        $this->em->getConnection()->executeStatement("DELETE FROM tokens WHERE user_id = ? AND action = 'twofa_backup_code'", [$user->id]);
        $this->notify($user, 'twofa_mail_body_security_notification_unpaired');
    }

    /** TwofaController#admin_deactivate: an administrator removes the scheme of a user who has one, without a code */
    public function unpairByAdministrator(User $user): void
    {
        if (self::isActive($user)) {
            $this->destroyPairingWithoutVerify($user);
        }
    }

    /** Redmine::Twofa.unpair_all! (when 2FA is switched off in the settings) */
    public function unpairAll(): void
    {
        $users = $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.twofaScheme IS NOT NULL')->getResult();
        foreach ($users as $user) {
            if ($user instanceof User && $this->isScheme((string) $user->twofaScheme)) {
                $this->destroyPairingWithoutVerify($user);
            }
        }
    }

    // --- Verification ------------------------------------------------------------

    /** Twofa::Base#verify!: OTP or backup code */
    public function verify(User $user, string $code): bool
    {
        return $this->verifyOtp($user, $code) || $this->verifyBackupCode($user, $code);
    }

    /** Twofa::Totp#verify_otp! (drift behind 30s, replay protection with twofa_totp_last_used_at) */
    public function verifyOtp(User $user, string $code, ?int $at = null): bool
    {
        $code = (string) preg_replace('/[[:space:]]/u', '', $code);
        $totp = $this->totp($user);
        if (null === $totp) {
            return false;
        }
        $verifiedAt = $totp->verify($code, self::ALLOWED_DRIFT, 0, $user->twofaTotpLastUsedAt, $at);
        if (null === $verifiedAt) {
            return false;
        }
        $user->twofaTotpLastUsedAt = $verifiedAt;
        $this->em->flush();

        return true;
    }

    /** Twofa::Base#verify_backup_code! (case and white-space insensitive, single use) */
    public function verifyBackupCode(User $user, string $code): bool
    {
        $code = strtolower((string) preg_replace('/[[:space:]]/u', '', $code));
        $token = $this->accounts->findToken('twofa_backup_code', $code);
        $owner = null !== $token && $token->user->isActive() ? $token->user : null;
        // invalidate the backup code after usage
        if (null !== $token && $token->user->id === $user->id) {
            $this->em->getConnection()->executeStatement('DELETE FROM tokens WHERE id = ?', [$token->id]);
            $this->em->detach($token);
        }
        if (null === $owner || $owner->id !== $user->id) {
            return false;
        }
        $this->notify($user, 'twofa_mail_body_backup_code_used');

        return true;
    }

    // --- Backup codes ------------------------------------------------------------

    /** @return list<Token> */
    public function backupCodes(User $user): array
    {
        return $this->em->getRepository(Token::class)->findBy(['user' => $user, 'action' => 'twofa_backup_code'], ['id' => 'ASC']);
    }

    /**
     * Twofa::Base#init_backup_codes!: 10 tokens whose value is Redmine::Utils.random_hex(6)
     * (12 lower case hex characters, stored in clear like every Redmine token).
     *
     * @return list<Token>
     */
    public function initBackupCodes(User $user): array
    {
        $this->em->getConnection()->executeStatement("DELETE FROM tokens WHERE user_id = ? AND action = 'twofa_backup_code'", [$user->id]);
        foreach ($this->backupCodes($user) as $t) {
            $this->em->detach($t);
        }
        $tokens = [];
        for ($i = 0; $i < 10; ++$i) {
            $token = new Token($user, 'twofa_backup_code');
            $token->value = bin2hex(random_bytes(6));
            $this->em->persist($token);
            $tokens[] = $token;
        }
        $this->em->flush();
        $this->notify($user, 'twofa_mail_body_backup_codes_generated');

        return $tokens;
    }

    // --- Login sessions (AccountController#setup_twofa_session ...) --------------

    public function createSessionToken(User $user): Token
    {
        return $this->accounts->createToken($user, 'twofa_session');
    }

    /** Token.find_active_user('twofa_session', value, 0.0014 days) */
    public function findSessionUser(string $value): ?User
    {
        $token = $this->accounts->findToken('twofa_session', $value);
        if (null === $token || !$token->user->isActive()) {
            return null;
        }
        // twofa sessions are only valid 2 minutes at a time (0.0014 day)
        if ($token->createdOn->getTimestamp() <= time() - 120.96) {
            return null;
        }

        return $token->user;
    }

    public function deleteSessionToken(string $value): void
    {
        if ('' !== $value) {
            $this->em->getConnection()->executeStatement("DELETE FROM tokens WHERE action = 'twofa_session' AND value = ?", [$value]);
        }
    }

    // ------------------------------------------------------------------------------

    /** @param array<string, mixed> $options */
    private function notify(User $user, string $message, array $options = []): void
    {
        $sender = $this->currentUser->get();
        $this->mailer->deliverSecurityNotification([$user], $sender, $options + [
            'title' => 'label_my_account',
            'message' => $message,
            'url' => $this->urls->generate('my_account', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'remote_ip' => $this->requestStack->getCurrentRequest()?->getClientIp(),
        ]);
    }
}
