<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WelcomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        $cond = $this->auth->allowedToCondition('view_news', 'IDENTITY(n.project)');
        $news = $this->em->createQuery("SELECT n FROM App\Entity\News n WHERE $cond ORDER BY n.createdOn DESC")
            ->setMaxResults(5)->getResult();

        return $this->render('welcome/index.html.twig', ['news' => $news, 'body_css' => 'controller-welcome action-index']);
    }

    #[Route('/robots.txt', name: 'robots', methods: ['GET'])]
    public function robots(): Response
    {
        $lines = ["User-agent: *"];
        foreach ($this->projects->visibleProjects($this->currentUser->anonymous()) as $p) {
            $lines[] = 'Disallow: /projects/'.$p->identifier.'/repository';
            $lines[] = 'Disallow: /projects/'.$p->identifier.'/issues';
            $lines[] = 'Disallow: /projects/'.$p->identifier.'/activity';
        }
        $lines[] = 'Disallow: /issues/gantt';
        $lines[] = 'Disallow: /issues/calendar';
        $lines[] = 'Disallow: /activity';
        $lines[] = 'Disallow: /search';

        return new Response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain']);
    }
}
