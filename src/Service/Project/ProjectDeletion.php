<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Mailer\NotificationMailer;
use App\Security\CurrentUser;
use App\Service\ProjectManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Deleting projects, one (projects#destroy) or several (projects#bulk_destroy): what the confirmation pages
 * show, and Redmine's DestroyProjectJob / DestroyProjectsJob, which are run at once here.
 */
final class ProjectDeletion
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectManager $manager,
        private readonly NotificationMailer $notifications,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * DestroyProjectJob#perform: deletes the project and tells the user who asked for it, by e-mail, that the
     * project was deleted (or could not be deleted).
     */
    public function destroy(Project $project, ?string $remoteIp): void
    {
        $userId = $this->currentUser->get()->id;
        $name = (string) $project->name;
        $message = \count($project->getSelfAndDescendants()) > 1 ? 'mail_destroy_project_with_subprojects_successful' : 'mail_destroy_project_successful';
        $failure = null;
        try {
            $this->manager->destroy($project);
        } catch (\Throwable $e) {
            $failure = $e;
            $message = 'mail_destroy_project_failed';
        }
        try {
            // the entity manager was cleared by the deletion
            $user = null === $userId ? null : $this->em->find(User::class, $userId);
            if ($user instanceof User && $user->isActive()) {
                $this->notifications->deliverSecurityNotification([$user], $user, [
                    'message' => $message,
                    'value' => $name,
                    'title' => 'label_project_plural',
                    'url' => '/admin/projects',
                    'remote_ip' => $remoteIp,
                ]);
            }
        } catch (\Throwable) {
            // the notification must never break the deletion
        }
        if (null !== $failure) {
            throw $failure;
        }
    }

    /**
     * Variables of the confirmation page of projects/destroy.html.twig.
     *
     * @return array<string, mixed>
     */
    public function view(Project $project): array
    {
        return [
            'project_to_destroy' => $project,
            'descendants' => \array_slice($project->getSelfAndDescendants(), 1),
        ];
    }

    /**
     * The projects selected in the administration list (params[:ids]) that are not being deleted already.
     *
     * @return non-empty-list<Project>
     *
     * @throws NotFoundHttpException when none of them exists
     */
    public function selection(Request $request): array
    {
        $params = self::params($request);
        $ids = array_values(array_unique(array_filter(array_map('intval', \is_array($params['ids'] ?? null) ? $params['ids'] : (isset($params['ids']) ? [$params['ids']] : [])))));
        $projects = [] === $ids ? [] : $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.id IN (:ids) AND p.status <> :deleted ORDER BY p.id')
            ->setParameter('ids', $ids)->setParameter('deleted', Project::STATUS_SCHEDULED_FOR_DELETION)->getResult();
        if ([] === $projects) {
            throw new NotFoundHttpException('Not Found');
        }

        return $projects;
    }

    /** The confirmation page of the bulk deletion was answered with "Yes" (params[:confirm] == l(:general_text_Yes)) */
    public function isConfirmed(Request $request): bool
    {
        $params = self::params($request);

        return isset($params['confirm']) && $params['confirm'] === $this->i18n->l('general_text_Yes');
    }

    /**
     * DestroyProjectsJob.schedule: deletion is done synchronously, like the single destroy.
     *
     * @param list<Project> $projects
     */
    public function destroyAll(array $projects, ?string $remoteIp): void
    {
        foreach (array_map(static fn (Project $p) => (int) $p->id, $projects) as $projectId) {
            $project = $this->em->find(Project::class, $projectId);
            if (null !== $project) {
                // DestroyProjectsJob: one DestroyProjectJob per project, a failure does not stop the others
                try {
                    $this->destroy($project, $remoteIp);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Variables of the confirmation page of projects/bulk_destroy.html.twig.
     *
     * @param list<Project> $projects
     *
     * @return array<string, mixed>
     */
    public function bulkView(array $projects): array
    {
        return [
            'projects_to_destroy' => array_map(static fn (Project $p) => ['project' => $p, 'descendants' => \array_slice($p->getSelfAndDescendants(), 1)], $projects),
            'ids' => array_map(static fn (Project $p) => $p->id, $projects),
            'head_html' => self::simpleFormat($this->i18n->l('text_projects_bulk_destroy_head')),
        ];
    }

    /** @return array<string, mixed> the parameters of the request, the form over the URL */
    private static function params(Request $request): array
    {
        return array_replace($request->query->all(), $request->request->all());
    }

    /** ActionView simple_format: paragraphs in <p>, single newlines followed by text get a <br /> */
    private static function simpleFormat(string $text): string
    {
        $paragraphs = preg_split('/\n\n+/', str_replace(["\r\n", "\r"], "\n", $text)) ?: [];

        return implode("\n\n", array_map(static fn (string $t) => '<p>'.preg_replace('/([^\n]\n)(?=[^\n])/', '$1<br />', htmlspecialchars($t)).'</p>', $paragraphs));
    }
}
