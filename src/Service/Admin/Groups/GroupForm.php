<?php

declare(strict_types=1);

namespace App\Service\Admin\Groups;

use App\Api\ApiRenderer;
use App\Entity\Group;
use App\Security\ApiRequestMatcher;
use App\Service\Admin\UserManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * The group form of the administration and the groups API (groups#create, groups#update): reads group[...]
 * from the form or the API payload and saves the group.
 */
final class GroupForm
{
    public function __construct(private readonly UserManager $users)
    {
    }

    public function create(Request $request): GroupSubmission
    {
        return $this->update(new Group(), $request);
    }

    public function update(Group $group, Request $request): GroupSubmission
    {
        $payload = ApiRequestMatcher::isApiRequest($request) ? ApiRenderer::payload($request) : $request->request->all();
        $attrs = \is_array($payload['group'] ?? null) ? $payload['group'] : [];

        return new GroupSubmission($group, $this->users->saveGroup($group, $attrs), $attrs);
    }
}
