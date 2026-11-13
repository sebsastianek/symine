<?php

declare(strict_types=1);

namespace App\Plugin\Activity;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Registered activity providers (Redmine::Activity.register / providers) */
final class ActivityProviderRegistry
{
    /** @var array<string, ActivityProviderInterface>|null */
    private ?array $providers = null;

    /** @param iterable<ActivityProviderInterface> $tagged */
    public function __construct(
        #[AutowireIterator(ActivityProviderInterface::TAG)] private readonly iterable $tagged = [],
    ) {
    }

    /** @return array<string, ActivityProviderInterface> event type => provider */
    public function all(): array
    {
        if (null === $this->providers) {
            $this->providers = [];
            foreach ($this->tagged as $p) {
                $this->providers[$p->getEventType()] = $p;
            }
        }

        return $this->providers;
    }

    public function get(string $eventType): ?ActivityProviderInterface
    {
        return $this->all()[$eventType] ?? null;
    }

    public function register(ActivityProviderInterface $provider): void
    {
        $this->all();
        $this->providers[$provider->getEventType()] = $provider;
    }
}
