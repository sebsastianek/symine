<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Service\Admin\Users\UsersContextMenu;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's ContextMenus::UsersController (context menu of the admin user list). */
final class UsersContextMenuController extends AbstractController
{
    public function __construct(private readonly UsersContextMenu $menu)
    {
    }

    #[Route('/users/context_menu', name: 'users_context_menu', methods: ['GET', 'POST'], priority: 10)]
    public function index(Request $request): Response
    {
        $this->requireAdmin();

        return $this->render('context_menus/users.html.twig', $this->menu->view($request));
    }
}
