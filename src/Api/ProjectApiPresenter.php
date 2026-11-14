<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\IssueCustomField;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Security\Authorization;
use App\Service\CustomFieldService;
use App\Service\ProjectManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** Builds the REST API structures of app/views/projects/index.api.rsb and show.api.rsb. */
final class ProjectApiPresenter
{
    public function __construct(
        private readonly ProjectManager $manager,
        private readonly CustomFieldService $customFields,
        private readonly Authorization $auth,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<string> the associations asked for with ?include=a,b */
    public static function includes(Request $request): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('include', '')))));
    }

    /**
     * projects/index.api.rsb
     *
     * @param list<Project>                   $projects the projects of the page
     * @param list<string>                    $includes
     * @param array{offset: int, limit: int}  $pager
     *
     * @return array<string, mixed>
     */
    public function index(array $projects, array $includes, int $count, array $pager): array
    {
        return [
            'projects' => array_map(fn (Project $p) => $this->project($p, $includes, false), $projects),
            'total_count' => $count, 'offset' => $pager['offset'], 'limit' => $pager['limit'],
        ];
    }

    /**
     * @param list<string> $includes
     * @param bool         $show     true for show.api.rsb, which also gives the default version and assignee
     *
     * @return array<string, mixed>
     */
    public function project(Project $p, array $includes, bool $show): array
    {
        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'identifier' => $p->identifier,
            'description' => $p->description,
            'homepage' => $p->homepage,
        ];
        if (null !== $p->parent && $this->auth->isProjectVisible($p->parent)) {
            $data['parent'] = ['id' => $p->parent->id, 'name' => $p->parent->name];
        }
        $data['status'] = $p->status;
        $data['is_public'] = $p->isPublic;
        $data['inherit_members'] = $p->inheritMembers;
        if ($show) {
            if (null !== $p->defaultVersion) {
                $data['default_version'] = ['id' => $p->defaultVersion->id, 'name' => $p->defaultVersion->name];
            }
            if (null !== $p->defaultAssignedTo) {
                $data['default_assignee'] = ['id' => $p->defaultAssignedTo->id, 'name' => $p->defaultAssignedTo->getName()];
            }
        }
        $fields = $this->manager->visibleCustomFields($p);
        if ([] !== $fields) {
            $values = $this->customFields->values('Project', $p->id);
            $data['custom_fields'] = array_map(static function ($f) use ($values) {
                $v = $values[$f->id] ?? ($f->isMultiple() ? [] : null);
                $row = ['id' => $f->id, 'name' => $f->name];
                if ($f->isMultiple()) {
                    $row['multiple'] = true;
                }
                $row['value'] = $v;

                return $row;
            }, $fields);
        }
        // render_api_includes: trackers, categories and issue custom fields need view_issues,
        // activities need view_time_entries
        $viewIssues = $this->auth->allowedTo('view_issues', $p);
        $viewTime = $this->auth->allowedTo('view_time_entries', $p);
        if ($viewIssues && \in_array('trackers', $includes, true)) {
            $data['trackers'] = array_map(static fn (Tracker $t) => ['id' => $t->id, 'name' => $t->name], $p->trackers->toArray());
        }
        if ($viewIssues && \in_array('issue_categories', $includes, true)) {
            $data['issue_categories'] = array_map(static fn ($c) => ['id' => $c->id, 'name' => $c->name], array_values($p->issueCategories->toArray()));
        }
        if ($viewTime && \in_array('time_entry_activities', $includes, true)) {
            $data['time_entry_activities'] = array_map(static fn ($a) => ['id' => $a->id, 'name' => $a->name], $this->manager->activities($p));
        }
        if (\in_array('enabled_modules', $includes, true)) {
            $mods = $p->enabledModules->toArray();
            usort($mods, static fn ($a, $b) => $a->id <=> $b->id);
            $data['enabled_modules'] = array_map(static fn ($m) => ['id' => $m->id, 'name' => $m->name], $mods);
        }
        if ($viewIssues && \in_array('issue_custom_fields', $includes, true)) {
            $ids = array_map(static fn ($f) => $f->id, $p->issueCustomFields->toArray());
            $all = array_filter($this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']), static fn ($f) => $f->isForAll || \in_array($f->id, $ids, true));
            $data['issue_custom_fields'] = array_values(array_map(static fn ($f) => ['id' => $f->id, 'name' => $f->name], $all));
        }
        $data['created_on'] = ApiRenderer::time($p->createdOn);
        $data['updated_on'] = ApiRenderer::time($p->updatedOn);

        return $data;
    }
}
