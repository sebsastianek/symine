<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserPreferenceRepository;

/**
 * UserPreference.
 * Table: user_preferences
 */
#[ORM\Entity(repositoryClass: UserPreferenceRepository::class)]
#[ORM\Table(name: 'user_preferences')]
class UserPreference
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property user
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    /**
     * Property others
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $others = NULL;

    /**
     * Property hideMail
     */
    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => '1'])]
    private ?int $hideMail = 1;

    /**
     * Property timeZone
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $timeZone = NULL;

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
     * Getter for user
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Setter for user
     */
    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Getter for others
     */
    public function getOthers(): ?string    {
        return $this->others;
    }

    /**
     * Setter for others
     */
    public function setOthers(?string $others): static
    {
        $this->others = $others;
        return $this;
    }

    /**
     * Getter for hideMail
     */
    public function getHideMail(): ?int    {
        return $this->hideMail;
    }

    /**
     * Setter for hideMail
     */
    public function setHideMail(?int $hideMail): static
    {
        $this->hideMail = $hideMail;
        return $this;
    }

    /**
     * Getter for timeZone
     */
    public function getTimeZone(): ?string    {
        return $this->timeZone;
    }

    /**
     * Setter for timeZone
     */
    public function setTimeZone(?string $timeZone): static
    {
        $this->timeZone = $timeZone;
        return $this;
    }

}
