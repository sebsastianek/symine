<?php

declare(strict_types=1);

namespace App\Service\Admin\CustomFields;

use App\Entity\CustomField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Loads the custom fields the administration actions work on. */
final class CustomFieldFinder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** The custom field of the route (404 when there is none) */
    public function find(int $id): CustomField
    {
        $field = $this->em->find(CustomField::class, $id);
        if (null === $field) {
            throw new NotFoundHttpException('Not Found');
        }

        return $field;
    }

    /** The custom field a new one is copied from: null when none is asked or when it does not exist */
    public function copySource(int $id): ?CustomField
    {
        return $id ? $this->em->find(CustomField::class, $id) : null;
    }

    /** @return list<CustomField> every custom field, in the order of the lists */
    public function all(): array
    {
        return $this->em->createQuery('SELECT cf FROM App\Entity\CustomField cf ORDER BY cf.position, cf.id')->getResult();
    }
}
