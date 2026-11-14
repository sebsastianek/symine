<?php

declare(strict_types=1);

namespace Symine\Hello\Hook;

use App\Entity\Issue;
use App\Entity\Project;
use App\Plugin\Hook\ViewListener;
use App\Security\Authorization;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symine\Hello\HelloPlugin;
use Symine\Hello\HelloSchema;

/**
 * View hooks of the Hello plugin (Redmine::Hook::ViewListener):
 * - render_on style: the project overview gets a "Greetings" box (view_projects_show_right)
 * - method style: the issue page shows the configured greeting (view_issues_show_details_bottom)
 *   and the layout includes the plugin stylesheet (view_layouts_base_html_head)
 */
final class HelloViewListener extends ViewListener
{
    protected const array RENDER_ON = [
        'view_projects_show_right' => '@HelloPlugin/hooks/_project_greetings.html.twig',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly Authorization $auth,
        private readonly EntityManagerInterface $em,
        private readonly HelloSchema $schema,
    ) {
    }

    public function view_layouts_base_html_head(array $context): string
    {
        return '<link rel="stylesheet" href="'.htmlspecialchars(($context['request']?->getBasePath() ?? '').'/plugin_assets/'.HelloPlugin::ID.'/stylesheets/hello.css').'" media="all" />';
    }

    public function view_issues_show_details_bottom(array $context): string
    {
        $issue = $context['issue'] ?? null;
        $settings = $this->settings->hash('plugin_'.HelloPlugin::ID);
        if (!$issue instanceof Issue || !$this->schema->isInstalled() || '1' !== (string) ($settings['show_on_issues'] ?? '1') || !$this->auth->allowedTo('view_hello_greetings', $issue->project)) {
            return '';
        }

        $count = $this->schema->whenInstalled(fn (): int => $this->count($issue->project), null);
        if (null === $count) {
            return '';
        }

        return $this->render('@HelloPlugin/hooks/_issue_details.html.twig', [
            'issue' => $issue,
            'greeting_word' => (string) ($settings['greeting'] ?? 'Hello'),
            'greetings_count' => $count,
        ]);
    }

    /** RENDER_ON templates get the hook context: add the data the template needs */
    public function renderOn(string $hook, array $context): array
    {
        $project = $context['project'] ?? null;
        if (!$project instanceof Project || !$this->schema->isInstalled() || !$this->auth->allowedTo('view_hello_greetings', $project)) {
            return [];
        }
        $greetings = $this->schema->whenInstalled(fn (): array => $this->em->createQuery('SELECT g FROM Symine\Hello\Entity\Greeting g WHERE g.project = :p ORDER BY g.createdOn DESC, g.id DESC')
            ->setParameter('p', $project)->setMaxResults(5)->getResult(), null);
        if (null === $greetings) {
            return [];
        }
        $context['greetings'] = $greetings;

        return parent::renderOn($hook, $context);
    }

    private function count(Project $project): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(g.id) FROM Symine\Hello\Entity\Greeting g WHERE g.project = :p')->setParameter('p', $project)->getSingleScalarResult();
    }
}
