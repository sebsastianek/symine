<?php

declare(strict_types=1);

namespace App\Service\Tracking\Categories;

use App\Entity\IssueCategory;
use Symfony\Component\HttpFoundation\Request;

/**
 * The scripts answered when a category is added from the issue form, in a dialog (issue_categories/new.js.erb
 * and create.js.erb).
 */
final class IssueCategoryScript
{
    /** Whether the request comes from the "new category" link of the issue form (format.js) */
    public function isRequested(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'javascript') || 'js' === $request->query->get('format');
    }

    /** Opens the dialog. $html is issue_categories/_new_modal.html.twig */
    public function modal(string $html): string
    {
        return "$('#ajax-modal').html(".self::quote($html).");\nshowModal('ajax-modal', '600px');";
    }

    /** Closes the dialog and selects the new category in the issue form */
    public function created(IssueCategory $category): string
    {
        $options = '<option></option>';
        $categories = $category->project->issueCategories->toArray();
        usort($categories, static fn (IssueCategory $a, IssueCategory $b) => strcmp($a->name, $b->name));
        foreach ($categories as $c) {
            $options .= '<option value="'.$c->id.'"'.($c->id === $category->id ? ' selected="selected"' : '').'>'.htmlspecialchars($c->name).'</option>';
        }
        $select = '<select id="issue_category_id" name="issue[category_id]">'.$options.'</select>';

        return "hideModal();\n$('#issue_category_id').replaceWith(".self::quote($select).');';
    }

    private static function quote(string $s): string
    {
        return (string) json_encode($s, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE);
    }
}
