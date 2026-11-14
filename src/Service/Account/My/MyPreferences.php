<?php

declare(strict_types=1);

namespace App\Service\Account\My;

use App\Entity\User;
use App\I18n\RailsTimeZones;
use App\Service\AccountService;
use App\Service\SyntaxHighlighting;

/** The preferences a user sets on My account (params[:pref], UserPreference safe attributes). */
final class MyPreferences
{
    public function __construct(private readonly SyntaxHighlighting $highlighting)
    {
    }

    /** @param array<string, mixed> $p the submitted preferences; what is not submitted is left as it is */
    public function assign(User $user, array $p): void
    {
        $pref = $user->getPref();
        if (\array_key_exists('hide_mail', $p)) {
            $pref->hideMail = \in_array($p['hide_mail'], ['1', 1, true, 'true'], true);
        }
        if (\array_key_exists('time_zone', $p)) {
            $tz = (string) $p['time_zone'];
            $pref->timeZone = '' === $tz || null !== RailsTimeZones::toIana($tz) ? $tz : $pref->timeZone;
        }
        foreach (['comments_sorting', 'warn_on_leaving_unsaved', 'no_self_notified', 'notify_about_high_priority_issues', 'textarea_font', 'history_default_tab', 'default_issue_query', 'default_project_query'] as $k) {
            if (\array_key_exists($k, $p)) {
                $pref->set($k, \is_scalar($p[$k]) ? (string) $p[$k] : '');
            }
        }
        if (\array_key_exists('recently_used_projects', $p)) {
            $pref->set('recently_used_projects', (int) $p['recently_used_projects']);
        }
        if (\array_key_exists('toolbar_language_options', $p)) {
            // UserPreference#toolbar_language_options=: only the languages the syntax highlighter supports
            $langs = array_filter(explode(',', str_replace(' ', '', (string) $p['toolbar_language_options'])), fn ($l) => '' !== $l && $this->highlighting->languageSupported($l));
            $pref->set('toolbar_language_options', implode(',', $langs));
        }
        if (\array_key_exists('auto_watch_on', $p)) {
            $pref->set('auto_watch_on', array_values(array_filter(array_map('strval', (array) $p['auto_watch_on']), static fn ($v) => \in_array($v, AccountService::AUTO_WATCH_ON_OPTIONS, true))));
        }
    }
}
