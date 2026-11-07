<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'documents')]
#[ORM\Index(name: 'documents_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_documents_on_category_id', columns: ['category_id'])]
#[ORM\Index(name: 'index_documents_on_created_on', columns: ['created_on'])]
class Document
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\ManyToOne(targetEntity: DocumentCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: false)]
    public DocumentCategory $category;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdOn = null;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
    }
}
