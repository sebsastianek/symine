<?php

declare(strict_types=1);

namespace App\Service\Text;

use App\Entity\Attachment;
use App\I18n\Localizer;
use App\Service\Settings;
use App\Service\SyntaxHighlighting;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * What Redmine does to the HTML produced by a text formatter (Redmine::WikiFormatting::*::Formatter#to_html):
 *
 *  1. sanitize it against an allowlist (Redmine::WikiFormatting::SanitizationFilter and its CommonMark / Textile
 *     subclasses): unknown elements are replaced by their content, only known attributes survive, link and image
 *     URLs are restricted to safe schemes, inline styles to a list of harmless CSS properties
 *  2. run the scrubbers: syntax highlighting, sortable tables, auto link fix-ups, "external" / "email" link
 *     classes, alert icons, inline attachment images and hires images, and the copy button of <pre> blocks
 */
final class HtmlPipeline
{
    /** SanitizationFilter::ALLOWLIST[:elements] (+ input for CommonMark task lists) */
    private const array ELEMENTS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'b', 'i', 'strong', 'em', 'a', 'pre', 'code', 'img', 'tt', 'u',
        'div', 'ins', 'del', 'sup', 'sub', 'p', 'ol', 'ul', 'table', 'thead', 'tbody', 'tfoot', 'blockquote',
        'dl', 'dt', 'dd', 'kbd', 'q', 'samp', 'var', 'hr', 'ruby', 'rt', 'rp', 'li', 'tr', 'td', 'th', 's', 'strike',
        'summary', 'details', 'caption', 'figure', 'figcaption',
        'abbr', 'bdo', 'cite', 'dfn', 'mark', 'small', 'span', 'time', 'wbr',
    ];

    /** SanitizationFilter::ALLOWLIST[:attributes][:all] */
    private const array ALL_ATTRIBUTES = [
        'abbr', 'accept', 'accept-charset', 'accesskey', 'action', 'align', 'alt',
        'aria-describedby', 'aria-hidden', 'aria-label', 'aria-labelledby',
        'axis', 'border', 'cellpadding', 'cellspacing', 'char', 'charoff', 'charset', 'checked',
        'clear', 'cols', 'colspan', 'color', 'compact', 'coords', 'datetime', 'dir',
        'disabled', 'enctype', 'for', 'frame', 'headers', 'height', 'hreflang',
        'hspace', 'ismap', 'label', 'lang', 'maxlength', 'media', 'method',
        'multiple', 'nohref', 'noshade', 'nowrap', 'open', 'progress', 'prompt', 'readonly', 'rel', 'rev',
        'role', 'rows', 'rowspan', 'rules', 'scope', 'selected', 'shape', 'size', 'span',
        'start', 'style', 'summary', 'tabindex', 'target', 'title', 'type', 'usemap', 'valign', 'value',
        'vspace', 'width', 'itemprop',
    ];

    private const array ELEMENT_ATTRIBUTES = [
        'a' => ['href', 'name'],
        'img' => ['src', 'longdesc'],
        'div' => ['itemscope', 'itemtype'],
        'blockquote' => ['cite'],
        'del' => ['cite'],
        'ins' => ['cite'],
        'q' => ['cite'],
    ];

    /** CommonMark::SanitizationFilter#default_allowlist */
    private const array COMMON_MARK_ATTRIBUTES = [
        'a' => ['href', 'id', 'name'],
        'code' => ['class'],
        'div' => ['class', 'itemscope', 'itemtype'],
        'li' => ['id', 'class'],
        'input' => ['class', 'type'],
        'p' => ['class'],
        'ul' => ['class'],
    ];

    /** Elements whose removal leaves a space (Sanitize :whitespace_elements) */
    private const array WHITESPACE_ELEMENTS = [
        'address', 'article', 'aside', 'footer', 'header', 'hgroup', 'main', 'nav', 'section', 'form', 'fieldset', 'center',
    ];

    /** Same properties as RedCloth3::STYLES_RE (SanitizationFilter::STYLE_PROPERTY_RE) */
    private const string STYLE_PROPERTY_RE = '/\A(color|(min-|max-)?(width|height)|border|background|padding|margin|font|text|float)(-[a-z]+)*\z/';
    /** RedCloth3::STYLES_RE: what a Textile {style} may contain */
    private const string TEXTILE_STYLE_RE = '/^(color|(min-|max-)?+(width|height)|border|background|padding|margin|font|text|float)(-[a-z]+)*:\s*((\d+%?|\d+px|\d+(\.\d+)?em|#[0-9a-f]+|[a-z]+(?:-[a-z]+)*)\s*)+$/i';

    private const array ALERT_ICONS = ['note' => 'help', 'tip' => 'bulb', 'warning' => 'warning', 'caution' => 'alert-circle', 'important' => 'message-report'];

    private const string IMAGE_FILENAME_RE = '~\A(?<filename>[^/"]+?\.(?:avif|bmp|gif|jpg|jpeg|jpe|png|webp))\z~i';
    private const string HIRES_RE = '/@(?<dpr>\dx)\.(?:bmp|gif|jpg|jpe|jpeg|png)\z/i';

    public function __construct(
        private readonly SyntaxHighlighting $highlighter,
        private readonly Settings $settings,
        private readonly ?Localizer $i18n = null,
    ) {
    }

    /**
     * @param array{
     *     attachments?: (\Closure(): list<Attachment>)|null,
     *     attachment_url?: (\Closure(Attachment): string)|null,
     *     macro_token?: string,
     * } $context attachments: the attachments inline images may refer to (loaded only when needed);
     *            macro_token: the placeholder of the macros taken out of the text ("<token><n>x"), see macrosInAttributes()
     */
    public function process(string $html, string $formatting, array $context = []): string
    {
        if ('' === trim($html)) {
            return '';
        }
        $textile = 'textile' === $formatting;
        $doc = HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>'.$html.'</body></html>', \LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        if (null === $body) {
            return '';
        }

        if ($textile) {
            $this->prepareTextile($body);
        } else {
            $this->prepareFootnotes($body);
            $this->prepareTaskLists($body);
            $this->prepareAlerts($doc, $body);
        }

        $this->sanitizeChildren($body, $textile);

        /** @var list<Element> $elements */
        $elements = iterator_to_array($body->querySelectorAll('pre, code, table, a, img, p.markdown-alert-title'), false);
        foreach ($elements as $el) {
            if (null === $el->parentNode) {
                continue;
            }
            switch ($el->localName) {
                case 'pre':
                    $this->copyButton($doc, $el);
                    break;
                case 'code':
                    $this->highlight($el, $textile);
                    break;
                case 'table':
                    $this->tablesort($el);
                    break;
                case 'a':
                    if ($textile) {
                        // RedCloth3#inline_textile_link: only http(s) links are "external"
                        if (preg_match('~\Ahttps?://~', (string) $el->getAttribute('href'))) {
                            $el->setAttribute('class', trim($el->getAttribute('class').' external'));
                        }
                        break;
                    }
                    if ($this->fixupAutoLink($doc, $el)) {
                        break;
                    }
                    $this->extendAutoLink($el);
                    $this->externalLink($el);
                    break;
                case 'img':
                    $this->inlineAttachment($el, $context);
                    $this->hiresImage($el);
                    break;
                case 'p':
                    $this->alertIcon($el);
                    break;
            }
        }
        if ($textile) {
            $this->autoLink($doc, $body);
        }
        $this->macrosInAttributes($body, (string) ($context['macro_token'] ?? ''));

        return $body->innerHTML;
    }

    // ------------------------------------------------------------------------------------------
    // Formatter specific preparation (what commonmarker / RedCloth3 emit and the PHP libraries do not)
    // ------------------------------------------------------------------------------------------

    /**
     * RedCloth3 prefixes user classes and ids ("p(foo#bar)." gives class="wiki-class-foo" id="wiki-id-bar") and
     * numbers footnotes fn1, fn2...
     */
    private function prepareTextile(Element $body): void
    {
        foreach (iterator_to_array($body->querySelectorAll('[class], [id], a[href^="#fn"]'), false) as $el) {
            /** @var Element $el */
            if ($el->hasAttribute('id')) {
                $id = (string) $el->getAttribute('id');
                if (preg_match('/\Afn[0-9a-f]+-(\d+)\z/', $id, $m)) {
                    $el->setAttribute('id', 'fn'.$m[1]);
                } elseif (preg_match('/\Afnrev[0-9a-f]+-\d+\z/', $id)) {
                    $el->removeAttribute('id');
                } elseif (!preg_match('/\A(wiki-id-\S+|fn\d+)\z/', $id)) {
                    $el->setAttribute('id', 'wiki-id-'.$id);
                }
            }
            if ('a' === $el->localName && preg_match('/\A#fn[0-9a-f]+-(\d+)\z/', (string) $el->getAttribute('href'), $m)) {
                $el->setAttribute('href', '#fn'.$m[1]);
            }
            if ($el->hasAttribute('class') && 'code' !== $el->localName) {
                $classes = [];
                foreach (preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [] as $class) {
                    if ('' === $class) {
                        continue;
                    }
                    if ('sup' === $el->localName && 'footnote' === $class) {
                        continue;
                    }
                    $classes[] = preg_match('/\A(wiki-class-\S+|footnote)\z/', $class) ? $class : 'wiki-class-'.$class;
                }
                if ([] === $classes) {
                    $el->removeAttribute('class');
                } else {
                    $el->setAttribute('class', implode(' ', $classes));
                }
            }
        }
        // RedCloth3#inline_textile_image: an aligned image ("!>image.png!") floats in a <span style="float:right">
        foreach (iterator_to_array($body->querySelectorAll('img[align]'), false) as $img) {
            /** @var Element $img */
            $align = strtolower((string) $img->getAttribute('align'));
            $img->removeAttribute('align');
            $document = $body->ownerDocument;
            if (null !== $document && ('left' === $align || 'right' === $align)) {
                $span = $document->createElement('span');
                $span->setAttribute('style', 'float:'.$align);
                $img->replaceWith($span);
                $span->appendChild($img);
            }
        }
    }

    /** Footnotes as commonmarker renders them: <sup><a href="#fn-1" id="fnref-1">1</a></sup> ... <ol><li id="fn-1"> */
    private function prepareFootnotes(Element $body): void
    {
        foreach (iterator_to_array($body->querySelectorAll('div.footnotes'), false) as $container) {
            /** @var Element $container */
            $numbers = [];
            foreach ($container->querySelectorAll('li.footnote') as $li) {
                $numbers[(string) $li->getAttribute('id')] = \count($numbers) + 1;
                $li->setAttribute('id', 'fn-'.\count($numbers));
                $li->removeAttribute('class');
                $li->removeAttribute('role');
            }
            $refs = [];
            $seen = [];
            foreach ($body->querySelectorAll('sup > a.footnote-ref') as $a) {
                $n = $numbers[substr((string) $a->getAttribute('href'), 1)] ?? null;
                $sup = $a->parentElement;
                if (null === $n || null === $sup) {
                    continue;
                }
                $seen[$n] = ($seen[$n] ?? 0) + 1;
                $id = 'fnref-'.$n.($seen[$n] > 1 ? '-'.$seen[$n] : '');
                $refs[(string) $sup->getAttribute('id')] = [$id, $n.($seen[$n] > 1 ? '-'.$seen[$n] : '')];
                $sup->removeAttribute('id');
                $a->setAttribute('href', '#fn-'.$n);
                $a->setAttribute('id', $id);
                $a->removeAttribute('class');
                $a->removeAttribute('role');
            }
            foreach ($container->querySelectorAll('a.footnote-backref') as $a) {
                [$id, $label] = $refs[substr((string) $a->getAttribute('href'), 1)] ?? [null, null];
                foreach (['class', 'rev', 'role'] as $attr) {
                    $a->removeAttribute($attr);
                }
                if (null !== $id) {
                    $a->setAttribute('href', '#'.$id);
                    $a->setAttribute('aria-label', 'Back to reference '.$label);
                }
                $previous = $a->previousSibling;
                if ($previous instanceof Text) {
                    $previous->data = str_replace("\u{A0}", ' ', $previous->data);
                }
            }
            $parent = $container->parentNode;
            if (null === $parent) {
                continue;
            }
            while (null !== $container->firstChild) {
                $parent->insertBefore($container->firstChild, $container);
            }
            $container->remove();
        }
    }

    /** Task lists as commonmarker renders them (tasklist_classes) */
    private function prepareTaskLists(Element $body): void
    {
        foreach ($body->querySelectorAll('li > input[type="checkbox"]:first-child') as $input) {
            /** @var Element $input */
            $li = $input->parentElement;
            $list = $li?->parentElement;
            if (null === $li || null === $list || 'ul' !== $list->localName && 'ol' !== $list->localName) {
                continue;
            }
            $input->setAttribute('class', 'task-list-item-checkbox');
            $li->setAttribute('class', 'task-list-item');
            $list->setAttribute('class', 'contains-task-list');
        }
    }

    /** GitHub alerts: a block quote starting with [!NOTE], [!TIP], [!WARNING], [!CAUTION] or [!IMPORTANT] */
    private function prepareAlerts(HTMLDocument $doc, Element $body): void
    {
        foreach (iterator_to_array($body->querySelectorAll('blockquote'), false) as $quote) {
            /** @var Element $quote */
            $p = $quote->firstElementChild;
            $first = $p?->firstChild;
            if (null === $p || 'p' !== $p->localName || !$first instanceof Text) {
                continue;
            }
            if (!preg_match('/\A\[!(note|tip|warning|caution|important)\]([^\n]*)\z/i', rtrim($first->data, "\n"), $m)) {
                continue;
            }
            $type = strtolower($m[1]);
            $title = trim($m[2]);
            $first->remove();
            $next = $p->firstChild;
            if ($next instanceof Element && 'br' === $next->localName) {
                $next->remove();
                $next = $p->firstChild;
            }
            if ($next instanceof Text) {
                $next->data = ltrim($next->data, "\n");
            }
            $div = $doc->createElement('div');
            $div->setAttribute('class', 'markdown-alert markdown-alert-'.$type);
            $titleP = $doc->createElement('p');
            $titleP->setAttribute('class', 'markdown-alert-title');
            $titleP->textContent = '' !== $title ? $title : ucfirst($type);
            if ('' === trim((string) $p->textContent) && null === $p->firstElementChild) {
                $quote->replaceChild($titleP, $p);
            } else {
                $quote->insertBefore($titleP, $p);
                $quote->insertBefore($doc->createTextNode("\n"), $p);
            }
            while (null !== $quote->firstChild) {
                $div->appendChild($quote->firstChild);
            }
            $quote->replaceWith($div);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Sanitizer
    // ------------------------------------------------------------------------------------------

    private function sanitizeChildren(Node $node, bool $textile): void
    {
        $child = $node->firstChild;
        while (null !== $child) {
            $next = $child->nextSibling;
            if ($child instanceof Element) {
                $name = $child->localName;
                if ('http://www.w3.org/1999/xhtml' === $child->namespaceURI && 'script' !== $name && $this->elementAllowed($child, $name, $textile)) {
                    $this->cleanAttributes($child, $name, $textile);
                    $this->sanitizeChildren($child, $textile);
                } elseif ('script' === $name) {
                    $child->remove();
                } else {
                    // not allowed: the element goes, its content stays
                    $space = \in_array($name, self::WHITESPACE_ELEMENTS, true);
                    $first = $child->firstChild;
                    if ($space) {
                        $node->insertBefore($node->ownerDocument->createTextNode(' '), $child);
                    }
                    while (null !== $child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    if ($space) {
                        $node->insertBefore($node->ownerDocument->createTextNode(' '), $child);
                    }
                    $child->remove();
                    $next = $first ?? $next;
                }
            } elseif (!$child instanceof Text) {
                $child->remove();
            }
            $child = $next;
        }
    }

    private function elementAllowed(Element $el, string $name, bool $textile): bool
    {
        if ('input' === $name) {
            // task list items only
            return !$textile && 'checkbox' === $el->getAttribute('type') && 'task-list-item-checkbox' === $el->getAttribute('class');
        }
        if (!\in_array($name, self::ELEMENTS, true)) {
            return false;
        }
        if ('li' === $name) {
            // top-level <li> elements are removed because they can break out of containing markup
            return $this->hasAncestor($el, ['ul', 'ol']);
        }
        if (\in_array($name, ['tr', 'td', 'th', 'thead', 'tbody', 'tfoot'], true)) {
            return $this->hasAncestor($el, ['table']);
        }

        return true;
    }

    /** @param list<string> $names */
    private function hasAncestor(Element $el, array $names): bool
    {
        for ($p = $el->parentElement; null !== $p; $p = $p->parentElement) {
            if (\in_array($p->localName, $names, true)) {
                return true;
            }
        }

        return false;
    }

    private function cleanAttributes(Element $el, string $name, bool $textile): void
    {
        $allowed = $textile ? (self::ELEMENT_ATTRIBUTES[$name] ?? []) : (self::COMMON_MARK_ATTRIBUTES[$name] ?? self::ELEMENT_ATTRIBUTES[$name] ?? []);
        foreach (iterator_to_array($el->attributes, false) as $attr) {
            $attrName = strtolower($attr->name);
            $ok = \in_array($attrName, $allowed, true) || \in_array($attrName, self::ALL_ATTRIBUTES, true)
                || $textile && ('class' === $attrName || 'id' === $attrName);
            if (!$ok) {
                $el->removeAttribute($attr->name);
            }
        }

        if ($el->hasAttribute('style')) {
            $style = $this->filterStyle((string) $el->getAttribute('style'), $textile);
            if ('' === $style) {
                $el->removeAttribute('style');
            } else {
                $el->setAttribute('style', $style);
            }
        }
        if ('a' === $name && $el->hasAttribute('href')) {
            $href = trim((string) $el->getAttribute('href'));
            if ('' === $href || !self::linkSafeScheme($href)) {
                $el->removeAttribute('href');
            } else {
                $el->setAttribute('href', $href);
            }
        }
        foreach ('img' === $name ? ['src', 'longdesc'] : (\in_array($name, ['blockquote', 'del', 'ins', 'q'], true) ? ['cite'] : []) as $attr) {
            if ($el->hasAttribute($attr) && !self::httpOrRelative((string) $el->getAttribute($attr))) {
                $el->removeAttribute($attr);
            }
        }

        if ($textile) {
            // Textile::SanitizationFilter#remove_disallowed_classes_and_ids
            if ($el->hasAttribute('class') && 'code' !== $name) {
                $classes = preg_grep('/\A(wiki-class-\S+|external|email|footnote)\z/', preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: []) ?: [];
                if ([] === $classes) {
                    $el->removeAttribute('class');
                } else {
                    $el->setAttribute('class', implode(' ', $classes));
                }
            }
            if ($el->hasAttribute('id') && !preg_match('/\A(wiki-id-\S+|fn\d+)\z/', (string) $el->getAttribute('id'))) {
                $el->removeAttribute('id');
            }

            return;
        }

        // CommonMark::SanitizationFilter#add_transformers
        $class = $el->getAttribute('class');
        $id = $el->getAttribute('id');
        switch ($name) {
            case 'code':
                if (null !== $class && !preg_match('/\Alanguage-(\S+)\z/', $class)) {
                    $el->removeAttribute('class');
                }
                break;
            case 'div':
                if (null !== $class && !preg_match('/\Amarkdown-alert markdown-alert-[a-z]+\z/', $class)) {
                    $el->removeAttribute('class');
                }
                break;
            case 'p':
                if (null !== $class && 'markdown-alert-title' !== $class) {
                    $el->removeAttribute('class');
                }
                break;
            case 'a':
                if (null !== $id && !preg_match('/\Afnref(-\d+){1,2}\z/', $id)) {
                    $el->removeAttribute('id');
                }
                break;
            case 'li':
                if (null !== $id && !preg_match('/\Afn-\d+\z/', $id)) {
                    $el->removeAttribute('id');
                }
                if (null !== $class && 'task-list-item' !== $class) {
                    $el->removeAttribute('class');
                }
                break;
            case 'ul':
                if (null !== $class && 'contains-task-list' !== $class) {
                    $el->removeAttribute('class');
                }
                break;
        }
    }

    /** Keeps the declarations of the allowed CSS properties ("color:red;width:10px;"), without urls or expressions */
    private function filterStyle(string $style, bool $textile): string
    {
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);
            if ('' === $declaration || !str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            if ('' === $value || !preg_match(self::STYLE_PROPERTY_RE, $property) && !\in_array($property, ['line-height', 'vertical-align'], true)) {
                continue;
            }
            if ($textile && !preg_match(self::TEXTILE_STYLE_RE, $declaration)) {
                continue;
            }
            if (preg_match('~url\s*\(|expression|javascript\s*:|[\\\\<>{}@]|/\*|-moz-binding|behaviou?r~i', $value)) {
                continue;
            }
            $kept[] = $declaration;
        }

        return [] === $kept ? '' : implode(';', $kept).';';
    }

    /** Redmine::Helpers::URL#uri_with_link_safe_scheme? */
    public static function linkSafeScheme(string $uri): bool
    {
        if (!preg_match('~\A\s*([^/#]*?)(?::|&#0*58|&#x0*3a)~i', $uri, $m)) {
            return true;
        }
        $scheme = strtolower($m[1]);
        if (!preg_match('/\A[a-z][a-z0-9+.\-]*\z/', $scheme)) {
            return false;
        }

        return !\in_array($scheme, ['data', 'javascript', 'vbscript'], true);
    }

    /** Sanitize protocols ['http', 'https', :relative] */
    private static function httpOrRelative(string $uri): bool
    {
        $uri = preg_replace('/[\x00-\x20]+/', '', $uri) ?? $uri;
        if (!preg_match('~\A([^/#?]*?)(?::|&#0*58|&#x0*3a)~i', $uri, $m)) {
            return true;
        }

        return \in_array(strtolower($m[1]), ['http', 'https'], true);
    }

    // ------------------------------------------------------------------------------------------
    // Scrubbers
    // ------------------------------------------------------------------------------------------

    /**
     * Macros are run after this pipeline, on its output. One written inside an attribute would then change a value
     * that was checked here (href="java{{macro}}script:..." is harmless until the macro disappears), so its
     * placeholder is marked "display only" (suffix "d" instead of "x"): the text of the macro is shown instead.
     */
    private function macrosInAttributes(Element $body, string $token): void
    {
        if ('' === $token || !str_contains((string) $body->innerHTML, $token)) {
            return;
        }
        $pattern = '/'.preg_quote($token, '/').'(\d+)x/';
        foreach ($body->querySelectorAll('*') as $el) {
            foreach (iterator_to_array($el->attributes) as $attribute) {
                if (str_contains($attribute->value, $token)) {
                    $el->setAttribute($attribute->name, (string) preg_replace($pattern, $token.'${1}d', $attribute->value));
                }
            }
        }
    }

    /** CopypreScrubber: the block is wrapped with a button that copies its content (clipboard_controller.js) */
    private function copyButton(HTMLDocument $doc, Element $pre): void
    {
        $pre->setAttribute('data-clipboard-target', 'pre');
        $wrapper = $doc->createElement('div');
        $wrapper->setAttribute('class', 'pre-wrapper');
        $wrapper->setAttribute('data-controller', 'clipboard');
        $pre->replaceWith($wrapper);
        $title = null !== $this->i18n && $this->i18n->has('button_copy') ? $this->i18n->l('button_copy') : 'Copy';
        $wrapper->innerHTML = '<a class="copy-pre-content-link icon-only" title="'.htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            .'" data-action="clipboard#copyPre"><svg class="s18 icon-svg" aria-hidden="true"><use href="/assets/icons.svg#icon--copy-pre-content"></use></svg></a>';
        $wrapper->appendChild($pre);
    }

    /** SyntaxHighlightScrubber (CommonMark: <pre><code class="language-xxx">, Textile: <code class="xxx">) */
    private function highlight(Element $code, bool $textile): void
    {
        $lang = (string) $code->getAttribute('class');
        if ('' === $lang) {
            return;
        }
        $inPre = 'pre' === $code->parentElement?->localName;
        if (!$textile) {
            if (!$inPre || !preg_match('/\Alanguage-(\S+)\z/', $lang, $m)) {
                return;
            }
            $lang = $m[1];
        }
        $text = str_replace("\r\n", "\n", (string) $code->textContent);
        if ($textile && str_starts_with($text, "\n")) {
            $text = substr($text, 1);
        }
        if (!$code->hasAttribute('data-language')) {
            $code->setAttribute('data-language', $lang);
        }
        if (!preg_match('/\s/', $lang) && $this->highlighter->languageSupported($lang)) {
            $code->innerHTML = $this->highlighter->highlightByLanguage($text, $lang);
            $code->setAttribute('class', $lang.' syntaxhl');
        } else {
            // unsupported language: no class, and nothing but text inside
            $code->removeAttribute('class');
            $code->textContent = $text;
        }
        if ('mermaid' === strtolower($lang) && $inPre) {
            $code->setAttribute('data-controller', 'mermaid');
        }
    }

    /** TablesortScrubber */
    private function tablesort(Element $table): void
    {
        if (!$this->settings->bool('wiki_tablesort_enabled')) {
            return;
        }
        $rows = $table->querySelectorAll('tr');
        if ($rows->length < 3) {
            return;
        }
        /** @var Element $first */
        $first = $rows->item(0);
        if (0 === $first->querySelectorAll('th')->length) {
            return;
        }
        $table->setAttribute('data-controller', 'tablesort');
        $first->setAttribute('data-sort-method', 'none');
        foreach ($first->querySelectorAll('td') as $td) {
            $td->setAttribute('data-sort-method', 'none');
        }
    }

    /** FixupAutoLinksScrubber: "@user@example.org", "user:user@example.org" and "image@2x.png" are not mail addresses */
    private function fixupAutoLink(HTMLDocument $doc, Element $a): bool
    {
        $href = (string) $a->getAttribute('href');
        if (!str_starts_with($href, 'mailto:')) {
            return false;
        }
        $previous = $a->previousSibling;
        $text = (string) $a->textContent;
        if ($previous instanceof Text && preg_match('/(@|user:|attachment:)\z/', $previous->data)
            || preg_match('/.+@\dx\.(bmp|gif|jpg|jpe|jpeg|png)\z/', $text)) {
            $a->replaceWith($doc->createTextNode($text));
            $previous?->parentNode?->normalize();

            return true;
        }

        return false;
    }

    /**
     * commonmarker auto links a URL up to the next space, symbols included ("http://host/issues?utf8=✓&set_filter=1");
     * the PHP parser stops at the first character that is neither a letter nor a digit nor ASCII punctuation.
     */
    private function extendAutoLink(Element $a): void
    {
        $next = $a->nextSibling;
        $href = (string) $a->getAttribute('href');
        $text = (string) $a->textContent;
        if (!$next instanceof Text || '' === $next->data || null !== $a->firstElementChild || ($href !== $text && $href !== 'http://'.$text)
            || !preg_match('/\A[^\x00-\x7F][^\s<]*/u', $next->data, $m)) {
            return;
        }
        // trailing punctuation is not part of the link
        $more = (string) preg_replace('/[?!.,:*_~\'"]+\z/', '', $m[0]);
        if ('' === $more) {
            return;
        }
        $a->textContent = $text.$more;
        $a->setAttribute('href', $href.preg_replace_callback('/[^\x21-\x7E]+/', static fn (array $x): string => rawurlencode($x[0]), $more));
        $next->data = substr($next->data, \strlen($more));
    }

    /** ExternalLinksScrubber: class "external" (or "email"), rel="noopener" for links opening a new window */
    private function externalLink(Element $a): void
    {
        $url = $a->getAttribute('href');
        if (null === $url || str_starts_with($url, '/') || str_starts_with($url, '#') || !str_contains($url, ':')) {
            return;
        }
        if (!preg_match('/\A([a-z][a-z0-9+.\-]*):/i', $url, $m)) {
            return;
        }
        $scheme = strtolower($m[1]);
        $class = 'mailto' === $scheme ? 'email' : 'external';
        $classes = preg_split('/\s+/', trim((string) $a->getAttribute('class')), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if (!\in_array($class, $classes, true)) {
            $classes[] = $class;
            $a->setAttribute('class', implode(' ', $classes));
        }
        if ('' !== (string) $a->getAttribute('target') && 'mailto' !== $scheme) {
            $rel = preg_split('/\s+/', trim((string) $a->getAttribute('rel')), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if (!\in_array('noopener', $rel, true)) {
                $rel[] = 'noopener';
            }
            $a->setAttribute('rel', implode(' ', $rel));
        }
    }

    /** AlertsIconsScrubber */
    private function alertIcon(Element $p): void
    {
        $parent = $p->parentElement;
        if (null === $parent || !preg_match('/markdown-alert-(\w+)/', (string) $parent->getAttribute('class'), $m)) {
            return;
        }
        $icon = self::ALERT_ICONS[$m[1]] ?? null;
        if (null === $icon) {
            return;
        }
        $label = (string) $p->textContent;
        if (isset(self::ALERT_ICONS[strtolower($label)]) && null !== $this->i18n && $this->i18n->has('label_alert_'.$m[1])) {
            $label = $this->i18n->l('label_alert_'.$m[1]);
        }
        $p->innerHTML = '<svg class="s18 icon-svg" aria-hidden="true"><use href="/assets/icons.svg#icon--'.$icon.'"></use></svg><span class="icon-label">'
            .htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'</span>';
    }

    /**
     * InlineAttachmentsScrubber: an image whose source is the file name of an attachment shows that attachment.
     *
     * @param array<string, mixed> $context
     */
    private function inlineAttachment(Element $img, array $context): void
    {
        $src = (string) $img->getAttribute('src');
        if ('' === $src || !isset($context['attachments'], $context['attachment_url']) || !preg_match(self::IMAGE_FILENAME_RE, $src, $m)) {
            return;
        }
        $filename = rawurldecode($m['filename']);
        if (!mb_check_encoding($filename, 'UTF-8')) {
            return;
        }
        foreach (($context['attachments'])() as $attachment) {
            if (0 !== strcasecmp(mb_strtolower($attachment->filename), mb_strtolower($filename))) {
                continue;
            }
            $img->setAttribute('src', ($context['attachment_url'])($attachment));
            $description = str_replace('"', '', (string) $attachment->description);
            if ('' !== trim($description) && '' === trim((string) $img->getAttribute('alt'))) {
                $img->setAttribute('title', $description);
                $img->setAttribute('alt', $description);
            }
            $img->setAttribute('loading', 'lazy');

            return;
        }
    }

    /** HiresImagesScrubber */
    private function hiresImage(Element $img): void
    {
        $src = (string) $img->getAttribute('src');
        if (str_contains($src, '@') && preg_match(self::HIRES_RE, $src, $m)) {
            $img->setAttribute('srcset', $src.' '.$m['dpr']);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Textile auto links (Redmine::WikiFormatting::LinksHelper#auto_link!, #auto_mailto!)
    // ------------------------------------------------------------------------------------------

    private function autoLink(HTMLDocument $doc, Node $node): void
    {
        $child = $node->firstChild;
        while (null !== $child) {
            $next = $child->nextSibling;
            if ($child instanceof Element) {
                if (!\in_array($child->localName, ['a', 'pre', 'code', 'kbd'], true)) {
                    $this->autoLink($doc, $child);
                }
            } elseif ($child instanceof Text && (str_contains($child->data, '://') || str_contains($child->data, 'www.') || str_contains($child->data, '@'))) {
                $this->autoLinkText($doc, $child);
            }
            $child = $next;
        }
    }

    private function autoLinkText(HTMLDocument $doc, Text $node): void
    {
        $text = $node->data;
        $url = '~(?<=^|[\s(\[,;<])((?:https?://)|(?:s?ftps?://)|(?:www\.))([^<\s]\S*?)([^[:alnum:]_=/;()\-]*?)(?=\s|$)~u';
        $mail = '~(?<![\w.!#$%+/@\-])(?<!user:)(?<!attachment:)([\w.!#$%\-+/]{1,64}@[A-Za-z0-9\-]{1,63}(?:\.[A-Za-z0-9\-]{1,63})+)~u';
        /** @var list<array{0: int, 1: int, 2: string, 3: string, 4: string}> $links [offset, length, href, label, class] */
        $links = [];
        if (preg_match_all($url, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $proto = $m[1][0];
                $rest = $m[2][0];
                if (str_ends_with($rest, ')') && substr_count($rest, '(') - substr_count($rest, ')') < 0) {
                    // the closing parenthesis is not part of the url
                    $rest = substr($rest, 0, -1);
                }
                if ('' === $rest) {
                    continue;
                }
                $links[] = [$m[1][1], \strlen($proto.$rest), ('www.' === $proto ? 'http://www.' : $proto).$rest, $proto.$rest, 'external'];
            }
        }
        if (str_contains($text, '@') && preg_match_all($mail, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $offset = $m[1][1];
                $address = $m[1][0];
                if (preg_match('/@\dx\.(bmp|gif|jpg|jpe|jpeg|png)\z/', $address)) {
                    continue;
                }
                foreach ($links as $link) {
                    if ($offset < $link[0] + $link[1] && $link[0] < $offset + \strlen($address)) {
                        continue 2;
                    }
                }
                $links[] = [$offset, \strlen($address), 'mailto:'.$address, $address, 'email'];
            }
        }
        if ([] === $links) {
            return;
        }
        usort($links, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $parent = $node->parentNode;
        if (null === $parent) {
            return;
        }
        $pos = 0;
        foreach ($links as [$offset, $length, $href, $label, $class]) {
            if ($offset < $pos) {
                continue;
            }
            if ($offset > $pos) {
                $parent->insertBefore($doc->createTextNode(substr($text, $pos, $offset - $pos)), $node);
            }
            $a = $doc->createElement('a');
            $a->setAttribute('class', $class);
            $a->setAttribute('href', $href);
            $a->textContent = $label;
            $parent->insertBefore($a, $node);
            $pos = $offset + $length;
        }
        if ($pos < \strlen($text)) {
            $parent->insertBefore($doc->createTextNode(substr($text, $pos)), $node);
        }
        $node->remove();
    }
}
