<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Api\ApiRenderer;
use App\Entity\AuthSource;
use App\Entity\CustomField;
use App\Entity\Group;
use App\Entity\Member;
use App\Entity\Principal;
use App\Entity\Token;
use App\Entity\User;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\CustomFieldService;
use Doctrine\ORM\EntityManagerInterface;

/** REST API representations of users and groups (users/*.api.rsb, groups/*.api.rsb). */
final class PrincipalApi
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomFieldService $customFields,
        private readonly UserManager $users,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
    ) {
    }

    /** render_api_custom_values */
    public function customValues(Principal $p, array $fields): ?array
    {
        if ([] === $fields) {
            return null;
        }
        $values = $this->customFields->values('Principal', $p->id);
        $out = [];
        foreach ($fields as $f) {
            /** @var CustomField $f */
            $item = ['id' => $f->id, 'name' => $f->name];
            if ($f->isMultiple()) {
                $item['multiple'] = true;
                $item['value'] = array_values(array_filter((array) ($values[$f->id] ?? []), static fn ($v) => '' !== (string) $v));
            } else {
                $item['value'] = $values[$f->id] ?? null;
            }
            $out[] = $item;
        }

        return $out;
    }

    /** users/index.api.rsb item */
    public function userListItem(User $u, array $includes = []): array
    {
        $d = [
            'id' => $u->id,
            'login' => $u->login,
            'admin' => $u->admin,
            'firstname' => $u->firstname,
            'lastname' => $u->lastname,
            'mail' => $u->getMail(),
            'created_on' => ApiRenderer::time($u->createdOn),
            'updated_on' => ApiRenderer::time($u->updatedOn),
            'last_login_on' => ApiRenderer::time($u->lastLoginOn),
            'passwd_changed_on' => ApiRenderer::time($u->passwdChangedOn),
            'twofa_scheme' => $u->twofaScheme,
            'status' => $u->status,
        ];
        if (\in_array('auth_source', $includes, true) && $u->authSourceId && ($src = $this->em->find(AuthSource::class, $u->authSourceId))) {
            $d['auth_source'] = ['id' => $src->id, 'name' => $src->name];
        }
        $cf = $this->customValues($u, $this->users->visibleCustomFields($this->users->userCustomFields()));
        if (null !== $cf) {
            $d['custom_fields'] = $cf;
        }

        return $d;
    }

    /** users/show.api.rsb */
    public function user(User $u, array $includes = []): array
    {
        $current = $this->currentUser->get();
        $self = $current->id === $u->id;
        $d = ['id' => $u->id, 'login' => $u->login];
        if ($current->admin || $self) {
            $d['admin'] = $u->admin;
        }
        $d['firstname'] = $u->firstname;
        $d['lastname'] = $u->lastname;
        if ($current->admin || !$u->getPref()->hideMail) {
            $d['mail'] = $u->getMail();
        }
        $d['created_on'] = ApiRenderer::time($u->createdOn);
        $d['updated_on'] = ApiRenderer::time($u->updatedOn);
        $d['last_login_on'] = ApiRenderer::time($u->lastLoginOn);
        $d['passwd_changed_on'] = ApiRenderer::time($u->passwdChangedOn);
        if ($current->admin || $self) {
            $d['twofa_scheme'] = $u->twofaScheme;
            $d['api_key'] = $this->apiKey($u);
        }
        if ($current->admin) {
            $d['status'] = $u->status;
        }
        $cf = $this->customValues($u, $this->users->visibleCustomFields($this->users->userCustomFields()));
        if (null !== $cf) {
            $d['custom_fields'] = $cf;
        }
        if ($current->admin && \in_array('auth_source', $includes, true) && $u->authSourceId && ($src = $this->em->find(AuthSource::class, $u->authSourceId))) {
            $d['auth_source'] = ['id' => $src->id, 'name' => $src->name];
        }
        if ($current->admin && \in_array('groups', $includes, true)) {
            $d['groups'] = array_values(array_map(static fn (Group $g) => ['id' => $g->id, 'name' => $g->getName()], $u->groups->toArray()));
        }
        if (\in_array('memberships', $includes, true)) {
            $d['memberships'] = $this->memberships($u);
        }

        return $d;
    }

    /** Visible memberships (non archived, visible projects) */
    public function memberships(Principal $p): array
    {
        $out = [];
        $members = $this->visibleMemberships($p);
        usort($members, static fn (Member $a, Member $b) => $a->id <=> $b->id);
        foreach ($members as $m) {
            $roles = [];
            foreach ($m->memberRoles as $mr) {
                $r = ['id' => $mr->role->id, 'name' => $mr->role->name];
                if (null !== $mr->inheritedFrom) {
                    $r['inherited'] = true;
                }
                $roles[] = $r;
            }
            $out[] = ['id' => $m->id, 'project' => ['id' => $m->project->id, 'name' => $m->project->name], 'roles' => $roles];
        }

        return $out;
    }

    /** @return list<Member> memberships of non archived projects visible by the current user, sorted by project */
    public function visibleMemberships(Principal $p): array
    {
        $members = $this->em->createQuery('SELECT m, mr, r, pr FROM App\Entity\Member m JOIN m.project pr LEFT JOIN m.memberRoles mr LEFT JOIN mr.role r WHERE m.principal = :p AND pr.status <> 9 ORDER BY pr.lft, pr.name')
            ->setParameter('p', $p->id)->getResult();
        $visible = $this->auth->visibleProjectIds();

        return array_values(array_filter($members, static fn (Member $m) => \in_array($m->project->id, $visible, true)));
    }

    public function apiKey(User $u): ?string
    {
        $t = $this->em->getRepository(Token::class)->findOneBy(['user' => $u, 'action' => 'api']);

        return $t?->value;
    }

    /** groups/index.api.rsb item */
    public function groupListItem(Group $g): array
    {
        $d = ['id' => $g->id, 'name' => $g->lastname ?: $g->getName()];
        if ($b = UserManager::builtinType($g)) {
            $d['builtin'] = $b;
        }
        $cf = $this->customValues($g, $this->users->visibleCustomFields($this->users->groupCustomFields()));
        if (null !== $cf) {
            $d['custom_fields'] = $cf;
        }

        return $d;
    }

    /** groups/show.api.rsb */
    public function group(Group $g, array $includes = []): array
    {
        $d = $this->groupListItem($g);
        if (\in_array('users', $includes, true) && !$g->isBuiltin()) {
            $d['users'] = array_values(array_map(static fn (User $u) => ['id' => $u->id, 'name' => $u->getName()], $g->users->toArray()));
        }
        if (\in_array('memberships', $includes, true)) {
            $d['memberships'] = $this->memberships($g);
        }

        return $d;
    }
}
