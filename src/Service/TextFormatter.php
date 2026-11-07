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

/**
 * Port of Redmine's textilizable: renders Textile / CommonMark (GFM) text, sanitizes the HTML
 * and resolves Redmine link syntax (#123, [[Wiki page]], version:"1.0", user#1, @login, r42, ...).
 */
final class TextFormatter
{
    private ?HtmlSanitizer $sanitizer = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly Authorization $auth,
    ) {
    }

    /** @param array{wiki_links?: bool, only_path?: bool, headings?: bool} $options */
    public function format(?string $text, ?Project $project = null, array $options = []): string
    {
        if (null === $text || '' === trim($text)) {
            return '';
        }
        $formatting = $this->settings->textFormatting();
        $text = str_replace("\r\n", "\n", $text);
        // {{toc}}, {{collapse}} macros are handled before rendering
        [$text, $macros] = $this->extractMacros($text);
        $html = match ($formatting) {
            'textile' => (new TextileParser('html5'))->setRestricted(false)->setLite(false)->parse($text),
            'common_mark', 'markdown' => (new GithubFlavoredMarkdownConverter(['html_input' => 'allow', 'allow_unsafe_links' => false]))->convert($text)->getContent(),
            default => nl2br(htmlspecialchars($text), false),
        };
        $html = $this->sanitizer()->sanitize($html);
        $html = $this->parseRedmineLinks($html, $project);
        $html = $this->renderMacros($html, $macros, $project);
        if (str_contains($html, '{{toc}}') || str_contains($html, '<p>{{toc}}</p>')) {
            $html = $this->toc($html);
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
    private function renderMacros(string $html, array $macros, ?Project $project): string
    {
        foreach ($macros as $key => $json) {
            $m = json_decode($json, true);
            $out = match ($m['name']) {
                'collapse' => '<details class="collapsed-text"><summary>'.htmlspecialchars($m['args'] ?: 'Show').'</summary>'.$this->format($m['body'], $project).'</details>',
                'issue' => $this->issueMacro((int) $m['args']),
                default => '',
            };
            $html = str_replace(['<p>'.$key.'</p>', $key], $out, $html);
        }

        return $html;
    }

    private function issueMacro(int $id): string
    {
        $issue = $this->em->find(Issue::class, $id);
        if (!$issue || !$this->auth->allowedTo('view_issues', $issue->project)) {
            return '#'.$id;
        }

        return '<a href="'.$this->urls->generate('issue_show', ['id' => $id]).'" class="issue">'.htmlspecialchars($issue->tracker->name.' #'.$id.': '.$issue->subject).'</a>';
    }

    private function toc(string $html): string
    {
        $items = [];
        $html = preg_replace_callback('#<h([1-4])(?: id="[^"]*")?>(.*?)</h\1>#s', function ($m) use (&$items) {
            $anchor = preg_replace('/[^\w\-]+/u', '-', trim(strip_tags($m[2]))) ?: 'section';
            $items[] = [(int) $m[1], $anchor, strip_tags($m[2])];

            return '<h'.$m[1].' id="'.htmlspecialchars($anchor).'">'.$m[2].'<a href="#'.htmlspecialchars($anchor).'" class="wiki-anchor">&para;</a></h'.$m[1].'>';
        }, $html) ?? $html;
        $toc = '<ul class="toc">';
        foreach ($items as [$level, $anchor, $title]) {
            $toc .= '<li class="heading'.$level.'"><a href="#'.htmlspecialchars($anchor).'">'.htmlspecialchars($title).'</a></li>';
        }
        $toc .= '</ul>';

        return str_replace(['<p>{{toc}}</p>', '{{toc}}'], $toc, $html);
    }

    /** Resolves Redmine link syntax in text nodes (not inside <a>, <pre>, <code>). */
    private function parseRedmineLinks(string $html, ?Project $project): string
    {
        $parts = preg_split('#(<pre\b.*?</pre>|<code\b.*?</code>|<a\b.*?</a>|<[^>]+>)#s', $html, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        foreach ($parts as $i => $part) {
            if ('' === $part || '<' === $part[0]) {
                continue;
            }
            $part = $this->wikiLinks($part, $project);
            $parts[$i] = $this->objectLinks($part, $project);
        }

        return implode('', $parts);
    }

    private function wikiLinks(string $text, ?Project $project): string
    {
        return preg_replace_callback('/(!)?\[\[([^\]\n|]+?)(\|([^\]\n]+?))?\]\]/', function ($m) use ($project) {
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
            if ($anchor) {
                $url .= '#'.rawurlencode($anchor);
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
