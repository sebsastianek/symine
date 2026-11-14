<?php

declare(strict_types=1);

namespace App\Service\Admin\Enumerations;

use App\Entity\Enumeration;
use App\Service\Admin\EnumerationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves what the enumeration routes name: the enumeration of an id, the kind of enumeration to create and
 * the kind listed by an API collection (404 when there is no such thing).
 */
final class EnumerationFinder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(int $id): Enumeration
    {
        $e = $this->em->find(Enumeration::class, $id);
        if (null === $e) {
            throw new NotFoundHttpException('Not Found');
        }

        return $e;
    }

    /** A new, unsaved enumeration of the type ("IssuePriority", "TimeEntryActivity", "DocumentCategory") */
    public function newOfType(string $type): Enumeration
    {
        if (!isset(EnumerationManager::CLASSES[$type])) {
            throw new NotFoundHttpException('Not Found');
        }
        $class = EnumerationManager::CLASSES[$type];

        return new $class();
    }

    /** The type listed by an API collection: "issue_priorities" gives "IssuePriority" */
    public function typeOfCollection(string $plural): string
    {
        return EnumerationManager::typeFromPlural($plural) ?? throw new NotFoundHttpException('Not Found');
    }
}
