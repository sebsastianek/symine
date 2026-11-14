<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Api\ApiRenderer;
use App\Entity\Attachment;
use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\Event\ContentEvent;
use App\Security\ApiRequestMatcher;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\WikiService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The edit form of a wiki page, of the whole text or of one section: content[text], content[comments],
 * content[version] (optimistic locking), section and section_hash, wiki_page[parent_id],
 * wiki_page[deleted_attachment_ids][], attachments[..]; the REST API sends {"wiki_page": {"text": ..,
 * "comments": .., "version": .., "parent_title": .., "uploads": [..]}}.
 */
final class WikiPageForm
{
    public function __construct(
        private readonly WikiService $wikis,
        private readonly WikiFinder $finder,
        private readonly WikiPageAttributes $attributes,
        private readonly WikiLayout $layout,
        private readonly WikiOptions $options,
        private readonly AttachmentService $attachments,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $events,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The form as it is first shown: with the text of the page (of the version or of the section asked), or
     * the title of a page that does not exist yet.
     *
     * @return array<string, mixed>
     *
     * @throws NotFoundHttpException when the section asked does not exist
     */
    public function edit(Wiki $wiki, WikiPage $page, Request $request): array
    {
        if (null === $page->id && '' !== (string) $request->query->get('parent', '')) {
            $page->parent = $this->wikis->findPage($wiki, (string) $request->query->get('parent'));
        }
        $text = null;
        if (null !== $page->content) {
            $version = $request->query->get('version');
            if (null !== $version && '' !== $version) {
                $v = $this->wikis->version($page->content, (int) $version);
                $text = $v ? $this->wikis->versionText($v) : null;
            } else {
                $text = $page->content->text;
            }
        }
        if (null === $text || '' === trim($text)) {
            $text = $this->wikis->initialPageContent($page);
        }
        $section = null;
        $sectionHash = null;
        if ('' !== (string) $request->query->get('section', '') && $this->wikis->supportsSectionEdit()) {
            $section = (int) $request->query->get('section');
            [$text, $sectionHash] = $this->wikis->getSection($text, $section);
            if ('' === trim($text)) {
                throw new NotFoundHttpException();
            }
        }

        return $this->view($wiki, $page, $text, null, $page->content?->version, $section, $sectionHash);
    }

    /** Saves the page with what was submitted, unless it is invalid or the page changed in the meantime */
    public function submit(Wiki $wiki, WikiPage $page, Request $request): WikiPageSubmission
    {
        $project = $wiki->project;
        $user = $this->currentUser->get();
        $isApi = ApiRequestMatcher::isApiRequest($request);
        $wasNew = null === $page->id;
        $payload = $isApi ? ApiRenderer::payload($request) : $request->request->all();
        $pageParams = (array) ($payload['wiki_page'] ?? []);
        $this->wikis->track($page);
        $this->attributes->assign($page, $wiki, $pageParams);
        $contentParams = $payload['content'] ?? null;
        if (null === $contentParams && [] !== $pageParams) {
            $contentParams = array_intersect_key($pageParams, ['text' => 1, 'comments' => 1, 'version' => 1]);
        }
        $contentParams = (array) ($contentParams ?? []);
        $comments = isset($contentParams['comments']) ? (string) $contentParams['comments'] : null;
        $text = isset($contentParams['text']) ? str_replace("\r\n", "\n", (string) $contentParams['text']) : null;
        $section = null;
        $sectionHash = null;
        $lockVersion = null;
        $previousText = $page->content?->text;
        try {
            if ('' !== (string) ($payload['section'] ?? '') && $this->wikis->supportsSectionEdit() && null !== $page->content) {
                $section = (int) $payload['section'];
                $sectionHash = (string) ($payload['section_hash'] ?? '');
                $newText = $this->wikis->updateSection((string) $page->content->text, $section, (string) $text, $sectionHash);
                $lockVersion = $page->content->version;
            } else {
                $newText = $text ?? (string) $page->content?->text;
                if (isset($contentParams['version']) && '' !== (string) $contentParams['version']) {
                    $lockVersion = (int) $contentParams['version'];
                }
            }
            $errors = $this->wikis->saveWithContent($page, $newText, $comments, $user, $lockVersion);
        } catch (StaleWikiContentException) {
            return new WikiPageSubmission([], true, $wasNew, (string) $text, $comments, $contentParams['version'] ?? null, $lockVersion, $section, $sectionHash);
        }
        if ([] !== $errors) {
            return new WikiPageSubmission($errors, false, $wasNew, (string) $text, $comments, $contentParams['version'] ?? null, $lockVersion, $section, $sectionHash);
        }
        // attachments
        $uploads = $isApi ? ($pageParams['uploads'] ?? null) : null;
        [$files, $attErrors] = $this->attachments->fromRequest($request, $user, \is_array($uploads) ? $uploads : null);
        $this->attachments->attachTo($files, 'WikiPage', (int) $page->id);
        if ($this->auth->allowedTo('delete_wiki_pages_attachments', $project) && $this->wikis->isEditable($page)) {
            foreach ((array) ($pageParams['deleted_attachment_ids'] ?? []) as $aid) {
                $a = $this->em->find(Attachment::class, (int) $aid);
                if ($a && 'WikiPage' === $a->containerType && $a->containerId === $page->id) {
                    $this->attachments->delete($a);
                }
            }
        }
        $this->em->flush();
        // WikiContent#send_notification_update: the update is only notified when the text changed (saved_change_to_text?),
        // and only the users newly mentioned by this edit are notified (Mentionable#get_mentioned_users)
        $this->events->dispatch(new ContentEvent('wiki_page', $wasNew ? 'created' : 'updated', $page, $user, [
            'content' => $page->content, 'project' => $project, 'attachments' => $files,
            'previous_text' => $wasNew ? null : $previousText,
            'text_changed' => $wasNew || $previousText !== $page->content?->text,
        ]));

        return new WikiPageSubmission([], false, $wasNew, (string) $text, $comments, $contentParams['version'] ?? null, $lockVersion, $section, $sectionHash, $attErrors);
    }

    /**
     * The form shown again with what was submitted, after errors.
     *
     * @return array<string, mixed>
     */
    public function rejected(Wiki $wiki, WikiPage $page, WikiPageSubmission $result): array
    {
        return $this->view($wiki, $page, $result->text, $result->comments, $result->lockVersion, $result->section, $result->sectionHash, $result->errors);
    }

    /**
     * The form shown again after an edit conflict: with the page as it is now saved (everything loaded so far
     * is forgotten) and the text as it was submitted.
     *
     * @param string $projectId the project and the page as the URL names them
     *
     * @return array<string, mixed>
     */
    public function conflicting(string $projectId, string $title, WikiPageSubmission $result): array
    {
        $this->em->clear();
        $wiki = $this->finder->wiki($projectId);
        $page = $this->wikis->findOrNewPage($wiki, $title);

        return $this->view($wiki, $page, $result->text, $result->comments, (int) ($result->submittedVersion ?? $page->content?->version), $result->section, $result->sectionHash);
    }

    /**
     * Attaches the files submitted with the "new file" form of a page.
     *
     * @return list<string> the files that could not be attached
     */
    public function addAttachments(WikiPage $page, Request $request): array
    {
        [$files, $errors] = $this->attachments->fromRequest($request, $this->currentUser->get());
        $this->attachments->attachTo($files, 'WikiPage', (int) $page->id);
        $this->em->flush();
        // WikiController#add_attachment only attaches the files: the page is not saved, no wiki_page.updated event

        return $errors;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    private function view(Wiki $wiki, WikiPage $page, string $text, ?string $comments, ?int $version, ?int $section, ?string $sectionHash, array $errors = []): array
    {
        $project = $wiki->project;
        $excluded = array_merge([$page], $this->wikis->descendants($page));
        $allPages = $this->wikis->pages($wiki);
        $canSetParent = null === $page->id || $this->auth->allowedTo('rename_wiki_pages', $project);

        return [
            'page' => $page,
            'text' => $text,
            'comments' => $comments,
            'version' => $version,
            'section' => $section,
            'section_hash' => $sectionHash,
            'errors' => $errors,
            'show_parent' => $canSetParent && [] !== $allPages,
            'parent_options' => $this->options->pages($allPages, $page->parent, $excluded),
            'attachments' => $this->wikis->attachmentsOf($page),
            'attachments_deletable' => $this->auth->allowedTo('delete_wiki_pages_attachments', $project) && $this->wikis->isEditable($page),
            'cancel_url' => null !== $page->id
                ? $this->urls->generate('wiki_show', ['project_id' => $project->identifier, 'id' => $page->title])
                : ($page->parent ? $this->urls->generate('wiki_show', ['project_id' => $project->identifier, 'id' => $page->parent->title]) : $this->urls->generate('wiki_index_by_title', ['project_id' => $project->identifier])),
            'ancestors' => $this->wikis->ancestors($page),
        ] + $this->layout->vars($wiki);
    }
}
