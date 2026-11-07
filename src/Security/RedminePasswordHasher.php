<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\PasswordHasher\Hasher\CheckPasswordLengthTrait;
use Symfony\Component\PasswordHasher\LegacyPasswordHasherInterface;

/**
 * Redmine's legacy password scheme: hashed_password = SHA1(salt + SHA1(password)).
 * Only used to verify imported hashes; Symine transparently re-hashes with the "auto"
 * algorithm (bcrypt/argon2) on successful login (see security.yaml migrate_from).
 */
final class RedminePasswordHasher implements LegacyPasswordHasherInterface
{
    use CheckPasswordLengthTrait;

    public function hash(#[\SensitiveParameter] string $plainPassword, ?string $salt = null): string
    {
        return sha1(($salt ?? '').sha1($plainPassword));
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword, ?string $salt = null): bool
    {
        if ('' === $plainPassword || $this->isPasswordTooLong($plainPassword) || 40 !== \strlen($hashedPassword)) {
            return false;
        }
        // Very old Redmine versions stored unsalted SHA1(password)
        if (null === $salt || '' === $salt) {
            return hash_equals($hashedPassword, sha1($plainPassword));
        }

        return hash_equals($hashedPassword, $this->hash($plainPassword, $salt));
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return true;
    }
}
