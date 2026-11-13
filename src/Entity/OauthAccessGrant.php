<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Doorkeeper::AccessGrant: authorization codes (token = SHA256 hex digest, hash_token_secrets). */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_access_grants')]
#[ORM\Index(name: 'index_oauth_access_grants_on_application_id', columns: ['application_id'])]
#[ORM\UniqueConstraint(name: 'index_oauth_access_grants_on_token', columns: ['token'])]
class OauthAccessGrant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public int $resourceOwnerId = 0;

    #[ORM\Column]
    public int $applicationId = 0;

    #[ORM\Column(length: 255)]
    public string $token = '';

    #[ORM\Column]
    public int $expiresIn = 600;

    #[ORM\Column(type: 'text')]
    public string $redirectUri = '';

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $scopes = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $codeChallenge = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $codeChallengeMethod = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return time() > $this->createdAt->getTimestamp() + $this->expiresIn;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt && $this->revokedAt <= new \DateTimeImmutable();
    }

    public function isAccessible(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return preg_split('/\s+/', trim((string) $this->scopes), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
