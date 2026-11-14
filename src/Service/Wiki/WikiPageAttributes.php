<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\Project;
use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\Event\ContentEvent;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\WikiService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * What a user may change of a wiki page besides its content: its title, parent and wiki (rename), whether it
 * is the start page and whether it is protected.
 */
final class WikiPageAttributes
{
    public function __construct(
        private readonly WikiService $wikis,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * safe_attributes of WikiPage: parent_id/parent_title/title/wiki_id (new page or rename permission), is_start_page (manage_wiki)
     *
     * @param Wiki                 $wiki   the wiki the page is edited in
     * @param array<string, mixed> $params
     */
    public function assign(WikiPage $page, Wiki $wiki, array $params): void
    {
        $project = $wiki->project;
        $canRename = null === $page->id || $this->auth->allowedTo('rename_wiki_pages', $project);
        if ($canRename) {
            if (isset($params['wiki_id']) && (int) $params['wiki_id'] !== $page->wiki->id) {
                $target = $this->em->find(Wiki::class, (int) $params['wiki_id']);
                if ($target && $this->auth->allowedTo('rename_wiki_pages', $target->project)) {
                    $page->wiki = $target;
                }
            }
            if (isset($params['title']) && '' !== trim((string) $params['title'])) {
                $page->title = Wiki::titleize((string) $params['title']);
            } elseif (isset($params['title'])) {
                $page->title = '';
            }
            if (\array_key_exists('parent_id', $params)) {
                $parent = '' === (string) $params['parent_id'] ? null : $this->em->find(WikiPage::class, (int) $params['parent_id']);
                $page->parent = $parent;
            }
            if (\array_key_exists('parent_title', $params)) {
                $t = trim((string) $params['parent_title']);
                $page->parent = '' === $t ? null : $this->wikis->findPage($page->wiki, $t);
            }
        }
        if (isset($params['is_start_page']) && \in_array((string) $params['is_start_page'], ['1', 'true'], true) && $this->auth->allowedTo('manage_wiki', $project)) {
            $this->wikis->requestStartPage($page);
        }
    }

    /** Protects or unprotects a page (wiki#protect) */
    public function protect(WikiPage $page, Project $project, bool $protected): void
    {
        $page->protected = $protected;
        $this->em->flush();
        // acts_as_webhookable: wiki_page.updated (the content is unchanged: no e-mail)
        $this->events->dispatch(new ContentEvent('wiki_page', 'updated', $page, $this->currentUser->get(), ['project' => $project, 'text_changed' => false]));
    }
}
