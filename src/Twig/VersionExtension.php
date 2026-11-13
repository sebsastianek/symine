<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Wiki;
use App\Service\VersionService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** View helpers of the versions / roadmap / issue categories pages; implemented lazily by VersionRuntime. */
final class VersionExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];
        $r = VersionRuntime::class;

        return [
            new TwigFunction('version_stats', [$r, 'stats']),
            new TwigFunction('version_completed', [$r, 'isCompleted']),
            new TwigFunction('version_css_classes', [$r, 'cssClasses']),
            new TwigFunction('version_spent_hours', [$r, 'spentHours']),
            new TwigFunction('version_allowed_sharings', [$r, 'allowedSharings']),
            new TwigFunction('version_wiki_page', [$r, 'wikiPage']),
            new TwigFunction('version_wiki', [$r, 'wiki']),
            new TwigFunction('version_status_by', [$r, 'statusBy']),
            new TwigFunction('version_status_by_criterias', static fn () => VersionService::STATUS_BY_CRITERIAS),
            new TwigFunction('version_custom_values', [$r, 'customValues']),
            new TwigFunction('version_custom_fields', [$r, 'customFields']),
            new TwigFunction('version_filtered_issues_path', [$r, 'filteredIssuesPath']),
            new TwigFunction('version_new_issue_tracker', [$r, 'newIssueTracker']),
            new TwigFunction('version_format_name', [$r, 'formatName']),
            new TwigFunction('format_version_sharing', [$r, 'formatSharing']),
            new TwigFunction('due_date_distance', [$r, 'dueDateDistance']),
            new TwigFunction('version_hours_html', [$r, 'hoursHtml'], $safe),
            new TwigFunction('wiki_titleize', static fn (?string $t) => Wiki::titleize((string) $t)),
            new TwigFunction('category_principals_options', [$r, 'principalsOptions'], $safe),
        ];
    }
}
