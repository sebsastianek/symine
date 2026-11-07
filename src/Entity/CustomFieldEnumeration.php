<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'custom_field_enumerations')]
class CustomFieldEnumeration implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomField::class, inversedBy: 'enumerations')]
    #[ORM\JoinColumn(nullable: false)]
    public CustomField $customField;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(options: ['default' => true])]
    public bool $active = true;

    #[ORM\Column(options: ['default' => 1])]
    public int $position = 1;

    public function __toString(): string
    {
        return $this->name;
    }
}
