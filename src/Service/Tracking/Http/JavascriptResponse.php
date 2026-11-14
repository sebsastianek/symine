<?php

declare(strict_types=1);

namespace App\Service\Tracking\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * The answer to a "remote" link or form (Rails' format.js): a script that jquery-ujs evaluates in the page.
 */
final class JavascriptResponse extends Response
{
    public function __construct(string $script = '', int $status = 200)
    {
        parent::__construct($script, $status, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    /** JSON string literal that is safe inside a script (escape_javascript) */
    public static function quote(string $s): string
    {
        return (string) json_encode($s, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE);
    }
}
