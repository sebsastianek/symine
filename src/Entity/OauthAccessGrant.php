<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\OauthAccessGrantRepository;

/**
 * OauthAccessGrant.
 * Table: oauth_access_grants
 */
#[ORM\Entity(repositoryClass: OauthAccessGrantRepository::class)]
#[ORM\Table(name: 'oauth_access_grants')]
class OauthAccessGrant
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
     * Property expiresIn
     */
    #[ORM\Column(type: 'integer')]
    private int $expiresIn;

    /**
     * Property redirectUri
     */
    #[ORM\Column(type: 'text', length: 65535)]
    private string $redirectUri;

    /**
     * Property createdAt
     */
    #[ORM\Column(type: 'datetime', length: 6)]
    private \DateTimeInterface $createdAt;

    /**
     * Property revokedAt
     */
    #[ORM\Column(type: 'datetime', length: 6, nullable: true)]
    private ?\DateTimeInterface $revokedAt = NULL;

    /**
     * Property scopes
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $scopes = NULL;

    /**
     * Property codeChallenge
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $codeChallenge = NULL;

    /**
     * Property codeChallengeMethod
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $codeChallengeMethod = NULL;

    /**
     * many_to_one relation to User
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resource_owner_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    private User $user;

    /**
     * many_to_one relation to OauthApplication
     */
    #[ORM\ManyToOne(targetEntity: OauthApplication::class)]
    #[ORM\JoinColumn(name: 'application_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    private OauthApplication $oauthApplication;

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
     * Getter for expiresIn
     */
    public function getExpiresIn(): int    {
        return $this->expiresIn;
    }

    /**
     * Setter for expiresIn
     */
    public function setExpiresIn(int $expiresIn): static
    {
        $this->expiresIn = $expiresIn;
        return $this;
    }

    /**
     * Getter for redirectUri
     */
    public function getRedirectUri(): string    {
        return $this->redirectUri;
    }

    /**
     * Setter for redirectUri
     */
    public function setRedirectUri(string $redirectUri): static
    {
        $this->redirectUri = $redirectUri;
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
     * Getter for codeChallenge
     */
    public function getCodeChallenge(): ?string    {
        return $this->codeChallenge;
    }

    /**
     * Setter for codeChallenge
     */
    public function setCodeChallenge(?string $codeChallenge): static
    {
        $this->codeChallenge = $codeChallenge;
        return $this;
    }

    /**
     * Getter for codeChallengeMethod
     */
    public function getCodeChallengeMethod(): ?string    {
        return $this->codeChallengeMethod;
    }

    /**
     * Setter for codeChallengeMethod
     */
    public function setCodeChallengeMethod(?string $codeChallengeMethod): static
    {
        $this->codeChallengeMethod = $codeChallengeMethod;
        return $this;
    }

    /**
     * Getter for relation user
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Setter for relation user
     */
    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Getter for relation oauthApplication
     */
    public function getOauthApplication(): OauthApplication
    {
        return $this->oauthApplication;
    }

    /**
     * Setter for relation oauthApplication
     */
    public function setOauthApplication(OauthApplication $oauthApplication): static
    {
        $this->oauthApplication = $oauthApplication;
        return $this;
    }

}
