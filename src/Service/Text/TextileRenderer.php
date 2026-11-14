<?php

declare(strict_types=1);

namespace App\Service\Text;

use Netcarver\Textile\Parser;

/**
 * Textile to (unsanitized) HTML with the rules of Redmine's RedCloth3 fork that stock Textile does not have
 * (Redmine::WikiFormatting::Textile::Formatter / redcloth3.rb):
 *
 *  - raw HTML is escaped, only <pre>, <code>, <kbd> and <notextile> are honoured (rip_offtags, escape_html_tags)
 *  - lines starting with ">" are block quotes (block_textile_quotes)
 *  - a line break is a <br /> except before a list item or a table row (hard_break)
 *  - text indented after an empty line is a code block, or a nested list under a list item (blocks, flush_left)
 *  - a block that starts with <pre>, <code>, <kbd> or <notextile>, or that contains a list, is not a paragraph
 *  - no typographic glyphs (curly quotes, dashes, (c), 2x3 ...) and no "caps" spans, but ABC(title) abbreviations
 *    from 2 capitals on
 *  - a list marker needs a following space ("#13 text" is not a list), a footnote reference a preceding word
 *
 * The result still has to go through {@see HtmlPipeline} (sanitizer, auto links, syntax highlighting).
 */
final class TextileRenderer
{
    private const string OFFTAGS = 'code|pre|kbd|notextile';

    private const array DISABLED_SYMBOLS = [
        'quote_single_open', 'quote_single_close', 'quote_double_open', 'quote_double_close', 'apostrophe', 'prime',
        'prime_double', 'ellipsis', 'emdash', 'endash', 'dimension', 'trademark', 'registered', 'copyright', 'half',
        'quarter', 'threequarters', 'degrees', 'plusminus', 'caps',
    ];

    /** A block that starts with one of these is handled by the Textile parser as written */
    private const string SIGNATURE_RE = '/\A(?:(?:h[1-6]|p|bq|bc|pre|notextile|fn\d+|table|###)[^\s.]*\.\.?:?\S*(?: |$)|\|)/';

    public function render(string $text): string
    {
        // clean_white_space
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
        $text = preg_replace('/^ +$/m', '', $text) ?? $text;
        $token = 'xoff'.bin2hex(random_bytes(6)).'n';
        /** @var list<array{0: string, 1: bool, 2: bool}> $pieces [html, is a block (<pre>), was written as a tag] */
        $pieces = [];
        $text = $this->ripOfftags($text, $pieces, $token);
        $text = $this->ripInlineCode($text, $pieces, $token);
        $text = preg_replace('/<!--[\s\S]*?-->/', '', $text) ?? $text;
        $text = $this->escapeHtmlTags($text);
        $text = $this->ripAbbreviations($text, $pieces, $token);
        // footnote_ref: "word[1]" only; "[#13]" is an issue in brackets, not a note reference
        $text = preg_replace('/(?<![\p{L}\p{N}_\]])\[(\d+)\]/u', '&#91;$1]', $text) ?? $text;
        $text = str_replace('[#', '&#91;#', $text);
        // block_markdown_rule: "---", "***" or "___" alone on a line is a horizontal rule
        if (preg_match('/^ ?(?:(?:\* ?){3,}|(?:- ?){3,}|(?:_ ?){3,})$/m', $text)) {
            $text = (string) preg_replace_callback('/^ ?(?:(?:\* ?){3,}|(?:- ?){3,}|(?:_ ?){3,})$/m', static function () use (&$pieces, $token): string {
                $pieces[] = ['<hr />', true, true];

                return "\n".$token.(\count($pieces) - 1)."x\n";
            }, $text);
        }
        $text = $this->blockQuotes($text);
        $text = $this->indentedBlocks($text, $pieces, $token);
        $text = $this->tableCells($text);
        $text = $this->hardBreaks($text);
        // inline_textile_link: a link text does not go over a line break
        if (str_contains($text, '<br />')) {
            $text = preg_replace('~"((?:(?!["\n]).)*?<br />(?:(?!["\n]).)*?)":(?=\S)~', '"$1"&#58;', $text) ?? $text;
        }
        $text = $this->separateLists($text, $token);
        // LISTS_RE: a list item is "[#*]+ text"; without the space it is plain text (an issue reference for example)
        $text = preg_replace_callback('/^#+(?=[\d_])/m', static fn (array $m): string => str_repeat('&#35;', \strlen($m[0])), $text) ?? $text;

        // C_LNGE, C_CLAS, C_STYL: a language is [a-z-_]+, anything else in brackets is text ("*[WIP] title*");
        // no double quote in a class or a style
        $parser = new class('html5') extends Parser {
            protected $lnge = '(?:\[[a-z\-_]+\])';
            protected $clas = '(?:\([^")\n]+\))';
            protected $styl = '(?:\{[^{"}\n][^"}\n]*\})';
        };
        $parser->setRestricted(false)->setLite(false)->setAlignClasses(false);
        foreach (self::DISABLED_SYMBOLS as $symbol) {
            $parser->setSymbol($symbol, false);
        }
        $html = str_replace($token.'at', '@', $parser->parse($text));

        // a block that contains a list is not a paragraph: the text before the list is left as it is
        if (str_contains($html, $token.'np')) {
            $html = (string) preg_replace('#<p>'.$token.'np((?:(?!</?p>).)*?)</p>#s', '$1', $html);
            $html = str_replace($token.'np', '', $html);
        }
        if ([] === $pieces) {
            return $html;
        }

        // a <pre> is a block: the paragraph it was written in ends before it and goes on after it
        $inParagraph = '#<p>((?:(?!</?p>).)*?)'.$token.'(\d+)x((?:(?!</?p>).)*?)</p>#s';
        for ($i = 0; $i < 50; ++$i) {
            $html = (string) preg_replace_callback($inParagraph, static function (array $m) use ($pieces, $token): string {
                [$piece, $block] = $pieces[(int) $m[2]] ?? ['', false];
                if (!$block) {
                    // marked as seen so that the next pass looks at the next placeholder of the paragraph
                    return '<p>'.$m[1].$token.'i'.$m[2].'x'.$m[3].'</p>';
                }
                $before = (string) preg_replace('#(?:<br\s*/?>)?\s*$#', '', $m[1]);
                $after = (string) preg_replace('#^\s*(?:<br\s*/?>)?\s*#', '', $m[3]);

                return ('' !== trim($before) ? '<p>'.$before."</p>\n" : '').$piece.('' !== trim($after) ? "\n<p>".$after.'</p>' : '');
            }, $html, -1, $count);
            if (0 === $count) {
                break;
            }
        }
        // blocks(): a block that starts with <code>, <kbd> or <notextile> is "complex HTML", not a paragraph
        $html = (string) preg_replace_callback('#<p>(\s*'.$token.'i?(\d+)x(?:(?!</?p>).)*?)</p>#s', static fn (array $m): string => ($pieces[(int) $m[2]][2] ?? false) ? $m[1] : $m[0], $html);

        return (string) preg_replace_callback('#'.$token.'i?(\d+)x#', static fn (array $m): string => $pieces[(int) $m[1]][0] ?? '', $html);
    }

    /**
     * RedCloth3#rip_offtags: the content of <pre>, <code>, <kbd> and <notextile> is escaped and kept away from the
     * Textile rules. A different off tag nested in another one stays a tag (<pre><code class="ruby">), the same tag
     * nested in itself is escaped. Only <code> keeps its class attribute (the language to highlight).
     *
     * @param list<array{0: string, 1: bool, 2: bool}> $pieces
     */
    private function ripOfftags(string $text, array &$pieces, string $token): string
    {
        if (!str_contains($text, '<')) {
            return $text;
        }
        $re = '~(?:(</('.self::OFFTAGS.')>)|(<('.self::OFFTAGS.')(?=[\s>])([^>]*)>))(.*?)(?=</?(?:'.self::OFFTAGS.')[\s>]|\z)~is';
        if (!preg_match_all($re, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        $out = '';
        $pos = 0;
        $depth = 0;
        $used = [];
        /** @var list<string> $stack real (unescaped) open tags */
        $stack = [];
        $buffer = '';
        $block = false;
        $flush = static function () use (&$buffer, &$stack, &$pieces, &$out, &$block, &$depth, &$used, $token): void {
            while ([] !== $stack) {
                $tag = array_pop($stack);
                $buffer .= 'notextile' === $tag ? '' : '</'.$tag.'>';
            }
            $pieces[] = [$buffer, $block, true];
            $out .= $token.(\count($pieces) - 1).'x';
            $buffer = '';
            $block = false;
            $depth = 0;
            $used = [];
        };
        foreach ($matches as $m) {
            $out .= substr($text, $pos, $m[0][1] - $pos);
            $pos = $m[0][1] + \strlen($m[0][0]);
            $after = $m[6][0] ?? '';
            if ('' !== ($m[3][0] ?? '')) {
                // opening tag
                $tag = strtolower($m[4][0]);
                ++$depth;
                $used[$tag] = true;
                if ($depth - \count($used) > 0) {
                    $buffer .= self::escape($m[0][0]);
                    continue;
                }
                if ([] === $stack) {
                    $block = 'pre' === $tag;
                }
                $class = '';
                if ('code' === $tag && preg_match('/class\s*=\s*("[^"]+"|\'[^\']+\')/i', $m[5][0], $cm)) {
                    $class = ' class="'.htmlspecialchars(substr($cm[1], 1, -1), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'"';
                }
                $stack[] = $tag;
                $buffer .= ('notextile' === $tag ? '' : '<'.$tag.$class.'>').self::escape($after);
                continue;
            }
            // closing tag
            if (0 === $depth) {
                // stray closing tag: dropped, the text that follows is ordinary text
                $out .= $after;
                continue;
            }
            if ($depth - \count($used) > 0) {
                $buffer .= self::escape($m[0][0]);
                --$depth;
                continue;
            }
            $tag = array_pop($stack);
            $buffer .= null === $tag || 'notextile' === $tag ? '' : '</'.$tag.'>';
            --$depth;
            if (0 === $depth || [] === $stack) {
                $flush();
                $out .= $after;
            } else {
                $buffer .= self::escape($after);
            }
        }
        if ([] !== $stack || '' !== $buffer) {
            // non closing tags are closed
            $flush();
        }

        return $out.substr($text, $pos);
    }

    /**
     * RedCloth3#inline_textile_code: @code@ and @|ruby|code@ (the content is displayed as typed). The remaining
     * "@" are mentions and mail addresses, not code delimiters.
     *
     * @param list<array{0: string, 1: bool, 2: bool}> $pieces
     */
    private function ripInlineCode(string $text, array &$pieces, string $token): string
    {
        if (!str_contains($text, '@')) {
            return $text;
        }
        $text = preg_replace_callback('/(?<!\w)@(?:\|(\w+?)\|)?(\S|\S[^\n@]*?\S)@(?!\w)/u', static function (array $m) use (&$pieces, $token): string {
            $pieces[] = ['<code'.('' !== $m[1] ? ' lang="'.self::escape($m[1]).'"' : '').'>'.self::escape($m[2]).'</code>', false, false];

            return $token.(\count($pieces) - 1).'x';
        }, $text) ?? $text;

        return str_replace('@', $token.'at', $text);
    }

    /**
     * RedCloth3#pgl: ABC(title) is an abbreviation, from 2 capitals on.
     *
     * @param list<array{0: string, 1: bool, 2: bool}> $pieces
     */
    private function ripAbbreviations(string $text, array &$pieces, string $token): string
    {
        if (!str_contains($text, '(')) {
            return $text;
        }

        // not in a file name or a URL ("!image.PNG(title)!")
        return preg_replace_callback('~(?<![\w./:!"\'=-])([A-Z][A-Z0-9]+)\(([^)\n]*)\)~', static function (array $m) use (&$pieces, $token): string {
            if ('' === trim($m[2])) {
                return $m[0];
            }
            $pieces[] = ['<abbr title="'.self::escape(html_entity_decode($m[2], \ENT_QUOTES | \ENT_HTML5)).'">'.$m[1].'</abbr>', false, false];

            return $token.(\count($pieces) - 1).'x';
        }, $text) ?? $text;
    }

    /** RedCloth3#escape_html_tags: every other tag is displayed, not interpreted */
    private function escapeHtmlTags(string $text): string
    {
        if (!str_contains($text, '<')) {
            return $text;
        }

        // "!<image.png!" is the left aligned image syntax
        return preg_replace_callback('~(?<!!)<(/?[!\w][^ >\t\f\r\n]*[^<>\n]*)(>?)~u', static function (array $m): string {
            return '&lt;'.self::escape($m[1]).('' !== $m[2] ? '&gt;' : '');
        }, $text) ?? $text;
    }

    /** RedCloth3#block_textile_quotes: "> text" lines (nested with ">>") */
    private function blockQuotes(string $text): string
    {
        if (!preg_match('/^>/m', $text)) {
            return $text;
        }

        return preg_replace_callback('/(?:^>+[^\n]*(?:\n|$))+/m', static function (array $m): string {
            $quotes = '';
            $indent = 0;
            foreach (explode("\n", rtrim($m[0], "\n")) as $line) {
                preg_match('/^([> ]+)(.*)$/s', $line, $lm);
                $level = substr_count($lm[1], '>');
                if ($level !== $indent) {
                    $quotes .= "\n\n".($level > $indent ? str_repeat('<blockquote>', $level - $indent) : str_repeat('</blockquote>', $indent - $level))."\n\n";
                    $indent = $level;
                }
                $quotes .= $lm[2]."\n";
            }

            return $quotes."\n".str_repeat('</blockquote>', $indent)."\n\n";
        }, $text) ?? $text;
    }

    /**
     * RedCloth3#blocks: lines indented after an empty line belong to the block before them. Under a list they are
     * a nested list ("* Item\n\n  * Sub item"), anywhere else they are a code block.
     *
     * @param list<array{0: string, 1: bool, 2: bool}> $pieces
     */
    private function indentedBlocks(string $text, array &$pieces, string $token): string
    {
        if (!preg_match('/\n\n +\S/', $text)) {
            return $text;
        }
        // BLOCKS_GROUP_RE: an empty line followed by an indented line does not end the block
        $blocks = preg_split('/\n{2,}(?! )/', $text) ?: [$text];
        foreach ($blocks as $i => $block) {
            if (!str_contains($block, "\n\n ") || preg_match('/\A[> <]/', $block) || str_starts_with($block, $token)) {
                continue;
            }
            $list = 1 === preg_match('/\A[#*]+ /', $block);
            $code = [];
            $block = (string) preg_replace_callback('/(?:\n(?:\n +[^\n]*)+)+/', static function (array $m) use ($list, &$code, &$pieces, $token, $block): string {
                $lines = explode("\n", $m[0]);
                // flush_left
                $indent = null;
                foreach ($lines as $line) {
                    if ('' !== trim($line)) {
                        $n = \strlen($line) - \strlen(ltrim($line, ' '));
                        $indent = null === $indent ? $n : min($indent, $n);
                    }
                }
                $lines = array_map(static fn (string $line): string => '' === trim($line) ? '' : substr($line, (int) $indent), $lines);
                if ($list) {
                    // a nested list: one level under the item before it
                    $before = substr($block, 0, (int) strpos($block, $m[0]));
                    $parent = preg_match_all('/^([#*]+) /m', $before, $pm) ? end($pm[1]) : '*';
                    $out = '';
                    foreach ($lines as $line) {
                        if ('' === $line) {
                            continue;
                        }
                        $out .= "\n".(preg_match('/^[#*]+ /', $line) ? $parent.$line : $line);
                    }

                    return $out;
                }
                // each paragraph of the indented text is a <pre><code>
                foreach (preg_split('/\n{2,}/', trim(implode("\n", $lines), "\n")) ?: [] as $paragraph) {
                    $pieces[] = ['<pre><code>'.self::escape(html_entity_decode(str_replace($token.'at', '@', $paragraph), \ENT_QUOTES | \ENT_HTML5)).'</code></pre>', true, true];
                    $code[] = $token.(\count($pieces) - 1).'x';
                }

                return '';
            }, $block);
            $blocks[$i] = $block.([] === $code ? '' : "\n\n".implode("\n\n", $code));
        }

        return implode("\n\n", $blocks);
    }

    /**
     * A table cell goes on over several lines; the lines of a cell are not list items
     * ("|cell|list\n# item\n# item 2|" displays "# item").
     */
    private function tableCells(string $text): string
    {
        if (!preg_match('/^\|/m', $text)) {
            return $text;
        }

        return preg_replace_callback('/^\|[^\n]*(?:\n[^\n]+)*/m', static function (array $m): string {
            // wiki links with a | are not cut as cells
            $m[0] = (string) preg_replace('/(\[\[[^|\]\n]*)\|([^|\]\n]*\]\])/', '$1&#124;$2', $m[0]);

            return (string) preg_replace_callback('/\n(?!\|)(?:([#*])(?=[#*]* ))?/', static fn (array $c): string => '<br />'.(isset($c[1]) && '' !== $c[1] ? ('#' === $c[1] ? '&#35;' : '&#42;') : ''), $m[0]);
        }, $text) ?? $text;
    }

    /**
     * Redmine's Textile::Formatter#hard_break: a line break is a <br />, except before a list item, a table row
     * or an attribute block. Preformatted blocks (bc. pre. notextile.) keep their lines.
     */
    private function hardBreaks(string $text): string
    {
        if (!str_contains($text, "\n")) {
            return $text;
        }
        $blocks = preg_split('/(\n{2,})/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($blocks as $i => $block) {
            if (0 === $i % 2 && str_contains($block, "\n") && !preg_match('/\A(?:bc|pre|notextile|###)[^\s.]*\.\.? /', $block)) {
                $blocks[$i] = preg_replace('/(.)\n(?!\n|\Z| *(?:[#*=]+(?:\s|$)|[{|]))/m', '$1<br />', $block) ?? $block;
            }
        }

        return implode('', $blocks);
    }

    /**
     * RedCloth3 starts a list right after a line of text ("This is a list:\n* One\n* Two") and does not make a
     * paragraph of that text; the Textile parser wants the list in a block of its own.
     */
    private function separateLists(string $text, string $token): string
    {
        if (!preg_match('/^[#*]+ /m', $text)) {
            return $text;
        }
        $out = [];
        $inList = false;
        $previousBlank = true;
        $blockStart = 0;
        foreach (explode("\n", $text) as $line) {
            $isList = 1 === preg_match('/^[#*]+ /', $line);
            if ($previousBlank) {
                $blockStart = \count($out);
            }
            if ($isList && !$inList && !$previousBlank) {
                if (!preg_match(self::SIGNATURE_RE, $out[$blockStart]) && !str_starts_with($out[$blockStart], '<') && !str_starts_with($out[$blockStart], $token)) {
                    $out[$blockStart] = $token.'np'.$out[$blockStart];
                }
                $out[] = '';
            }
            $previousBlank = '' === trim($line);
            $inList = $previousBlank ? false : ($inList || $isList);
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
