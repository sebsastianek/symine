<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\Wiki;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Netcarver\Textile\Parser as TextileParser;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Port of Redmine's textilizable: renders Textile / CommonMark (GFM) text, sanitizes the HTML
 * and resolves Redmine link syntax (#123, [[Wiki page]], version:"1.0", user#1, @login, r42, ...).
 */
final class TextFormatter
{
    private ?HtmlSanitizer $sanitizer = null;
    private ?\App\I18n\Localizer $i18n = null;
    /** @var list<int> wiki page ids being included (circular inclusion detection) */
    private array $includedPages = [];
    private int $collapseCounter = 0;

    public const string TOC_RE = '#<p>\{\{((<|&lt;)|(>|&gt;))?toc\}\}</p>#i';

    public function __construct(
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly Authorization $auth,
    ) {
    }

    private ?SyntaxHighlighting $syntaxHighlighting = null;

    #[Required]
    public function setSyntaxHighlighting(SyntaxHighlighting $syntaxHighlighting): void
    {
        $this->syntaxHighlighting = $syntaxHighlighting;
    }

    /**
     * Redmine::WikiFormatting::SyntaxHighlight (CommonMark/Textile SyntaxHighlightScrubber): highlights
     * <pre><code class="language-xxx"> (CommonMark) and <code class="xxx"> (Textile) blocks with
     * Rouge compatible token classes; class becomes "xxx syntaxhl", data-language keeps the language.
     */
    private function highlightCodeBlocks(string $html, string $formatting): string
    {
        if (!str_contains($html, '<code') && !str_contains($html, '&lt;code')) {
            return $html;
        }
        $highlighter = $this->syntaxHighlighting ?? new SyntaxHighlighting($this->settings);
        $textile = 'textile' === $formatting;
        if ($textile) {
            // <pre><code class="ruby"> written in Textile is escaped by the parser: restore the code element
            $html = (string) preg_replace_callback('#<pre>\s*&lt;code(\s[^<>]*?)?&gt;(.*?)&lt;/code&gt;\s*</pre>#s', static function (array $m): string {
                $attrs = html_entity_decode($m[1] ?? '', \ENT_QUOTES | \ENT_HTML5);
                $class = preg_match('/\bclass\s*=\s*["\']([^"\'<>]*)["\']/', $attrs, $cm) ? $cm[1] : '';

                return '<pre><code'.('' !== $class ? ' class="'.htmlspecialchars($class).'"' : '').'>'.$m[2].'</code></pre>';
            }, $html);
        }
        $re = $textile ? '#<code class="([^"]*)"([^>]*)>(.*?)</code>#s' : '#(?<=<pre>)<code class="([^"]*)"([^>]*)>(.*?)</code>(?=</pre>)#s';

        return (string) preg_replace_callback($re, static function (array $m) use ($highlighter, $textile): string {
            $class = trim(html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5));
            if ('' === $class) {
                return $m[0];
            }
            if (!$textile) {
                if (!preg_match('/\Alanguage-(\S+)\z/', $class, $lm)) {
                    return $m[0];
                }
                $class = $lm[1];
            }
            $lang = $class;
            $attrs = $m[2];
            $text = html_entity_decode($m[3], \ENT_QUOTES | \ENT_HTML5);
            if ($textile && str_starts_with($text, "\n")) {
                $text = substr($text, 1);
            }
            if (!str_contains($attrs, 'data-language=')) {
                $attrs .= ' data-language="'.htmlspecialchars($lang).'"';
            }
            if ('mermaid' === strtolower($lang)) {
                $attrs .= ' data-controller="mermaid"';
            }
            if ($highlighter->languageSupported($lang)) {
                return '<code class="'.htmlspecialchars($lang).' syntaxhl"'.$attrs.'>'.$highlighter->highlightByLanguage($text, $lang).'</code>';
            }

            // unsupported language: the class attribute is removed
            return '<code'.$attrs.'>'.htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'</code>';
        }, $html);
    }

    #[Required]
    public function setLocalizer(\App\I18n\Localizer $i18n): void
    {
        $this->i18n = $i18n;
    }

    private function t(string $key, array $params = []): string
    {
        return $this->i18n?->l($key, $params) ?? $key;
    }

    /**
     * Options (all optional):
     * - headings: true adds anchors to headings (Redmine parse_headings); false disables them (and the TOC)
     * - edit_section_url: base URL of the wiki edit action; adds "edit section" links before headings (section=N)
     * - wiki_links: 'local' (links to Title.html) or 'anchor' (#Title) for exports
     * - object: the formatted object (WikiPage) used by {{child_pages}} / {{include}} / {{thumbnail}}
     * - attachments: list<Attachment> used by {{thumbnail}}
     *
     * @param array{wiki_links?: bool|string, only_path?: bool, headings?: bool, edit_section_url?: string, object?: object, attachments?: list<\App\Entity\Attachment>} $options
     */
    public function format(?string $text, ?Project $project = null, array $options = []): string
    {
        if (null === $text || '' === trim($text)) {
            return '';
        }
        $formatting = $this->settings->textFormatting();
        $text = str_replace("\r\n", "\n", $text);
        // {{toc}}, {{collapse}} macros are handled before rendering
        [$text, $macros] = $this->extractMacros($text);
        // common_mark: Redmine renders soft line breaks as <br> (common_mark_enable_hardbreaks, default true)
        $html = match ($formatting) {
            'textile' => (new TextileParser('html5'))->setRestricted(false)->setLite(false)->parse($text),
            'common_mark', 'markdown' => (new GithubFlavoredMarkdownConverter(['html_input' => 'allow', 'allow_unsafe_links' => false, 'renderer' => ['soft_break' => "<br />\n"]]))->convert($text)->getContent(),
            default => nl2br(htmlspecialchars($text), false),
        };
        $html = $this->sanitizer()->sanitize($html);
        $html = $this->highlightCodeBlocks($html, $formatting);
        $html = $this->parseRedmineLinks($html, $project, $options);
        $html = $this->renderMacros($html, $macros, $project, $options);
        if (!empty($options['edit_section_url'])) {
            $html = $this->sections($html, (string) $options['edit_section_url']);
        }
        $hasToc = (bool) preg_match(self::TOC_RE, $html);
        if (false !== ($options['headings'] ?? null) && (true === ($options['headings'] ?? null) || $hasToc)) {
            $headings = [];
            $html = $this->headings($html, $headings, $options);
            if ($hasToc) {
                $html = $this->replaceToc($html, $headings);
            }
        } elseif ($hasToc) {
            $html = preg_replace(self::TOC_RE, '', $html) ?? $html;
        }

        return $html;
    }

    private function sanitizer(): HtmlSanitizer
    {
        if (null === $this->sanitizer) {
            $config = (new HtmlSanitizerConfig())
                ->allowSafeElements()
                ->allowElement('a', ['href', 'title', 'class', 'name', 'id', 'rel', 'target'])
                ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'class', 'style'])
                ->allowElement('pre', ['class'])
                ->allowElement('code', ['class', 'data-language'])
                ->allowElement('span', ['class', 'style', 'title'])
                ->allowElement('div', ['class', 'style', 'id'])
                ->allowElement('p', ['class', 'style'])
                ->allowElement('table', ['class', 'style'])
                ->allowElement('td', ['class', 'style', 'colspan', 'rowspan'])
                ->allowElement('th', ['class', 'style', 'colspan', 'rowspan'])
                ->allowElement('input', ['type', 'checked', 'disabled', 'class'])
                ->allowElement('h1', ['id'])->allowElement('h2', ['id'])->allowElement('h3', ['id'])
                ->allowElement('h4', ['id'])->allowElement('h5', ['id'])->allowElement('h6', ['id'])
                ->allowElement('ol', ['start', 'class'])
                ->allowElement('li', ['class'])
                ->allowElement('details', ['open'])->allowElement('summary')
                ->allowRelativeLinks()->allowRelativeMedias()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'ftp', 'ftps'])
                ->withMaxInputLength(5_000_000);
            $this->sanitizer = new HtmlSanitizer($config);
        }

        return $this->sanitizer;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function extractMacros(string $text): array
    {
        $macros = [];
        $i = 0;
        $text = preg_replace_callback('/\{\{(collapse|child_pages|include|recent_pages|issue|thumbnail|hello_world|macro_list)(\((.*?)\))?(\n(.*?)\n)?\}\}/s', function ($m) use (&$macros, &$i) {
            $key = 'SYMINEMACRO'.$i++.'X';
            $macros[$key] = json_encode(['name' => $m[1], 'args' => $m[3] ?? '', 'body' => $m[5] ?? '']);

            return $key;
        }, $text) ?? $text;

        return [$text, $macros];
    }

    /** @param array<string, string> $macros */
    private function renderMacros(string $html, array $macros, ?Project $project, array $options = []): string
    {
        foreach ($macros as $key => $json) {
            $m = json_decode($json, true);
            try {
                $out = match ($m['name']) {
                    'collapse' => $this->collapseMacro($m['args'], $m['body'], $project, $options),
                    'issue' => $this->issueMacro((int) $m['args']),
                    'child_pages' => $this->childPagesMacro($m['args'], $project, $options),
                    'include' => $this->includeMacro($m['args'], $project, $options),
                    'recent_pages' => $this->recentPagesMacro($m['args'], $project),
                    'thumbnail' => $this->thumbnailMacro($m['args'], $options),
                    default => '',
                };
            } catch (\RuntimeException $e) {
                $out = '<div class="flash error">'.$this->t('error_can_not_execute_macro_html', ['name' => htmlspecialchars($m['name']), 'error' => htmlspecialchars($e->getMessage())]).'</div>';
            }
            $html = str_replace(['<p>'.$key.'</p>', $key], $out, $html);
        }

        return $html;
    }

    /** Splits macro arguments "a, b, key=value" into [positional args, options] (Redmine's extract_macro_options) */
    private function macroArgs(string $args, array $keys): array
    {
        $positional = [];
        $opts = [];
        foreach ('' === trim($args) ? [] : array_map('trim', explode(',', $args)) as $arg) {
            if (preg_match('/^('.implode('|', array_map('preg_quote', $keys)).')=(.*)$/', $arg, $mm)) {
                $opts[$mm[1]] = $mm[2];
            } else {
                $positional[] = $arg;
            }
        }

        return [$positional, $opts];
    }

    private function collapseMacro(string $args, string $body, ?Project $project, array $options): string
    {
        $args = '' === trim($args) ? [] : array_map('trim', explode(',', $args));
        $id = 'collapse-'.bin2hex(random_bytes(4));
        $show = $args[0] ?? $this->t('button_show');
        $hide = $args[1] ?? $args[0] ?? $this->t('button_hide');
        $js = "$('#$id-show, #$id-hide').toggle(); $('#$id').fadeToggle(150); return false;";
        $icon = static fn (string $name, string $label, string $extra = '') => '<svg class="s18 icon-svg'.$extra.'" aria-hidden="true"><use href="/assets/icons.svg#icon--'.$name.'"></use></svg><span class="icon-label">'.htmlspecialchars($label).'</span>';

        return '<a id="'.$id.'-show" class="icon icon-collapsed collapsible" href="#" onclick="'.htmlspecialchars($js).'">'.$icon('angle-right', $show, ' icon-rtl').'</a>'
            .'<a id="'.$id.'-hide" class="icon icon-expanded collapsible" style="display:none;" href="#" onclick="'.htmlspecialchars($js).'">'.$icon('angle-down', $hide).'</a>'
            .'<div id="'.$id.'" class="collapsed-text" style="display:none;">'.$this->format($body, $project, ['headings' => false] + array_intersect_key($options, ['object' => 1, 'attachments' => 1])).'</div>';
    }

    /** Wiki.find_page(title, project: project): "project:Title" syntax supported, page must have content */
    private function findWikiPage(string $title, ?Project $project): ?\App\Entity\WikiPage
    {
        if (preg_match('/^([^:]+):(.*)$/', $title, $mm)) {
            $project = $this->em->getRepository(Project::class)->findOneBy(['identifier' => $mm[1]])
                ?? $this->em->getRepository(Project::class)->findOneBy(['name' => $mm[1]]);
            $title = $mm[2];
        }
        if (null === $project) {
            return null;
        }
        $wiki = $this->em->getRepository(Wiki::class)->findOneBy(['project' => $project]);
        if (null === $wiki) {
            return null;
        }
        $title = Wiki::titleize('' === trim($title) ? $wiki->startPage : $title);
        $page = $this->em->createQuery('SELECT p FROM App\Entity\WikiPage p WHERE p.wiki = :w AND LOWER(p.title) = LOWER(:t)')
            ->setParameters(['w' => $wiki, 't' => $title])->setMaxResults(1)->getOneOrNullResult();
        if (null === $page) {
            $redirect = $this->em->createQuery('SELECT r FROM App\Entity\WikiRedirect r WHERE r.wikiId = :w AND LOWER(r.title) = LOWER(:t)')
                ->setParameters(['w' => $wiki->id, 't' => $title])->setMaxResults(1)->getOneOrNullResult();
            if ($redirect) {
                $page = $this->em->createQuery('SELECT p FROM App\Entity\WikiPage p WHERE IDENTITY(p.wiki) = :w AND LOWER(p.title) = LOWER(:t)')
                    ->setParameters(['w' => $redirect->redirectsToWikiId, 't' => (string) $redirect->redirectsTo])->setMaxResults(1)->getOneOrNullResult();
            }
        }

        return $page && $page->content ? $page : null;
    }

    private function wikiPageUrl(\App\Entity\WikiPage $page): string
    {
        return $this->safeUrl('wiki_show', ['project_id' => $page->wiki->project->identifier, 'id' => $page->title]);
    }

    private function childPagesMacro(string $args, ?Project $project, array $options): string
    {
        [$args, $opts] = $this->macroArgs($args, ['parent', 'depth']);
        $depth = isset($opts['depth']) && '' !== $opts['depth'] ? (int) $opts['depth'] : null;
        $object = $options['object'] ?? null;
        if ([] !== $args) {
            $page = $this->findWikiPage($args[0], $project);
        } elseif ($object instanceof \App\Entity\WikiPage) {
            $page = $object;
        } elseif ($object instanceof \App\Entity\WikiContent) {
            $page = $object->page;
        } else {
            throw new \RuntimeException($this->t('error_childpages_macro_no_argument'));
        }
        if (null === $page || !$this->auth->allowedTo('view_wiki_pages', $page->wiki->project)) {
            throw new \RuntimeException($this->t('error_page_not_found'));
        }
        $all = $this->em->createQuery('SELECT p FROM App\Entity\WikiPage p WHERE p.wiki = :w ORDER BY p.title ASC')->setParameter('w', $page->wiki)->getResult();
        $byParent = [];
        foreach ($all as $p) {
            $byParent[$p->parent?->id ?? 0][] = $p;
        }
        // self_and_descendants(depth)
        $tree = [];
        $collect = function (\App\Entity\WikiPage $p, int $level) use (&$collect, &$tree, $byParent, $depth): void {
            $tree[$p->parent?->id ?? 0][] = $p;
            if (null !== $depth && $level >= $depth) {
                return;
            }
            foreach ($byParent[$p->id] ?? [] as $child) {
                $collect($child, $level + 1);
            }
        };
        $collect($page, 0);
        $root = isset($opts['parent']) && '' !== $opts['parent'] ? ($page->parent?->id ?? 0) : $page->id;

        return $this->pageHierarchy($tree, $root);
    }

    /** Port of render_page_hierarchy (pages grouped by parent id, 0 = root) */
    public function pageHierarchy(array $pagesByParent, int $node, ?callable $title = null, ?callable $href = null): string
    {
        if (empty($pagesByParent[$node])) {
            return '';
        }
        $out = "<ul class=\"pages-hierarchy\">\n";
        foreach ($pagesByParent[$node] as $page) {
            $t = $title ? $title($page) : null;
            $out .= '<li><a'.(null !== $t ? ' title="'.htmlspecialchars($t).'"' : '').' href="'.htmlspecialchars($href ? $href($page) : $this->wikiPageUrl($page)).'">'.htmlspecialchars($page->prettyTitle()).'</a>';
            if (!empty($pagesByParent[$page->id])) {
                $out .= "\n".$this->pageHierarchy($pagesByParent, (int) $page->id, $title, $href);
            }
            $out .= "</li>\n";
        }

        return $out."</ul>\n";
    }

    private function includeMacro(string $args, ?Project $project, array $options): string
    {
        $page = $this->findWikiPage(trim(explode(',', $args)[0] ?? ''), $project);
        if (null === $page || !$this->auth->allowedTo('view_wiki_pages', $page->wiki->project)) {
            throw new \RuntimeException($this->t('error_page_not_found'));
        }
        if (\in_array($page->id, $this->includedPages, true)) {
            throw new \RuntimeException($this->t('error_circular_inclusion'));
        }
        $this->includedPages[] = $page->id;
        try {
            $attachments = $this->em->getRepository(\App\Entity\Attachment::class)->findBy(['containerType' => 'WikiPage', 'containerId' => $page->id]);

            return $this->format($page->content->text, $page->wiki->project, ['headings' => false, 'object' => $page, 'attachments' => $attachments]);
        } finally {
            array_pop($this->includedPages);
        }
    }

    private function recentPagesMacro(string $args, ?Project $project): string
    {
        [, $opts] = $this->macroArgs($args, ['days', 'limit', 'time', 'project', 'include_subprojects']);
        if (!empty($opts['project'])) {
            $project = $this->em->getRepository(Project::class)->findOneBy(['identifier' => $opts['project']]);
        }
        if (null === $project) {
            return '';
        }
        $days = (int) ($opts['days'] ?? 0) ?: 7;
        $limit = isset($opts['limit']) && '' !== $opts['limit'] ? (int) $opts['limit'] : null;
        $showTime = 'true' === ($opts['time'] ?? '');
        $projectIds = [];
        if ('true' === ($opts['include_subprojects'] ?? '')) {
            foreach ($project->getSelfAndDescendants() as $p) {
                if ($this->auth->allowedTo('view_wiki_pages', $p)) {
                    $projectIds[] = $p->id;
                }
            }
        } elseif ($this->auth->allowedTo('view_wiki_pages', $project)) {
            $projectIds = [$project->id];
        }
        if ([] === $projectIds) {
            return '';
        }
        $q = $this->em->createQuery('SELECT p, c FROM App\Entity\WikiPage p JOIN p.content c JOIN p.wiki w WHERE IDENTITY(w.project) IN (:ids) AND c.updatedOn >= :since ORDER BY c.updatedOn DESC, p.id DESC')
            ->setParameters(['ids' => $projectIds, 'since' => new \DateTimeImmutable('-'.$days.' days')]);
        if (null !== $limit) {
            $q->setMaxResults($limit);
        }
        $out = '<ul>';
        foreach ($q->getResult() as $page) {
            $out .= '<li><a href="'.htmlspecialchars($this->wikiPageUrl($page)).'">'.htmlspecialchars($page->prettyTitle()).'</a>';
            if ($showTime) {
                $out .= ' ('.htmlspecialchars($this->i18n?->distanceOfTimeInWords($page->content->updatedOn) ?? '').')';
            }
            $out .= '</li>';
        }

        return $out.'</ul>';
    }

    private function thumbnailMacro(string $args, array $options): string
    {
        [$args, $opts] = $this->macroArgs($args, ['size', 'title']);
        $filename = $args[0] ?? '';
        if ('' === $filename) {
            throw new \RuntimeException($this->t('error_filename_required'));
        }
        $size = $opts['size'] ?? null;
        if (null !== $size && !preg_match('/^\d+$/', $size)) {
            throw new \RuntimeException($this->t('error_invalid_size_parameter'));
        }
        $size = (int) $size > 0 ? (int) $size : 200;
        $attachments = $options['attachments'] ?? [];
        $object = $options['object'] ?? null;
        if ([] === $attachments && $object instanceof \App\Entity\WikiPage && null !== $object->id) {
            $attachments = $this->em->getRepository(\App\Entity\Attachment::class)->findBy(['containerType' => 'WikiPage', 'containerId' => $object->id]);
        }
        $found = null;
        foreach ($attachments as $a) {
            if (0 === strcasecmp($a->filename, $filename) && (null === $found || $a->createdOn >= $found->createdOn)) {
                $found = $a;
            }
        }
        if (null === $found) {
            throw new \RuntimeException($this->t('error_attachment_not_found', ['name' => $filename]));
        }
        $title = $opts['title'] ?? ($found->description ? $found->filename.' ('.$found->description.')' : $found->filename);

        return '<a class="thumbnail" title="'.htmlspecialchars($title).'" href="/attachments/'.$found->id.'"><img alt="'.htmlspecialchars($found->filename).'" src="/attachments/thumbnail/'.$found->id.'/'.$size.'" /></a>';
    }

    private function issueMacro(int $id): string
    {
        $issue = $this->em->find(Issue::class, $id);
        if (!$issue || !$this->auth->allowedTo('view_issues', $issue->project)) {
            return '#'.$id;
        }

        return '<a href="'.$this->urls->generate('issue_show', ['id' => $id]).'" class="issue">'.htmlspecialchars($issue->tracker->name.' #'.$id.': '.$issue->subject).'</a>';
    }

    private const string HEADING_RE = '#(<h(\d)( [^>]+)?>(.+?)</h(\d)>)#is';

    /** Redmine parse_sections: "edit section" links before each heading but the first */
    private function sections(string $html, string $editUrl): string
    {
        $section = 0;
        $sep = str_contains($editUrl, '?') ? '&' : '?';

        return preg_replace_callback(self::HEADING_RE, function ($m) use (&$section, $editUrl, $sep) {
            ++$section;
            if ($section <= 1) {
                return $m[1];
            }
            $label = $this->t('button_edit_section');

            return '<div class="contextual heading-'.$m[2].'" title="'.htmlspecialchars($label).'" id="section-'.$section.'"><a class="icon-only icon-edit" href="'.htmlspecialchars($editUrl.$sep.'section='.$section).'"><svg class="s18 icon-svg" aria-hidden="true"><use href="/assets/icons.svg#icon--edit"></use></svg><span class="icon-label">'.htmlspecialchars($label).'</span></a></div>'.$m[1];
        }, $html) ?? $html;
    }

    /** Redmine parse_headings: adds anchors to headings; collects [level, anchor, label] */
    private function headings(string $html, array &$headings, array $options): string
    {
        $anchors = [];

        return preg_replace_callback(self::HEADING_RE, function ($m) use (&$headings, &$anchors, $options) {
            $level = (int) $m[2];
            $attrs = preg_replace('/\s*id="[^"]*"/', '', $m[3]);
            $content = preg_replace('#<a href="\#[^"]*" class="wiki-anchor">&para;</a>$#', '', $m[4]) ?? $m[4];
            $item = trim(html_entity_decode(strip_tags($content), \ENT_QUOTES | \ENT_HTML5));
            $anchor = preg_replace('/\s+(\-+\s*)?/u', '-', preg_replace('/[^\s\-\p{L}\p{N}_]/u', '', $item) ?? '') ?? '';
            if ('anchor' === ($options['wiki_links'] ?? null) && ($options['object'] ?? null) instanceof \App\Entity\WikiPage) {
                $anchor = $options['object']->title.'_'.$anchor;
            }
            $anchors[$anchor] = ($anchors[$anchor] ?? 0) + 1;
            if ($anchors[$anchor] > 1) {
                $anchor .= '-'.$anchors[$anchor];
            }
            $headings[] = [$level, $anchor, htmlspecialchars($item)];

            return '<a name="'.htmlspecialchars($anchor).'"></a>'."\n".'<h'.$level.($attrs ?: '').'>'.$content.'<a href="#'.htmlspecialchars($anchor).'" class="wiki-anchor">&para;</a></h'.$level.'>';
        }, $html) ?? $html;
    }

    /** Redmine replace_toc */
    private function replaceToc(string $html, array $headings): string
    {
        return preg_replace_callback(self::TOC_RE, function ($m) use ($headings) {
            $headings = array_values(array_filter($headings, static fn ($h) => $h[0] <= 4));
            if ([] === $headings) {
                return '';
            }
            $class = 'toc'.(!empty($m[3]) ? ' right' : '').(!empty($m[2]) ? ' left' : '');
            $out = '<ul class="'.$class.'"><li><strong>'.htmlspecialchars($this->t('label_table_of_contents')).'</strong></li><li>';
            $root = min(array_column($headings, 0));
            $current = $root;
            $started = false;
            foreach ($headings as [$level, $anchor, $item]) {
                if ($level > $current) {
                    $out .= str_repeat('<ul><li>', $level - $current);
                } elseif ($level < $current) {
                    $out .= str_repeat("</li></ul>\n", $current - $level).'</li><li>';
                } elseif ($started) {
                    $out .= '</li><li>';
                }
                $out .= '<a href="#'.htmlspecialchars($anchor).'">'.$item.'</a>';
                $current = $level;
                $started = true;
            }
            $out .= str_repeat('</li></ul>', $current - $root);

            return $out.'</li></ul>';
        }, $html) ?? $html;
    }

    /** Resolves Redmine link syntax in text nodes (not inside <a>, <pre>, <code>). */
    private function parseRedmineLinks(string $html, ?Project $project, array $options = []): string
    {
        $parts = preg_split('#(<pre\b.*?</pre>|<code\b.*?</code>|<a\b.*?</a>|<[^>]+>)#s', $html, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        foreach ($parts as $i => $part) {
            if ('' === $part || '<' === $part[0]) {
                continue;
            }
            $part = $this->wikiLinks($part, $project, $options['wiki_links'] ?? null);
            $parts[$i] = $this->objectLinks($part, $project);
        }

        return implode('', $parts);
    }

    private function wikiLinks(string $text, ?Project $project, mixed $mode = null): string
    {
        return preg_replace_callback('/(!)?\[\[([^\]\n|]+?)(\|([^\]\n]+?))?\]\]/', function ($m) use ($project, $mode) {
            if ('!' === $m[1]) {
                return substr($m[0], 1);
            }
            $target = html_entity_decode($m[2]);
            $label = isset($m[4]) && '' !== $m[4] ? $m[4] : null;
            $linkProject = $project;
            if (preg_match('/^([^:]+):(.*)$/', $target, $pm)) {
                $p = $this->em->getRepository(Project::class)->findOneBy(['identifier' => $pm[1]])
                    ?? $this->em->getRepository(Project::class)->findOneBy(['name' => $pm[1]]);
                if ($p) {
                    $linkProject = $p;
                    $target = $pm[2];
                    $label ??= '' === $target ? $p->name : null;
                }
            }
            if (null === $linkProject) {
                return $m[0];
            }
            $anchor = null;
            if (str_contains($target, '#')) {
                [$target, $anchor] = explode('#', $target, 2);
            }
            $title = Wiki::titleize($target);
            $label ??= $target;
            $exists = '' !== $title && (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM wiki_pages wp JOIN wikis w ON w.id = wp.wiki_id WHERE w.project_id = ? AND LOWER(wp.title) = LOWER(?)',
                [$linkProject->id, $title]
            ) > 0;
            try {
                $url = $this->urls->generate('wiki_show', ['project_id' => $linkProject->identifier, 'id' => '' === $title ? 'Wiki' : $title]);
            } catch (\Throwable) {
                $url = '/projects/'.$linkProject->identifier.'/wiki/'.rawurlencode($title);
            }
            if ('local' === $mode) {
                $url = ('' === $title ? 'index' : $title).'.html';
            } elseif ('anchor' === $mode) {
                $url = '#'.$title;
            }
            if ($anchor) {
                $url = ('anchor' === $mode ? '#'.$title.'_' : ('local' === $mode ? $url.'#' : $url.'#')).preg_replace('/[^\s\-\p{L}\p{N}_]/u', '', $anchor);
            }

            return '<a href="'.htmlspecialchars($url).'" class="wiki-page'.($exists ? '' : ' new').'">'.$label.'</a>';
        }, $text) ?? $text;
    }

    private function objectLinks(string $text, ?Project $project): string
    {
        // ##123 (with subject), #123, version#3, version:"1.0", user#2, user:login, @login, document#1, project#1, r123, commit:abc
        $pattern = '/(?<=^|[\s\(\[,\-\.>]|&gt;)(!)?(([a-z0-9\-_]+):)?(version|user|document|project|news|message|attachment|commit|source|export|forum)?((#+|r)(\d+)|(:)([^"\s<>][^\s<>]*?|"[^"]+?"))(?=(?=[[:punct:]][^A-Za-z0-9_\/])|,|\s|\]|<|$)/u';
        $text = preg_replace_callback($pattern, function ($m) use ($project) {
            if ('!' === ($m[1] ?? '')) {
                return substr($m[0], 1);
            }
            $prefix = $m[4] ?? '';
            $sep = $m[6] ?? '';
            $id = isset($m[7]) && '' !== $m[7] ? (int) $m[7] : null;
            $name = isset($m[9]) && '' !== $m[9] ? trim(html_entity_decode($m[9]), '"') : null;
            $out = null;
            if ('' === $prefix && null !== $id && str_starts_with($sep, '#')) {
                $issue = $this->em->find(Issue::class, $id);
                if ($issue && $this->auth->allowedTo('view_issues', $issue->project)) {
                    $url = $this->urls->generate('issue_show', ['id' => $id]);
                    $cls = 'issue tracker-'.$issue->tracker->id.' status-'.$issue->status->id.($issue->isClosed() ? ' closed' : '');
                    $label = '##' === $sep ? $issue->tracker->name.' #'.$id.': '.$issue->subject : '#'.$id;
                    $out = '<a href="'.htmlspecialchars($url).'" class="'.$cls.'" title="'.htmlspecialchars(mb_strimwidth($issue->subject, 0, 100, '...').' ('.$issue->status->name.')').'">'.htmlspecialchars($label).'</a>';
                }
            } elseif ('version' === $prefix) {
                $v = null;
                if (null !== $id) {
                    $v = $this->em->find(Version::class, $id);
                } elseif (null !== $name && $project) {
                    $v = $this->em->getRepository(Version::class)->findOneBy(['project' => $project, 'name' => $name]);
                }
                if ($v && $this->auth->allowedTo('view_issues', $v->project)) {
                    $out = '<a href="'.htmlspecialchars($this->safeUrl('version_show', ['id' => $v->id])).'" class="version">'.htmlspecialchars((string) $v->name).'</a>';
                }
            } elseif ('user' === $prefix) {
                $u = null !== $id ? $this->em->find(User::class, $id) : (null !== $name ? $this->em->getRepository(User::class)->findOneBy(['login' => $name]) : null);
                if ($u instanceof User && $u->isLogged()) {
                    $out = '<a href="'.htmlspecialchars($this->safeUrl('user_show', ['id' => $u->id])).'" class="user active">'.htmlspecialchars($u->getName()).'</a>';
                }
            } elseif ('project' === $prefix) {
                $p = null !== $id ? $this->em->find(Project::class, $id) : (null !== $name ? $this->em->getRepository(Project::class)->findOneBy(['identifier' => $name]) : null);
                if ($p && $this->auth->isProjectVisible($p)) {
                    $out = '<a href="'.htmlspecialchars($this->safeUrl('project_show', ['id' => $p->identifier])).'" class="project">'.htmlspecialchars($p->name).'</a>';
                }
            } elseif ('document' === $prefix && null !== $id) {
                $d = $this->em->find(\App\Entity\Document::class, $id);
                if ($d && $this->auth->allowedTo('view_documents', $d->project)) {
                    $out = '<a href="'.htmlspecialchars($this->safeUrl('document_show', ['id' => $d->id])).'" class="document">'.htmlspecialchars($d->title).'</a>';
                }
            } elseif ('news' === $prefix && null !== $id) {
                $n = $this->em->find(\App\Entity\News::class, $id);
                if ($n && $n->project && $this->auth->allowedTo('view_news', $n->project)) {
                    $out = '<a href="'.htmlspecialchars($this->safeUrl('news_show', ['id' => $n->id])).'" class="news">'.htmlspecialchars($n->title).'</a>';
                }
            } elseif ('message' === $prefix && null !== $id) {
                $msg = $this->em->find(\App\Entity\Message::class, $id);
                if ($msg && $this->auth->allowedTo('view_messages', $msg->board->project)) {
                    $out = '<a href="'.htmlspecialchars($this->safeUrl('board_message_show', ['board_id' => $msg->board->id, 'project_id' => $msg->board->project->identifier, 'id' => $msg->id])).'" class="message">'.htmlspecialchars($msg->subject).'</a>';
                }
            }

            return $out ?? $m[0];
        }, $text) ?? $text;

        // @login mentions
        return preg_replace_callback('/(?<=^|\s|>)@([A-Za-z0-9_\-\.@]*[A-Za-z0-9])/', function ($m) {
            $u = $this->em->getRepository(User::class)->findOneBy(['login' => $m[1]]);
            if (!$u instanceof User || !$u->isLogged()) {
                return $m[0];
            }

            return '<a href="'.htmlspecialchars($this->safeUrl('user_show', ['id' => $u->id])).'" class="user active user-mention">@'.htmlspecialchars($u->getName()).'</a>';
        }, $text) ?? $text;
    }

    private function safeUrl(string $route, array $params): string
    {
        try {
            return $this->urls->generate($route, $params);
        } catch (\Throwable) {
            return '#';
        }
    }

    /** Plain-text version (for e-mails / feeds) */
    public function toText(?string $text): string
    {
        return trim(html_entity_decode(strip_tags($this->format($text))));
    }
}
