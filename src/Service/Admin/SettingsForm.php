<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\CustomField;
use App\Entity\IssueStatus;
use App\Entity\Role;
use App\Entity\TimeEntryActivity;
use App\Entity\Tracker;
use App\Entity\User;
use App\I18n\Localizer;
use App\I18n\RailsTimeZones;
use App\Security\AccessControl;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Administration settings form (port of SettingsController#edit, SettingsHelper and Setting.set_all_from_params).
 */
final class SettingsForm
{
    /** SettingsHelper#administration_settings_tabs: name => [partial, label] */
    public const array TABS = [
        'general' => ['general', 'label_general'],
        'display' => ['display', 'label_display'],
        'authentication' => ['authentication', 'label_authentication'],
        'integrations' => ['api', 'label_integrations'],
        'projects' => ['projects', 'label_project_plural'],
        'users' => ['users', 'label_user_plural'],
        'issues' => ['issues', 'label_issue_tracking'],
        'timelog' => ['timelog', 'label_time_tracking'],
        'attachments' => ['attachments', 'label_attachment_plural'],
        'notifications' => ['notifications', 'field_mail_notification'],
        'mail_handler' => ['mail_handler', 'label_incoming_emails'],
        'repositories' => ['repositories', 'label_repository_plural'],
    ];

    public const array DATE_FORMATS = ['%Y-%m-%d', '%d/%m/%Y', '%d.%m.%Y', '%d-%m-%Y', '%m/%d/%Y', '%d %b %Y', '%d %B %Y', '%b %d, %Y', '%B %d, %Y'];
    public const array TIME_FORMATS = ['%H:%M', '%I:%M %p'];
    public const array PASSWORD_CHAR_CLASSES = ['uppercase', 'lowercase', 'digits', 'special_chars'];
    public const array SCMS = ['Subversion', 'Mercurial', 'Cvs', 'Bazaar', 'Git', 'Filesystem'];
    /** Redmine::Notifiable.all: name => parent */
    public const array NOTIFIABLES = [
        'issue_added' => null, 'issue_updated' => null, 'issue_note_added' => 'issue_updated', 'issue_status_updated' => 'issue_updated',
        'issue_assigned_to_updated' => 'issue_updated', 'issue_priority_updated' => 'issue_updated', 'issue_fixed_version_updated' => 'issue_updated',
        'issue_attachment_added' => 'issue_updated', 'news_added' => null, 'news_comment_added' => null, 'document_added' => null,
        'file_added' => null, 'message_posted' => null, 'wiki_content_added' => null, 'wiki_content_updated' => null,
    ];
    public const array MAIL_NOTIFICATION_OPTIONS = ['all' => 'label_user_mail_option_all', 'only_my_events' => 'label_user_mail_option_only_my_events',
        'only_assigned' => 'label_user_mail_option_only_assigned', 'only_owner' => 'label_user_mail_option_only_owner',
        'only_my_watches' => 'label_user_mail_option_only_my_watches', 'none' => 'label_user_mail_option_none'];
    public const array AUTO_WATCH_ON_OPTIONS = ['issue_created', 'issue_contributed_to', 'issue_assigned_to_me'];

    /** Inline columns of IssueQuery: name => caption key */
    public const array ISSUE_COLUMNS = ['id' => '#', 'project' => 'field_project', 'tracker' => 'field_tracker', 'parent' => 'field_parent_issue',
        'parent.subject' => 'field_parent_issue_subject', 'status' => 'field_status', 'priority' => 'field_priority', 'subject' => 'field_subject',
        'author' => 'field_author', 'assigned_to' => 'field_assigned_to', 'watcher_users' => 'label_issue_watchers', 'updated_on' => 'field_updated_on',
        'category' => 'field_category', 'fixed_version' => 'field_fixed_version', 'start_date' => 'field_start_date', 'due_date' => 'field_due_date',
        'estimated_hours' => 'field_estimated_hours', 'estimated_remaining_hours' => 'field_estimated_remaining_hours',
        'total_estimated_hours' => 'field_total_estimated_hours', 'spent_hours' => 'label_spent_time', 'total_spent_hours' => 'label_total_spent_time',
        'done_ratio' => 'field_done_ratio', 'created_on' => 'field_created_on', 'closed_on' => 'field_closed_on', 'last_updated_by' => 'field_last_updated_by',
        'relations' => 'label_related_issues', 'attachments' => 'label_attachment_plural'];
    public const array ISSUE_TOTALS = ['estimated_hours' => 'field_estimated_hours', 'estimated_remaining_hours' => 'field_estimated_remaining_hours', 'spent_hours' => 'label_spent_time'];
    public const array PROJECT_COLUMNS = ['name' => 'field_name', 'status' => 'field_status', 'short_description' => 'field_description',
        'homepage' => 'field_homepage', 'identifier' => 'field_identifier', 'parent_id' => 'field_parent', 'is_public' => 'field_is_public',
        'created_on' => 'field_created_on', 'updated_on' => 'field_updated_on', 'last_activity_date' => 'field_last_activity_date'];
    public const array TIME_ENTRY_COLUMNS = ['project' => 'field_project', 'spent_on' => 'field_spent_on', 'created_on' => 'field_created_on',
        'updated_on' => 'field_updated_on', 'tweek' => 'label_week', 'author' => 'field_author', 'user' => 'field_user', 'activity' => 'field_activity',
        'issue' => 'field_issue', 'issue.tracker' => 'field_tracker', 'issue.parent' => 'field_parent_issue', 'issue.status' => 'field_status',
        'issue.category' => 'field_category', 'issue.fixed_version' => 'field_fixed_version', 'comments' => 'field_comments', 'hours' => 'field_hours'];

    public function __construct(
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
        private readonly string $projectDir,
        private readonly ?\App\Security\Twofa\TwofaManager $twofa = null,
        private readonly ?SecurityNotifier $security = null,
        private readonly ?\App\Theme\ThemeRegistry $themes = null,
    ) {
    }

    /**
     * The settings[...] of a submitted form: unticking "default due date" empties its offset.
     *
     * @return array<string, mixed>
     */
    public function posted(Request $request): array
    {
        $posted = $request->request->all('settings');
        if (($posted['default_issue_due_date_offset_enabled'] ?? null) === '0') {
            $posted['default_issue_due_date_offset'] = '';
        }

        return $posted;
    }

    /**
     * Saves the submitted settings when all of them are valid.
     *
     * @param array<string, mixed> $posted
     *
     * @return array<string, string> setting name => message; empty when the settings were saved
     */
    public function submit(array $posted): array
    {
        $errors = $this->validate($posted);
        if ([] === $errors) {
            $this->save($posted);
        }

        return $errors;
    }

    /**
     * Variables of the settings page, with what was submitted when it could not be saved.
     *
     * @param array<string, mixed>  $posted
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, User $current, array $posted = [], array $errors = []): array
    {
        $tabs = [];
        foreach (self::TABS as $name => [$template, $label]) {
            $tabs[] = ['name' => $name, 'label' => $label, 'template' => 'admin/settings/_'.$template.'.html.twig'];
        }

        return [
            'tabs' => $tabs,
            'values' => $this->values($posted),
            'posted' => $posted,
            'setting_errors' => $errors,
            'opts' => $this->options($current),
            'guessed_host_and_path' => $request->getHttpHost().$request->getBasePath(),
            'admin_menu_selected' => 'settings',
            'page_title' => $this->i18n->l('label_settings'),
            'body_css' => 'controller-settings action-edit',
        ];
    }

    /**
     * Current values (posted values take precedence, like SettingsHelper#setting_value).
     *
     * @return array<string, mixed>
     */
    public function values(array $posted = []): array
    {
        $out = [];
        foreach (array_keys($this->settings->definitions()) as $name) {
            $out[$name] = \array_key_exists($name, $posted) ? $posted[$name] : $this->settings->get($name);
        }

        return $out;
    }

    /**
     * Setting.validate_all_from_params.
     *
     * @return array<string, string> setting name => message
     */
    public function validate(array $settings): array
    {
        $errors = [];
        foreach ([['mail_handler_enable_regex_delimiters', 'mail_handler_body_delimiters', '/[\r\n]+/'],
            ['mail_handler_enable_regex_excluded_filenames', 'mail_handler_excluded_filenames', '/\s*,\s*/']] as [$enable, $field, $delimiter]) {
            if (\array_key_exists($field, $settings) || \array_key_exists($enable, $settings)) {
                $regexp = $this->settings->bool($enable);
                if (\array_key_exists($enable, $settings)) {
                    $regexp = '0' !== (string) $settings[$enable];
                }
                if ($regexp) {
                    foreach (preg_split($delimiter, (string) ($settings[$field] ?? '')) ?: [] as $value) {
                        if ('' === $value) {
                            continue;
                        }
                        if (false === @preg_match('/'.str_replace('/', '\/', $value).'/', '')) {
                            $errors[$field] = $this->i18n->l('activerecord.errors.messages.not_a_regexp').' ('.(error_get_last()['message'] ?? '').')';
                        }
                    }
                }
            }
        }
        if (\array_key_exists('mail_from', $settings)) {
            $from = trim((string) $settings['mail_from']);
            if (preg_match('/<([^>]+)>\s*$/', $from, $m)) {
                $from = $m[1];
            }
            if (!filter_var($from, \FILTER_VALIDATE_EMAIL)) {
                $errors['mail_from'] = $this->i18n->l('activerecord.errors.messages.invalid');
            }
        }
        if (\array_key_exists('default_issue_due_date_offset', $settings)) {
            $value = trim((string) $settings['default_issue_due_date_offset']);
            if ('' !== $value) {
                if (!preg_match('/^[+-]?\d+$/', $value)) {
                    $errors['default_issue_due_date_offset'] = $this->i18n->l('activerecord.errors.messages.not_a_number');
                } elseif ((int) $value < 0) {
                    $errors['default_issue_due_date_offset'] = $this->i18n->l('activerecord.errors.messages.greater_than_or_equal_to', ['count' => 0]);
                }
            }
        }

        return $errors;
    }

    /** Setting.set_all_from_params (+ set_from_params / *_from_params) */
    public function save(array $settings): void
    {
        $values = [];
        foreach ($settings as $name => $value) {
            if (!$this->settings->has((string) $name) || 'favicon' === $name) {
                continue;
            }
            if ('commit_update_keywords' === $name) {
                $value = self::commitUpdateKeywordsFromParams(\is_array($value) ? $value : []);
            } elseif (\is_array($value)) {
                if (array_is_list($value)) {
                    $value = array_values(array_filter($value, static fn ($v) => '' !== (string) (\is_array($v) ? 'x' : $v)));
                } else {
                    foreach ($value as $k => $v) {
                        if (\is_array($v) && array_is_list($v)) {
                            $value[$k] = array_values(array_filter($v, static fn ($x) => '' !== (string) $x));
                        }
                    }
                }
            } else {
                $value = (string) $value;
            }
            $values[(string) $name] = $value;
        }
        // Setting.twofa_from_params: unpair all 2FA devices when switching 2FA off
        if ('0' === ($values['twofa'] ?? null) && null !== $this->twofa && $this->twofa->enabled()) {
            $this->twofa->unpairAll();
        }
        // settings flagged security_notifications whose value changes are reported to the administrators
        $definitions = $this->settings->definitions();
        $comparable = static fn (mixed $v): mixed => \is_array($v) ? $v : (string) (true === $v ? '1' : (false === $v ? '0' : $v));
        $previous = [];
        foreach ($values as $name => $value) {
            if ($definitions[$name]['security_notifications'] ?? false) {
                $previous[$name] = $comparable($this->settings->get($name));
            }
        }
        $this->settings->setMany($values);
        $changes = [];
        foreach ($previous as $name => $old) {
            if ($comparable($this->settings->get($name)) != $old) {
                $changes[] = $name;
            }
        }
        $this->security?->settingsUpdated($changes);
    }

    /** Setting.commit_update_keywords_from_params */
    public static function commitUpdateKeywordsFromParams(array $params): array
    {
        $s = [];
        if (isset($params['keywords']) && \is_array($params['keywords']) && [] === array_filter($params, static fn ($v) => !\is_array($v))) {
            $attributes = array_diff(array_keys($params), ['keywords']);
            foreach (array_values($params['keywords']) as $i => $keywords) {
                if ('' === trim((string) $keywords)) {
                    continue;
                }
                $h = [];
                foreach ($attributes as $a) {
                    $v = (string) (array_values($params[$a])[$i] ?? '');
                    if ('' !== $v) {
                        $h[$a] = $v;
                    }
                }
                $h['keywords'] = (string) $keywords;
                $s[] = $h;
            }
        }

        return $s;
    }

    /** All option lists used by the settings partials */
    public function options(User $current): array
    {
        $i = $this->i18n;
        $l = $i->l(...);
        $today = $i->today();
        $now = new \DateTimeImmutable();

        // Redmine::Themes.themes; the default theme is the blank option of the select
        $themes = [];
        foreach ($this->themes?->all() ?? [] as $theme) {
            if (!$theme->isDefault()) {
                $themes[] = [$theme->name, $theme->id];
            }
        }
        $languages = [];
        foreach ($i->languageOptions() as $code => $name) {
            $languages[] = [$name, $code];
        }
        $dateFormats = [];
        foreach (self::DATE_FORMATS as $f) {
            $format = strtr(str_replace('%', '', $f), ['d' => 'dd', 'm' => 'mm', 'Y' => 'yyyy']);
            $dateFormats[] = [$i->strftime($today, $f).' ('.$format.')', $f];
        }
        $timeFormats = array_map(static fn ($f) => [$i->strftime($now, $f), $f], self::TIME_FORMATS);
        $userFormats = [];
        foreach (array_keys(User::USER_FORMATS) as $f) {
            $userFormats[] = [$current->getName($f), $f];
        }
        $days = static fn (array $nums) => array_map(static fn ($d) => [$i->dayName($d), (string) $d], $nums);
        $xDays = static fn (array $nums) => array_map(static fn ($d) => [$l('datetime.distance_in_words.x_days', ['count' => $d]), (string) $d], $nums);
        $xHours = static fn (array $nums) => array_map(static fn ($h) => [$l('datetime.distance_in_words.x_hours', ['count' => $h]), (string) ($h * 60)], $nums);
        $sessionLifetime = [[$l('label_disabled'), '0'], ...$xHours([4, 8, 12]),
            ...array_map(static fn ($d) => [$l('datetime.distance_in_words.x_days', ['count' => $d]), (string) ($d * 24 * 60)], [1, 7, 30, 60, 365])];
        $sessionTimeout = [[$l('label_disabled'), '0'], ...$xHours([1, 2, 4, 8, 12, 24, 48])];
        $trackers = $this->em->getRepository(Tracker::class)->findBy([], ['position' => 'ASC']);
        $statuses = $this->em->getRepository(IssueStatus::class)->findBy([], ['position' => 'ASC']);
        $roles = $this->em->getRepository(Role::class)->findBy(['builtin' => 0], ['position' => 'ASC']);
        $activities = $this->em->createQuery('SELECT a FROM App\Entity\TimeEntryActivity a WHERE a.project IS NULL AND a.active = true ORDER BY a.position')->getResult();
        $doneRatioInterval = max(1, $this->settings->int('issue_done_ratio_interval') ?: 10);

        $cfColumns = function (string $type, bool $numericOnly = false): array {
            $out = [];
            foreach ($this->em->createQuery("SELECT cf FROM $type cf ORDER BY cf.position")->getResult() as $cf) {
                /** @var CustomField $cf */
                if ($numericOnly && !\in_array($cf->fieldFormat, ['int', 'float'], true)) {
                    continue;
                }
                $out['cf_'.$cf->id] = $cf->name;
            }

            return $out;
        };
        $caption = static fn (array $cols) => array_map(static fn ($k) => str_starts_with($k, 'field_') || str_starts_with($k, 'label_') ? $l($k) : $k, $cols);
        $issueColumns = $caption(self::ISSUE_COLUMNS) + $cfColumns('App\Entity\IssueCustomField');
        $projectColumns = $caption(self::PROJECT_COLUMNS) + $cfColumns('App\Entity\ProjectCustomField');
        $teColumns = $caption(self::TIME_ENTRY_COLUMNS) + $cfColumns('App\Entity\TimeEntryCustomField');
        $issueTotals = $caption(self::ISSUE_TOTALS) + $cfColumns('App\Entity\IssueCustomField', true);
        $teTotals = ['hours' => $l('field_hours')] + $cfColumns('App\Entity\TimeEntryCustomField', true);

        $publicQueries = function (string $type, bool $globalOnly): array {
            $dql = "SELECT q FROM $type q WHERE q.visibility = 2".($globalOnly ? ' AND q.project IS NULL' : '').' ORDER BY q.id';
            try {
                $qs = $this->em->createQuery($dql)->getResult();
            } catch (\Throwable) {
                $qs = $this->em->createQuery("SELECT q FROM $type q WHERE q.visibility = 2 ORDER BY q.id")->getResult();
            }

            return array_map(static fn ($q) => [$q->name, (string) $q->id], $qs);
        };

        return [
            'text_formattings' => [['Textile', 'textile'], ['CommonMark Markdown (GitHub Flavored)', 'common_mark']],
            'themes' => $themes,
            'languages' => $languages,
            'start_of_week' => $days([1, 6, 7]),
            'date_formats' => $dateFormats,
            'time_formats' => $timeFormats,
            'timespan_formats' => [['0.75', 'decimal'], ['0:45 h', 'minutes']],
            'user_formats' => $userFormats,
            'gravatar_defaults' => [['Initials (Redmine)', ''], ['Initials (Gravatar)', 'initials'], ['Color', 'color'], ['Mystery man', 'mm'],
                ['Identicons', 'identicon'], ['Monster ids', 'monsterid'], ['Wavatars', 'wavatar'], ['Retro', 'retro'], ['Robohash', 'robohash']],
            'new_item_menu_tab' => [[$l('label_none'), '0'], [$l('label_new_project_issue_tab_enabled'), '1'], [$l('label_new_object_tab_enabled'), '2']],
            'autologin' => [[$l('label_disabled'), '0'], ...$xDays([1, 7, 30, 365])],
            'self_registration' => [[$l('label_disabled'), '0'], [$l('label_registration_activation_by_email'), '1'],
                [$l('label_registration_manual_activation'), '2'], [$l('label_registration_automatic_activation'), '3']],
            'password_char_classes' => array_map(static fn ($c) => [$l('label_password_char_class_'.$c), $c], self::PASSWORD_CHAR_CLASSES),
            'password_max_age' => [[$l('label_disabled'), '0'], ...$xDays([7, 30, 60, 90, 180, 365])],
            'twofa' => [[$l('label_disabled'), '0'], [$l('label_optional'), '1'], [$l('label_required_administrators'), '3'], [$l('label_required_lower'), '2']],
            'session_lifetime' => $sessionLifetime,
            'session_timeout' => $sessionTimeout,
            'anonymous_role_id' => $this->em->getRepository(Role::class)->findOneBy(['builtin' => Role::BUILTIN_ANONYMOUS])?->id,
            'project_modules' => array_map(static fn ($m) => [$l('project_module_'.$m), $m], AccessControl::modules()),
            'trackers' => array_map(static fn ($t) => [$t->name, (string) $t->id], $trackers),
            'givable_roles' => array_map(static fn ($r) => [$r->name, (string) $r->id], $roles),
            'project_display_types' => ['board', 'list'],
            'project_columns' => $projectColumns,
            'project_queries' => [[$l('label_none'), ''], ...$publicQueries('App\Entity\ProjectQuery', false)],
            'mail_notification_options' => array_map(static fn ($k, $v) => [$l($v), $k], array_keys(self::MAIL_NOTIFICATION_OPTIONS), self::MAIL_NOTIFICATION_OPTIONS),
            'time_zones' => $this->timeZones(),
            'auto_watch_on' => array_map(static fn ($o) => [$l('label_auto_watch_on_'.$o), $o], self::AUTO_WATCH_ON_OPTIONS),
            'yes_no_ask' => [[$l('general_text_Yes'), 'yes'], [$l('general_text_No'), 'no'], [$l('label_ask'), 'ask']],
            'cross_project_subtasks' => [[$l('label_disabled'), ''], [$l('label_cross_project_system'), 'system'], [$l('label_cross_project_tree'), 'tree'],
                [$l('label_cross_project_hierarchy'), 'hierarchy'], [$l('label_cross_project_descendants'), 'descendants']],
            'assignee_dropdown_formats' => [[$l('label_assignee_dropdown_display_format_users_then_groups'), 'users_then_groups'],
                [$l('label_assignee_dropdown_display_format_groups_then_users'), 'groups_then_users'],
                [$l('label_assignee_dropdown_display_format_users_by_group'), 'users_by_group']],
            'parent_attributes' => [[$l('label_parent_task_attributes_derived'), 'derived'], [$l('label_parent_task_attributes_independent'), 'independent']],
            'issue_done_ratio' => [[$l('setting_issue_done_ratio_issue_field'), 'issue_field'], [$l('setting_issue_done_ratio_issue_status'), 'issue_status']],
            'done_ratio_intervals' => [['5 %', '5'], ['10 %', '10']],
            'week_days' => $days([1, 2, 3, 4, 5, 6, 7]),
            'issue_columns' => $issueColumns,
            'issue_totals_choices' => array_map(static fn ($k, $c) => [$c, (string) $k], array_keys($issueTotals), $issueTotals),
            'issue_queries' => [[$l('label_none'), ''], ...$publicQueries('App\Entity\IssueQuery', true)],
            'related_issues_columns' => array_diff_key($issueColumns, ['tracker' => 1, 'subject' => 1]),
            'timelog_required_fields' => [[$l('field_issue'), 'issue_id'], [$l('field_comments'), 'comments']],
            'time_entry_columns' => $teColumns,
            'time_entry_totals' => $teTotals,
            'notifiables' => self::NOTIFIABLES,
            'preferred_body_part' => [[$l('label_preferred_body_part_text'), 'plain'], [$l('label_preferred_body_part_html'), 'html']],
            'scms' => self::SCMS,
            'activities' => [[$l('label_default'), '0'], ...array_map(static fn (TimeEntryActivity $a) => [$a->name, (string) $a->id], $activities)],
            'tracker_options' => [[$l('label_all'), ''], ...array_map(static fn ($t) => [$t->name, (string) $t->id], $trackers)],
            'status_options' => [['', '0'], ...array_map(static fn ($s) => [$s->name, (string) $s->id], $statuses)],
            'done_ratio_options' => [['', ''], ...array_map(static fn ($r) => [$r.' %', (string) $r], range(0, 100, $doneRatioInterval))],
        ];
    }

    /** ActiveSupport::TimeZone.all as [label, name], sorted by offset then name */
    private function timeZones(): array
    {
        $zones = [];
        $year = (int) date('Y');
        foreach (RailsTimeZones::MAP as $name => $iana) {
            try {
                $tz = new \DateTimeZone($iana);
            } catch (\Throwable) {
                continue;
            }
            $offset = min($tz->getOffset(new \DateTimeImmutable("$year-01-01", $tz)), $tz->getOffset(new \DateTimeImmutable("$year-07-01", $tz)));
            $sign = $offset < 0 ? '-' : '+';
            $abs = abs($offset);
            $zones[] = [$offset, $name, \sprintf('(GMT%s%02d:%02d) %s', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60), $name)];
        }
        usort($zones, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn ($z) => [$z[2], $z[1]], $zones);
    }
}
