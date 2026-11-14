<?php

declare(strict_types=1);

namespace App\Service\Admin\Groups;

use App\Entity\Group;
use App\I18n\Localizer;
use App\Service\Admin\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** The list of Administration > Groups and of the groups API (groups#index). */
final class GroupList
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserManager $users,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * The groups listed by the API: the builtin ones only when asked with "builtin=1".
     *
     * @return list<Group>
     */
    public function forApi(Request $request): array
    {
        $groups = $this->users->allGroups();
        if ('1' !== $request->query->get('builtin')) {
            $groups = array_values(array_filter($groups, static fn (Group $g) => !$g->isBuiltin()));
        }

        return $groups;
    }

    /**
     * Variables of the list page: the groups whose name contains "name", one page of them.
     *
     * @param callable(Request, int): array<string, mixed> $paginate the pager for a number of results
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, callable $paginate): array
    {
        $groups = $this->users->allGroups();
        $name = trim((string) $request->query->get('name', ''));
        if ('' !== $name) {
            $n = mb_strtolower($name);
            $groups = array_values(array_filter($groups, static fn (Group $g) => str_contains(mb_strtolower($g->getName()), $n)));
        }
        $pager = $paginate($request, \count($groups));
        $counts = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT group_id, COUNT(*) AS c FROM groups_users GROUP BY group_id') as $r) {
            $counts[(int) $r['group_id']] = (int) $r['c'];
        }

        return [
            'groups' => \array_slice($groups, $pager['offset'], $pager['limit']),
            'pager' => $pager,
            'name' => $name,
            'user_counts' => $counts,
            'admin_menu_selected' => 'groups',
            'page_title' => $this->i18n->l('label_group_plural'),
            'body_css' => 'controller-groups action-index',
        ];
    }
}
