<?php

declare(strict_types=1);

namespace App\Plugin\Hook;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched for every hook call, with the hook name as event name: plugins can listen to
 * Redmine hooks with the usual Symfony mechanisms, e.g.
 *
 *     #[AsEventListener(event: 'controller_issues_new_before_save')]
 *     public function onNewIssue(HookEvent $event): void { $issue = $event->get('issue'); ... }
 *
 * View hooks: listeners add HTML with addResponse().
 */
#[Exclude]
final class HookEvent extends Event implements \ArrayAccess
{
    /** @var list<mixed> */
    private array $responses = [];

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $hook,
        private array $context = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    public function addResponse(mixed $response): void
    {
        $this->responses[] = $response;
    }

    /** @return list<mixed> */
    public function getResponses(): array
    {
        return $this->responses;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->context[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->context[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->context[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->context[$offset]);
    }
}
