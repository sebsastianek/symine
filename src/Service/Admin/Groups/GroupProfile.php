<?php

declare(strict_types=1);

namespace App\Service\Admin\Groups;

use App\Entity\Group;
use App\Entity\User;
use App\Service\Admin\UserManager;
use App\Service\CustomFieldService;

/** What the page of a group shows to the current user (groups#show): its custom fields and the users he may see. */
final class GroupProfile
{
    public function __construct(
        private readonly UserManager $users,
        private readonly CustomFieldService $customFields,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(Group $group): array
    {
        $values = $this->customFields->values('Principal', $group->id);
        $cfs = [];
        foreach ($this->users->visibleCustomFields($this->users->groupCustomFields()) as $f) {
            $cfs[] = ['field' => $f, 'value' => $this->customFields->formatValue($f, $values[$f->id] ?? null), 'raw' => $values[$f->id] ?? null];
        }
        $users = array_values(array_filter($group->users->toArray(), fn (User $u) => $this->users->isVisible($u)));

        return [
            'group' => $group,
            'custom_values' => $cfs,
            'users' => $users,
            'page_title' => $group->getName(),
            'body_css' => 'controller-groups action-show',
        ];
    }
}
