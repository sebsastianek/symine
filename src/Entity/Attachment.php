<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\AttachmentRepository;

/**
 * Attachment.
 * Table: attachments
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Table(name: 'attachments')]
class Attachment
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property containerId
     * Note: This represents a polymorphic relation where the target entity
     * is determined by containerType (Issue, Document, Wiki, etc.)
     * Keep as integer ID for now due to polymorphic nature
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $containerId = NULL;

    /**
     * Property containerType
     * Determines the entity type that this attachment belongs to
     * Common values: 'Issue', 'Document', 'Wiki', 'Project', etc.
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $containerType = NULL;

    /**
     * Property filename
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $filename = '';

    /**
     * Property diskFilename
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $diskFilename = '';

    /**
     * Property filesize
     */
    #[ORM\Column(type: 'bigint', options: ['default' => '0'])]
    private int $filesize = 0;

    /**
     * Property contentType
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    private ?string $contentType = '';

    /**
     * Property digest
     */
    #[ORM\Column(type: 'string', length: 64, options: ['default' => ''])]
    private string $digest = '';

    /**
     * Property downloads
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $downloads = 0;

    /**
     * The user who uploaded this attachment
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    private User $author;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdOn = NULL;

    /**
     * Property description
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = NULL;

    /**
     * Property diskDirectory
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $diskDirectory = NULL;

    /**
     * Getter for id
     */
    public function getId(): int    {
        return $this->id;
    }

    /**
     * Setter for id
     */
    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Getter for containerId
     */
    public function getContainerId(): ?int    {
        return $this->containerId;
    }

    /**
     * Setter for containerId
     */
    public function setContainerId(?int $containerId): static
    {
        $this->containerId = $containerId;
        return $this;
    }

    /**
     * Getter for containerType
     */
    public function getContainerType(): ?string    {
        return $this->containerType;
    }

    /**
     * Setter for containerType
     */
    public function setContainerType(?string $containerType): static
    {
        $this->containerType = $containerType;
        return $this;
    }

    /**
     * Getter for filename
     */
    public function getFilename(): string    {
        return $this->filename;
    }

    /**
     * Setter for filename
     */
    public function setFilename(string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }

    /**
     * Getter for diskFilename
     */
    public function getDiskFilename(): string    {
        return $this->diskFilename;
    }

    /**
     * Setter for diskFilename
     */
    public function setDiskFilename(string $diskFilename): static
    {
        $this->diskFilename = $diskFilename;
        return $this;
    }

    /**
     * Getter for filesize
     */
    public function getFilesize(): int    {
        return $this->filesize;
    }

    /**
     * Setter for filesize
     */
    public function setFilesize(int $filesize): static
    {
        $this->filesize = $filesize;
        return $this;
    }

    /**
     * Getter for contentType
     */
    public function getContentType(): ?string    {
        return $this->contentType;
    }

    /**
     * Setter for contentType
     */
    public function setContentType(?string $contentType): static
    {
        $this->contentType = $contentType;
        return $this;
    }

    /**
     * Getter for digest
     */
    public function getDigest(): string    {
        return $this->digest;
    }

    /**
     * Setter for digest
     */
    public function setDigest(string $digest): static
    {
        $this->digest = $digest;
        return $this;
    }

    /**
     * Getter for downloads
     */
    public function getDownloads(): int    {
        return $this->downloads;
    }

    /**
     * Setter for downloads
     */
    public function setDownloads(int $downloads): static
    {
        $this->downloads = $downloads;
        return $this;
    }

    /**
     * Getter for author
     */
    public function getAuthor(): User
    {
        return $this->author;
    }

    /**
     * Setter for author
     */
    public function setAuthor(User $author): static
    {
        $this->author = $author;
        return $this;
    }

    /**
     * Legacy getter for authorId (for backwards compatibility)
     */
    public function getAuthorId(): int
    {
        return $this->author->getId();
    }

    /**
     * Legacy setter for authorId (for backwards compatibility)
     * @deprecated Use setAuthor() instead
     */
    public function setAuthorId(int $authorId): static
    {
        // This method is kept for backwards compatibility but should not be used
        // in new code. Use setAuthor() instead.
        throw new \BadMethodCallException('Use setAuthor() instead of setAuthorId()');
    }

    /**
     * Getter for createdOn
     */
    public function getCreatedOn(): ?\DateTimeInterface    {
        return $this->createdOn;
    }

    /**
     * Setter for createdOn
     */
    public function setCreatedOn(?\DateTimeInterface $createdOn): static
    {
        $this->createdOn = $createdOn;
        return $this;
    }

    /**
     * Getter for description
     */
    public function getDescription(): ?string    {
        return $this->description;
    }

    /**
     * Setter for description
     */
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Getter for diskDirectory
     */
    public function getDiskDirectory(): ?string    {
        return $this->diskDirectory;
    }

    /**
     * Setter for diskDirectory
     */
    public function setDiskDirectory(?string $diskDirectory): static
    {
        $this->diskDirectory = $diskDirectory;
        return $this;
    }

}
