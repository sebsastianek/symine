<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Query\IssueQuery;
use App\Service\Settings;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The content of the Atom feed of an issue list (issues#index.atom): the most recently created issues the
 * query finds.
 */
final class IssueFeed
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function title(?Project $project): string
    {
        return ($project?->name ?? $this->settings->appTitle()).': '.$this->i18n->l('label_issue_plural');
    }

    /** @return list<array<string, mixed>> the entries, as App\Service\AtomFeed renders them */
    public function items(IssueQuery $query): array
    {
        $limit = $this->settings->int('feeds_limit') ?: 15;
        $issues = $query->issues(null, $limit);
        usort($issues, static fn (Issue $a, Issue $b) => $b->createdOn <=> $a->createdOn);

        return array_map(fn (Issue $i) => [
            'title' => $i->tracker->name.' #'.$i->id.' ('.$i->status->name.'): '.$i->subject,
            'url' => $this->urls->generate('issue_show', ['id' => $i->id]),
            'updated' => $i->createdOn ?? new \DateTimeImmutable(),
            'author' => $i->author,
            'content' => $i->description,
            'project' => $i->project,
            'object' => $i,
        ], \array_slice($issues, 0, $limit));
    }
}
