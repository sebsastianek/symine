<?php

declare(strict_types=1);

namespace App\Plugin\Search;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Registered search providers (Redmine::Search.available_search_types added by plugins) */
final class SearchProviderRegistry
{
    /** @var array<string, SearchProviderInterface>|null */
    private ?array $providers = null;

    /** @param iterable<SearchProviderInterface> $tagged */
    public function __construct(
        #[AutowireIterator(SearchProviderInterface::TAG)] private readonly iterable $tagged = [],
    ) {
    }

    /** @return array<string, SearchProviderInterface> */
    public function all(): array
    {
        if (null === $this->providers) {
            $this->providers = [];
            foreach ($this->tagged as $p) {
                $this->providers[$p->getSearchType()] = $p;
            }
        }

        return $this->providers;
    }

    public function get(string $type): ?SearchProviderInterface
    {
        return $this->all()[$type] ?? null;
    }

    public function register(SearchProviderInterface $provider): void
    {
        $this->all();
        $this->providers[$provider->getSearchType()] = $provider;
    }
}
