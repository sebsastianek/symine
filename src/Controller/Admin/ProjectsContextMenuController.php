<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Service\Admin\ProjectsContextMenu;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of ContextMenus::ProjectsController (context menu of the administration projects list). */
final class ProjectsContextMenuController extends AbstractController
{
    public function __construct(private readonly ProjectsContextMenu $menu)
    {
    }

    #[Route('/admin/projects_context_menu', name: 'projects_context_menu', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin();

        return $this->render('context_menus/projects.html.twig', $this->menu->view($request));
    }
}
