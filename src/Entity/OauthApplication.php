<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doorkeeper::Application (Redmine >= 6 OAuth2 provider). The secret is stored as a BCrypt hash
 * (hash_application_secrets using: Doorkeeper::SecretStoring::BCrypt); scopes are space separated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_applications')]
#[ORM\UniqueConstraint(name: 'index_oauth_applications_on_uid', columns: ['uid'])]
class OauthApplication
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(length: 255)]
    public string $uid = '';

    #[ORM\Column(length: 255)]
    public string $secret = '';

    #[ORM\Column(type: 'text')]
    public string $redirectUri = '';

    #[ORM\Column(type: 'text')]
    public string $scopes = '';

    #[ORM\Column(options: ['default' => true])]
    public bool $confidential = true;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    /** Plain secret, only known right after creation / renewal (never persisted) */
    public ?string $plaintextSecret = null;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return array_values(array_unique(preg_split('/\s+/', trim($this->scopes), -1, \PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** @return list<string> */
    public function getRedirectUris(): array
    {
        return preg_split('/\s+/', trim($this->redirectUri), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
