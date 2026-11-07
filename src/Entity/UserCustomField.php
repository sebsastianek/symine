<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class UserCustomField extends CustomField
{
    public static function typeName(): string
    {
        return 'UserCustomField';
    }

    public static function customizedType(): string
    {
        return 'Principal';
    }
}
