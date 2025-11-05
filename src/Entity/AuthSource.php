<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\AuthSourceRepository;

/**
 * AuthSource.
 * Table: auth_sources
 */
#[ORM\Entity(repositoryClass: AuthSourceRepository::class)]
#[ORM\Table(name: 'auth_sources')]
class AuthSource
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property type
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $type = '';

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 60, options: ['default' => ''])]
    private string $name = '';

    /**
     * Property host
     */
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $host = NULL;

    /**
     * Property port
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $port = NULL;

    /**
     * Property account
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $account = NULL;

    /**
     * Property accountPassword
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    private ?string $accountPassword = '';

    /**
     * Property baseDn
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $baseDn = NULL;

    /**
     * Property attrLogin
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $attrLogin = NULL;

    /**
     * Property attrFirstname
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $attrFirstname = NULL;

    /**
     * Property attrLastname
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $attrLastname = NULL;

    /**
     * Property attrMail
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $attrMail = NULL;

    /**
     * Property ontheflyRegister
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $ontheflyRegister = false;

    /**
     * Property tls
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $tls = false;

    /**
     * Property filter
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $filter = NULL;

    /**
     * Property timeout
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $timeout = NULL;

    /**
     * Property verifyPeer
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $verifyPeer = true;

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
     * Getter for type
     */
    public function getType(): string    {
        return $this->type;
    }

    /**
     * Setter for type
     */
    public function setType(string $type): static
    {
        $this->type = $type;
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
     * Getter for host
     */
    public function getHost(): ?string    {
        return $this->host;
    }

    /**
     * Setter for host
     */
    public function setHost(?string $host): static
    {
        $this->host = $host;
        return $this;
    }

    /**
     * Getter for port
     */
    public function getPort(): ?int    {
        return $this->port;
    }

    /**
     * Setter for port
     */
    public function setPort(?int $port): static
    {
        $this->port = $port;
        return $this;
    }

    /**
     * Getter for account
     */
    public function getAccount(): ?string    {
        return $this->account;
    }

    /**
     * Setter for account
     */
    public function setAccount(?string $account): static
    {
        $this->account = $account;
        return $this;
    }

    /**
     * Getter for accountPassword
     */
    public function getAccountPassword(): ?string    {
        return $this->accountPassword;
    }

    /**
     * Setter for accountPassword
     */
    public function setAccountPassword(?string $accountPassword): static
    {
        $this->accountPassword = $accountPassword;
        return $this;
    }

    /**
     * Getter for baseDn
     */
    public function getBaseDn(): ?string    {
        return $this->baseDn;
    }

    /**
     * Setter for baseDn
     */
    public function setBaseDn(?string $baseDn): static
    {
        $this->baseDn = $baseDn;
        return $this;
    }

    /**
     * Getter for attrLogin
     */
    public function getAttrLogin(): ?string    {
        return $this->attrLogin;
    }

    /**
     * Setter for attrLogin
     */
    public function setAttrLogin(?string $attrLogin): static
    {
        $this->attrLogin = $attrLogin;
        return $this;
    }

    /**
     * Getter for attrFirstname
     */
    public function getAttrFirstname(): ?string    {
        return $this->attrFirstname;
    }

    /**
     * Setter for attrFirstname
     */
    public function setAttrFirstname(?string $attrFirstname): static
    {
        $this->attrFirstname = $attrFirstname;
        return $this;
    }

    /**
     * Getter for attrLastname
     */
    public function getAttrLastname(): ?string    {
        return $this->attrLastname;
    }

    /**
     * Setter for attrLastname
     */
    public function setAttrLastname(?string $attrLastname): static
    {
        $this->attrLastname = $attrLastname;
        return $this;
    }

    /**
     * Getter for attrMail
     */
    public function getAttrMail(): ?string    {
        return $this->attrMail;
    }

    /**
     * Setter for attrMail
     */
    public function setAttrMail(?string $attrMail): static
    {
        $this->attrMail = $attrMail;
        return $this;
    }

    /**
     * Getter for ontheflyRegister
     */
    public function getOntheflyRegister(): bool
    {
        return $this->ontheflyRegister;
    }

    /**
     * Setter for ontheflyRegister
     */
    public function setOntheflyRegister(bool $ontheflyRegister): static
    {
        $this->ontheflyRegister = $ontheflyRegister;
        return $this;
    }

    /**
     * Getter for tls
     */
    public function getTls(): bool
    {
        return $this->tls;
    }

    /**
     * Setter for tls
     */
    public function setTls(bool $tls): static
    {
        $this->tls = $tls;
        return $this;
    }

    /**
     * Getter for filter
     */
    public function getFilter(): ?string    {
        return $this->filter;
    }

    /**
     * Setter for filter
     */
    public function setFilter(?string $filter): static
    {
        $this->filter = $filter;
        return $this;
    }

    /**
     * Getter for timeout
     */
    public function getTimeout(): ?int    {
        return $this->timeout;
    }

    /**
     * Setter for timeout
     */
    public function setTimeout(?int $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Getter for verifyPeer
     */
    public function getVerifyPeer(): bool
    {
        return $this->verifyPeer;
    }

    /**
     * Setter for verifyPeer
     */
    public function setVerifyPeer(bool $verifyPeer): static
    {
        $this->verifyPeer = $verifyPeer;
        return $this;
    }

}
