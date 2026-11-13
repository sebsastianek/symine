<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\TimeEntryQuery as TimeEntryQueryRecord;
use App\Query\QueryFactory;
use App\Query\TimeEntry\TimeEntryQuery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Registers the TimeEntryQuery type in the generic query factory (saved queries, /queries/filter, ...). */
final class TimeEntryQueryRegistrationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly QueryFactory $factory)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 255]];
    }

    public function onRequest(RequestEvent $event): void
    {
        self::register($this->factory);
    }

    public static function register(QueryFactory $factory): void
    {
        if (!$factory->hasType('TimeEntryQuery')) {
            $factory->register('TimeEntryQuery', TimeEntryQueryRecord::class, TimeEntryQuery::class);
        }
    }
}
