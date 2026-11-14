<?php

declare(strict_types=1);

namespace App\Service\Welcome;

use App\Security\CurrentUser;
use App\Service\ProjectService;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The robots.txt of the site (port of welcome/robots.text.erb): what crawlers should stay away from. */
final class RobotsTxt
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ProjectService $projects,
        private readonly CurrentUser $currentUser,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function content(): string
    {
        $lines = ['User-agent: *'];
        if ($this->settings->bool('login_required')) {
            $lines[] = 'Disallow: /';
        } else {
            $projects = $this->projects->visibleProjects($this->currentUser->anonymous());
            usort($projects, static fn ($a, $b) => $a->id <=> $b->id);
            foreach ($projects as $p) {
                foreach ([$p->identifier, (string) $p->id] as $id) {
                    $lines[] = 'Disallow: '.$this->urls->generate('repository_show', ['id' => $id]);
                    $lines[] = 'Disallow: '.$this->urls->generate('project_issues', ['project_id' => $id]);
                    $lines[] = 'Disallow: '.$this->urls->generate('project_activity', ['id' => $id]);
                }
            }
            foreach (['/issues/gantt', '/issues/calendar', '/activity', '/search', '/issues?*sort=', '/issues?*query_id=', '/issues?*set_filter=', '/issues/*.pdf$', '/projects/*.pdf$', '/login', '/account/register', '/account/lost_password'] as $path) {
                $lines[] = 'Disallow: '.$this->requestStack->getCurrentRequest()?->getBasePath().$path;
            }
        }

        return implode("\n", $lines)."\n";
    }
}
