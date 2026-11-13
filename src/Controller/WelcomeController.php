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

    #[Route('/robots.{_format}', name: 'robots', requirements: ['_format' => 'txt'], methods: ['GET'])]
    public function robots(): Response
    {
        // port of welcome/robots.text.erb
        $lines = ['User-agent: *'];
        if ($this->settings->bool('login_required')) {
            $lines[] = 'Disallow: /';
        } else {
            $projects = $this->projects->visibleProjects($this->currentUser->anonymous());
            usort($projects, static fn ($a, $b) => $a->id <=> $b->id);
            foreach ($projects as $p) {
                foreach ([$p->identifier, (string) $p->id] as $id) {
                    $lines[] = 'Disallow: '.$this->generateUrl('repository_show', ['id' => $id]);
                    $lines[] = 'Disallow: '.$this->generateUrl('project_issues', ['project_id' => $id]);
                    $lines[] = 'Disallow: '.$this->generateUrl('project_activity', ['id' => $id]);
                }
            }
            foreach (['/issues/gantt', '/issues/calendar', '/activity', '/search', '/issues?*sort=', '/issues?*query_id=', '/issues?*set_filter=', '/issues/*.pdf$', '/projects/*.pdf$', '/login', '/account/register', '/account/lost_password'] as $path) {
                $lines[] = 'Disallow: '.$this->requestStack->getCurrentRequest()?->getBasePath().$path;
            }
        }

        return new Response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
