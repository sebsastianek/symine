<?php

declare(strict_types=1);

namespace App\Service\Content\Attachments;

use App\Entity\Attachment;
use App\Entity\Document;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Project;
use App\Entity\Version;
use App\Entity\WikiPage;
use App\I18n\Localizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Knows what attachments are attached to (acts_as_attachable containers): the object itself, its project,
 * its page and the menu item it belongs to.
 */
final class AttachmentContainers
{
    /** object_type route param (plural underscore) => container type */
    private const array OBJECT_TYPES = ['issues' => 'Issue', 'wiki_pages' => 'WikiPage', 'documents' => 'Document', 'messages' => 'Message', 'news' => 'News', 'projects' => 'Project', 'versions' => 'Version'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** Container type named by the object_type of a URL such as /attachments/issues/1/edit (404 when unknown) */
    public function type(string $objectType): string
    {
        return self::OBJECT_TYPES[$objectType] ?? throw new NotFoundHttpException();
    }

    /** 404 unless the container exists */
    public function mustExist(string $type, int $id): void
    {
        $class = 'App\\Entity\\'.$type;
        if (!class_exists($class) || null === $this->em->find($class, $id)) {
            throw new NotFoundHttpException();
        }
    }

    public function of(Attachment $a): ?object
    {
        if (null === $a->containerType || null === $a->containerId) {
            return null;
        }
        $class = 'App\\Entity\\'.$a->containerType;

        return class_exists($class) ? $this->em->find($class, $a->containerId) : null;
    }

    public function projectOf(Attachment $a): ?Project
    {
        $c = $this->of($a);

        return match (true) {
            null === $c => null,
            $c instanceof Project => $c,
            $c instanceof Issue => $c->project,
            $c instanceof Journal => $c->issue->project,
            $c instanceof WikiPage => $c->wiki->project,
            $c instanceof Message => $c->board->project,
            property_exists($c, 'project') => $c->project,
            default => null,
        };
    }

    /** URL of the page of the container, null when it has none */
    public function url(Attachment $a): ?string
    {
        $c = $this->of($a);

        return match (true) {
            $c instanceof Issue => $this->urls->generate('issue_show', ['id' => $c->id]),
            $c instanceof Project => $this->safeUrl('project_files', ['project_id' => $c->identifier]),
            $c instanceof Version => $this->safeUrl('project_files', ['project_id' => $c->project->identifier]),
            $c instanceof WikiPage => $this->safeUrl('wiki_show', ['project_id' => $c->wiki->project->identifier, 'id' => $c->title]),
            $c instanceof News => $this->safeUrl('news_show', ['id' => $c->id]),
            $c instanceof Document => $this->safeUrl('document_show', ['id' => $c->id]),
            $c instanceof Message => $this->safeUrl('board_message_show', ['project_id' => $c->board->project->identifier, 'board_id' => $c->board->id, 'id' => $c->parent?->id ?? $c->id]),
            default => null,
        };
    }

    /** HTML link to the container (its name alone when it has no page), null for an unknown container */
    public function link(Attachment $a): ?string
    {
        $c = $this->of($a);
        $url = $this->url($a);
        $label = match (true) {
            $c instanceof Issue => $c->tracker->name.' #'.$c->id.': '.$c->subject,
            $c instanceof Project => $this->i18n->l('label_file_plural'),
            $c instanceof WikiPage => $c->title,
            $c instanceof Message => $c->subject,
            $c instanceof News => $c->title,
            $c instanceof Document => $c->title ?? '',
            $c instanceof Version => $c->name,
            default => null,
        };
        if (null === $label) {
            return null;
        }

        return null !== $url ? '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($label).'</a>' : htmlspecialchars($label);
    }

    /** The item of the project menu the container belongs to */
    public function menuItem(Attachment $a): ?string
    {
        return match ($a->containerType) {
            'WikiPage' => 'wiki',
            'Message' => 'boards',
            'Project', 'Version' => 'files',
            'Issue', 'Journal' => 'issues',
            'News' => 'news',
            'Document' => 'documents',
            default => null,
        };
    }

    /** @param array<string, mixed> $params */
    private function safeUrl(string $route, array $params): ?string
    {
        try {
            return $this->urls->generate($route, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
