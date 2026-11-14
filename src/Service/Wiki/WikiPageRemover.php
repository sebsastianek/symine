<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\Event\ContentEvent;
use App\Security\ApiRequestMatcher;
use App\Security\CurrentUser;
use App\Service\WikiService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Deletes a wiki page, once it is known what becomes of its child pages (the "todo" parameter): left without
 * parent (nullify), deleted too (destroy) or given to another page (reassign, reassign_to_id).
 */
final class WikiPageRemover
{
    public function __construct(
        private readonly WikiService $wikis,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @return bool false when nothing was deleted because the user must first say what to do with the child pages
     */
    public function remove(Wiki $wiki, WikiPage $page, Request $request): bool
    {
        $descendants = $this->wikis->descendants($page);
        if ([] !== $descendants) {
            $todo = (string) $request->request->get('todo', $request->query->get('todo', ''));
            switch ($todo) {
                case 'nullify':
                    break;
                case 'destroy':
                    foreach (array_reverse($descendants) as $d) {
                        $this->wikis->destroyPage($d);
                    }
                    break;
                case 'reassign':
                    $target = $this->em->find(WikiPage::class, (int) $request->request->get('reassign_to_id'));
                    if (null === $target || $target->wiki->id !== $wiki->id || \in_array($target, $descendants, true) || $target === $page) {
                        return false;
                    }
                    foreach ($this->wikis->children($page) as $child) {
                        $child->parent = $target;
                    }
                    $this->em->flush();
                    break;
                default:
                    // the REST API has no such question: the child pages are left without parent
                    if (!ApiRequestMatcher::isApiRequest($request)) {
                        return false;
                    }
            }
        }
        $this->events->dispatch(new ContentEvent('wiki_page', 'deleted', $page, $this->currentUser->get(), ['project' => $wiki->project, 'title' => $page->title]));
        $this->wikis->destroyPage($page);

        return true;
    }
}
