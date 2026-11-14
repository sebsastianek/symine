<?php

declare(strict_types=1);

namespace App\Service\Tracking\Watchers;

use App\Entity\Issue;
use App\Entity\Principal;
use App\Entity\User;
use App\Service\Tracking\Http\JavascriptResponse;
use App\Twig\IssuesExtension;

/**
 * The scripts answered to the remote watchers links and forms: the watchers/*.js.erb views of Redmine. The
 * HTML fragments they insert are rendered by the controller.
 */
final class WatcherScript
{
    public function __construct(private readonly IssuesExtension $helpers)
    {
    }

    /**
     * _set_watcher.js.erb: replaces the watch links of the objects and the list of watchers of the first one.
     *
     * @param list<object> $watchables
     * @param string       $watchersHtml watchers/_watchers.html.twig for the first object
     */
    public function setWatcher(array $watchables, User $user, string $watchersHtml): string
    {
        $selector = '.'.$this->helpers->watcherCss($watchables);
        $js = '$('.json_encode($selector).').each(function(){$(this).replaceWith('.JavascriptResponse::quote($this->helpers->watcherLink($watchables, $user)).')});';
        $first = $watchables[0];
        $partial = JavascriptResponse::quote($watchersHtml);
        if ($first instanceof Issue) {
            $js .= '$(\'#watchers[data-watchable-id="'.$first->id.'"]\').html('.$partial.');';
        } else {
            $js .= "$('#watchers').html(".$partial.');';
        }

        return $js;
    }

    /** new.js.erb: opens the "add watchers" dialog. $formHtml is watchers/_new.html.twig */
    public function openDialog(string $formHtml): string
    {
        return $this->refreshDialog($formHtml).'showModal(\'ajax-modal\', \'400px\');$(\'#ajax-modal\').addClass(\'new-watcher\');';
    }

    /** create.js.erb: the dialog stays open, without the users that were just added */
    public function refreshDialog(string $formHtml): string
    {
        return '$(\'#ajax-modal\').html('.JavascriptResponse::quote($formHtml).');';
    }

    /**
     * append.js.erb: adds the checked boxes of the chosen users to the new issue form.
     *
     * @param list<Principal> $users
     */
    public function append(array $users): string
    {
        $js = '';
        $boxes = '';
        foreach ($users as $u) {
            $js .= '$("#issue_watcher_user_ids_'.$u->id.'").remove();';
            $boxes .= '<label id="issue_watcher_user_ids_'.$u->id.'" class="floating"><input type="checkbox" name="issue[watcher_user_ids][]" value="'.$u->id.'" checked="checked"> '.htmlspecialchars($u->getName()).'</label>';
        }

        return $js.'$(\'#watchers_inputs\').append('.JavascriptResponse::quote($boxes).');';
    }
}
