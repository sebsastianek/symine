<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Wiki;
use App\Entity\WikiContent;
use App\Entity\WikiContentVersion;
use App\Entity\WikiPage;
use App\Entity\WikiRedirect;
use App\Security\Authorization;
use App\Service\Wiki\StaleWikiContentException;
use App\Service\Wiki\WikiDiff;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Port of Redmine's Wiki, WikiPage, WikiContent, WikiContentVersion and WikiRedirect model logic
 * (find_page with redirects, save_with_content with optimistic locking and versioning, rename/move
 * with redirects, destroy with children handling, destroy_version, annotate, section editing).
 */
final class WikiService
{
    public const array DEFAULT_PROTECTED_PAGES = ['sidebar'];

    private bool $foundWithRedirect = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly Authorization $auth,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
    ) {
    }

    public function wikiFor(Project $project, bool $createIfModuleEnabled = true): ?Wiki
    {
        $wiki = $this->em->getRepository(Wiki::class)->findOneBy(['project' => $project]);
        if (null === $wiki && $createIfModuleEnabled && $project->isModuleEnabled('wiki')) {
            // EnabledModule#module_enabled creates the wiki when the module is enabled
            $wiki = $this->createDefault($project);
            $this->em->flush();
        }

        return $wiki;
    }

    public function createDefault(Project $project): Wiki
    {
        $wiki = new Wiki();
        $wiki->project = $project;
        $wiki->startPage = 'Wiki';
        $this->em->persist($wiki);

        return $wiki;
    }

    /** @return list<WikiPage> ordered by LOWER(title) */
    public function pages(Wiki $wiki): array
    {
        $pages = $this->em->createQuery('SELECT p, c FROM App\Entity\WikiPage p LEFT JOIN p.content c WHERE p.wiki = :w')
            ->setParameter('w', $wiki)->getResult();
        usort($pages, static fn (WikiPage $a, WikiPage $b) => strcmp(mb_strtolower($a->title), mb_strtolower($b->title)) ?: $a->id <=> $b->id);

        return $pages;
    }

    public function findPage(Wiki $wiki, ?string $title, bool $withRedirect = true): ?WikiPage
    {
        $this->foundWithRedirect = false;
        $title = Wiki::titleize(null === $title || '' === trim($title) ? $wiki->startPage : $title);
        $page = $this->em->createQuery('SELECT p FROM App\Entity\WikiPage p WHERE p.wiki = :w AND LOWER(p.title) = LOWER(:t)')
            ->setParameters(['w' => $wiki, 't' => $title])->setMaxResults(1)->getOneOrNullResult();
        if (null === $page && $withRedirect) {
            $redirect = $this->em->createQuery('SELECT r FROM App\Entity\WikiRedirect r WHERE r.wikiId = :w AND LOWER(r.title) = LOWER(:t) ORDER BY r.id ASC')
                ->setParameters(['w' => $wiki->id, 't' => $title])->setMaxResults(1)->getOneOrNullResult();
            if ($redirect instanceof WikiRedirect) {
                $target = $this->em->find(Wiki::class, $redirect->redirectsToWikiId ?: $redirect->wikiId);
                if ($target) {
                    $page = $this->findPage($target, (string) $redirect->redirectsTo, false);
                    $this->foundWithRedirect = null !== $page;
                }
            }
        }

        return $page;
    }

    public function pageFoundWithRedirect(): bool
    {
        return $this->foundWithRedirect;
    }

    public function findOrNewPage(Wiki $wiki, ?string $title): WikiPage
    {
        $title = null === $title || '' === trim($title) ? $wiki->startPage : $title;
        $page = $this->findPage($wiki, $title);
        if (null === $page) {
            $page = new WikiPage();
            $page->wiki = $wiki;
            $page->title = Wiki::titleize($title);
            if (\in_array(mb_strtolower($page->title), self::DEFAULT_PROTECTED_PAGES, true)) {
                $page->protected = true;
            }
        }

        return $page;
    }

    public function sidebar(Wiki $wiki): ?WikiPage
    {
        $page = $this->findPage($wiki, 'Sidebar', false);

        return $page?->content ? $page : null;
    }

    public function isEditable(WikiPage $page, ?User $user = null): bool
    {
        return !$page->protected || $this->auth->allowedTo('protect_wiki_pages', $page->wiki->project, $user);
    }

    /** @return list<WikiPage> root first */
    public function ancestors(WikiPage $page): array
    {
        $out = [];
        $seen = [];
        $p = $page->parent;
        while (null !== $p && !isset($seen[$p->id])) {
            $seen[$p->id] = true;
            array_unshift($out, $p);
            $p = $p->parent;
        }

        return $out;
    }

    /** @return list<WikiPage> */
    public function descendants(WikiPage $page): array
    {
        if (null === $page->id) {
            return [];
        }
        $byParent = [];
        foreach ($this->em->getRepository(WikiPage::class)->findBy(['wiki' => $page->wiki]) as $p) {
            if ($p->parent) {
                $byParent[$p->parent->id][] = $p;
            }
        }
        $out = [];
        $walk = function (int $id) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$id] ?? [] as $child) {
                if (!isset($out[$child->id])) {
                    $out[$child->id] = $child;
                    $walk((int) $child->id);
                }
            }
        };
        $walk((int) $page->id);

        return array_values($out);
    }

    /** @return list<WikiPage> */
    public function children(WikiPage $page): array
    {
        if (null === $page->id) {
            return [];
        }

        return $this->em->getRepository(WikiPage::class)->findBy(['parent' => $page], ['title' => 'ASC']);
    }

    // ---- versions ----

    public function versionText(WikiContentVersion $v): string
    {
        $data = \is_resource($v->data) ? (string) stream_get_contents($v->data) : (string) $v->data;
        if ('gzip' === $v->compression) {
            // Redmine uses Zlib::Deflate.deflate (zlib stream)
            $decoded = @gzuncompress($data);
            if (false === $decoded) {
                $decoded = @gzdecode($data);
            }
            if (false === $decoded) {
                $decoded = @gzinflate($data);
            }

            return false === $decoded ? '' : $decoded;
        }

        return $data;
    }

    public function setVersionText(WikiContentVersion $v, string $text): void
    {
        if ('gzip' === $this->settings->string('wiki_compression')) {
            $compressed = gzcompress($text, 9);
            if (false !== $compressed) {
                $v->data = $compressed;
                $v->compression = 'gzip';

                return;
            }
        }
        $v->data = $text;
        $v->compression = '';
    }

    public function version(WikiContent $content, int $version): ?WikiContentVersion
    {
        return $this->em->getRepository(WikiContentVersion::class)->findOneBy(['wikiContentId' => $content->id, 'version' => $version]);
    }

    public function versionsCount(WikiContent $content): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wiki_content_versions WHERE wiki_content_id = ?', [$content->id]);
    }

    public function previousVersion(WikiContentVersion $v): ?WikiContentVersion
    {
        return $this->em->createQuery('SELECT v FROM App\Entity\WikiContentVersion v WHERE v.wikiContentId = :c AND v.version < :n ORDER BY v.version DESC')
            ->setParameters(['c' => $v->wikiContentId, 'n' => $v->version])->setMaxResults(1)->getOneOrNullResult();
    }

    public function nextVersion(WikiContentVersion $v): ?WikiContentVersion
    {
        return $this->em->createQuery('SELECT v FROM App\Entity\WikiContentVersion v WHERE v.wikiContentId = :c AND v.version > :n ORDER BY v.version ASC')
            ->setParameters(['c' => $v->wikiContentId, 'n' => $v->version])->setMaxResults(1)->getOneOrNullResult();
    }

    /** @return list<WikiContentVersion> */
    public function versions(WikiContent $content, int $offset = 0, ?int $limit = null): array
    {
        return $this->em->createQuery('SELECT v FROM App\Entity\WikiContentVersion v WHERE v.wikiContentId = :c ORDER BY v.version DESC')
            ->setParameter('c', $content->id)->setFirstResult($offset)->setMaxResults($limit)->getResult();
    }

    public function updatedOn(WikiPage $page): ?\DateTimeImmutable
    {
        return $page->content?->updatedOn;
    }

    /**
     * WikiPage#save_with_content: saves the page and, when the text changed, the content with a new version.
     *
     * @param int|null $lockVersion expected current version (optimistic locking), null to skip the check
     *
     * @return list<string> validation errors (empty on success)
     *
     * @throws StaleWikiContentException
     */
    public function saveWithContent(WikiPage $page, string $text, ?string $comments, User $author, ?int $lockVersion = null): array
    {
        $errors = $this->validatePage($page);
        $content = $page->content;
        $isNewContent = null === $content;
        $textChanged = $isNewContent || $content->text !== $text;
        if ($textChanged && '' === trim($text)) {
            $errors[] = $this->fieldError('field_text', 'activerecord.errors.messages.blank');
        }
        if (null !== $comments && mb_strlen($comments) > 1024) {
            $errors[] = $this->fieldError('field_comments', 'activerecord.errors.messages.too_long', ['count' => 1024]);
        }
        if ([] !== $errors) {
            return $errors;
        }
        if (!$isNewContent && null !== $lockVersion && $lockVersion !== $content->version) {
            throw new StaleWikiContentException();
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {

            $this->beforeSavePage($page);
            $this->em->persist($page);
            $this->em->flush();
            $this->afterSavePage($page);
            if ($textChanged) {
                $now = new \DateTimeImmutable();
                if ($isNewContent) {
                    $content = new WikiContent();
                    $content->page = $page;
                    $content->version = 1;
                    $page->content = $content;
                } else {
                    // optimistic locking: UPDATE ... WHERE version = <current>
                    $current = (int) $conn->fetchOne('SELECT version FROM wiki_contents WHERE id = ?', [$content->id]);
                    if ($current !== $content->version) {
                        throw new StaleWikiContentException();
                    }
                    ++$content->version;
                }
                $content->text = $text;
                $content->comments = $comments ?? '';
                $content->author = $author;
                $content->updatedOn = $now;
                $this->em->persist($content);
                $this->em->flush();
                $this->createVersion($content);
                $this->em->flush();
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return [];
    }

    /** WikiContent#create_version (after_save) */
    private function createVersion(WikiContent $content): WikiContentVersion
    {
        $v = new WikiContentVersion();
        $v->wikiContentId = (int) $content->id;
        $v->pageId = (int) $content->page->id;
        $v->author = $content->author;
        $v->comments = $content->comments;
        $v->updatedOn = $content->updatedOn;
        $v->version = $content->version;
        $this->setVersionText($v, (string) $content->text);
        $this->em->persist($v);

        return $v;
    }

    /** @return list<string> */
    public function validatePage(WikiPage $page): array
    {
        $errors = [];
        if ('' === $page->title) {
            $errors[] = $this->fieldError('field_title', 'activerecord.errors.messages.blank');
        } elseif (preg_match('/[,.\/?;|\s]/u', $page->title)) {
            $errors[] = $this->fieldError('field_title', 'activerecord.errors.messages.invalid');
        } elseif (mb_strlen($page->title) > 255) {
            $errors[] = $this->fieldError('field_title', 'activerecord.errors.messages.too_long', ['count' => 255]);
        } else {
            $dup = $this->em->createQuery('SELECT COUNT(p.id) FROM App\Entity\WikiPage p WHERE p.wiki = :w AND LOWER(p.title) = LOWER(:t)'.(null !== $page->id ? ' AND p.id <> :id' : ''))
                ->setParameters(array_filter(['w' => $page->wiki, 't' => $page->title, 'id' => $page->id], static fn ($v) => null !== $v))->getSingleScalarResult();
            if ((int) $dup > 0) {
                $errors[] = $this->fieldError('field_title', 'activerecord.errors.messages.taken');
            }
        }
        if (null !== $page->parent) {
            if ($page->parent === $page || (null !== $page->id && $page->parent->id === $page->id) || \in_array($page, $this->ancestors($page->parent), true)) {
                $errors[] = $this->fieldError('field_parent_title', 'activerecord.errors.messages.circular_dependency');
            } elseif ($page->parent->wiki->id !== $page->wiki->id) {
                $errors[] = $this->fieldError('field_parent_title', 'activerecord.errors.messages.not_same_project');
            }
        }

        return $errors;
    }

    private ?\App\I18n\Localizer $i18n = null;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setLocalizer(\App\I18n\Localizer $i18n): void
    {
        $this->i18n = $i18n;
    }

    private function fieldError(string $field, string $message, array $params = []): string
    {
        if (null === $this->i18n) {
            return $field.' '.$message;
        }

        return $this->i18n->l($field).' '.$this->i18n->l($message, $params);
    }

    // ---- rename / move ----

    /** @var array<int, array{title: string, wiki: Wiki, redirect: bool}> original state of pages being saved */
    private array $originals = [];

    /** Remembers the persisted title/wiki of a page before changing them (title_was / wiki_id_was) */
    public function track(WikiPage $page, bool $redirectExistingLinks = true): void
    {
        if (null !== $page->id && !isset($this->originals[$page->id])) {
            $this->originals[$page->id] = ['title' => $page->title, 'wiki' => $page->wiki, 'redirect' => $redirectExistingLinks];
        } elseif (null !== $page->id) {
            $this->originals[$page->id]['redirect'] = $redirectExistingLinks;
        }
    }

    /** Saves a page without content change (rename, parent change, protect...) @return list<string> */
    public function savePage(WikiPage $page): array
    {
        $errors = $this->validatePage($page);
        if ([] !== $errors) {
            return $errors;
        }
        $this->beforeSavePage($page);
        $this->em->persist($page);
        $this->em->flush();
        $this->afterSavePage($page);
        $this->em->flush();

        return [];
    }

    /** WikiPage before_save :handle_rename_or_move, :update_wiki_start_page */
    private function beforeSavePage(WikiPage $page): void
    {
        $orig = null !== $page->id ? ($this->originals[$page->id] ?? null) : null;
        if (null === $orig) {
            return;
        }
        $titleWas = $orig['title'];
        $wikiWas = $orig['wiki'];
        if ($titleWas !== $page->title || $wikiWas->id !== $page->wiki->id) {
            foreach ($this->em->getRepository(WikiRedirect::class)->findBy(['redirectsTo' => $titleWas, 'redirectsToWikiId' => $wikiWas->id]) as $r) {
                $r->redirectsTo = $page->title;
                $r->redirectsToWikiId = (int) $page->wiki->id;
                if ($r->title === $r->redirectsTo && $r->wikiId === $r->redirectsToWikiId) {
                    $this->em->remove($r);
                }
            }
            $this->em->createQuery('DELETE FROM App\Entity\WikiRedirect r WHERE r.wikiId = :w AND r.title = :t')
                ->setParameters(['w' => $page->wiki->id, 't' => $page->title])->execute();
            if ($orig['redirect']) {
                $r = new WikiRedirect();
                $r->wikiId = (int) $wikiWas->id;
                $r->title = $titleWas;
                $r->redirectsToWikiId = (int) $page->wiki->id;
                $r->redirectsTo = $page->title;
                $this->em->persist($r);
            }
        }
        if ($wikiWas->id !== $page->wiki->id && null !== $page->parent && $page->parent->wiki->id !== $page->wiki->id) {
            $page->parent = null;
        }
        if ($this->isStartPageRequested[$page->id] ?? false) {
            $page->wiki->startPage = $page->title;
        }
    }

    /** WikiPage after_save :handle_children_move */
    private function afterSavePage(WikiPage $page): void
    {
        $orig = null !== $page->id ? ($this->originals[$page->id] ?? null) : null;
        unset($this->originals[$page->id], $this->isStartPageRequested[$page->id]);
        if (null === $orig || $orig['wiki']->id === $page->wiki->id) {
            return;
        }
        foreach ($this->children($page) as $child) {
            $this->track($child, $orig['redirect']);
            $child->wiki = $page->wiki;
            if ([] !== $this->savePage($child)) {
                $child->parent = null;
                $this->em->flush();
            }
        }
    }

    /** @var array<int, bool> */
    private array $isStartPageRequested = [];

    public function isStartPage(WikiPage $page): bool
    {
        $orig = null !== $page->id ? ($this->originals[$page->id]['title'] ?? $page->title) : $page->title;

        return $page->wiki->startPage === $orig;
    }

    public function requestStartPage(WikiPage $page): void
    {
        if (null !== $page->id) {
            $this->isStartPageRequested[$page->id] = true;
        }
    }

    // ---- destroy ----

    /** WikiPage#destroy (children nullified, content/versions/attachments/watchers/redirects removed) */
    public function destroyPage(WikiPage $page): void
    {
        foreach ($this->children($page) as $child) {
            $child->parent = null;
        }
        $this->em->createQuery('DELETE FROM App\Entity\WikiRedirect r WHERE r.redirectsToWikiId = :w AND r.redirectsTo = :t')
            ->setParameters(['w' => $page->wiki->id, 't' => $page->title])->execute();
        if ($page->content) {
            $this->em->createQuery('DELETE FROM App\Entity\WikiContentVersion v WHERE v.wikiContentId = :c')->setParameter('c', $page->content->id)->execute();
            $this->em->remove($page->content);
            $page->content = null;
        }
        $this->attachments->deleteForContainer('WikiPage', (int) $page->id);
        $this->watchers->removeAll('WikiPage', (int) $page->id);
        $this->em->remove($page);
        $this->em->flush();
    }

    /** Destroys a version; reverts the content to the latest remaining version or destroys the page if none left */
    public function destroyVersion(WikiPage $page, WikiContentVersion $version): void
    {
        $this->em->remove($version);
        $this->em->flush();
        $content = $page->content;
        $latest = $this->em->createQuery('SELECT v FROM App\Entity\WikiContentVersion v WHERE v.wikiContentId = :c ORDER BY v.version DESC')
            ->setParameter('c', $content->id)->setMaxResults(1)->getOneOrNullResult();
        if ($latest instanceof WikiContentVersion && $content->version !== $latest->version) {
            $content->author = $latest->author;
            $content->text = $this->versionText($latest);
            $content->comments = $latest->comments;
            $content->version = $latest->version;
            $content->updatedOn = $latest->updatedOn;
            $this->em->flush();
        } elseif (null === $latest) {
            $this->destroyPage($page);
        }
    }

    /** Wiki#destroy (pages + redirects) */
    public function destroyWiki(Wiki $wiki): void
    {
        foreach ($this->em->getRepository(WikiPage::class)->findBy(['wiki' => $wiki]) as $page) {
            $page->parent = null;
        }
        $this->em->flush();
        foreach ($this->em->getRepository(WikiPage::class)->findBy(['wiki' => $wiki]) as $page) {
            $this->destroyPage($page);
        }
        $this->em->createQuery('DELETE FROM App\Entity\WikiRedirect r WHERE r.wikiId = :w OR r.redirectsToWikiId = :w')->setParameter('w', $wiki->id)->execute();
        $this->watchers->removeAll('Wiki', (int) $wiki->id);
        $this->em->remove($wiki);
        $this->em->flush();
    }

    /** WikisController#destroy: deletes the wiki of the project with all its pages; a new empty wiki takes its place */
    public function recreate(Wiki $wiki, Project $project): void
    {
        $this->destroyWiki($wiki);
        $this->createDefault($project);
        $this->em->flush();
    }

    // ---- diff / annotate ----

    public function diff(WikiPage $page, ?int $versionTo = null, ?int $versionFrom = null): ?WikiDiff
    {
        if (null === $page->content) {
            return null;
        }
        $to = $this->version($page->content, $versionTo ?? $page->content->version);
        if (null === $to) {
            return null;
        }
        $from = null !== $versionFrom ? $this->version($page->content, $versionFrom) : $this->previousVersion($to);
        if (null === $from) {
            return null;
        }
        if ($from->version > $to->version) {
            [$to, $from] = [$from, $to];
        }

        return new WikiDiff($to, $from, $this->versionText($to), $this->versionText($from));
    }

    /**
     * WikiAnnotate: for each line of the version, [version, author, text].
     *
     * @return array{content: WikiContentVersion, lines: list<array{0: int, 1: ?User, 2: string}>}|null
     */
    public function annotate(WikiPage $page, ?int $version = null): ?array
    {
        if (null === $page->content) {
            return null;
        }
        $content = $this->version($page->content, $version ?? $page->content->version);
        if (null === $content) {
            return null;
        }
        $current = $content;
        $currentLines = preg_split('/\r?\n/', $this->versionText($current)) ?: [];
        if ([''] === $currentLines) {
            $currentLines = [];
        }
        $lines = array_map(static fn ($t) => [null, null, $t], $currentLines);
        $positions = array_keys($currentLines); // index in $current lines => index in $lines
        while ($previous = $this->previousVersion($current)) {
            $prevLines = preg_split('/\r?\n/', $this->versionText($previous)) ?: [];
            $match = \App\Service\Wiki\Lcs::matches($prevLines, $currentLines); // current index => prev index
            foreach ($currentLines as $j => $_) {
                if (!isset($match[$j]) && isset($positions[$j]) && null !== $positions[$j] && null === $lines[$positions[$j]][0]) {
                    $lines[$positions[$j]][0] = $current->version;
                    $lines[$positions[$j]][1] = $current->author;
                }
            }
            $newPositions = array_fill(0, \count($prevLines), null);
            foreach ($match as $j => $i) {
                $newPositions[$i] = $positions[$j] ?? null;
            }
            $positions = $newPositions;
            $currentLines = $prevLines;
            $current = $previous;
            $done = true;
            foreach ($lines as $l) {
                if (null === $l[0]) {
                    $done = false;
                    break;
                }
            }
            if ($done) {
                break;
            }
        }
        foreach ($lines as &$l) {
            if (null === $l[0]) {
                $l[0] = $current->version;
                if (1 === $current->version) {
                    $l[1] = $current->author;
                }
            }
        }
        unset($l);

        return ['content' => $content, 'lines' => $lines];
    }

    // ---- sections ----

    public function supportsSectionEdit(): bool
    {
        return \in_array($this->settings->textFormatting(), ['textile', 'common_mark', 'markdown'], true);
    }

    /** @return array{0: string, 1: string} section text and its hash */
    public function getSection(string $text, int $index): array
    {
        $section = $this->extractSections($text, $index)[1];

        return [$section, self::sectionHash($section)];
    }

    /** @throws StaleWikiContentException when the section was changed meanwhile */
    public function updateSection(string $text, int $index, string $update, ?string $hash = null): string
    {
        $t = $this->extractSections($text, $index);
        if (null !== $hash && '' !== $hash && $hash !== self::sectionHash($t[1])) {
            throw new StaleWikiContentException();
        }
        if ('' !== $t[1]) {
            $t[1] = $update;
        }

        return implode("\n\n", array_filter($t, static fn ($s) => '' !== trim($s)));
    }

    public static function sectionHash(string $section): string
    {
        return substr(hash('sha256', $section), 0, 32);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function extractSections(string $text, int $index): array
    {
        return 'textile' === $this->settings->textFormatting() ? $this->extractTextileSections($text, $index) : $this->extractMarkdownSections($text, $index);
    }

    /** Redmine::WikiFormatting::SectionHelper#extract_sections (CommonMark) */
    private function extractMarkdownSections(string $text, int $index): array
    {
        $sections = ['', '', ''];
        $offset = 0;
        $i = 0;
        $l = 1;
        $insidePre = false;
        $parts = preg_split('/(^(?:(?!\s*$|#).+\r?\n\r?(?:=+|-+)|#+ .+|(?:~~~|```).*)\s*$)/m', $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($parts as $part) {
            $level = null;
            if (preg_match('/\A(~{3,}|`{3,})(\s*\S+)?\s*$/', $part, $m)) {
                if (!$insidePre) {
                    $insidePre = true;
                } elseif (empty($m[2])) {
                    $insidePre = false;
                }
            } elseif ($insidePre) {
                // nop
            } elseif (preg_match('/\A(#+) .+/', $part, $m)) {
                $level = \strlen($m[1]);
            } elseif (preg_match('/\A.+\r?\n\r?(=+|-+)\s*$/', $part, $m)) {
                $level = str_contains($m[1], '=') ? 1 : 2;
            }
            if (null !== $level) {
                ++$i;
                if (0 === $offset && $i === $index) {
                    $offset = 1;
                    $l = $level;
                } elseif (1 === $offset && $i > $index && $level <= $l) {
                    $offset = 2;
                }
            }
            $sections[$offset] .= $part;
        }

        return array_map('trim', $sections);
    }

    /** Redmine::WikiFormatting::Textile::Formatter#extract_sections (simplified: <pre> blocks are not ripped) */
    private function extractTextileSections(string $text, int $index): array
    {
        $before = '';
        $s = '';
        $after = '';
        $i = 0;
        $l = 1;
        $started = false;
        $ended = false;
        preg_match_all('/(((?:.*?)(\A|\r?\n\s*\r?\n))(h(\d+)((?:\([^)]*\)|\{[^}]*\}|\[[^\]]*\]|[<>=]|<>)*)\.(?::(\S+))?[ \t](.*?)$)|.*)/ms', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $m) {
            $all = $m[0];
            $heading = $m[4] ?? '';
            if ('' === $heading) {
                if ($ended) {
                    $after .= $all;
                } elseif ($started) {
                    $s .= $all;
                } else {
                    $before .= $all;
                }
                break;
            }
            $content = $m[2];
            $level = (int) $m[5];
            ++$i;
            if ($ended) {
                $after .= $all;
            } elseif ($i === $index) {
                $l = $level;
                $before .= $content;
                $s .= $heading;
                $started = true;
            } elseif ($i > $index) {
                $s .= $content;
                if ($level > $l) {
                    $s .= $heading;
                } else {
                    $after .= $heading;
                    $ended = true;
                }
            } else {
                $before .= $all;
            }
        }

        return [trim($before), trim($s), trim($after)];
    }

    /** Default content of a new wiki page (helper initial_page_content) */
    public function initialPageContent(WikiPage $page): string
    {
        return match ($this->settings->textFormatting()) {
            'textile' => 'h1. '.$page->prettyTitle(),
            'common_mark', 'markdown' => '# '.$page->prettyTitle(),
            default => '',
        };
    }

    /** @return list<Attachment> */
    public function attachmentsOf(WikiPage $page): array
    {
        return $this->attachments->forContainer('WikiPage', $page->id);
    }
}
