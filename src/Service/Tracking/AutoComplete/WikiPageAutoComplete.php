<?php

declare(strict_types=1);

namespace App\Service\Tracking\AutoComplete;

use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\Security\Authorization;
use App\Service\ProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The wiki pages offered when "[[" is typed in a text area (AutoCompletesController#wiki_pages).
 */
final class WikiPageAutoComplete
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectService $projects,
        private readonly Authorization $auth,
    ) {
    }

    /**
     * Parameters: project_id, q. Nothing is offered without a project, a wiki, or the permission to read it.
     *
     * @return list<array{id: ?int, label: string, value: string}>
     *
     * @throws NotFoundHttpException when the project does not exist
     */
    public function suggest(Request $request): array
    {
        $project = null;
        if ('' !== ($pid = (string) $request->query->get('project_id', ''))) {
            $project = $this->projects->findByIdentifierOrId($pid) ?? throw new NotFoundHttpException();
        }
        $wiki = null === $project ? null : $this->em->getRepository(Wiki::class)->findOneBy(['project' => $project]);
        if (null === $wiki || !$this->auth->allowedTo('view_wiki_pages', $project)) {
            return [];
        }
        $q = trim((string) $request->query->get('q', ''));
        $qb = $this->em->createQueryBuilder()->select('wp')->from(WikiPage::class, 'wp')
            ->where('wp.wiki = :wiki')->setParameter('wiki', $wiki)->orderBy('wp.id', 'DESC')->setMaxResults(10);
        if ('' !== $q) {
            $qb->andWhere('LOWER(wp.title) LIKE :q')->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        return array_map(static fn (WikiPage $p) => [
            'id' => $p->id,
            'label' => mb_strlen($p->title) > 255 ? mb_substr($p->title, 0, 252).'...' : $p->title,
            'value' => $p->title,
        ], $qb->getQuery()->getResult());
    }
}
