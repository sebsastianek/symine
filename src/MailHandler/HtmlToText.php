<?php

declare(strict_types=1);

namespace App\MailHandler;

/**
 * Port of Redmine::WikiFormatting::HtmlParser.to_text (+ the CommonMark/Textile tag maps): converts the
 * HTML part of an incoming e-mail to text in the configured wiki syntax.
 */
final class HtmlToText
{
    private const array BLOCKS = ['address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'tfoot', 'ul', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /** @return array<string, array{0: string, 1: string}|string|null> */
    private static function tags(string $formatting): array
    {
        $base = ['br' => ['', "\n"], 'style' => '', 'script' => '', 'head' => '', 'title' => ''];

        return $base + match ($formatting) {
            'common_mark', 'markdown' => [
                'b' => ['**', '**'], 'strong' => ['**', '**'], 'i' => ['*', '*'], 'em' => ['*', '*'], 'u' => ['_', '_'], 'strike' => ['~~', '~~'],
                'h1' => ["\n\n# ", "\n\n"], 'h2' => ["\n\n## ", "\n\n"], 'h3' => ["\n\n### ", "\n\n"], 'h4' => ["\n\n#### ", "\n\n"],
                'h5' => ["\n\n##### ", "\n\n"], 'h6' => ["\n\n###### ", "\n\n"], 'th' => ['*', "*\n"], 'td' => ['', "\n"], 'a' => null,
            ],
            'textile' => [
                'b' => ['*', '*'], 'strong' => ['*', '*'], 'i' => ['_', '_'], 'em' => ['_', '_'], 'u' => ['+', '+'], 'strike' => ['-', '-'],
                'h1' => ["\n\nh1. ", "\n\n"], 'h2' => ["\n\nh2. ", "\n\n"], 'h3' => ["\n\nh3. ", "\n\n"], 'h4' => ["\n\nh4. ", "\n\n"],
                'h5' => ["\n\nh5. ", "\n\n"], 'h6' => ["\n\nh6. ", "\n\n"], 'th' => ['*', "*\n"], 'td' => ['', "\n"], 'a' => null,
            ],
            default => [],
        };
    }

    public static function convert(string $html, string $formatting = ''): string
    {
        // a leading UTF-8 byte order mark is not content (dropped by Redmine's HTML parser)
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }
        $html = str_replace(["\r", "\n"], ' ', $html);
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><html><body>'.$html.'</body></html>', \LIBXML_NONET | \LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $doc->getElementsByTagName('body')->item(0);
        if (null === $body) {
            return trim(strip_tags($html));
        }
        $text = self::node($body, self::tags($formatting), $formatting);
        // Loofah.remove_extraneous_whitespace
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text;
        $text = trim($text);
        $text = preg_replace('/ {2,}/', ' ', $text) ?? $text;

        return preg_replace('/^ +/m', '', $text) ?? $text;
    }

    /** @param array<string, mixed> $tags */
    private static function node(\DOMNode $node, array $tags, string $formatting): string
    {
        if ($node instanceof \DOMText) {
            return $node->nodeValue ?? '';
        }
        if (!$node instanceof \DOMElement && !$node->hasChildNodes()) {
            return '';
        }
        $content = '';
        foreach ($node->childNodes as $child) {
            $content .= self::node($child, $tags, $formatting);
        }
        if (!$node instanceof \DOMElement) {
            return $content;
        }
        $name = strtolower($node->nodeName);
        if (\array_key_exists($name, $tags)) {
            $f = $tags[$name];
            if (\is_string($f)) {
                $content = $f;
            } elseif (\is_array($f)) {
                $content = $f[0].$content.$f[1];
            } elseif ('a' === $name) {
                $href = $node->getAttribute('href');
                if ('' !== trim($content) && '' !== $href) {
                    $content = 'textile' === $formatting ? ' "'.$content.'":'.$href.' ' : ' ['.$content.']('.$href.') ';
                } elseif ('' !== $href) {
                    $content = ' '.$href.' ';
                }
            }
        }
        if (\in_array($name, self::BLOCKS, true)) {
            $content = "\n".$content."\n";
        }

        return $content;
    }
}
