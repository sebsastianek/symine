<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tracking\ContextMenu\IssueContextMenu;
use App\Service\Tracking\ContextMenu\IssueSelectionFinder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of ContextMenus::IssuesController + context_menus/issues.html.erb + ContextMenusHelper.
 * Returns the HTML fragment displayed by context_menu.js on the issue list.
 */
final class ContextMenusController extends AbstractController
{
    public function __construct(
        private readonly IssueSelectionFinder $finder,
        private readonly IssueContextMenu $menu,
    ) {
    }

    #[Route('/issues/context_menu', name: 'issues_context_menu', methods: ['GET', 'POST'], priority: 10)]
    public function issues(Request $request): Response
    {
        $selection = $this->finder->find($request);

        return $this->render('context_menus/issues.html.twig', $this->menu->view($selection, $request));
    }
}
