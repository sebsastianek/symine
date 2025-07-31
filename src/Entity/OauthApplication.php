<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\OauthApplicationRepository;

/**
 * OauthApplication.
 * Table: oauth_applications
 */
#[ORM\Entity(repositoryClass: OauthApplicationRepository::class)]
#[ORM\Table(name: 'oauth_applications')]
class OauthApplication
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', nullable: false)]
    private int $id;

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Property uid
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $uid;

    /**
     * Property secret
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $secret;

    /**
     * Property redirectUri
     */
    #[ORM\Column(type: 'text', length: 65535)]
    private string $redirectUri;

    /**
     * Property scopes
     */
    #[ORM\Column(type: 'text', length: 65535)]
    private string $scopes;

    /**
     * Property confidential
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '1'])]
    private int $confidential = 1;

    /**
     * Property createdAt
     */
    #[ORM\Column(type: 'datetime', length: 6)]
    private \DateTimeInterface $createdAt;

    /**
     * Property updatedAt
     */
    #[ORM\Column(type: 'datetime', length: 6)]
    private \DateTimeInterface $updatedAt;

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
     * Getter for name
     */
    public function getName(): string    {
        return $this->name;
    }

    /**
     * Setter for name
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Getter for uid
     */
    public function getUid(): string    {
        return $this->uid;
    }

    /**
     * Setter for uid
     */
    public function setUid(string $uid): static
    {
        $this->uid = $uid;
        return $this;
    }

    /**
     * Getter for secret
     */
    public function getSecret(): string    {
        return $this->secret;
    }

    /**
     * Setter for secret
     */
    public function setSecret(string $secret): static
    {
        $this->secret = $secret;
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
     * Getter for scopes
     */
    public function getScopes(): string    {
        return $this->scopes;
    }

    /**
     * Setter for scopes
     */
    public function setScopes(string $scopes): static
    {
        $this->scopes = $scopes;
        return $this;
    }

    /**
     * Getter for confidential
     */
    public function getConfidential(): int    {
        return $this->confidential;
    }

    /**
     * Setter for confidential
     */
    public function setConfidential(int $confidential): static
    {
        $this->confidential = $confidential;
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
     * Getter for updatedAt
     */
    public function getUpdatedAt(): \DateTimeInterface    {
        return $this->updatedAt;
    }

    /**
     * Setter for updatedAt
     */
    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

}
