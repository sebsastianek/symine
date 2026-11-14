<?php

declare(strict_types=1);

namespace App\Service\Content\Documents;

use App\Entity\Document;
use App\Security\AccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the document an action works on (404 when it does not exist, 403 when its project is not visible).
 */
final class DocumentFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
    ) {
    }

    public function find(int $id): Document
    {
        $document = $this->em->find(Document::class, $id);
        if (null === $document) {
            throw new NotFoundHttpException();
        }
        $this->guard->findProject((int) $document->project->id);

        return $document;
    }
}
