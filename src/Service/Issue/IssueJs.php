<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;

/**
 * The scripts answered to the Ajax requests of the issue forms (the *.js.erb views of Redmine's
 * IssuesController), built from the rendered partials.
 */
final class IssueJs
{
    public const string CONTENT_TYPE = 'text/javascript; charset=utf-8';

    /**
     * issues/new.js.erb: replaces the form after a field was changed (updateIssueFrom), and what depends on
     * the changed field outside the form.
     *
     * @param string            $trigger      id of the changed field (form_update_triggered_by)
     * @param string            $form         the rendered issues/_form
     * @param \Closure(): string $watchersForm renders issues/_watchers_form (only needed when the project changed)
     */
    public static function newForm(string $trigger, Issue $issue, string $form, \Closure $watchersForm): string
    {
        $js = self::replaceForm($form);
        if ('issue_project_id' === $trigger) {
            $js .= '$("#watchers_form_container").html('.json_encode($watchersForm(), \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).');';
        } elseif ('issue_category_id' === $trigger) {
            $name = $issue->category?->assignedTo?->getName();
            $js .= "$('#issue_assigned_to_id').find('option').first().removeAttr('label').html(".json_encode(null !== $name ? htmlspecialchars($name) : '&nbsp;').');';
        }

        return $js;
    }

    /**
     * issues/edit.js.erb: replaces the form after a field was changed and shows the "Log time" and "Notes"
     * fieldsets according to what the new values allow.
     */
    public static function editForm(string $form, bool $timeLoggable, bool $notesAddable): string
    {
        return self::replaceForm($form)
            .($timeLoggable ? "$('#log_time').show();" : "$('#log_time').hide();")
            .($notesAddable ? "$('#add_notes').show();$('#add_attachments').show();" : "$('#add_notes').hide();$('#add_attachments').hide();");
    }

    /** issues/bulk_edit.js.erb: updateBulkEditFrom() replaces the form after a project/tracker/status change */
    public static function bulkEditForm(string $content): string
    {
        return "$('#content').html(".json_encode($content, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG).');';
    }

    private static function replaceForm(string $form): string
    {
        return 'replaceIssueFormWith('.json_encode($form, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).');';
    }
}
