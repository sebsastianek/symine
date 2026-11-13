<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\Wiki;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\ProjectService;
use App\Service\VersionService;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\RuntimeExtensionInterface;

/** Lazy runtime of VersionExtension (port of VersionsHelper & co). */
final class VersionRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly VersionService $versions,
        private readonly Localizer $i18n,
        private readonly Authorization $auth,
        private readonly RouterInterface $router,
        private readonly ProjectService $projects,
        private readonly IssueService $issues,
        private readonly CustomFieldService $customFields,
    ) {
    }

    public function stats(Version $v, bool $visible = true): array
    {
        return $this->versions->stats($v, $visible);
    }

    public function service(): VersionService
    {
        return $this->versions;
    }

    public function isCompleted(Version $v): bool
    {
        return $this->versions->isCompleted($v);
    }

    public function cssClasses(Version $v): string
    {
        return $this->versions->cssClasses($v);
    }

    public function spentHours(Version $v): float
    {
        return $this->versions->spentHours($v);
    }

    /** @return list<string> */
    public function allowedSharings(Version $v): array
    {
        return $this->versions->allowedSharings($v);
    }

    public function wikiPage(Version $v): ?\App\Entity\WikiPage
    {
        return $this->versions->wikiPage($v);
    }

    public function wiki(Project $p): ?Wiki
    {
        return $this->versions->wiki($p);
    }

    public function statusBy(Version $v, ?string $criteria): array
    {
        return $this->versions->statusBy($v, (string) $criteria);
    }

    public function customFields(Version $v): array
    {
        return $this->versions->visibleCustomFields($v);
    }

    public function formatName(Version $v, ?Project $p): string
    {
        return (null !== $p && $v->project->id !== $p->id) ? $v->project->name.' - '.$v->name : (string) $v->name;
    }

    public function formatSharing(?string $s): string
    {
        return $this->i18n->l('label_version_sharing_'.(\in_array($s, Version::SHARINGS, true) ? $s : 'none'));
    }

    /** @return list<array{field: object, value: mixed, formatted: string}> */
    public function customValues(Version $version): array
    {
        $out = [];
        foreach ($this->versions->customValues($version) as $cv) {
            $v = $cv['value'];
            if (null === $v || '' === $v || [] === $v) {
                continue;
            }
            $out[] = ['field' => $cv['field'], 'value' => $v, 'formatted' => $this->customFields->formatValue($cv['field'], $v)];
        }

        return $out;
    }

    /** VersionsHelper#version_filtered_issues_path */
    public function filteredIssuesPath(Version $version, array $options = []): ?string
    {
        $options = ['fixed_version_id' => $version->id, 'set_filter' => 1] + $options;
        $project = match ($version->sharing) {
            'tree' => ($root = $version->project->getRoot()) && $this->auth->isProjectVisible($root) && $this->auth->allowedTo('view_issues', $root) ? $root : null,
            'system' => null,
            default => $version->project,
        };
        ksort($options);
        if (null !== $project) {
            return $this->route('project_issues', ['project_id' => $project->identifier] + $options);
        }

        return $this->route('issues_index', $options);
    }

    private function route(string $name, array $params): ?string
    {
        if (null === $this->router->getRouteCollection()->get($name)) {
            return null;
        }

        return $this->router->generate($name, $params);
    }

    /** VersionsHelper#link_to_new_issue: tracker usable for a new issue of the version, or null */
    public function newIssueTracker(Version $version, Project $project): ?Tracker
    {
        if (!$version->isOpen() || !$this->auth->allowedTo('add_issues', $project)) {
            return null;
        }
        $issue = new Issue();
        $issue->project = $project;
        $issue->author = $this->auth->user();
        foreach ($this->projects->allowedTargetTrackers($project) as $tracker) {
            $issue->tracker = $tracker;
            if ($tracker->defaultStatus) {
                $issue->status = $tracker->defaultStatus;
            }
            try {
                if ($this->issues->isSafeAttribute($issue, 'fixed_version_id')) {
                    return $tracker;
                }
            } catch (\Throwable) {
                return $tracker;
            }
        }

        return null;
    }

    public function dueDateDistance(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return '';
        }
        $today = $this->i18n->today();
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'));
        $days = abs((int) $today->diff($d)->format('%r%a'));
        $p = 'datetime.distance_in_words.';
        $words = match (true) {
            $days <= 60 => $this->i18n->l($p.'x_days', ['count' => $days]),
            $days <= 720 => $this->i18n->l($p.'about_x_months', ['count' => (int) round($days / 30)]),
            default => $this->i18n->l($p.'over_x_years', ['count' => intdiv($days, 365)]),
        };

        return $this->i18n->l($d < $today ? 'label_roadmap_overdue' : 'label_roadmap_due_in', ['value' => $words]);
    }

    /** html_hours(l_hours(hours)) */
    public function hoursHtml(?float $hours): string
    {
        $hours = (float) $hours;
        $text = $this->i18n->l($hours < 2.0 ? 'label_f_hour' : 'label_f_hour_plural', ['value' => $this->i18n->formatHours($hours)]);
        $text = htmlspecialchars($text);

        return (string) preg_replace('/(\d+)([.,:])(\d+)/', '<span class="hours hours-int">$1</span><span class="hours hours-dec">$2$3</span>', $text);
    }

    /**
     * ApplicationHelper#principals_options_for_select (users then groups).
     *
     * @param list<Principal> $collection
     */
    public function principalsOptions(array $collection, ?Principal $selected = null): string
    {
        $me = $this->auth->user();
        $s = '';
        $ids = array_map(static fn (Principal $p) => $p->id, $collection);
        if ($me->isLogged() && \in_array($me->id, $ids, true)) {
            $s .= '<option value="'.$me->id.'">'.htmlspecialchars('<< '.$this->i18n->l('label_me').' >>').'</option>';
        }
        $users = array_values(array_filter($collection, static fn ($p) => $p instanceof User));
        $groups = array_values(array_filter($collection, static fn ($p) => $p instanceof Group));
        $tags = static function (array $list) use ($selected): string {
            $o = '';
            foreach ($list as $p) {
                $o .= '<option value="'.$p->id.'"'.($selected?->id === $p->id ? ' selected="selected"' : '').'>'.htmlspecialchars($p->getName()).'</option>';
            }

            return $o;
        };
        if ([] === $groups) {
            return $s.$tags($users);
        }
        if ([] !== $users) {
            $s .= '<optgroup label="'.htmlspecialchars($this->i18n->l('label_user_plural')).'">'.$tags($users).'</optgroup>';
        }
        $s .= '<optgroup label="'.htmlspecialchars($this->i18n->l('label_group_plural')).'">'.$tags($groups).'</optgroup>';

        return $s;
    }
}
