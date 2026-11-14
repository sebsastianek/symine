<?php

declare(strict_types=1);

namespace App\Service\Admin\AuthSources;

use App\Entity\AuthSource;
use App\I18n\Localizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The authentication modes as Administration > LDAP authentication manages them (AuthSourcesController):
 * finding one, the list with the number of users of each, and deleting those nobody uses.
 */
final class AuthSources
{
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
    ) {
    }

    public function find(int $id): AuthSource
    {
        return $this->em->find(AuthSource::class, $id) ?? throw new NotFoundHttpException();
    }

    /**
     * Variables of the list page: always 25 per page, whatever the per_page setting says.
     *
     * @return array<string, mixed>
     */
    public function view(Request $request): array
    {
        $count = (int) $this->em->createQuery('SELECT COUNT(a.id) FROM App\Entity\AuthSource a')->getSingleScalarResult();
        $pages = max(1, (int) ceil($count / self::PER_PAGE));
        $page = max(1, min($pages, (int) $request->query->get('page', 1)));
        $pager = ['offset' => ($page - 1) * self::PER_PAGE, 'limit' => self::PER_PAGE, 'page' => $page, 'count' => $count, 'pages' => $pages, 'per_page_options' => [self::PER_PAGE]];
        $sources = $this->em->createQuery('SELECT a FROM App\Entity\AuthSource a ORDER BY a.id')
            ->setFirstResult($pager['offset'])->setMaxResults(self::PER_PAGE)->getResult();
        $userCounts = [];
        foreach ($this->em->createQuery('SELECT u.authSourceId AS id, COUNT(u.id) AS n FROM App\Entity\User u WHERE u.authSourceId IS NOT NULL GROUP BY u.authSourceId')->getArrayResult() as $row) {
            $userCounts[(int) $row['id']] = (int) $row['n'];
        }

        return [
            'auth_sources' => $sources,
            'user_counts' => $userCounts,
            'pager' => $pager,
            'admin_menu_selected' => 'ldap_authentication',
            'page_title' => $this->i18n->l('label_auth_source_plural'),
            'body_css' => 'controller-auth_sources action-index',
        ];
    }

    /** Deletes the authentication mode; false when users still authenticate with it */
    public function remove(AuthSource $source): bool
    {
        $used = (int) $this->em->createQuery('SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.authSourceId = :id')->setParameter('id', $source->id)->getSingleScalarResult();
        if (0 !== $used) {
            return false;
        }
        $this->em->remove($source);
        $this->em->flush();

        return true;
    }
}
