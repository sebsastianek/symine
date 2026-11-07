<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Polymorphic attachment (container_type: Issue, Journal, WikiPage, Document, Message, News, Project, Version) */
#[ORM\Entity(repositoryClass: \App\Repository\AttachmentRepository::class)]
#[ORM\Table(name: 'attachments')]
#[ORM\Index(name: 'index_attachments_on_container_id_and_container_type', columns: ['container_id', 'container_type'])]
#[ORM\Index(name: 'index_attachments_on_author_id', columns: ['author_id'])]
#[ORM\Index(name: 'index_attachments_on_created_on', columns: ['created_on'])]
#[ORM\Index(name: 'index_attachments_on_disk_filename', columns: ['disk_filename'])]
class Attachment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(nullable: true)]
    public ?int $containerId = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $containerType = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $filename = '';

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $diskFilename = '';

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    public int|string $filesize = 0;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $contentType = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $digest = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $downloads = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $author;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdOn = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $diskDirectory = null;

    public function isImage(): bool
    {
        return (bool) preg_match('/\.(bmp|gif|jpg|jpe|jpeg|png|webp|svg)$/i', $this->filename);
    }

    public function isText(): bool
    {
        return str_starts_with((string) $this->contentType, 'text/')
            || (bool) preg_match('/\.(txt|log|md|markdown|textile|csv|json|xml|yml|yaml|php|rb|py|js|ts|css|html|sql|sh|c|h|cpp|java|go|rs|ini|conf)$/i', $this->filename);
    }

    public function isPdf(): bool
    {
        return (bool) preg_match('/\.pdf$/i', $this->filename);
    }

    public function relativePath(): string
    {
        return ($this->diskDirectory ? $this->diskDirectory.'/' : '').$this->diskFilename;
    }

    public function getFilesize(): int
    {
        return (int) $this->filesize;
    }
}
