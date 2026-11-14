<?php

declare(strict_types=1);

namespace App\Service\Admin\Memberships;

use App\Entity\Group;
use App\Entity\Member;
use App\Entity\Principal;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads what the "Projects" tab of a user or a group works on (PrincipalMembershipsController#find_principal,
 * #find_membership): the principal of the URL, one of its memberships, all of them, the roles that can be given.
 */
final class PrincipalMemberships
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** The user ("users") or the group ("groups") the memberships belong to: 404 when the id is of another kind */
    public function principal(string $type, int $id): Principal
    {
        $p = $this->em->find(Principal::class, $id);
        if (null === $p || ('users' === $type && !($p instanceof User && $p->isLogged())) || ('groups' === $type && !$p instanceof Group)) {
            throw new NotFoundHttpException();
        }

        return $p;
    }

    public function membership(int $id): Member
    {
        $m = $this->em->find(Member::class, $id);
        if (null === $m) {
            throw new NotFoundHttpException();
        }

        return $m;
    }

    /** @return list<Member> the memberships of the principal in projects that are not archived, in the order of the project tree */
    public function of(Principal $principal): array
    {
        return $this->em->createQuery('SELECT m, pr FROM App\Entity\Member m JOIN m.project pr WHERE m.principal = :p AND pr.status <> 9 ORDER BY pr.lft, pr.name')
            ->setParameter('p', $principal->id)->getResult();
    }

    /** @return list<Role> */
    public function givableRoles(): array
    {
        return $this->em->getRepository(Role::class)->findBy(['builtin' => Role::BUILTIN_NONE], ['position' => 'ASC']);
    }
}
