<?php

declare(strict_types=1);

namespace App\Service\Issue;

/**
 * The scripts answered to the Ajax requests about issue journals (the *.js.erb views of Redmine's
 * JournalsController), built from the rendered partials.
 */
final class JournalJs
{
    public const string CONTENT_TYPE = 'text/javascript; charset=utf-8';

    /** journals/edit.js.erb: shows the notes form (the rendered journals/_notes_form) in place of the notes */
    public static function edit(int $jid, string $form): string
    {
        $js = <<<JS
            $("#journal-{$jid}-notes").hide();
            if ($("form#journal-{$jid}-form").length > 0) {
              $("#journal-{$jid}-form").show();
            } else {
              $("#journal-{$jid}-notes").after(%s);
            }
            (function() {
              var textarea = $("#journal-{$jid}-form .wiki-edit");
              if (textarea.length > 0) {
                textarea.focus();
                var l = textarea.val().length;
                textarea.get(0).setSelectionRange(l, l);
              }
            })();
            JS;

        return \sprintf($js, self::literal($form));
    }

    /** journals/update.js.erb when nothing is left of the journal */
    public static function removed(int $jid): string
    {
        return "$(\"#change-{$jid}\").remove();";
    }

    /**
     * journals/update.js.erb: replaces the parts of the journal by the rendered journals/_actions,
     * _private_notes_indicator, _notes and _update_info partials.
     */
    public static function updated(int $jid, string $cssClasses, string $actions, string $privateNotesIndicator, string $notes, string $updateInfo): string
    {
        return '$("#change-'.$jid.'").attr("class", '.json_encode($cssClasses).');'
            .'$("#change-'.$jid.' .journal-actions").html('.self::literal($actions).');'
            .'$("#journal-'.$jid.'-private_notes").replaceWith('.self::literal($privateNotesIndicator).');'
            .'$("#journal-'.$jid.'-notes").replaceWith('.self::literal($notes).');'
            .'$("#journal-'.$jid.'-notes").show();'
            .'$("#journal-'.$jid.'-form").remove();'
            .'var journal_header = $("#change-'.$jid.'>div.note>h4.journal-header>.journal-info");'
            .'var journal_updated_info = journal_header.find("span.update-info");'
            .'if (journal_updated_info.length > 0) { journal_updated_info.replaceWith('.self::literal($updateInfo).'); } else { journal_header.append('.self::literal($updateInfo).'); }'
            .'if (typeof setupHoverTooltips === "function") { setupHoverTooltips(); }';
    }

    /** journals/new.js.erb: appends the quote to the notes of the issue form */
    public static function quote(string $content, bool $private): string
    {
        $js = "$('#update').show();\nshowAndScrollTo(\"add_notes\");\n"
            ."var notes = $('#issue_notes').val();\nif (notes > \"\") { notes = notes + \"\\n\\n\"}\n"
            ."$('#issue_notes').blur().focus().val(notes + ".self::literal($content).");\n";
        if ($private) {
            $js .= "$('#issue_private_notes').prop('checked', true);\n";
        }

        return $js;
    }

    private static function literal(string $s): string
    {
        return (string) json_encode($s, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE);
    }
}
