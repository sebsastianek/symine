<?php

declare(strict_types=1);

namespace App\Service\Timelog;

/** The scripts answered to the Ajax requests of the time log forms (the *.js.erb views of timelog). */
final class TimelogJs
{
    public const string CONTENT_TYPE = 'text/javascript; charset=utf-8';

    /** bulk_edit.js.erb: the page content is replaced by the form */
    public static function replaceContent(string $html): string
    {
        return "\$('#content').html('".self::escape($html)."');\n";
    }

    /** escape_javascript */
    public static function escape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '</' => '<\/', "\r\n" => '\n', "\n" => '\n', "\r" => '\n', '"' => '\"', "'" => "\\'"]);
    }
}
