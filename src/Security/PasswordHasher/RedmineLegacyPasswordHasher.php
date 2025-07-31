<?php

declare(strict_types=1);

namespace App\Security\PasswordHasher;

use App\Entity\User;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;

/**
 * Legacy password hasher for Redmine SHA1+salt compatibility
 * 
 * Redmine uses: SHA1(salt + SHA1(password))
 * This hasher supports both verification and migration to modern hashing
 */
class RedmineLegacyPasswordHasher implements PasswordHasherInterface
{
    public function hash(string $plainPassword): string
    {
        if (empty($plainPassword)) {
            throw new InvalidPasswordException('Password cannot be empty');
        }

        $salt = $this->generateSalt();
        $innerHash = sha1($plainPassword);
        $finalHash = sha1($salt . $innerHash);
        
        // Store salt with hash for new passwords (format: salt$hash)
        return $salt . '$' . $finalHash;
    }

    public function verify(string $hashedPassword, string $plainPassword): bool
    {
        if (empty($plainPassword)) {
            return false;
        }

        // Handle new format with embedded salt (salt$hash)
        if (strpos($hashedPassword, '$') !== false) {
            [$salt, $storedHash] = explode('$', $hashedPassword, 2);
            return $this->verifyWithSalt($plainPassword, $storedHash, $salt);
        }

        // Legacy format - hash only, salt must be provided separately
        // This should not happen in normal operation as we always need the salt
        throw new InvalidPasswordException('Legacy password verification requires salt');
    }

    /**
     * Verify password using Redmine's algorithm with explicit salt
     */
    public function verifyWithSalt(string $plainPassword, string $hashedPassword, string $salt): bool
    {
        if (empty($plainPassword) || empty($hashedPassword) || empty($salt)) {
            return false;
        }

        $innerHash = sha1($plainPassword);
        $expectedHash = sha1($salt . $innerHash);
        
        return hash_equals($hashedPassword, $expectedHash);
    }

    /**
     * Verify legacy Redmine password from User entity
     */
    public function verifyLegacyUser(User $user, string $plainPassword): bool
    {
        $hashedPassword = $user->getHashedPassword();
        $salt = $user->getSalt();

        if (empty($hashedPassword) || empty($salt)) {
            return false;
        }

        return $this->verifyWithSalt($plainPassword, $hashedPassword, $salt);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        // Always rehash legacy Redmine passwords to modern algorithm
        return true;
    }

    /**
     * Generate salt compatible with Redmine format
     */
    public function generateSalt(): string
    {
        // Redmine uses 16 bytes (32 hex characters) for salt
        return bin2hex(random_bytes(16));
    }

    /**
     * Hash password using Redmine algorithm with provided salt
     */
    public function hashWithSalt(string $plainPassword, string $salt): string
    {
        if (empty($plainPassword) || empty($salt)) {
            throw new InvalidPasswordException('Password and salt cannot be empty');
        }

        $innerHash = sha1($plainPassword);
        return sha1($salt . $innerHash);
    }

    /**
     * Create legacy format hash for database storage (separate fields)
     */
    public function createLegacyHash(string $plainPassword): array
    {
        $salt = $this->generateSalt();
        $hash = $this->hashWithSalt($plainPassword, $salt);
        
        return [
            'salt' => $salt,
            'hashed_password' => $hash
        ];
    }

    /**
     * Validate salt format
     */
    public function isValidSalt(string $salt): bool
    {
        // Redmine salt should be 32 hex characters
        return preg_match('/^[a-f0-9]{32}$/i', $salt) === 1;
    }

    /**
     * Convert legacy user to modern password format
     */
    public function migrateLegacyPassword(User $user, string $plainPassword): ?string
    {
        // First verify the legacy password is correct
        if (!$this->verifyLegacyUser($user, $plainPassword)) {
            return null;
        }

        // Create new format with embedded salt
        return $this->hash($plainPassword);
    }
}