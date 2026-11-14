<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\Wiki;
use App\Entity\WikiContentVersion;
use App\Entity\WikiPage;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Service\TextFormatter;
use App\Service\WikiService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the pages about one wiki page show: the page itself (or one of its versions), its history, the
 * differences between two versions, who wrote each line, the preview of an edit and the questions asked
 * before deleting it.
 */
final class WikiPageView
{
    public function __construct(
        private readonly WikiService $wikis,
        private readonly WikiLayout $layout,
        private readonly WikiOptions $options,
        private readonly Authorization $auth,
        private readonly TextFormatter $formatter,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** What is shown for the page: its content or the version asked; null when there is nothing to show */
    public function revision(WikiPage $page, ?string $version): ?WikiPageRevision
    {
        if (null === $page->content) {
            return null;
        }
        if (null === $version) {
            return new WikiPageRevision($page, $page->content);
        }
        $versionRow = $this->wikis->version($page->content, (int) $version);

        return null === $versionRow ? null : new WikiPageRevision($page, $versionRow, $versionRow);
    }

    /** Whether the user may write the page: a page without content is then shown as its edit form */
    public function isWritable(WikiPage $page): bool
    {
        return $this->auth->allowedTo('edit_wiki_pages', $page->wiki->project) && $this->wikis->isEditable($page);
    }

    public function text(WikiPageRevision $revision): string
    {
        return $revision->version instanceof WikiContentVersion ? $this->wikis->versionText($revision->version) : (string) $revision->page->content->text;
    }

    /** @return array<string, mixed> */
    public function show(Wiki $wiki, WikiPageRevision $revision): array
    {
        $project = $wiki->project;
        $page = $revision->page;
        $text = $this->text($revision);
        $current = $revision->isCurrent();
        $editable = $this->wikis->isEditable($page);
        $sectionsEditable = $editable && $this->auth->allowedTo('edit_wiki_pages', $project) && $current && $this->wikis->supportsSectionEdit();
        $attachments = $this->wikis->attachmentsOf($page);

        return [
            'page' => $page,
            'content' => $revision->content,
            'text' => $text,
            'current' => $current,
            'previous' => $revision->version ? $this->wikis->previousVersion($revision->version) : null,
            'next' => $revision->version ? $this->wikis->nextVersion($revision->version) : null,
            'editable' => $editable,
            'html' => $this->formatter->format($text, $project, array_filter([
                'headings' => true,
                'object' => $page,
                'attachments' => $attachments,
                'edit_section_url' => $sectionsEditable ? $this->urls->generate('wiki_edit', ['project_id' => $project->identifier, 'id' => $page->title]) : null,
            ])),
            'attachments' => $attachments,
            'versions_count' => $this->wikis->versionsCount($page->content),
            'ancestors' => $this->wikis->ancestors($page),
        ] + $this->layout->vars($wiki);
    }

    /**
     * The file of a single exported page.
     *
     * @return array<string, mixed>
     */
    public function export(Wiki $wiki, WikiPageRevision $revision): array
    {
        return ['page' => $revision->page, 'project' => $wiki->project, 'text' => $this->text($revision)];
    }

    /** The HTML of the preview of the text typed in the edit form of a page (which may not exist yet) */
    public function preview(Wiki $wiki, ?WikiPage $page, Request $request): string
    {
        $content = $request->request->all('content');
        $text = (string) ($content['text'] ?? $request->request->get('text', ''));
        if ('' === trim($text)) {
            return '<p class="empty-preview">'.htmlspecialchars($this->i18n->l('label_nothing_to_preview')).'</p>';
        }
        $attachments = $page ? $this->wikis->attachmentsOf($page) : [];

        return $this->formatter->format($text, $wiki->project, array_filter(['headings' => true, 'object' => $page, 'attachments' => $attachments]));
    }

    /**
     * @param array{offset: int, limit: int} $pager the pager of the versions of the page
     *
     * @return array<string, mixed>
     */
    public function history(Wiki $wiki, WikiPage $page, array $pager): array
    {
        $versions = $this->wikis->versions($page->content, $pager['offset'], $pager['limit'] + 1);

        return [
            'page' => $page,
            'versions' => \array_slice($versions, 0, $pager['limit'] + 1),
            'version_count' => $pager['count'],
            'pager' => $pager,
            'ancestors' => $this->wikis->ancestors($page),
        ] + $this->layout->vars($wiki);
    }

    /**
     * The differences between two versions (the version given and the one before it by default).
     *
     * @param mixed $version     the "version" parameter, the latest when empty
     * @param mixed $versionFrom the "version_from" parameter
     *
     * @return array<string, mixed>
     *
     * @throws NotFoundHttpException when the versions do not exist
     */
    public function diff(Wiki $wiki, WikiPage $page, mixed $version, mixed $versionFrom): array
    {
        $diff = $this->wikis->diff($page, null !== $version && '' !== $version ? (int) $version : null, null !== $versionFrom && '' !== $versionFrom ? (int) $versionFrom : null);
        if (null === $diff) {
            throw new NotFoundHttpException();
        }

        return [
            'page' => $page,
            'diff' => $diff,
            'ancestors' => $this->wikis->ancestors($page),
        ] + $this->layout->vars($wiki);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundHttpException when the version does not exist
     */
    public function annotate(Wiki $wiki, WikiPage $page, int $version): array
    {
        $annotate = $this->wikis->annotate($page, $version);
        if (null === $annotate) {
            throw new NotFoundHttpException();
        }
        $colors = [];
        foreach ($annotate['lines'] as $line) {
            $colors[$line[0]] ??= \count($colors) % 12;
        }

        return [
            'page' => $page,
            'annotate' => $annotate,
            'colors' => $colors,
            'ancestors' => $this->wikis->ancestors($page),
        ] + $this->layout->vars($wiki);
    }

    /**
     * The form asking what to do with the child pages of a page being deleted.
     *
     * @return array<string, mixed>
     */
    public function destroy(Wiki $wiki, WikiPage $page): array
    {
        $descendants = $this->wikis->descendants($page);
        $excluded = array_merge([$page], $descendants);
        $reassignable = array_values(array_filter($this->wikis->pages($wiki), static fn (WikiPage $p) => !\in_array($p, $excluded, true)));

        return [
            'page' => $page,
            'descendants_count' => \count($descendants),
            'reassignable_options' => [] === $reassignable ? '' : $this->options->pages($reassignable, null),
            'ancestors' => $this->wikis->ancestors($page),
        ] + $this->layout->vars($wiki);
    }
}
