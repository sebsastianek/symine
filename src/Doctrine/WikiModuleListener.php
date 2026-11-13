<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\EnabledModule;
use App\Entity\Wiki;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Port of EnabledModule#module_enabled: enabling the "wiki" module of a project creates
 * its wiki (start page "Wiki") when the project has none.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class WikiModuleListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof EnabledModule || 'wiki' !== $entity->name || null === $entity->project) {
                continue;
            }
            $project = $entity->project;
            foreach ($uow->getScheduledEntityInsertions() as $other) {
                if ($other instanceof Wiki && $other->project === $project) {
                    continue 2;
                }
            }
            if (null !== $project->id && null !== $em->getRepository(Wiki::class)->findOneBy(['project' => $project])) {
                continue;
            }
            $wiki = new Wiki();
            $wiki->project = $project;
            $wiki->startPage = 'Wiki';
            $em->persist($wiki);
            $uow->computeChangeSet($em->getClassMetadata(Wiki::class), $wiki);
        }
    }
}
