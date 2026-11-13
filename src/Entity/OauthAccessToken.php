<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doorkeeper::AccessToken: Bearer tokens and refresh tokens, both stored as SHA256 hex digests
 * (hash_token_secrets). previous_refresh_token holds the (hashed) refresh token this token was
 * issued for: the old token is revoked when the new one is used for the first time.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_access_tokens')]
#[ORM\Index(name: 'index_oauth_access_tokens_on_application_id', columns: ['application_id'])]
#[ORM\UniqueConstraint(name: 'index_oauth_access_tokens_on_token', columns: ['token'])]
#[ORM\Index(name: 'index_oauth_access_tokens_on_resource_owner_id', columns: ['resource_owner_id'])]
#[ORM\UniqueConstraint(name: 'index_oauth_access_tokens_on_refresh_token', columns: ['refresh_token'])]
class OauthAccessToken
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(nullable: true)]
    public ?int $resourceOwnerId = null;

    #[ORM\Column(nullable: true)]
    public ?int $applicationId = null;

    #[ORM\Column(length: 255)]
    public string $token = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $refreshToken = null;

    #[ORM\Column(nullable: true)]
    public ?int $expiresIn = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $scopes = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $previousRefreshToken = '';

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresIn && time() > $this->createdAt->getTimestamp() + $this->expiresIn;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt && $this->revokedAt <= new \DateTimeImmutable();
    }

    /** Doorkeeper::Models::Accessible#accessible? */
    public function isAccessible(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }

    public function expiresInSeconds(): ?int
    {
        if (null === $this->expiresIn) {
            return null;
        }

        return max(0, $this->createdAt->getTimestamp() + $this->expiresIn - time());
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return preg_split('/\s+/', trim((string) $this->scopes), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
