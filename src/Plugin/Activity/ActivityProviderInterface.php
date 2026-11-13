<?php

declare(strict_types=1);

namespace App\Plugin\Activity;

use App\Service\Activity\ActivityEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An activity provider (Redmine `activity_provider :type, :class_name => ..., :default => ...` +
 * acts_as_activity_provider#find_events). Implementations are registered automatically.
 *
 * The event type appears in the activity sidebar with the label "label_<singular type>_plural"
 * (e.g. hello_greetings => label_hello_greeting_plural) and in the "show_<type>" request parameter.
 */
#[AutoconfigureTag(self::TAG)]
interface ActivityProviderInterface
{
    public const string TAG = 'symine.activity_provider';

    /** Event type, plural (e.g. "hello_greetings") */
    public function getEventType(): string;

    /** Included in the default activity scope (Redmine :default option) */
    public function isDefault(): bool;

    /** Permission needed to see the type in a project's activity (null = always listed) */
    public function getPermission(): ?string;

    /**
     * Events matching the scope (user visibility, project, dates, author, limit), any order.
     *
     * @return list<ActivityEvent>
     */
    public function findEvents(ActivityScope $scope): array;
}
