<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\Board;
use App\Entity\Changeset;
use App\Entity\Document;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\Member;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Repository;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\Wiki;
use App\Entity\WikiContent;
use App\Entity\WikiPage;
use App\Entity\WikiRedirect;
use App\Security\Authorization;
use App\Service\Scm\ScmTwigExtension;
use App\Service\Text\HtmlPipeline;
use App\Service\Text\MarkupConverter;
use App\Service\Text\MarkupConverterInterface;
use App\Service\Text\RenderContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Port of Redmine's textilizable (ApplicationHelper): renders Textile / CommonMark text, sanitizes the HTML
 * ({@see HtmlPipeline}), runs the wiki macros and resolves Redmine links (#123, r42, commit:abc, source:file,
 * attachment:file, [[Wiki page]], version:"1.0", document:Title, forum:Name, user:login, @login ...).
 */
final class TextFormatter implements ResetInterface
{
    public const string TOC_RE = '#<p>\{\{((<|&lt;)|(>|&gt;))?toc\}\}</p>#i';

    /** ApplicationHelper::MACROS_RE: 1 escape, 2 macro, 3 name, 5 arguments, 6 block of text */
    private const string MACROS_RE = '/(!)?(\{\{(\w+)(\(([^\n\r]*?)\))?([\n\r].*?[\n\r])?\}\})/su';

    private const string HEADING_RE = '#(<h(\d)( [^>]+)?>(.+?)</h(\d)>)#is';

    /** ApplicationHelper::LINKS_RE; tags (and whole links) are skipped so that nothing is replaced inside them */
    private const string LINKS_RE = '~
        <a(?:\s[^>]*)?>(?s:.*?)</a>(*SKIP)(*F)|
        <[^>]*>(*SKIP)(*F)|
        (?<leading>[\s(,\-\[]|(?<=>)|^)
        (?<esc>!)?
        (?<project_prefix>(?<project_identifier>[a-z0-9\-_]+):)?
        (?<prefix>attachment|document|version|forum|news|message|project|commit|source|export|user)?
        (?:
          (?:
            (?:(?<sep1>\#\#?)|(?:(?<repo_prefix>(?<repo_identifier>[a-z0-9\-_]+)\|)?(?<sep2>r)))
            (?<identifier1>\d+|note)
            (?<comment_suffix>(?:\#note)?-(?<comment_id>\d+))?
          )|
          (?:(?<sep3>:)(?<identifier2>[^"\s<>][^\s<>]*?|"[^"]+?"))|
          (?:(?<sep4>@)(?<identifier3>[A-Za-z0-9_\-@.]*?))
        )
        (?=(?=[[:punct:]][^A-Za-z0-9_/])|,|\s|\]|<|$)
    ~xmu';

    /**
     * Redmine::WikiFormatting::Macros: name => [description, accepts a block of text, output is part of the page sections].
     *
     * @var array<string, array{0: string, 1: bool, 2: bool}>
     */
    private const array MACROS = [
        'hello_world' => ['Sample macro.', true, false],
        'macro_list' => ['Displays a list of all available macros, including description if available.', false, false],
        'child_pages' => ["Displays a list of child pages. With no argument, it displays the child pages of the current wiki page. Examples:\n\n"
            ."{{child_pages}} -- can be used from a wiki page only\n"
            ."{{child_pages(depth=2)}} -- display 2 levels nesting only\n"
            ."{{child_pages(Foo)}} -- lists all children of page Foo\n"
            .'{{child_pages(Foo, parent=1)}} -- same as above with a link to page Foo', false, false],
        'recent_pages' => ["Displays a list of recently updated wiki pages. With no argument, it displays pages that have been updated within the last 7 days. Examples:\n\n"
            ."{{recent_pages}} -- displays pages updated within the last 7 days\n"
            ."{{recent_pages(days=3)}} -- displays pages updated within the last 3 days\n"
            ."{{recent_pages(limit=5)}} -- limits the maximum number of pages to display to 5\n"
            ."{{recent_pages(time=true)}} -- displays pages updated within the last 7 days with updated time\n"
            ."{{recent_pages(project=identifier)}} -- displays pages updated within the last 7 days from a specific project\n"
            .'{{recent_pages(include_subprojects=true)}} -- includes pages from subprojects', false, false],
        'include' => ["Includes a wiki page. Examples:\n\n"
            ."{{include(Foo)}}\n"
            .'{{include(projectname:Foo)}} -- to include a page of a specific project wiki', false, false],
        'collapse' => ["Inserts of collapsed block of text. Examples:\n\n"
            ."{{collapse\nThis is a block of text that is collapsed by default.\nIt can be expanded by clicking a link.\n}}\n\n"
            ."{{collapse(View details...)\nWith custom link text.\n}}", true, true],
        'thumbnail' => ["Displays a clickable thumbnail of an attached image.\n"
            ."Default size is 200 pixels. Examples:\n\n"
            ."{{thumbnail(image.png)}}\n"
            .'{{thumbnail(image.png, size=300, title=Thumbnail)}} -- with custom title and size', false, false],
        'issue' => ["Displays an issue link including additional information. Examples:\n\n"
            ."{{issue(123)}}                              -- Issue #123: Enhance macro capabilities\n"
            ."{{issue(123, project=true)}}                -- Andromeda - Issue #123: Enhance macro capabilities\n"
            ."{{issue(123, tracker=false)}}               -- #123: Enhance macro capabilities\n"
            ."{{issue(123, subject=false, project=true)}} -- Andromeda - Issue #123\n", false, false],
    ];

    private ?\App\I18n\Localizer $i18n = null;
    private ?SyntaxHighlighting $syntaxHighlighting = null;
    private ?IssueService $issueService = null;
    private ?HtmlPipeline $pipeline = null;
    private readonly MarkupConverterInterface $markup;

    /** @var list<int> wiki page ids being included (circular inclusion detection) */
    private array $includedPages = [];
    /** @var list<RenderContext> */
    private array $contexts = [];

    // per request caches (see reset())
    /** @var array<int, Issue|false> */
    private array $issueCache = [];
    /** @var array<string, list<Attachment>> */
    private array $attachmentCache = [];
    /** @var array<int, Wiki|false> */
    private array $wikiCache = [];
    /** @var array<int, array<string, WikiPage|false>> wiki id => lower case title => page */
    private array $wikiPageCache = [];
    /** @var array<string, Project|false> */
    private array $projectCache = [];
    /** @var array<string, User|false> */
    private array $userCache = [];
    /** @var array<int, list<Repository>> */
    private array $repositoryCache = [];
    private ?bool $viewAllActiveUsers = null;

    /** id of the user $viewAllActiveUsers was computed for */
    private ?int $viewAllActiveUsersFor = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly Authorization $auth,
        ?MarkupConverterInterface $markup = null,
    ) {
        $this->markup = $markup ?? new MarkupConverter();
    }

    #[Required]
    public function setSyntaxHighlighting(SyntaxHighlighting $syntaxHighlighting): void
    {
        $this->syntaxHighlighting = $syntaxHighlighting;
    }

    #[Required]
    public function setLocalizer(\App\I18n\Localizer $i18n): void
    {
        $this->i18n = $i18n;
    }

    #[Required]
    public function setIssueService(IssueService $issueService): void
    {
        $this->issueService = $issueService;
    }

    public function reset(): void
    {
        $this->includedPages = [];
        $this->contexts = [];
        $this->issueCache = [];
        $this->attachmentCache = [];
        $this->wikiCache = [];
        $this->wikiPageCache = [];
        $this->projectCache = [];
        $this->userCache = [];
        $this->repositoryCache = [];
        $this->viewAllActiveUsers = null;
        $this->viewAllActiveUsersFor = null;
    }

    private function t(string $key, array $params = []): string
    {
        return $this->i18n?->l($key, $params) ?? $key;
    }

    private static function h(?string $text): string
    {
        return htmlspecialchars((string) $text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Options (all optional):
     * - object: the formatted object (Issue, Journal, News, Message, Document, WikiPage, WikiContent...); its
     *   attachments are used by inline images, attachment: links and {{thumbnail}}, its page by {{child_pages}}
     * - attachments: list<Attachment> more attachments (the pending uploads of a preview for example)
     * - container: [type, id] (['Issue', 12]) when the object itself is not at hand: its attachments are used
     * - inline_attachments: false leaves images alone
     * - only_path: false generates absolute URLs (feeds, e-mails)
     * - headings: false does not add anchors to headings (and disables the TOC)
     * - edit_section_url: base URL of the wiki edit action; adds "edit section" links before headings (section=N)
     * - wiki_links: 'local' (links to Title.html) or 'anchor' (#Title) for exports
     * - formatting: 'textile' / 'common_mark' instead of the text formatting setting
     *
     * @param array{wiki_links?: bool|string|null, only_path?: bool, headings?: bool, edit_section_url?: string|null, object?: object|null, attachments?: list<Attachment>, inline_attachments?: bool, formatting?: string} $options
     */
    public function format(?string $text, ?Project $project = null, array $options = []): string
    {
        if (null === $text || '' === trim($text)) {
            return '';
        }
        $object = $options['object'] ?? null;
        $project ??= $this->projectOf($object);
        $parent = [] === $this->contexts ? null : $this->contexts[array_key_last($this->contexts)];
        $onlyPath = \array_key_exists('only_path', $options) ? false !== $options['only_path'] : ($parent?->onlyPath ?? true);
        // formatting: false displays the text as it is (links are still resolved)
        $formatting = false === ($options['formatting'] ?? null) ? 'unformatted' : (string) ($options['formatting'] ?? $this->settings->textFormatting());
        if ('markdown' === $formatting) {
            $formatting = 'common_mark';
        }
        $ctx = new RenderContext($project, $object, $options, $onlyPath, $formatting, fn (): array => $this->attachmentsOf($object, $options['attachments'] ?? [], $options['container'] ?? null));
        $this->contexts[] = $ctx;
        try {
            return $this->render(str_replace(["\r\n", "\r"], "\n", $text), $ctx);
        } finally {
            array_pop($this->contexts);
        }
    }

    private function render(string $text, RenderContext $ctx): string
    {
        $options = $ctx->options;
        $text = $this->catchMacros($text, $ctx);

        if ('textile' === $ctx->formatting || 'common_mark' === $ctx->formatting) {
            // the conversion alone (cached when "Cache formatted text" is on): everything that depends on the
            // reader or on the project happens below, on its result
            $html = $this->markup->toHtml($ctx->formatting, $text);
        } elseif ('unformatted' === $ctx->formatting) {
            $html = self::h($text);
        } else {
            // Redmine::WikiFormatting::NullFormatter: escaped text, auto links and simple_format paragraphs
            $html = '';
            foreach (preg_split('/\n\n+/', trim($text, "\n")) ?: [] as $paragraph) {
                $html .= ('' === $html ? '' : "\n\n").'<p>'.str_replace("\n", "\n<br />", self::h($paragraph)).'</p>';
            }
        }
        if ('unformatted' !== $ctx->formatting) {
            $context = false === ($options['inline_attachments'] ?? null) ? [] : [
                'attachments' => $ctx->attachments(...),
                'attachment_url' => fn (Attachment $a): string => $this->url($ctx, 'attachment_download', ['id' => $a->id, 'filename' => $a->filename]),
            ];
            // the null formatter auto links like Textile does
            $html = $this->pipeline()->process($html, 'common_mark' === $ctx->formatting ? 'common_mark' : 'textile', $context + ['macro_token' => $ctx->macroToken]);
        }

        $sectionUrl = (string) ($options['edit_section_url'] ?? '');
        if ('' !== $sectionUrl) {
            // the output of macros whose headings are not sections of the page (include...) is hidden
            // while the sections are numbered, so that it does not get edit links
            $ctx->hiddenMacros = [];
        }
        $this->prefetchIssues($html);
        $html = $this->parseNonPreBlocks($html, $ctx);
        if ('' !== $sectionUrl) {
            $html = $this->parseSections($html, $sectionUrl);
            $hidden = $ctx->hiddenMacros ?? [];
            $ctx->hiddenMacros = null;
            if ([] !== $hidden) {
                $html = preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $hidden[(int) $m[1]] ?? $m[0], $html) ?? $html;
            }
        }

        $hasToc = false !== stripos($html, 'toc}}') && preg_match(self::TOC_RE, $html);
        if (false !== ($options['headings'] ?? null)) {
            $headings = [];
            $html = $this->parseHeadings($html, $headings, $ctx);
            if ($hasToc && [] !== $headings) {
                $html = $this->replaceToc($html, $headings);
            }
        } elseif ($hasToc && 1 === \count($this->contexts)) {
            $html = preg_replace(self::TOC_RE, '', $html) ?? $html;
        }

        return $html;
    }

    private function pipeline(): HtmlPipeline
    {
        return $this->pipeline ??= new HtmlPipeline($this->syntaxHighlighting ?? new SyntaxHighlighting($this->settings), $this->settings, $this->i18n);
    }

    private function url(RenderContext $ctx, string $route, array $params = []): string
    {
        try {
            return $this->urls->generate($route, $params, $ctx->onlyPath ? UrlGeneratorInterface::ABSOLUTE_PATH : UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return '#';
        }
    }

    /** Makes a path built by hand ("/projects/x/repository/...") absolute when only_path is false */
    private function absolute(RenderContext $ctx, string $path): string
    {
        if ($ctx->onlyPath) {
            return $path;
        }
        $context = $this->urls->getContext();
        $scheme = $context->getScheme();
        $port = 'https' === $scheme ? $context->getHttpsPort() : $context->getHttpPort();

        return $scheme.'://'.$context->getHost().(\in_array($port, [80, 443], true) ? '' : ':'.$port).$context->getBaseUrl().$path;
    }

    private function projectOf(?object $object): ?Project
    {
        return match (true) {
            $object instanceof Journal => $object->issue->project,
            $object instanceof WikiPage => $object->wiki->project,
            $object instanceof WikiContent => $object->page->wiki->project,
            $object instanceof Message => $object->board->project,
            $object instanceof Project => $object,
            null !== $object && property_exists($object, 'project') && isset($object->project) && $object->project instanceof Project => $object->project,
            default => null,
        };
    }

    // ------------------------------------------------------------------------------------------
    // Attachments of the formatted object
    // ------------------------------------------------------------------------------------------

    /**
     * options[:attachments] + obj.attachments (the issue's for a journal), most recent first.
     *
     * @param list<Attachment> $extra
     *
     * @return list<Attachment>
     */
    private function attachmentsOf(?object $object, array $extra, ?array $reference = null): array
    {
        $container = match (true) {
            $object instanceof Journal => $object->issue,
            $object instanceof WikiContent => $object->page,
            default => $object,
        };
        $type = match (true) {
            $container instanceof Issue => 'Issue',
            $container instanceof WikiPage => 'WikiPage',
            $container instanceof News => 'News',
            $container instanceof Message => 'Message',
            $container instanceof Document => 'Document',
            $container instanceof Version => 'Version',
            $container instanceof Project => 'Project',
            default => null,
        };
        $attachments = array_values(array_filter($extra, static fn ($a): bool => $a instanceof Attachment));
        $id = null !== $type ? $container->id : null;
        if (null === $id && \is_array($reference) && \is_string($reference[0] ?? null) && is_numeric($reference[1] ?? null)) {
            [$type, $id] = [$reference[0], (int) $reference[1]];
        }
        if (null !== $type && null !== $id) {
            $key = $type.':'.$id;
            $this->attachmentCache[$key] ??= $this->em->getRepository(Attachment::class)->findBy(['containerType' => $type, 'containerId' => $id]);
            foreach ($this->attachmentCache[$key] as $a) {
                if (!\in_array($a, $attachments, true)) {
                    $attachments[] = $a;
                }
            }
        }
        usort($attachments, static fn (Attachment $a, Attachment $b): int => [$b->createdOn, (int) $b->id] <=> [$a->createdOn, (int) $a->id]);

        return $attachments;
    }

    /** Attachment.latest_attach */
    private function latestAttachment(RenderContext $ctx, string $filename): ?Attachment
    {
        foreach ($ctx->attachments() as $a) {
            if (mb_strtolower($a->filename) === mb_strtolower($filename)) {
                return $a;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------------------------------
    // Macros
    // ------------------------------------------------------------------------------------------

    /** catch_macros: the macros are taken out of the text before it is formatted */
    private function catchMacros(string $text, RenderContext $ctx): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        // The placeholder is derived from the text, so that the same text always gives the same converted text
        // (the formatted text cache is keyed by it), and it is never something the text already contains.
        $salt = 0;
        do {
            $ctx->macroToken = 'xmacro'.substr(hash('xxh128', $salt++.':'.$text), 0, 12).'n';
        } while (str_contains($text, $ctx->macroToken));

        return preg_replace_callback(self::MACROS_RE, static function (array $m) use ($ctx): string {
            // {{macro(1)}} is what Redmine itself puts in place of a macro: typed in a text it is displayed
            if (!isset(self::MACROS[strtolower($m[3])]) && !('macro' === $m[3] && preg_match('/\A\d+\z/', $m[5] ?? ''))) {
                return $m[0];
            }
            $index = \count($ctx->macros);
            $ctx->macros[$index] = $m[0];

            return $ctx->macroToken.$index.'x';
        }, $text) ?? $text;
    }

    /** inject_macros: runs the macros (or puts their text back, escaped, inside <pre> and <code>) */
    private function injectMacros(string $text, RenderContext $ctx, bool $execute): string
    {
        if ('' === $ctx->macroToken || !str_contains($text, $ctx->macroToken)) {
            return $text;
        }

        return preg_replace_callback('#(<p>\s*)?'.$ctx->macroToken.'(\d+)([xd])(\s*</p>)?#', function (array $m) use ($ctx, $execute): string {
            $open = $m[1] ?? '';
            $close = $m[4] ?? '';
            $orig = $ctx->macros[(int) $m[2]] ?? null;
            unset($ctx->macros[(int) $m[2]]);
            if (null === $orig || !preg_match(self::MACROS_RE, $orig, $mm)) {
                return $open.$close;
            }
            // "d": the macro was written inside an attribute (HtmlPipeline marks those). It is displayed, never run:
            // its output would rewrite a URL after the HTML was sanitized (href="java{{macro}}script:...").
            if (!$execute || 'd' === $m[3]) {
                return $open.self::h($orig).$close;
            }
            if ('' !== $mm[1] || !isset(self::MACROS[strtolower($mm[3])])) {
                // !{{macro}} displays the macro
                return $open.self::h($mm[2]).$close;
            }
            $name = strtolower($mm[3]);
            $block = isset($mm[6]) && '' !== $mm[6] ? trim($mm[6]) : null;
            $hide = null !== $ctx->hiddenMacros && !self::MACROS[$name][2];
            $hidden = $ctx->hiddenMacros;
            if ($hide) {
                // the whole output is hidden, macros nested in this one don't need to be
                $ctx->hiddenMacros = null;
            }
            try {
                $out = $this->execMacro($name, $mm[5] ?? '', $block, $ctx);
            } finally {
                $ctx->hiddenMacros = $hidden;
            }
            // a macro alone in its paragraph that outputs a block replaces the paragraph
            $unwrap = '' !== $open && '' !== $close && preg_match('/\A\s*<(div|ul|ol|dl|p|table|pre|h[1-6]|blockquote|a id="collapse-)/', $out);
            if ($hide) {
                $ctx->hiddenMacros[] = $out;
                $out = "\x00".(\count($ctx->hiddenMacros) - 1)."\x00";
            }

            return $unwrap ? $out : $open.$out.$close;
        }, $text) ?? $text;
    }

    /** exec_macro */
    private function execMacro(string $name, string $args, ?string $block, RenderContext $ctx): string
    {
        // split the arguments on commas, but only if the comma is not within double quotes
        $list = '' === trim($args) ? [] : array_map(
            static fn (string $a): string => trim((string) preg_replace('/^\s*"(.*)"\s*$/', '$1', $a)),
            preg_split('/\s*,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $args) ?: []
        );
        try {
            if (null !== $block && !self::MACROS[$name][1]) {
                throw new \RuntimeException($this->t('error_macro_does_not_accept_block'));
            }

            return match ($name) {
                'hello_world' => self::h('Hello world! Object: '.(null === $ctx->object ? 'NilClass' : (new \ReflectionClass($ctx->object))->getShortName()).', '
                    .([] === $list ? 'Called with no argument' : 'Arguments: '.implode(', ', $list))
                    .' and '.(null !== $block && '' !== $block ? 'a '.\strlen($block).' bytes long block of text.' : 'no block of text.')),
                'macro_list' => $this->macroListMacro(),
                'collapse' => $this->collapseMacro($list, (string) $block, $ctx),
                'issue' => $this->issueMacro($list, $ctx),
                'child_pages' => $this->childPagesMacro($list, $ctx),
                'include' => $this->includeMacro($list, $ctx),
                'recent_pages' => $this->recentPagesMacro($list, $ctx),
                'thumbnail' => $this->thumbnailMacro($list, $ctx),
            };
        } catch (\RuntimeException $e) {
            return '<div class="flash error">'.$this->t('error_can_not_execute_macro_html', ['name' => self::h($name), 'error' => self::h($e->getMessage())]).'</div>';
        }
    }

    /**
     * Redmine's extract_macro_options: trailing "key=value" arguments are options.
     *
     * @param list<string> $args
     * @param list<string> $keys
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function macroOptions(array $args, array $keys): array
    {
        $options = [];
        while ([] !== $args && preg_match('/^(.+?)=(.+)$/s', trim((string) end($args)), $m) && \in_array(strtolower($m[1]), $keys, true)) {
            $options[strtolower($m[1])] = $m[2];
            array_pop($args);
        }

        return [$args, $options];
    }

    private function macroListMacro(): string
    {
        $out = '';
        foreach (self::MACROS as $name => [$description]) {
            $out .= '<dt><code>'.self::h($name).'</code></dt><dd><pre>'.self::h($description).'</pre></dd>';
        }

        return '<dl>'.$out.'</dl>';
    }

    /** @param list<string> $args */
    private function collapseMacro(array $args, string $body, RenderContext $ctx): string
    {
        $id = 'collapse-'.bin2hex(random_bytes(4));
        $show = $args[0] ?? $this->t('button_show');
        $hide = $args[1] ?? $args[0] ?? $this->t('button_hide');
        $js = "$('#$id-show, #$id-hide').toggle(); $('#$id').fadeToggle(150); return false;";
        $icon = static fn (string $name, string $label, string $extra = '') => '<svg class="s18 icon-svg'.$extra.'" aria-hidden="true"><use href="/assets/icons.svg#icon--'.$name.'"></use></svg><span class="icon-label">'.self::h($label).'</span>';

        return '<a id="'.$id.'-show" class="icon icon-collapsed collapsible" href="#" onclick="'.self::h($js).'">'.$icon('angle-right', $show, ' icon-rtl').'</a>'
            .'<a id="'.$id.'-hide" class="icon icon-expanded collapsible" style="display:none;" href="#" onclick="'.self::h($js).'">'.$icon('angle-down', $hide).'</a>'
            .'<div id="'.$id.'" class="collapsed-text" style="display:none;">'
            .$this->format($body, $ctx->project, ['headings' => false] + array_intersect_key($ctx->options, ['object' => 1, 'attachments' => 1, 'inline_attachments' => 1, 'formatting' => 1]))
            .'</div>';
    }

    /** Wiki.find_page(title, project: project): "project:Title" syntax supported, the page must have a content */
    private function findWikiPage(string $title, ?Project $project): ?WikiPage
    {
        if (preg_match('/^([^:]+):(.*)$/', $title, $mm)) {
            $project = $this->findProject($mm[1], true);
            $title = $mm[2];
        }
        $wiki = null !== $project ? $this->wikiOf($project) : null;
        if (null === $wiki) {
            return null;
        }
        $page = $this->wikiPage($wiki, $title);

        return $page && $page->content ? $page : null;
    }

    private function wikiPageUrl(WikiPage $page, ?RenderContext $ctx = null): string
    {
        $params = ['project_id' => $page->wiki->project->identifier, 'id' => $page->title];
        if (null !== $ctx) {
            return $this->url($ctx, 'wiki_show', $params);
        }
        try {
            return $this->urls->generate('wiki_show', $params);
        } catch (\Throwable) {
            return '#';
        }
    }

    /** @param list<string> $args */
    private function childPagesMacro(array $args, RenderContext $ctx): string
    {
        [$args, $opts] = $this->macroOptions($args, ['parent', 'depth']);
        $depth = isset($opts['depth']) && '' !== $opts['depth'] ? (int) $opts['depth'] : null;
        $object = $ctx->object;
        if ([] !== $args) {
            $page = $this->findWikiPage($args[0], $ctx->project);
        } elseif ($object instanceof WikiPage) {
            $page = $object;
        } elseif ($object instanceof WikiContent) {
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
        $collect = function (WikiPage $p, int $level) use (&$collect, &$tree, $byParent, $depth): void {
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

        return $this->pageHierarchy($tree, (int) $root, null, fn (WikiPage $p): string => $this->wikiPageUrl($p, $ctx));
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
            $out .= '<li><a'.(null !== $t ? ' title="'.self::h($t).'"' : '').' href="'.self::h($href ? $href($page) : $this->wikiPageUrl($page)).'">'.self::h($page->prettyTitle()).'</a>';
            if (!empty($pagesByParent[$page->id])) {
                $out .= "\n".$this->pageHierarchy($pagesByParent, (int) $page->id, $title, $href);
            }
            $out .= "</li>\n";
        }

        return $out."</ul>\n";
    }

    /** @param list<string> $args */
    private function includeMacro(array $args, RenderContext $ctx): string
    {
        $page = $this->findWikiPage($args[0] ?? '', $ctx->project);
        if (null === $page || !$this->auth->allowedTo('view_wiki_pages', $page->wiki->project)) {
            throw new \RuntimeException($this->t('error_page_not_found'));
        }
        if (\in_array($page->id, $this->includedPages, true)) {
            throw new \RuntimeException($this->t('error_circular_inclusion'));
        }
        $this->includedPages[] = $page->id;
        try {
            return $this->format($page->content->text, $page->wiki->project, ['headings' => false, 'object' => $page] + array_intersect_key($ctx->options, ['inline_attachments' => 1, 'formatting' => 1, 'wiki_links' => 1]));
        } finally {
            array_pop($this->includedPages);
        }
    }

    /** @param list<string> $args */
    private function recentPagesMacro(array $args, RenderContext $ctx): string
    {
        [, $opts] = $this->macroOptions($args, ['days', 'limit', 'time', 'project', 'include_subprojects']);
        $project = $ctx->project;
        if ('' !== ($opts['project'] ?? '')) {
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
            $out .= '<li><a href="'.self::h($this->wikiPageUrl($page, $ctx)).'">'.self::h($page->prettyTitle()).'</a>';
            if ($showTime) {
                $out .= ' ('.self::h($this->i18n?->distanceOfTimeInWords($page->content->updatedOn) ?? '').')';
            }
            $out .= '</li>';
        }

        return $out.'</ul>';
    }

    /** @param list<string> $args */
    private function thumbnailMacro(array $args, RenderContext $ctx): string
    {
        [$args, $opts] = $this->macroOptions($args, ['size', 'title']);
        $filename = $args[0] ?? '';
        if ('' === $filename) {
            throw new \RuntimeException($this->t('error_filename_required'));
        }
        $size = $opts['size'] ?? null;
        if (null !== $size && !preg_match('/^\d+$/', $size)) {
            throw new \RuntimeException($this->t('error_invalid_size_parameter'));
        }
        $size = (int) $size > 0 ? (int) $size : 200;
        $found = $this->latestAttachment($ctx, $filename);
        if (null === $found) {
            throw new \RuntimeException($this->t('error_attachment_not_found', ['name' => $filename]));
        }
        $title = $opts['title'] ?? ($found->description ? $found->filename.' ('.$found->description.')' : $found->filename);

        return '<a class="thumbnail" title="'.self::h($title).'" href="'.self::h($this->url($ctx, 'attachment_show', ['id' => $found->id])).'">'
            .'<img alt="'.self::h($found->filename).'" src="'.self::h($this->url($ctx, 'attachment_thumbnail', ['id' => $found->id, 'size' => $size])).'" /></a>';
    }

    /** @param list<string> $args */
    private function issueMacro(array $args, RenderContext $ctx): string
    {
        [$args, $opts] = $this->macroOptions($args, ['project', 'subject', 'tracker']);
        $id = $args[0] ?? '';
        $issue = preg_match('/\A\d+\z/', $id) ? $this->visibleIssue((int) $id) : null;
        if (null === $issue) {
            // fall back to the regular issue link format to indicate that there should have been something
            return '#'.self::h($id);
        }
        $linkOptions = [];
        foreach ($opts as $key => $value) {
            if ('true' === $value || 'false' === $value) {
                $linkOptions[$key] = 'true' === $value;
            }
        }

        return $this->linkToIssue($issue, $linkOptions, $ctx);
    }

    /**
     * ApplicationHelper#link_to_issue.
     *
     * @param array{project?: bool, subject?: bool, tracker?: bool} $options
     */
    private function linkToIssue(Issue $issue, array $options, RenderContext $ctx): string
    {
        $title = null;
        $subject = null;
        $text = false === ($options['tracker'] ?? null) ? '#'.$issue->id : $issue->tracker->name.' #'.$issue->id;
        if (false === ($options['subject'] ?? null)) {
            $title = self::truncate($issue->subject, 60);
        } else {
            $subject = $issue->subject;
        }
        $s = '<a href="'.self::h($this->url($ctx, 'issue_show', ['id' => $issue->id])).'" class="'.self::h($this->issueCssClasses($issue)).'"'
            .(null !== $title ? ' title="'.self::h($title).'"' : '').'>'.self::h($text).'</a>';
        if (null !== $subject) {
            $s .= self::h(': '.$subject);
        }
        if ($options['project'] ?? false) {
            $s = self::h($issue->project->name.' - ').$s;
        }

        return $s;
    }

    // ------------------------------------------------------------------------------------------
    // Sections, headings and table of contents
    // ------------------------------------------------------------------------------------------

    /** parse_sections: "edit section" links before each heading but the first */
    private function parseSections(string $html, string $editUrl): string
    {
        $section = 0;
        $sep = str_contains($editUrl, '?') ? '&' : '?';

        return preg_replace_callback(self::HEADING_RE, function ($m) use (&$section, $editUrl, $sep) {
            ++$section;
            if ($section <= 1) {
                return $m[1];
            }
            $label = $this->t('button_edit_section');

            return '<div class="contextual heading-'.$m[2].'" title="'.self::h($label).'" id="section-'.$section.'"><a class="icon-only icon-edit" href="'.self::h($editUrl.$sep.'section='.$section).'"><svg class="s18 icon-svg" aria-hidden="true"><use href="/assets/icons.svg#icon--edit"></use></svg><span class="icon-label">'.self::h($label).'</span></a></div>'.$m[1];
        }, $html) ?? $html;
    }

    /** parse_headings: adds anchors to headings; collects [level, anchor, label] */
    private function parseHeadings(string $html, array &$headings, RenderContext $ctx): string
    {
        if (!str_contains($html, '<h') && !str_contains($html, '<H')) {
            return $html;
        }
        $anchors = [];
        $page = match (true) {
            $ctx->object instanceof WikiPage => $ctx->object,
            $ctx->object instanceof WikiContent => $ctx->object->page,
            default => null,
        };

        return preg_replace_callback(self::HEADING_RE, static function ($m) use (&$headings, &$anchors, $ctx, $page) {
            $level = (int) $m[2];
            $attrs = $m[3] ?? '';
            $content = $m[4];
            // full_sanitizer without escaping quotes: "Bob's & B" gives the anchor Bobs-amp-B
            $item = trim(str_replace(['&quot;', '&#34;', '&#39;', '&#039;', '&apos;'], ['"', '"', "'", "'", "'"], strip_tags($content)));
            $anchor = self::sanitizeAnchorName($item);
            // used for single-file wiki export
            if ('anchor' === ($ctx->options['wiki_links'] ?? null) && null !== $page) {
                $anchor = $page->title.'_'.$anchor;
            }
            $anchors[$anchor] = ($anchors[$anchor] ?? 0) + 1;
            if ($anchors[$anchor] > 1) {
                $anchor .= '-'.$anchors[$anchor];
            }
            $headings[] = [$level, $anchor, htmlspecialchars($item, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8', false)];

            return '<a name="'.self::h($anchor).'"></a>'."\n".'<h'.$level.$attrs.'>'.$content.'<a href="#'.self::h($anchor).'" class="wiki-anchor">&para;</a></h'.$level.'>';
        }, $html) ?? $html;
    }

    /** replace_toc */
    private function replaceToc(string $html, array $headings): string
    {
        return preg_replace_callback(self::TOC_RE, function ($m) use ($headings) {
            // keep only the 4 first levels
            $headings = array_values(array_filter($headings, static fn ($h) => $h[0] <= 4));
            if ([] === $headings) {
                return '';
            }
            $class = 'toc'.(!empty($m[3]) ? ' right' : '').(!empty($m[2]) ? ' left' : '');
            $out = '<ul class="'.$class.'"><li><strong>'.self::h($this->t('label_table_of_contents')).'</strong></li><li>';
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
                $out .= '<a href="#'.self::h($anchor).'">'.$item.'</a>';
                $current = $level;
                $started = true;
            }
            $out .= str_repeat('</li></ul>', $current - $root);

            return $out.'</li></ul>';
        }, $html) ?? $html;
    }

    /** ApplicationHelper#sanitize_anchor_name */
    private static function sanitizeAnchorName(string $anchor): string
    {
        $anchor = preg_replace('/[^\s\-\p{L}\p{M}\p{N}\p{Pc}]/u', '', $anchor) ?? '';

        return preg_replace('/\s+(\-+\s*)?/u', '-', $anchor) ?? '';
    }

    private static function truncate(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 3).'...' : $text;
    }

    // ------------------------------------------------------------------------------------------
    // Links
    // ------------------------------------------------------------------------------------------

    /**
     * parse_non_pre_blocks: wiki and Redmine links are resolved and macros run outside <pre> and <code>;
     * inside, the text of the macros is put back.
     */
    private function parseNonPreBlocks(string $html, RenderContext $ctx): string
    {
        $parsed = '';
        $tags = [];
        $pos = 0;
        $length = \strlen($html);
        while ($pos < $length) {
            if (preg_match('~<(/)?(pre|code)(?=[\s>/])[^>]*>~i', $html, $m, \PREG_OFFSET_CAPTURE, $pos)) {
                $text = substr($html, $pos, $m[0][1] - $pos);
                $fullTag = $m[0][0];
                $closing = '' !== $m[1][0];
                $tag = strtolower($m[2][0]);
                $pos = $m[0][1] + \strlen($fullTag);
            } else {
                $text = substr($html, $pos);
                $fullTag = null;
                $closing = false;
                $tag = '';
                $pos = $length;
            }
            if ([] === $tags) {
                $text = $this->parseWikiLinks($text, $ctx);
                $text = $this->parseRedmineLinks($text, $ctx);
                $text = $this->injectMacros($text, $ctx, true);
            } else {
                $text = $this->injectMacros($text, $ctx, false);
            }
            $parsed .= $text;
            if (null !== $fullTag) {
                if ($closing) {
                    if ([] !== $tags && end($tags) === $tag) {
                        array_pop($tags);
                    }
                } else {
                    $tags[] = $tag;
                }
                $parsed .= $fullTag;
            }
        }
        // close any non closing tags
        while (null !== ($tag = array_pop($tags))) {
            $parsed .= '</'.$tag.'>';
        }

        return $parsed;
    }

    /**
     * parse_wiki_links: [[mypage]], [[mypage|mytext]], [[#anchor]], [[project:]], [[project:mypage|mytext]].
     */
    private function parseWikiLinks(string $text, RenderContext $ctx): string
    {
        if (!str_contains($text, '[[')) {
            return $text;
        }
        $pattern = '~<[^>]*>(*SKIP)(*F)|(!)?(\[\[([^\n|<>]+?)(\|([^\n|]+?))?\]\])~u';
        $this->prefetchWikiPages($text, $pattern, $ctx);

        return preg_replace_callback($pattern, function (array $m) use ($ctx): string {
            $all = $m[2];
            if ('' !== $m[1]) {
                return $all;
            }
            $page = html_entity_decode($m[3], \ENT_QUOTES | \ENT_HTML5);
            $title = isset($m[5]) && '' !== $m[5] ? $m[5] : null;
            if (preg_match('/^#(.+)$/', $page, $am)) {
                return '<a href="#'.self::h(self::sanitizeAnchorName($am[1])).'" class="wiki-page">'.($title ?? self::h($page)).'</a>';
            }
            $linkProject = $ctx->project;
            if (preg_match('/^([^:]+):(.*)$/', $page, $pm)) {
                $linkProject = $this->findProject($pm[1], true);
                $page = $pm[2];
                if ('' === trim($page)) {
                    $title ??= self::h($pm[1]);
                }
            }
            $wiki = null !== $linkProject ? $this->wikiOf($linkProject) : null;
            if (null === $linkProject || null === $wiki || !$this->auth->allowedTo('view_wiki_pages', $linkProject)) {
                // project or wiki doesn't exist
                return $all;
            }
            $anchor = null;
            if (preg_match('/^(.+?)#(.+)$/', $page, $am)) {
                $page = $am[1];
                $anchor = self::sanitizeAnchorName($am[2]);
            }
            $anchor = '' === (string) $anchor ? null : $anchor;
            $wikiPage = $this->wikiPage($wiki, $page);
            $current = match (true) {
                $ctx->object instanceof WikiPage => $ctx->object,
                $ctx->object instanceof WikiContent => $ctx->object->page,
                default => null,
            };
            $titleized = '' !== trim($page) ? Wiki::titleize($page) : '';
            if (null !== $anchor && null !== $wikiPage && $current === $wikiPage) {
                $url = '#'.$anchor;
            } else {
                switch ($ctx->options['wiki_links'] ?? null) {
                    case 'local':
                        $url = $titleized.'.html'.(null !== $anchor ? '#'.$anchor : '');
                        break;
                    case 'anchor':
                        // used for single-file wiki export
                        $url = '#'.('' !== $titleized ? $titleized : strip_tags((string) $title)).(null !== $anchor ? '_'.$anchor : '');
                        break;
                    default:
                        $params = ['project_id' => $linkProject->identifier];
                        if (null === $wikiPage && null !== $current && $ctx->project === $linkProject) {
                            $params['parent'] = $current->title;
                        }
                        $url = '' !== $titleized
                            ? $this->url($ctx, 'wiki_show', ['project_id' => $linkProject->identifier, 'id' => $titleized] + $params)
                            : $this->url($ctx, 'wiki_index', $params);
                        if (null !== $anchor) {
                            $url .= '#'.$anchor;
                        }
                }
            }

            return '<a href="'.self::h($url).'" class="wiki-page'.(null !== $wikiPage ? '' : ' new').'">'.($title ?? self::h($page)).'</a>';
        }, $text) ?? $text;
    }

    /** One query for all the pages of the current project's wiki that the text links to */
    private function prefetchWikiPages(string $text, string $pattern, RenderContext $ctx): void
    {
        $wiki = null !== $ctx->project ? $this->wikiOf($ctx->project) : null;
        if (null === $wiki || !preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER)) {
            return;
        }
        $titles = [];
        foreach ($matches as $m) {
            $page = html_entity_decode($m[3], \ENT_QUOTES | \ENT_HTML5);
            if ('' !== $m[1] || str_starts_with($page, '#') || str_contains($page, ':')) {
                continue;
            }
            $page = explode('#', $page, 2)[0];
            $titles[] = Wiki::titleize('' === trim($page) ? $wiki->startPage : $page);
        }
        $this->loadWikiPages($wiki, $titles);
    }

    /** @param list<string> $titles titleized page titles */
    private function loadWikiPages(Wiki $wiki, array $titles): void
    {
        $cache = &$this->wikiPageCache[(int) $wiki->id];
        $cache ??= [];
        $missing = [];
        foreach (array_unique($titles) as $title) {
            if ('' !== $title && !isset($cache[mb_strtolower($title)])) {
                $missing[mb_strtolower($title)] = $title;
            }
        }
        if ([] === $missing) {
            return;
        }
        $lower = array_map('strval', array_keys($missing));
        $pages = $this->em->createQuery('SELECT p, c FROM App\Entity\WikiPage p LEFT JOIN p.content c WHERE p.wiki = :w AND (p.title IN (:exact) OR LOWER(p.title) IN (:lower))')
            ->setParameters(['w' => $wiki, 'exact' => array_values($missing), 'lower' => $lower])->getResult();
        foreach ($pages as $page) {
            $cache[mb_strtolower($page->title)] = $page;
        }
        $notFound = array_diff_key($missing, $cache);
        if ([] !== $notFound) {
            // search for redirects
            $redirects = $this->em->createQuery('SELECT r FROM App\Entity\WikiRedirect r WHERE r.wikiId = :w AND (r.title IN (:exact) OR LOWER(r.title) IN (:lower))')
                ->setParameters(['w' => $wiki->id, 'exact' => array_values($notFound), 'lower' => array_map('strval', array_keys($notFound))])->getResult();
            /** @var WikiRedirect $redirect */
            foreach ($redirects as $redirect) {
                $target = $this->em->createQuery('SELECT p FROM App\Entity\WikiPage p WHERE IDENTITY(p.wiki) = :w AND LOWER(p.title) = LOWER(:t)')
                    ->setParameters(['w' => $redirect->redirectsToWikiId, 't' => (string) $redirect->redirectsTo])->setMaxResults(1)->getOneOrNullResult();
                $cache[mb_strtolower((string) $redirect->title)] = $target ?? false;
            }
        }
        foreach ($missing as $key => $title) {
            $cache[$key] ??= false;
        }
    }

    /** Wiki#find_page (redirects are followed) */
    private function wikiPage(Wiki $wiki, string $title): ?WikiPage
    {
        $title = Wiki::titleize('' === trim($title) ? $wiki->startPage : $title);
        if ('' === $title) {
            return null;
        }
        $key = mb_strtolower($title);
        if (!isset($this->wikiPageCache[(int) $wiki->id][$key])) {
            $this->loadWikiPages($wiki, [$title]);
        }

        return ($this->wikiPageCache[(int) $wiki->id][$key] ?? false) ?: null;
    }

    private function wikiOf(Project $project): ?Wiki
    {
        $this->wikiCache[(int) $project->id] ??= $this->em->getRepository(Wiki::class)->findOneBy(['project' => $project]) ?? false;

        return $this->wikiCache[(int) $project->id] ?: null;
    }

    /** Project by identifier (or by name too): the project prefix of a link */
    private function findProject(string $identifier, bool $orName = false): ?Project
    {
        $key = ($orName ? 'n:' : 'i:').$identifier;
        if (!isset($this->projectCache[$key])) {
            $repo = $this->em->getRepository(Project::class);
            $this->projectCache[$key] = $repo->findOneBy(['identifier' => $identifier]) ?? ($orName ? $repo->findOneBy(['name' => $identifier]) : null) ?? false;
        }

        return $this->projectCache[$key] ?: null;
    }

    /** One query for all the issues the text refers to */
    private function prefetchIssues(string $html): void
    {
        if (!str_contains($html, '#') || !preg_match_all('/(?<![\w&])##?(\d{1,10})(?!\d)/', $html, $m)) {
            return;
        }
        $this->loadIssues(array_map('intval', $m[1]));
    }

    /** @param list<int> $ids */
    private function loadIssues(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->issueCache)));
        if ([] === $ids) {
            return;
        }
        $issues = $this->em->createQuery('SELECT i, t, s, pr, p FROM App\Entity\Issue i JOIN i.tracker t JOIN i.status s JOIN i.priority pr JOIN i.project p WHERE i.id IN (:ids)')
            ->setParameter('ids', $ids)->getResult();
        foreach ($issues as $issue) {
            $this->issueCache[(int) $issue->id] = $issue;
        }
        foreach ($ids as $id) {
            $this->issueCache[$id] ??= false;
        }
    }

    /** Issue.visible.find_by_id */
    private function visibleIssue(int $id): ?Issue
    {
        if (!isset($this->issueCache[$id])) {
            $this->loadIssues([$id]);
        }
        $issue = $this->issueCache[$id] ?: null;
        if (null === $issue) {
            return null;
        }
        $visible = null !== $this->issueService
            ? $this->issueService->isVisible($issue)
            : $this->auth->allowedTo('view_issues', $issue->project) && !$issue->isPrivate;

        return $visible ? $issue : null;
    }

    /** Issue#css_classes */
    private function issueCssClasses(Issue $issue): string
    {
        $user = $this->auth->user();
        $s = 'issue tracker-'.$issue->tracker->id.' status-'.$issue->status->id.' priority-'.$issue->priority->id
            .($issue->priority->positionName ? ' priority-'.$issue->priority->positionName : '');
        $s .= $issue->isClosed() ? ' closed' : '';
        $s .= $issue->isOverdue() ? ' overdue' : '';
        $s .= null !== $issue->parent ? ' child' : '';
        $s .= !$issue->isLeaf() ? ' parent' : '';
        $s .= $issue->isPrivate ? ' private' : '';
        if ($user->isLogged()) {
            $s .= $issue->author->id === $user->id ? ' created-by-me' : '';
            $s .= null !== $issue->assignedTo && $user->isOrBelongsTo($issue->assignedTo) ? ' assigned-to-me' : '';
        }

        return $s;
    }

    /**
     * parse_redmine_links: #52, ##52, #52-3, r52, commit:a85130f, document#17, document:Greetings, version#3,
     * version:"1.0 beta 2", attachment:file.zip, source:some/file@52#L120, export:some/file, forum#1,
     * forum:Support, message#1218, project:someproject, news#2, user:jsmith, @jsmith; all but the attachment
     * links can refer to another project (identifier:r52, identifier:version:1.0.0).
     */
    private function parseRedmineLinks(string $text, RenderContext $ctx): string
    {
        if (false === strpbrk($text, '#:@') && !preg_match('/r\d/', $text)) {
            return $text;
        }

        return preg_replace_callback(self::LINKS_RE, function (array $m) use ($ctx): string {
            $leading = $m['leading'] ?? '';
            $projectPrefix = $m['project_prefix'] ?? '';
            $prefix = $m['prefix'] ?? '';
            $repoPrefix = $m['repo_prefix'] ?? '';
            $sep = ($m['sep1'] ?? '') ?: ($m['sep2'] ?? '') ?: ($m['sep3'] ?? '') ?: ($m['sep4'] ?? '');
            $identifier = ($m['identifier1'] ?? '') ?: ($m['identifier2'] ?? '') ?: ($m['identifier3'] ?? '');
            $commentSuffix = $m['comment_suffix'] ?? '';
            $link = null;
            if ('' === ($m['esc'] ?? '')) {
                $project = $ctx->project;
                if ('' !== ($m['project_identifier'] ?? '')) {
                    // Project.visible.find_by_identifier
                    $project = $this->findProject($m['project_identifier']);
                    $project = null !== $project && $this->auth->isProjectVisible($project) ? $project : null;
                }
                $link = $this->redmineLink($ctx, $project, $projectPrefix, $prefix, $repoPrefix, $m['repo_identifier'] ?? '', $sep, $identifier, $commentSuffix, $m['comment_id'] ?? '');
            }

            return $leading.($link ?? $projectPrefix.$prefix.$repoPrefix.$sep.$identifier.$commentSuffix);
        }, $text, -1, $count, \PREG_UNMATCHED_AS_NULL) ?? $text;
    }

    private function redmineLink(RenderContext $ctx, ?Project $project, string $projectPrefix, string $prefix, string $repoPrefix, string $repoIdentifier, string $sep, string $identifier, string $commentSuffix, string $commentId): ?string
    {
        $link = static fn (string $label, string $url, string $class = '', ?string $title = null): string => '<a href="'.self::h($url).'"'.('' !== $class ? ' class="'.self::h($class).'"' : '')
            .(null !== $title ? ' title="'.self::h($title).'"' : '').'>'.$label.'</a>';

        if ('' === $prefix && 'r' === $sep) {
            $repository = null !== $project ? $this->repository($project, '' !== $repoIdentifier ? $repoIdentifier : null) : null;
            $changeset = null !== $repository && $this->auth->allowedTo('view_changesets', $project)
                ? $this->em->getRepository(Changeset::class)->findOneBy(['repository' => $repository, 'revision' => $identifier]) : null;
            if (null === $changeset) {
                return null;
            }

            return $link($projectPrefix.$repoPrefix.'r'.$identifier, $this->absolute($ctx, ScmTwigExtension::url('revision', $repository, ['rev' => $changeset->revision])), 'changeset', self::truncateSingleLine($changeset->comments, 100));
        }

        if ('#' === $sep || '##' === $sep) {
            $oid = (int) $identifier;
            switch ($prefix) {
                case '':
                    if ((string) $oid === $identifier && null !== ($issue = $this->visibleIssue($oid))) {
                        $url = $this->url($ctx, 'issue_show', ['id' => $oid]).('' !== $commentId ? '#note-'.$commentId : '');
                        if ('##' === $sep) {
                            return $link(self::h($issue->tracker->name.' #'.$oid).$commentSuffix.self::h(': '.$issue->subject), $url, $this->issueCssClasses($issue), $this->t('field_status').': '.$issue->status->name);
                        }

                        return $link('#'.$oid.$commentSuffix, $url, $this->issueCssClasses($issue), $issue->tracker->name.': '.self::truncate($issue->subject, 100).' ('.$issue->status->name.')');
                    }
                    if ('note' === $identifier && '' !== $commentId) {
                        return $link('#note-'.$commentId, '#note-'.$commentId);
                    }

                    return null;
                case 'document':
                    $document = $this->em->find(Document::class, $oid);

                    return $document && $this->auth->allowedTo('view_documents', $document->project)
                        ? $link(self::h($document->title), $this->url($ctx, 'document_show', ['id' => $document->id]), 'document') : null;
                case 'version':
                    $version = $this->em->find(Version::class, $oid);

                    return $version && $this->auth->allowedTo('view_issues', $version->project)
                        ? $link(self::h($version->name), $this->url($ctx, 'version_show', ['id' => $version->id]), 'version') : null;
                case 'message':
                    $message = $this->em->find(Message::class, $oid);

                    return $message && $this->auth->allowedTo('view_messages', $message->board->project) ? $this->linkToMessage($message, $ctx) : null;
                case 'forum':
                    $board = $this->em->find(Board::class, $oid);

                    return $board && $this->auth->allowedTo('view_messages', $board->project) ? $this->linkToBoard($board, $ctx) : null;
                case 'news':
                    $news = $this->em->find(News::class, $oid);

                    return $news && $news->project && $this->auth->allowedTo('view_news', $news->project)
                        ? $link(self::h($news->title), $this->url($ctx, 'news_show', ['id' => $news->id]), 'news') : null;
                case 'project':
                    $p = $this->em->find(Project::class, $oid);

                    return $p && $this->auth->isProjectVisible($p) ? $this->linkToProject($p, $ctx) : null;
                case 'user':
                    $u = $this->em->find(User::class, $oid);

                    return $u instanceof User && $this->userVisible($u) ? $this->linkToUser($u, $ctx) : null;
            }

            return null;
        }

        if (':' === $sep) {
            $name = html_entity_decode((string) preg_replace('/^"(.*)"$/s', '$1', $identifier), \ENT_QUOTES | \ENT_HTML5);
            switch ($prefix) {
                case 'document':
                    $document = $project && $this->auth->allowedTo('view_documents', $project) ? $this->em->getRepository(Document::class)->findOneBy(['project' => $project, 'title' => $name]) : null;

                    return $document ? $link(self::h($document->title), $this->url($ctx, 'document_show', ['id' => $document->id]), 'document') : null;
                case 'version':
                    $version = $project && $this->auth->allowedTo('view_issues', $project) ? $this->em->getRepository(Version::class)->findOneBy(['project' => $project, 'name' => $name]) : null;

                    return $version ? $link(self::h($version->name), $this->url($ctx, 'version_show', ['id' => $version->id]), 'version') : null;
                case 'forum':
                    $board = $project && $this->auth->allowedTo('view_messages', $project) ? $this->em->getRepository(Board::class)->findOneBy(['project' => $project, 'name' => $name]) : null;

                    return $board ? $this->linkToBoard($board, $ctx) : null;
                case 'news':
                    $news = $project && $this->auth->allowedTo('view_news', $project) ? $this->em->getRepository(News::class)->findOneBy(['project' => $project, 'title' => $name]) : null;

                    return $news ? $link(self::h($news->title), $this->url($ctx, 'news_show', ['id' => $news->id]), 'news') : null;
                case 'commit':
                case 'source':
                case 'export':
                    if (null === $project) {
                        return null;
                    }
                    $repoPrefix = '';
                    $repoIdentifier = null;
                    if (preg_match('~^(([a-z0-9\-_]+)\|)(.+)$~s', $name, $rm)) {
                        [, $repoPrefix, $repoIdentifier, $name] = $rm;
                    }
                    $repository = $this->repository($project, $repoIdentifier);
                    if (null === $repository) {
                        return null;
                    }
                    if ('commit' === $prefix) {
                        $changeset = $this->auth->allowedTo('view_changesets', $project)
                            ? $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND c.scmid LIKE :s ORDER BY c.id ASC')
                                ->setParameters(['r' => $repository, 's' => addcslashes($name, '%_\\').'%'])->setMaxResults(1)->getOneOrNullResult() : null;
                        if (null === $changeset) {
                            return null;
                        }
                        $rev = null !== $changeset->scmid && '' !== $changeset->scmid ? $changeset->scmid : $changeset->revision;

                        return $link(self::h($projectPrefix.$repoPrefix.$name), $this->absolute($ctx, ScmTwigExtension::url('revision', $repository, ['rev' => $rev])), 'changeset', self::truncateSingleLine($changeset->comments, 100));
                    }
                    if (!$this->auth->allowedTo('browse_repository', $project) || !preg_match('~^[/\\\\]*(.*?)(@([^/\\\\@]+?))?(#(L\d+))?$~s', $name, $sm)) {
                        return null;
                    }
                    $url = ScmTwigExtension::url('export' === $prefix ? 'raw' : 'entry', $repository, ['path' => $sm[1], 'rev' => ($sm[3] ?? '') ?: null]);

                    return $link(self::h($projectPrefix.$prefix.':'.$repoPrefix.$name), $this->absolute($ctx, $url).('' !== ($sm[5] ?? '') ? '#'.$sm[5] : ''), 'export' === $prefix ? 'source download' : 'source');
                case 'attachment':
                    $attachment = $this->latestAttachment($ctx, $name);

                    return $attachment ? $link(self::h($attachment->filename), $this->url($ctx, 'attachment_show', ['id' => $attachment->id, 'filename' => $attachment->filename]), 'attachment') : null;
                case 'project':
                    $p = $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.identifier = :s OR LOWER(p.name) = :s')
                        ->setParameter('s', mb_strtolower($name))->setMaxResults(1)->getOneOrNullResult();

                    return $p && $this->auth->isProjectVisible($p) ? $this->linkToProject($p, $ctx) : null;
                case 'user':
                    $u = $this->userByLogin($name);

                    return null !== $u ? $this->linkToUser($u, $ctx) : null;
            }

            return null;
        }

        if ('@' === $sep) {
            $u = $this->userByLogin((string) preg_replace('/^"(.*)"$/s', '$1', $identifier));

            return null !== $u ? $this->linkToMention($u, $ctx) : null;
        }

        return null;
    }

    private static function truncateSingleLine(?string $text, int $length): string
    {
        return self::truncate((string) preg_replace('/[\r\n]+/', ' ', trim((string) $text)), $length);
    }

    /** The default repository of the project, or the one with the given identifier */
    private function repository(Project $project, ?string $identifier): ?Repository
    {
        $this->repositoryCache[(int) $project->id] ??= $this->em->getRepository(Repository::class)->findBy(['project' => $project], ['id' => 'ASC']);
        foreach ($this->repositoryCache[(int) $project->id] as $repository) {
            if (null === $identifier ? $repository->isDefault : $repository->identifier === $identifier) {
                return $repository;
            }
        }

        return null;
    }

    private function linkToMessage(Message $message, RenderContext $ctx): string
    {
        $topic = $message->parent ?? $message;
        $params = ['board_id' => $message->board->id, 'id' => $topic->id];
        if (null !== $message->parent) {
            $params['r'] = $message->id;
        }

        return '<a href="'.self::h($this->url($ctx, 'board_message_show', $params).(null !== $message->parent ? '#message-'.$message->id : '')).'" class="message">'
            .self::h(self::truncate($message->subject, 60)).'</a>';
    }

    private function linkToBoard(Board $board, RenderContext $ctx): string
    {
        return '<a href="'.self::h($this->url($ctx, 'board_show', ['project_id' => $board->project->identifier, 'id' => $board->id])).'" class="board">'.self::h($board->name).'</a>';
    }

    private function linkToProject(Project $project, RenderContext $ctx): string
    {
        if ($project->isArchived()) {
            return self::h($project->name);
        }

        return '<a href="'.self::h($this->url($ctx, 'project_show', ['id' => $project->identifier])).'" class="project">'.self::h($project->name).'</a>';
    }

    /** link_to_user: only active users (and, for administrators, registered and locked ones) are linked */
    private function linkToUser(User $user, RenderContext $ctx, string $class = '', bool $mention = false): string
    {
        $name = self::h(($mention ? '@' : '').$user->getName());
        if (!$user->isActive() && !($this->auth->user()->admin && $user->isLogged())) {
            return $name;
        }
        $css = 'user '.match ($user->status) {
            Principal::STATUS_ACTIVE => 'active',
            Principal::STATUS_REGISTERED => 'registered',
            Principal::STATUS_LOCKED => 'locked',
            default => 'anon',
        };

        return '<a href="'.self::h($this->url($ctx, 'user_show', ['id' => $user->id])).'" class="'.$css.('' !== $class ? ' '.$class : '').'">'.$name.'</a>';
    }

    /** link_to_mention */
    private function linkToMention(User $user, RenderContext $ctx): string
    {
        $classes = ['user-mention'];
        if ($user->id === $this->auth->user()->id) {
            $classes[] = 'user-current';
        }
        $issue = match (true) {
            $ctx->object instanceof Issue => $ctx->object,
            $ctx->object instanceof Journal => $ctx->object->issue,
            default => null,
        };
        if (null !== $issue && null !== $this->issueService && $this->issueService->isVisible($issue, $user)) {
            $classes[] = 'user-mentionable';
        }

        return $this->linkToUser($user, $ctx, implode(' ', $classes), true);
    }

    /** User.visible.find_by_login(login.downcase) */
    private function userByLogin(string $login): ?User
    {
        $login = mb_strtolower(html_entity_decode($login, \ENT_QUOTES | \ENT_HTML5));
        if ('' === $login) {
            return null;
        }
        if (!isset($this->userCache[$login])) {
            $user = $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE LOWER(u.login) = :l')->setParameter('l', $login)->setMaxResults(1)->getOneOrNullResult();
            $this->userCache[$login] = $user instanceof User ? $user : false;
        }
        // the visibility is checked on every call: mails are rendered for several recipients in one request
        $user = $this->userCache[$login] ?: null;

        return null !== $user && $this->userVisible($user) ? $user : null;
    }

    /** User.visible: everybody for an administrator, else active users (all of them or the members of visible projects) */
    private function userVisible(User $user): bool
    {
        if (!$user->isLogged()) {
            return false;
        }
        $current = $this->auth->user();
        if ($current->admin) {
            return true;
        }
        if (!$user->isActive()) {
            return false;
        }
        if ($user->id === $current->id) {
            return true;
        }
        if ($this->viewAllActiveUsersFor !== (int) $current->id) {
            // mails are rendered for several recipients in one request
            $this->viewAllActiveUsers = null;
            $this->viewAllActiveUsersFor = (int) $current->id;
        }
        if (null === $this->viewAllActiveUsers) {
            $memberships = $this->auth->membershipsOf($current);
            if ([] !== $memberships) {
                $this->viewAllActiveUsers = false;
                foreach ($memberships as $membership) {
                    foreach ($membership->getRoles() as $role) {
                        if ('all' === $role->usersVisibility) {
                            $this->viewAllActiveUsers = true;
                            break 2;
                        }
                    }
                }
            } else {
                $this->viewAllActiveUsers = 'all' === $this->auth->builtinRole($current)->usersVisibility;
            }
        }
        if ($this->viewAllActiveUsers) {
            return true;
        }
        $projectIds = $this->auth->visibleProjectIds($current);

        return [] !== $projectIds && (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.Member::class.' m WHERE IDENTITY(m.principal) = :u AND IDENTITY(m.project) IN (:p)')
            ->setParameters(['u' => $user->id, 'p' => $projectIds])->getSingleScalarResult() > 0;
    }

    /** Plain-text version (for e-mails / feeds) */
    public function toText(?string $text): string
    {
        return trim(html_entity_decode(strip_tags($this->format($text))));
    }
}
