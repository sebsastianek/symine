<?php

declare(strict_types=1);

namespace App\Import;

/** The JavaScript answers of the import wizard (imports/mapping.js.erb, imports/run.js.erb). */
final class ImportJs
{
    /** updates the mapping form on project or tracker change */
    public static function fieldsMapping(string $html): string
    {
        return '$(\'#fields-mapping\').html('.json_encode($html, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP).');';
    }

    /**
     * Moves the progress bar, then goes to the results when the import is finished or asks for the next items.
     *
     * @param string $nextUrl the page of the results when finished, the run request to repeat otherwise
     */
    public static function progress(int $current, int $total, bool $finished, string $nextUrl): string
    {
        $js = \sprintf("\$('#import-progress').progressbar({value: %d});\n\$('#progress-label').text(%s);\n", $current, json_encode($current.' / '.$total));
        if ($finished) {
            return $js.'window.location.href='.json_encode($nextUrl).";\n";
        }

        return $js.'$.ajax({url: '.json_encode($nextUrl).", type: 'post', headers: {'X-CSRF-Token': $('meta[name=\"csrf-token\"]').attr('content')}});\n";
    }
}
