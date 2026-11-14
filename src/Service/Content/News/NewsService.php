<?php

declare(strict_types=1);

namespace App\Service\Content\News;

use App\Entity\Attachment;
use App\Entity\News;
use App\Entity\Project;
use App\Event\ContentEvent;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\WatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The News model of Redmine: the visible news of a project or of all projects, and what happens when a news
 * item is created, updated or deleted (attachments, watchers, comments, notifications).
 */
final class NewsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /** Number of news the user may see, in the project or in all projects */
    public function count(?Project $project): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(n.id) FROM App\Entity\News n JOIN n.project pr WHERE '.$this->visibleCondition($project))
            ->setParameters($this->parameters($project))->getSingleScalarResult();
    }

    /** @return list<News> latest first */
    public function page(?Project $project, int $offset, int $limit): array
    {
        return $this->em->createQuery('SELECT n, pr, a FROM App\Entity\News n JOIN n.project pr JOIN n.author a WHERE '.$this->visibleCondition($project).' ORDER BY n.createdOn DESC, n.id DESC')
            ->setParameters($this->parameters($project))->setFirstResult($offset)->setMaxResults($limit)->getResult();
    }

    /** A blank news item of the current user, for the forms */
    public function build(?Project $project): News
    {
        $news = new News();
        $news->project = $project;
        $news->author = $this->currentUser->get();

        return $news;
    }

    /** @return list<Project> projects where the user can manage news (Project.allowed_to(:manage_news)) */
    public function manageableProjects(): array
    {
        $cond = $this->auth->allowedToCondition('manage_news', 'p.id');

        return $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE $cond ORDER BY p.lft")->getResult();
    }

    /** Id of the "news" module of the project (what the watch link of the news list watches) */
    public function moduleId(Project $project): ?int
    {
        $id = $this->em->getConnection()->fetchOne("SELECT id FROM enabled_modules WHERE project_id = ? AND name = 'news'", [$project->id]);

        return false === $id ? null : (int) $id;
    }

    /** @param list<Attachment> $attachments */
    public function create(News $news, array $attachments): void
    {
        $this->em->persist($news);
        $this->em->flush();
        $this->attachments->attachTo($attachments, 'News', (int) $news->id);
        $this->watchers->add('News', (int) $news->id, $news->author);
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('news', 'created', $news, $this->currentUser->get(), ['attachments' => $attachments]));
    }

    /** @param list<Attachment> $attachments the files added by this update */
    public function update(News $news, array $attachments): void
    {
        $this->attachments->attachTo($attachments, 'News', (int) $news->id);
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('news', 'updated', $news, $this->currentUser->get(), ['attachments' => $attachments]));
    }

    public function destroy(News $news): void
    {
        $this->events->dispatch(new ContentEvent('news', 'deleted', $news, $this->currentUser->get()));
        $this->em->createQuery("DELETE FROM App\Entity\Comment c WHERE c.commentedType = 'News' AND c.commentedId = :id")->setParameter('id', $news->id)->execute();
        $this->attachments->deleteForContainer('News', (int) $news->id);
        $this->watchers->removeAll('News', (int) $news->id);
        $this->em->remove($news);
        $this->em->flush();
    }

    private function visibleCondition(?Project $project): string
    {
        return $this->auth->allowedToCondition('view_news', 'IDENTITY(n.project)').($project ? ' AND n.project = :p' : '');
    }

    /** @return array<string, int|null> */
    private function parameters(?Project $project): array
    {
        return $project ? ['p' => $project->id] : [];
    }
}
