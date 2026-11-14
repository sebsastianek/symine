<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Project;
use App\Entity\Version;
use App\Security\Authorization;
use App\Service\ProjectService;
use App\Service\VersionService;

/** The versions of the REST API (versions/index.api.rsb and versions/show.api.rsb). */
final class VersionApiPresenter
{
    public function __construct(
        private readonly VersionService $versions,
        private readonly ProjectService $projects,
        private readonly Authorization $auth,
    ) {
    }

    /**
     * The versions of the project and those shared with it, in the order of their ids.
     *
     * @return array{versions: list<array<string, mixed>>, total_count: int}
     */
    public function index(Project $project): array
    {
        $versions = $this->projects->sharedVersions($project);
        usort($versions, static fn (Version $a, Version $b) => $a->id <=> $b->id);

        return [
            'versions' => array_map(fn (Version $v) => $this->version($v, false), $versions),
            'total_count' => \count($versions),
        ];
    }

    /**
     * @param bool $full true for a single version: estimated and spent hours are given to the users allowed
     *                   to view the time entries of its project
     *
     * @return array<string, mixed>
     */
    public function version(Version $version, bool $full): array
    {
        $data = [
            'id' => $version->id,
            'project' => ['id' => $version->project->id, 'name' => $version->project->name],
            'name' => $version->name,
            'description' => $version->description,
            'status' => $version->status,
            'due_date' => ApiRenderer::date($version->effectiveDate),
            'sharing' => $version->sharing,
            'wiki_page_title' => $version->wikiPageTitle,
        ];
        if ($full && $this->auth->allowedTo('view_time_entries', $version->project)) {
            $data['estimated_hours'] = $this->versions->stats($version, true)['estimated_hours'];
            $data['spent_hours'] = $this->versions->spentHours($version);
        }
        $cfs = [];
        foreach ($this->versions->customValues($version) as $cv) {
            $f = $cv['field'];
            $item = ['id' => $f->id, 'name' => $f->name];
            if ($f->isMultiple()) {
                $item['multiple'] = true;
                $item['value'] = array_values(array_filter((array) $cv['value'], static fn ($v) => '' !== $v && null !== $v));
            } else {
                $item['value'] = $cv['value'];
            }
            $cfs[] = $item;
        }
        if ([] !== $cfs) {
            $data['custom_fields'] = $cfs;
        }
        $data['created_on'] = ApiRenderer::time($version->createdOn);
        $data['updated_on'] = ApiRenderer::time($version->updatedOn);

        return $data;
    }
}
