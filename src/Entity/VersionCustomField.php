<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class VersionCustomField extends CustomField
{
    public static function typeName(): string
    {
        return 'VersionCustomField';
    }

    public static function customizedType(): string
    {
        return 'Version';
    }
}
