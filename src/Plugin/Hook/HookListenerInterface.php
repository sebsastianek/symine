<?php

declare(strict_types=1);

namespace App\Plugin\Hook;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A hook listener (Redmine::Hook::Listener): a service whose public methods are named after the hooks
 * it listens to, e.g.
 *
 *     public function view_issues_show_details_bottom(array $context): string
 *     public function controller_issues_edit_before_save(array $context): void
 *
 * Every implementation is registered automatically (autoconfiguration). View hook methods return HTML
 * (strings are joined with a space and output raw, like Redmine's call_hook in views).
 */
#[AutoconfigureTag(self::TAG)]
interface HookListenerInterface
{
    public const string TAG = 'symine.hook_listener';
}
