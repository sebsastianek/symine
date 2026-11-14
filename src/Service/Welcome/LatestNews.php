<?php

declare(strict_types=1);

namespace App\Service\Welcome;

use App\Entity\News;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;

/** The latest news of the home page (WelcomeController#index: News.latest User.current). */
final class LatestNews
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
    ) {
    }

    /** @return list<News> the most recent news of the projects whose news the current user may view */
    public function forCurrentUser(int $count = 5): array
    {
        $cond = $this->auth->allowedToCondition('view_news', 'IDENTITY(n.project)');

        return $this->em->createQuery("SELECT n FROM App\Entity\News n WHERE $cond ORDER BY n.createdOn DESC")
            ->setMaxResults($count)->getResult();
    }
}
