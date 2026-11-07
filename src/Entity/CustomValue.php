<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Polymorphic custom field value. Multiple-value fields have one row per value. */
#[ORM\Entity(repositoryClass: \App\Repository\CustomValueRepository::class)]
#[ORM\Table(name: 'custom_values')]
#[ORM\Index(name: 'custom_values_customized_custom_field', columns: ['customized_type', 'customized_id', 'custom_field_id'])]
#[ORM\Index(name: 'index_custom_values_on_custom_field_id', columns: ['custom_field_id'])]
class CustomValue
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $customizedType = '';

    #[ORM\Column(options: ['default' => 0])]
    public int $customizedId = 0;

    #[ORM\ManyToOne(targetEntity: CustomField::class)]
    #[ORM\JoinColumn(nullable: false)]
    public CustomField $customField;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $value = null;
}
