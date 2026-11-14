<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Group;
use App\Entity\GroupAnonymous;
use App\Entity\GroupNonMember;
use App\Entity\Member;
use App\I18n\Localizer;
use App\Service\MembersManager;

/** Serialises project memberships for the REST API (members/index.api.rsb, members/show.api.rsb). */
final class MembershipApiPresenter
{
    public function __construct(
        private readonly MembersManager $members,
        private readonly Localizer $i18n,
    ) {
    }

    /** @return array<string, mixed> membership API payload (members/show.api.rsb) */
    public function membership(Member $m): array
    {
        $data = [
            'id' => $m->id,
            'project' => ['id' => $m->project->id, 'name' => $m->project->name],
        ];
        // api.__send__ membership.principal.class.name.underscore: user, group, group_anonymous, group_non_member
        $key = match (true) {
            $m->principal instanceof GroupAnonymous => 'group_anonymous',
            $m->principal instanceof GroupNonMember => 'group_non_member',
            $m->principal instanceof Group => 'group',
            default => 'user',
        };
        $data[$key] = ['id' => $m->principal->id, 'name' => $this->members->principalName($m->principal)];
        $roles = [];
        foreach ($m->memberRoles as $mr) {
            $r = ['id' => $mr->role->id, 'name' => $mr->role->name];
            if (null !== $mr->inheritedFrom) {
                $r['inherited'] = true;
            }
            $roles[] = $r;
        }
        $data['roles'] = $roles;

        return $data;
    }

    /**
     * members/index.api.rsb: the page of the memberships the pager designates.
     *
     * @param list<Member>                    $all
     * @param array{offset: int, limit: int} $pager
     *
     * @return array<string, mixed>
     */
    public function memberships(array $all, array $pager): array
    {
        return [
            'memberships' => array_map($this->membership(...), \array_slice($all, $pager['offset'], $pager['limit'])),
            'total_count' => \count($all), 'offset' => $pager['offset'], 'limit' => $pager['limit'],
        ];
    }

    /**
     * Errors answered to a creation request: those of the memberships that could not be saved, and the
     * missing principal when nothing was created at all. Empty when the membership was created.
     *
     * @param array{members: list<Member>, errors: list<string>} $result
     *
     * @return list<string>
     */
    public function creationErrors(array $result): array
    {
        if ([] === $result['errors'] && [] !== $result['members']) {
            return [];
        }

        return $result['errors'] ?: [$this->i18n->l('field_principal').' '.$this->i18n->l('activerecord.errors.messages.blank')];
    }
}
