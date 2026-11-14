<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\Version;
use App\Service\ProjectService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The JavaScript answers of the versions screens (the .js.erb views of Redmine's VersionsController): the
 * "new version" modal opened from the issue form and the "issues by" box of the version page.
 */
final class VersionJs
{
    public function __construct(private readonly ProjectService $projects)
    {
    }

    /** Whether the request asks for JavaScript (format.js) */
    public static function wants(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'javascript') || 'js' === $request->query->get('format');
    }

    /** versions/new.js.erb: shows the form (the rendered versions/_new_modal.html.twig) in the modal */
    public function modal(string $html): string
    {
        return "$('#ajax-modal').html(".self::string($html).");\nshowModal('ajax-modal', '600px');";
    }

    /**
     * versions/create.js.erb: closes the modal and replaces the target version field of the issue form by the
     * open versions the issues of the project can be assigned to, with the new version selected.
     */
    public function created(Project $project, Version $version): string
    {
        $options = '<option></option>';
        foreach ($this->projects->sharedVersions($project) as $v) {
            if ($v->isOpen()) {
                $options .= '<option value="'.$v->id.'"'.($v->id === $version->id ? ' selected="selected"' : '').'>'.htmlspecialchars($v->displayName($project)).'</option>';
            }
        }
        $select = '<select id="issue_fixed_version_id" name="issue[fixed_version_id]">'.$options.'</select>';

        return "hideModal();\n$('#issue_fixed_version_id').replaceWith(".self::string($select).');';
    }

    /** versions/status_by.js.erb: replaces the "issues by" box by the rendered versions/_issue_counts.html.twig */
    public function statusBy(string $html): string
    {
        return "$('#status_by').html(".json_encode($html, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP).');';
    }

    private static function string(string $s): string
    {
        return (string) json_encode($s, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE);
    }
}
