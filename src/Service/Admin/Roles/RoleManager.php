<?php

declare(strict_types=1);

namespace App\Service\Admin\Roles;

use App\Entity\Role;
use App\Service\Admin\ActsAsPositioned;
use App\Service\Admin\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves and deletes roles (Redmine's Role model callbacks): position among the roles of the same kind,
 * workflow copied to a new role, what goes away with a deleted role, and the permissions report update.
 */
final class RoleManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowService $workflows,
        private readonly ActsAsPositioned $positioned,
        private readonly RoleFinder $finder,
    ) {
    }

    /** @param int $copyWorkflowFrom id of the role whose workflow the new role gets (0: none) */
    public function create(Role $role, int $copyWorkflowFrom = 0): void
    {
        $role->position = $this->positioned->nextPosition('roles', ['builtin' => $role->builtin]);
        $this->em->persist($role);
        $this->em->flush();
        if ($copyWorkflowFrom && ($source = $this->em->find(Role::class, $copyWorkflowFrom))) {
            $this->workflows->copy(null, $source, [], [$role]);
        }
    }

    /** @param int|null $oldPosition position of the role before it was changed */
    public function update(Role $role, ?int $oldPosition): void
    {
        $this->em->flush();
        if (null !== $role->position && $oldPosition !== $role->position) {
            $this->positioned->positionChanged('roles', (int) $role->id, $oldPosition, $role->position, ['builtin' => $role->builtin]);
        }
    }

    /**
     * Deletes the role with its workflow, unless it is a built-in role or members have it.
     *
     * @return bool false when the role cannot be removed and was left untouched
     */
    public function destroy(Role $role): bool
    {
        if ($this->finder->hasMembers($role) || $role->isBuiltin()) {
            return false;
        }
        $conn = $this->em->getConnection();
        $rid = (int) $role->id;
        $position = $role->position;
        $builtin = $role->builtin;
        $this->workflows->deleteForRole($rid);
        $conn->executeStatement('DELETE FROM custom_fields_roles WHERE role_id = ?', [$rid]);
        $conn->executeStatement('DELETE FROM queries_roles WHERE role_id = ?', [$rid]);
        $role->managedRoles->clear();
        $this->em->remove($role);
        $this->em->flush();
        $this->positioned->removePosition('roles', $rid, $position, ['builtin' => $builtin]);

        return true;
    }

    /**
     * The permissions report: replaces the permissions of each submitted role.
     *
     * @param array<int|string, mixed> $permissions role id => list of permissions
     */
    public function updatePermissions(array $permissions): void
    {
        foreach ($permissions as $roleId => $list) {
            $role = $this->em->find(Role::class, (int) $roleId);
            if (null !== $role) {
                $role->setPermissions(array_values(array_filter((array) $list, static fn ($p) => '' !== (string) $p)));
            }
        }
        $this->em->flush();
    }
}
