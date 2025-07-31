<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        // If user is logged in, redirect to projects
        if ($this->getUser()) {
            return $this->redirectToRoute('project_index');
        }
        
        // If not logged in, redirect to login
        return $this->redirectToRoute('login');
    }
}