<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Welcome\LatestNews;
use App\Service\Welcome\RobotsTxt;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WelcomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(LatestNews $news): Response
    {
        return $this->render('welcome/index.html.twig', ['news' => $news->forCurrentUser(), 'body_css' => 'controller-welcome action-index']);
    }

    #[Route('/robots.{_format}', name: 'robots', requirements: ['_format' => 'txt'], methods: ['GET'])]
    public function robots(RobotsTxt $robots): Response
    {
        return new Response($robots->content(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
