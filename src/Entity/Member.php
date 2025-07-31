<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\MemberRepository;

/**
 * Member.
 * Table: members
 */
#[ORM\Entity(repositoryClass: MemberRepository::class)]
#[ORM\Table(name: 'members')]
class Member
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
     * Property project
     */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private Project $project;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdOn = NULL;

    /**
     * Property mailNotification
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '0'])]
    private int $mailNotification = 0;

    /**
     * Property memberRoles
     */
    #[ORM\OneToMany(mappedBy: 'member', targetEntity: MemberRole::class, cascade: ['persist', 'remove'])]
    private Collection $memberRoles;

    public function __construct()
    {
        $this->memberRoles = new ArrayCollection();
    }

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
    public function getUser(): User    {
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
     * Getter for project
     */
    public function getProject(): Project    {
        return $this->project;
    }

    /**
     * Setter for project
     */
    public function setProject(Project $project): static
    {
        $this->project = $project;
        return $this;
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
     * Getter for mailNotification
     */
    public function getMailNotification(): int    {
        return $this->mailNotification;
    }

    /**
     * Setter for mailNotification
     */
    public function setMailNotification(int $mailNotification): static
    {
        $this->mailNotification = $mailNotification;
        return $this;
    }

    /**
     * Getter for memberRoles
     */
    public function getMemberRoles(): Collection
    {
        return $this->memberRoles;
    }

    /**
     * Add memberRole
     */
    public function addMemberRole(MemberRole $memberRole): static
    {
        if (!$this->memberRoles->contains($memberRole)) {
            $this->memberRoles->add($memberRole);
            $memberRole->setMember($this);
        }

        return $this;
    }

    /**
     * Remove memberRole
     */
    public function removeMemberRole(MemberRole $memberRole): static
    {
        if ($this->memberRoles->removeElement($memberRole)) {
            if ($memberRole->getMember() === $this) {
                $memberRole->setMember(null);
            }
        }

        return $this;
    }

}
