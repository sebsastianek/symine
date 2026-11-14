<?php

declare(strict_types=1);

namespace App\Security\Twofa;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/** Generation of the backup codes and their one-time display (Redmine's TwofaBackupCodesController). */
final class TwofaBackupCodes
{
    private const string FLASH_BACKUP_TOKEN_IDS = 'twofa_backup_token_ids';

    public function __construct(private readonly TwofaManager $twofa)
    {
    }

    /**
     * Replaces the backup codes of the user and keeps the new ones in the session for their display.
     *
     * @return \DateTimeInterface|null when the codes this invalidates were generated, null when there were none
     */
    public function generate(User $user, SessionInterface $session): ?\DateTimeInterface
    {
        $existing = $this->twofa->backupCodes($user);
        $invalidated = [] === $existing ? null : max(array_map(static fn ($t) => $t->createdOn, $existing));
        $tokens = $this->twofa->initBackupCodes($user);
        $session->set(self::FLASH_BACKUP_TOKEN_IDS, array_map(static fn ($t) => $t->id, $tokens));

        return $invalidated;
    }

    /**
     * The codes that were just generated, for the page that shows them.
     *
     * @return array{backup_codes: list<string>, created_at: \DateTimeInterface}|null null when they were already
     *                                                                               shown or are older than 5 minutes
     */
    public function reveal(User $user, SessionInterface $session): ?array
    {
        // flash[:twofa_backup_token_ids]: the codes are shown only once
        $ids = array_map('intval', (array) $session->get(self::FLASH_BACKUP_TOKEN_IDS, []));
        $session->remove(self::FLASH_BACKUP_TOKEN_IDS);
        $tokens = array_values(array_filter($this->twofa->backupCodes($user), static fn ($t) => \in_array($t->id, $ids, true)));
        $createdAt = [] === $tokens ? null : max(array_map(static fn ($t) => $t->createdOn, $tokens));
        if (null === $createdAt || $createdAt <= new \DateTimeImmutable('-5 minutes')) {
            return null;
        }

        return [
            'backup_codes' => array_map(static fn ($t) => $t->value, $tokens),
            'created_at' => $createdAt,
        ];
    }
}
