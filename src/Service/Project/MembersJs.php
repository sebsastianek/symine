<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Member;

/** The JavaScript answers of the members screens (members/*.js.erb), built around the HTML rendered by the controller. */
final class MembersJs
{
    /** members/new.js.erb */
    public static function newModal(string $html): string
    {
        return "$('#ajax-modal').html(".json_encode($html, \JSON_HEX_TAG).");\nshowModal('ajax-modal', '90%');";
    }

    /** members/autocomplete.js.erb */
    public static function principals(string $html): string
    {
        return "$('#principals_for_new_member').html(".json_encode($html, \JSON_HEX_TAG).');';
    }

    /** members/edit.js.erb */
    public static function editForm(Member $member, string $html): string
    {
        return '$("#member-'.$member->id.'-roles").hide();'."\n".'$("#member-'.$member->id.'-form").html('.json_encode($html, \JSON_HEX_TAG).');';
    }

    /** Re-renders the members tab (create/update/destroy .js responses) */
    public static function tab(string $html, string $extra = ''): string
    {
        return "$('#tab-content-members').html(".json_encode($html, \JSON_HEX_TAG).");\nhideOnLoad();\n".$extra;
    }

    /**
     * members/create.js.erb, after the tab: the errors in an alert, or the modal closed and the new members highlighted
     *
     * @param list<Member> $members
     * @param list<string> $errors
     */
    public static function created(array $members, array $errors, string $failureMessage): string
    {
        if ([] !== $errors) {
            return 'alert('.json_encode($failureMessage, \JSON_HEX_TAG).');';
        }
        $js = '';
        if ([] !== $members) {
            $js = "hideModal();\n";
            foreach ($members as $m) {
                $js .= '$("#member-'.$m->id.'").effect("highlight");'."\n";
            }
        }

        return $js;
    }

    /** members/update.js.erb, after the tab */
    public static function highlight(int $memberId): string
    {
        return '$("#member-'.$memberId.'").effect("highlight");';
    }
}
