<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class DocumentCustomField extends CustomField
{
    public static function typeName(): string
    {
        return 'DocumentCustomField';
    }

    public static function customizedType(): string
    {
        return 'Document';
    }
}
