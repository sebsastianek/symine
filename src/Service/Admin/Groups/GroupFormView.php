<?php

declare(strict_types=1);

namespace App\Service\Admin\Groups;

use App\Entity\Group;
use App\Entity\User;
use App\I18n\Localizer;
use App\Service\Admin\Memberships\PrincipalMemberships;
use App\Service\Admin\UserManager;
use App\Service\CustomFieldService;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the "new group" and "edit group" pages of the administration show (groups/new, groups/edit with its
 * tabs, groups/_form): the group as it is, or as it was submitted when it could not be saved.
 */
final class GroupFormView
{
    public function __construct(
        private readonly UserManager $users,
        private readonly CustomFieldService $customFields,
        private readonly PrincipalMemberships $memberships,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * The empty form, or the form of a group that could not be created.
     *
     * @return array<string, mixed>
     */
    public function forNew(?GroupSubmission $submission = null): array
    {
        return $this->form($submission?->group ?? new Group(), $this->submitted($submission));
    }

    /**
     * The edit page of a group (users_page and per_page page its users), with what was submitted when it
     * could not be saved.
     *
     * @return array<string, mixed>
     */
    public function forEdit(Request $request, Group $group, ?GroupSubmission $submission = null): array
    {
        $tabs = [['name' => 'general', 'label' => 'label_general', 'template' => 'admin/groups/_general.html.twig']];
        if (!$group->isBuiltin()) {
            $tabs[] = ['name' => 'users', 'label' => 'label_user_plural', 'template' => 'admin/groups/_users.html.twig'];
        }
        $tabs[] = ['name' => 'memberships', 'label' => 'label_project_plural', 'template' => 'admin/principal_memberships/_index.html.twig'];

        $users = $group->users->toArray();
        usort($users, static fn (User $a, User $b) => [$a->firstname, $a->lastname, $a->id] <=> [$b->firstname, $b->lastname, $b->id]);
        $perPage = $this->settings->perPageOptions();
        $limit = \in_array((int) $request->query->get('per_page'), $perPage, true) ? (int) $request->query->get('per_page') : $perPage[0];
        $pages = max(1, (int) ceil(\count($users) / $limit));
        $page = max(1, min($pages, (int) $request->query->get('users_page', 1)));
        $usersPager = ['offset' => ($page - 1) * $limit, 'limit' => $limit, 'page' => $page, 'count' => \count($users), 'pages' => $pages, 'per_page_options' => $perPage];

        $memberships = $this->memberships->of($group);

        return $this->form($group, $this->submitted($submission) + [
            'tabs' => $tabs,
            'group_users' => \array_slice($users, $usersPager['offset'], $limit),
            'users_pager' => $usersPager,
            'principal' => $group,
            'principal_memberships' => $memberships,
        ]);
    }

    /** @return array<string, mixed> */
    private function submitted(?GroupSubmission $submission): array
    {
        return null === $submission ? [] : ['errors' => $submission->errors, 'posted_cf' => $this->users->customFieldParams($submission->attrs)];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function form(Group $group, array $extra = []): array
    {
        $fields = $group->isBuiltin() ? [] : $this->users->groupCustomFields();
        $values = null === $group->id ? $this->customFields->defaultValues($fields) : $this->customFields->values('Principal', $group->id);
        foreach ($extra['posted_cf'] ?? [] as $k => $v) {
            $values[$k] = $v;
        }

        return $extra + [
            'group' => $group,
            'custom_fields' => $fields,
            'custom_values' => $values,
            'cf_options' => array_combine(array_map(static fn ($f) => $f->id, $fields), array_map(fn ($f) => $this->customFields->possibleValuesOptions($f), $fields)) ?: [],
            'errors' => $extra['errors'] ?? [],
            'admin_menu_selected' => 'groups',
            'page_title' => null === $group->id ? $this->i18n->l('label_group_new') : $group->getName(),
            'body_css' => 'controller-groups action-'.(null === $group->id ? 'new' : 'edit'),
        ];
    }
}
