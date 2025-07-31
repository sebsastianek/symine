<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\OauthAccessTokenRepository;

/**
 * OauthAccessToken.
 * Table: oauth_access_tokens
 */
#[ORM\Entity(repositoryClass: OauthAccessTokenRepository::class)]
#[ORM\Table(name: 'oauth_access_tokens')]
class OauthAccessToken
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', nullable: false)]
    private int $id;

    /**
     * Property token
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $token;

    /**
     * Property refreshToken
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $refreshToken = NULL;

    /**
     * Property expiresIn
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $expiresIn = NULL;

    /**
     * Property revokedAt
     */
    #[ORM\Column(type: 'datetime', length: 6, nullable: true)]
    private ?\DateTimeInterface $revokedAt = NULL;

    /**
     * Property createdAt
     */
    #[ORM\Column(type: 'datetime', length: 6)]
    private \DateTimeInterface $createdAt;

    /**
     * Property scopes
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $scopes = NULL;

    /**
     * Property previousRefreshToken
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $previousRefreshToken = '';

    /**
     * many_to_one relation to OauthApplication
     */
    #[ORM\ManyToOne(targetEntity: OauthApplication::class)]
    #[ORM\JoinColumn(name: 'application_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    private ?OauthApplication $oauthApplication = null;

    /**
     * many_to_one relation to User
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resource_owner_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    private ?User $user = null;

    /**
     * Getter for id
     */
    public function getId(): int    {
        return $this->id;
    }

    /**
     * Setter for id
     */
    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Getter for token
     */
    public function getToken(): string    {
        return $this->token;
    }

    /**
     * Setter for token
     */
    public function setToken(string $token): static
    {
        $this->token = $token;
        return $this;
    }

    /**
     * Getter for refreshToken
     */
    public function getRefreshToken(): ?string    {
        return $this->refreshToken;
    }

    /**
     * Setter for refreshToken
     */
    public function setRefreshToken(?string $refreshToken): static
    {
        $this->refreshToken = $refreshToken;
        return $this;
    }

    /**
     * Getter for expiresIn
     */
    public function getExpiresIn(): ?int    {
        return $this->expiresIn;
    }

    /**
     * Setter for expiresIn
     */
    public function setExpiresIn(?int $expiresIn): static
    {
        $this->expiresIn = $expiresIn;
        return $this;
    }

    /**
     * Getter for revokedAt
     */
    public function getRevokedAt(): ?\DateTimeInterface    {
        return $this->revokedAt;
    }

    /**
     * Setter for revokedAt
     */
    public function setRevokedAt(?\DateTimeInterface $revokedAt): static
    {
        $this->revokedAt = $revokedAt;
        return $this;
    }

    /**
     * Getter for createdAt
     */
    public function getCreatedAt(): \DateTimeInterface    {
        return $this->createdAt;
    }

    /**
     * Setter for createdAt
     */
    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Getter for scopes
     */
    public function getScopes(): ?string    {
        return $this->scopes;
    }

    /**
     * Setter for scopes
     */
    public function setScopes(?string $scopes): static
    {
        $this->scopes = $scopes;
        return $this;
    }

    /**
     * Getter for previousRefreshToken
     */
    public function getPreviousRefreshToken(): string    {
        return $this->previousRefreshToken;
    }

    /**
     * Setter for previousRefreshToken
     */
    public function setPreviousRefreshToken(string $previousRefreshToken): static
    {
        $this->previousRefreshToken = $previousRefreshToken;
        return $this;
    }

    /**
     * Getter for relation oauthApplication
     */
    public function getOauthApplication(): ?OauthApplication
    {
        return $this->oauthApplication;
    }

    /**
     * Setter for relation oauthApplication
     */
    public function setOauthApplication(?OauthApplication $oauthApplication): static
    {
        $this->oauthApplication = $oauthApplication;
        return $this;
    }

    /**
     * Getter for relation user
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Setter for relation user
     */
    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

}
