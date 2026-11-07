<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AnonymousUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Equivalent of Redmine's User.current: the authenticated user, or the AnonymousUser record.
 */
final class CurrentUser
{
    private ?AnonymousUser $anonymous = null;
    private ?User $override = null;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function get(): User
    {
        if (null !== $this->override) {
            return $this->override;
        }
        $user = $this->security->getUser();
        if ($user instanceof User) {
            return $user;
        }

        return $this->anonymous();
    }

    /** Used by the API "X-Redmine-Switch-User" header, mail handler and console commands */
    public function set(?User $user): void
    {
        $this->override = $user;
    }

    public function isLogged(): bool
    {
        return $this->get()->isLogged();
    }

    public function anonymous(): AnonymousUser
    {
        if (null === $this->anonymous) {
            $anon = $this->em->getRepository(AnonymousUser::class)->findOneBy([]);
            if (null === $anon) {
                $anon = new AnonymousUser();
                $this->em->persist($anon);
                $this->em->flush();
            }
            $this->anonymous = $anon;
        }

        return $this->anonymous;
    }
}
