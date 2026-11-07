<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class GroupAnonymous extends GroupBuiltin
{
    public function getName(?string $format = null): string
    {
        return 'Anonymous users';
    }
}
