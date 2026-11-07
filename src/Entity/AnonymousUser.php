<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AnonymousUser extends User
{
    public function __construct()
    {
        parent::__construct();
        $this->status = self::STATUS_ANONYMOUS;
        $this->lastname = 'Anonymous';
        $this->mailNotification = 'only_my_events';
    }

    public function getName(?string $format = null): string
    {
        return 'Anonymous';
    }

    public function isLogged(): bool
    {
        return false;
    }

    public function getRoles(): array
    {
        return [];
    }
}
