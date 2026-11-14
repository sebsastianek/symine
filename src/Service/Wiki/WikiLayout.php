<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\Wiki;
use App\Security\Authorization;
use App\Service\WikiService;

/**
 * What every page of a wiki shows around its content: the project, the wiki and its sidebar page.
 */
final class WikiLayout
{
    public function __construct(
        private readonly WikiService $wikis,
        private readonly Authorization $auth,
    ) {
    }

    /** @return array<string, mixed> */
    public function vars(Wiki $wiki): array
    {
        $project = $wiki->project;

        return [
            'project' => $project,
            'wiki' => $wiki,
            'sidebar_page' => $this->wikis->sidebar($wiki),
            'sidebar_editable' => $this->auth->allowedTo('edit_wiki_pages', $project) && $this->wikis->isEditable($this->wikis->findOrNewPage($wiki, 'Sidebar')),
        ];
    }
}
