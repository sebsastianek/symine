<?php

declare(strict_types=1);

namespace App\Service\Issue;

/**
 * The scripts answered to the Ajax requests of the "Related issues" block of the issue page (the *.js.erb
 * views of Redmine's IssueRelationsController), built from the rendered HTML.
 */
final class IssueRelationJs
{
    public const string CONTENT_TYPE = 'text/javascript; charset=utf-8';

    /**
     * issue_relations/create.js.erb: replaces the block by the rendered issues/_relations and gets the form
     * ready for another relation (its fields are kept when the relation was refused).
     */
    public static function created(string $relations, bool $saved): string
    {
        $js = '$("#relations").html('.json_encode($relations, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).');';
        if ($saved) {
            $js .= "$('#relation_delay').val('');$('#relation_issue_to_id').val('');";
        }

        return $js."$('#new-relation-form').show();$('#relation_issue_to_id').focus();";
    }

    /** issue_relations/destroy.js.erb: removes the relation and updates the counts of the block (the rendered stats) */
    public static function destroyed(int $id, string $stats): string
    {
        return '$("#relation-'.$id.'").remove();$(".issues-stat").replaceWith('.json_encode($stats, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).');';
    }
}
