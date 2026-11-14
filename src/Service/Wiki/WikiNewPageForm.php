<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\I18n\Localizer;
use App\Service\WikiService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The "new wiki page" form: it only asks for a title (and carries the parent page), then leads to the edit
 * form of that page.
 */
final class WikiNewPageForm
{
    public function __construct(
        private readonly WikiService $wikis,
        private readonly WikiLayout $layout,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * Reads the form; a submitted (POST) title is validated.
     *
     * @return array<string, mixed> the variables of the form: page, errors, title, parent...
     */
    public function handle(Wiki $wiki, Request $request): array
    {
        $page = new WikiPage();
        $page->wiki = $wiki;
        $title = (string) ($request->request->get('title') ?? $request->query->get('title', ''));
        $page->title = Wiki::titleize($title);
        $errors = [];
        $parent = (string) ($request->request->get('parent') ?? $request->query->get('parent', ''));
        if ($request->isMethod('POST')) {
            if (!$this->wikis->isEditable($page)) {
                $page->title = '';
            }
            $errors = array_values(array_filter($this->wikis->validatePage($page), fn ($e) => str_starts_with($e, $this->i18n->l('field_title'))));
        }

        return ['page' => $page, 'errors' => $errors, 'title' => $title, 'parent' => $parent] + $this->layout->vars($wiki);
    }

    /**
     * The route parameters of the page the valid form leads to.
     *
     * @param array<string, mixed> $form what handle() returned
     *
     * @return array<string, mixed>
     */
    public function target(array $form): array
    {
        return array_filter(['project_id' => $form['project']->identifier, 'id' => $form['page']->title, 'parent' => '' !== $form['parent'] ? $form['parent'] : null]);
    }
}
