<?php

declare(strict_types=1);

namespace Symine\Hello\Activity;

use App\Plugin\Activity\ActivityProviderInterface;
use App\Plugin\Activity\ActivityScope;
use App\Service\Activity\ActivityEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symine\Hello\Entity\Greeting;
use Symine\Hello\HelloSchema;

/** activity_provider :hello_greetings (acts_as_activity_provider on Greeting) */
final class GreetingActivityProvider implements ActivityProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HelloSchema $schema,
    ) {
    }

    public function getEventType(): string
    {
        return 'hello_greetings';
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function getPermission(): ?string
    {
        return 'view_hello_greetings';
    }

    public function findEvents(ActivityScope $scope): array
    {
        return $this->schema->whenInstalled(function () use ($scope): array {
            $qb = $this->em->createQueryBuilder()->select('g', 'p', 'a')->from(Greeting::class, 'g')->join('g.project', 'p')->join('g.author', 'a');
            $qb = $scope->apply($qb, 'g.createdOn', 'IDENTITY(g.author)', $scope->allowedToCondition('view_hello_greetings'), 'g.id');

            return null === $qb ? [] : array_map(self::event(...), $qb->getQuery()->getResult());
        }, []);
    }

    public static function event(Greeting $g): ActivityEvent
    {
        return new ActivityEvent(
            'hello-greeting',
            $g->createdOn,
            'Greeting #'.$g->id.': '.$g->message,
            '/projects/'.rawurlencode((string) $g->project->identifier).'/hello#greeting-'.$g->id,
            $g->message,
            $g->author,
            $g->project,
            'hello-greeting-'.$g->id,
            (int) $g->id,
        );
    }
}
