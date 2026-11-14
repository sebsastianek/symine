<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Member;
use App\Entity\Project;
use App\Service\MembersManager;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;

/** Template variables of the members screens: the list, the "new member" form, its principals and the roles form. */
final class MembersView
{
    public function __construct(
        private readonly MembersManager $members,
        private readonly Settings $settings,
    ) {
    }

    /**
     * members/_members.html.twig for the list the request describes (filters, page, per_page).
     *
     * @param bool $indexPage true on MembersController#index, false for the members tab of the project settings
     *
     * @return array<string, mixed>
     */
    public function list(Project $project, Request $request, bool $indexPage = false): array
    {
        return ['project' => $project] + $this->members->listView($project, MembersRequest::listParams($request), $this->settings->perPageOptions(), $indexPage);
    }

    /** @return array<string, mixed> members/new.html.twig and members/_new_modal.html.twig */
    public function newForm(Project $project, Request $request): array
    {
        [$principals, $count] = $this->members->principalsForNewMembers($project, null);

        return [
            'project' => $project,
            'principals' => $principals,
            'principal_count' => $count,
            'roles' => $this->members->managedRoles($project),
            'members_manager' => $this->members,
            'member_list_params' => MembersManager::listParams($request->query->all()),
        ];
    }

    /** @return array<string, mixed> members/_principals.html.twig: a page of 100 principals matching params[:q] */
    public function principals(Project $project, Request $request): array
    {
        $page = max(1, (int) $request->query->get('page', 1));
        [$principals, $count] = $this->members->principalsForNewMembers($project, (string) $request->query->get('q', ''), ($page - 1) * 100, 100);

        return ['project' => $project, 'principals' => $principals, 'principal_count' => $count, 'page' => $page, 'q' => (string) $request->query->get('q', ''), 'members_manager' => $this->members];
    }

    /** @return array<string, mixed> members/edit.html.twig and members/_edit.html.twig */
    public function editForm(Member $member, Request $request): array
    {
        return ['member' => $member, 'roles' => $this->members->givableRoles(), 'members_manager' => $this->members, 'xhr' => MembersRequest::wantsJs($request), 'member_list_params' => MembersManager::listParams($request->query->all())];
    }
}
