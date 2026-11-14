<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\Event\ContentEvent;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\WikiService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form renaming or moving a wiki page: wiki_page[title], wiki_page[redirect_existing_links],
 * wiki_page[is_start_page], wiki_page[wiki_id] (another project) and wiki_page[parent_id].
 */
final class WikiRenameForm
{
    public function __construct(
        private readonly WikiService $wikis,
        private readonly WikiPageAttributes $attributes,
        private readonly WikiLayout $layout,
        private readonly WikiOptions $options,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * Saves the page with its new title, wiki and parent when they are valid.
     *
     * @param Wiki $wiki the wiki the page is renamed from
     *
     * @return list<string> the errors that prevented saving
     */
    public function submit(Wiki $wiki, WikiPage $page, Request $request): array
    {
        $this->wikis->track($page, $this->redirectsExistingLinks($request));
        $this->attributes->assign($page, $wiki, $request->request->all('wiki_page'));
        $errors = $this->wikis->savePage($page);
        if ([] === $errors) {
            // acts_as_webhookable: wiki_page.updated (the content is unchanged: no e-mail)
            $this->events->dispatch(new ContentEvent('wiki_page', 'updated', $page, $this->currentUser->get(), ['project' => $page->wiki->project, 'text_changed' => false]));
        }

        return $errors;
    }

    /**
     * @param string       $originalTitle the title of the page before the form changed it
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(Wiki $wiki, WikiPage $page, string $originalTitle, Request $request, array $errors = []): array
    {
        $project = $wiki->project;
        $excluded = array_merge([$page], $this->wikis->descendants($page));

        return [
            'page' => $page,
            'original_title' => $originalTitle,
            'errors' => $errors,
            'redirect_existing_links' => $this->redirectsExistingLinks($request),
            'is_start_page' => $this->wikis->isStartPage($page),
            'can_manage_wiki' => $this->auth->allowedTo('manage_wiki', $project),
            'can_move' => $this->auth->allowedTo('rename_wiki_pages', $project),
            'wiki_options' => $this->options->wikis($page),
            'parent_options' => $this->options->pages($this->wikis->pages($page->wiki), $page->parent, $excluded),
            'ancestors' => $this->wikis->ancestors($page),
        ] + $this->layout->vars($wiki);
    }

    /** The parent page options of the wiki chosen in the form (wiki_page[wiki_id]), asked when it changes */
    public function parentOptions(Wiki $wiki, WikiPage $page, Request $request): string
    {
        $target = $this->em->find(Wiki::class, (int) ($request->query->all('wiki_page')['wiki_id'] ?? 0)) ?? $wiki;

        return '<option value=""></option>'.$this->options->pages($this->wikis->pages($target), null, array_merge([$page], $this->wikis->descendants($page)));
    }

    /** Whether the old title keeps leading to the page: yes unless the submitted form says otherwise */
    private function redirectsExistingLinks(Request $request): bool
    {
        if (!$request->isMethod('POST')) {
            return true;
        }

        return '0' !== (string) ($request->request->all('wiki_page')['redirect_existing_links'] ?? '1');
    }
}
