<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use App\Service\Settings;

/** format.atom of activities#index: the latest events, whatever the period. */
final class ActivityFeed
{
    public function __construct(
        private readonly ActivityFetcher $fetcher,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    public function title(ActivityCriteria $criteria, ?Project $project): string
    {
        $title = $this->i18n->l('label_activity');
        if (null !== $criteria->author) {
            $title = $criteria->author->getName();
        } elseif (1 === \count($criteria->scope)) {
            $title = $this->i18n->l('label_'.ActivityView::singularize($criteria->scope[0]).'_plural');
        }

        return ($project?->name ?? $this->settings->appTitle()).': '.$title;
    }

    /** @return list<array<string, mixed>> items of App\Service\AtomFeed */
    public function items(ActivityCriteria $criteria, ?Project $project): array
    {
        $events = $this->fetcher->events($this->currentUser->get(), $criteria->scope, $project, $criteria->withSubprojects, $criteria->author, null, null, max(1, $this->settings->int('feeds_limit') ?: 15));

        return array_map(static fn (ActivityEvent $e) => [
            'title' => $e->title, 'url' => $e->url, 'updated' => $e->datetime, 'author' => $e->author instanceof User ? $e->author : null,
            'content' => $e->description, 'project' => $e->project, 'event' => $e,
        ], $events);
    }
}
