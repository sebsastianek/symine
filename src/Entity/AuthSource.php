<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** LDAP authentication sources (type = AuthSourceLdap) */
#[ORM\Entity]
#[ORM\Table(name: 'auth_sources')]
#[ORM\Index(name: 'index_auth_sources_on_id_and_type', columns: ['id', 'type'])]
class AuthSource
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $type = 'AuthSourceLdap';

    #[ORM\Column(length: 60, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $host = null;

    #[ORM\Column(nullable: true)]
    public ?int $port = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $account = null;

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    public ?string $accountPassword = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $baseDn = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $attrLogin = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $attrFirstname = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $attrLastname = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $attrMail = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $ontheflyRegister = false;

    #[ORM\Column(options: ['default' => false])]
    public bool $tls = false;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $filter = null;

    #[ORM\Column(nullable: true)]
    public ?int $timeout = null;

    #[ORM\Column(options: ['default' => true])]
    public bool $verifyPeer = true;
}
