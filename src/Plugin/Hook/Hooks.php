<?php

declare(strict_types=1);

namespace App\Plugin\Hook;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Hook dispatcher (Redmine::Hook.call_hook).
 *
 * A hook call:
 * 1. calls the method named after the hook on every HookListenerInterface service that defines it
 *    (ViewListener: also the templates declared in RENDER_ON),
 * 2. dispatches a HookEvent named after the hook (Symfony event listeners/subscribers),
 * and returns the list of responses. View hooks (Twig `call_hook()`) join the responses with a space.
 *
 * The core calls the hooks at the same places as Redmine (views, controllers and models), with the same
 * names and context keys - see docs/PLUGINS.md for the list.
 */
final class Hooks
{
    /** @var list<HookListenerInterface>|null */
    private ?array $listeners = null;
    /** @var array<string, list<HookListenerInterface>> */
    private array $hookListeners = [];

    /** @param iterable<HookListenerInterface> $listeners */
    public function __construct(
        #[AutowireIterator(HookListenerInterface::TAG)] private readonly iterable $taggedListeners,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * Calls a hook and returns the responses of the listeners.
     *
     * @param array<string, mixed> $context
     *
     * @return list<mixed>
     */
    public function call(string $hook, array $context = []): array
    {
        $context += ['request' => $this->requests->getCurrentRequest()];
        $responses = [];
        foreach ($this->hookListeners($hook) as $listener) {
            if (method_exists($listener, $hook)) {
                $responses[] = $listener->{$hook}($context);
            } elseif ($listener instanceof ViewListener) {
                array_push($responses, ...$listener->renderOn($hook, $context));
            }
        }
        if ($this->dispatcher instanceof \Symfony\Component\EventDispatcher\EventDispatcherInterface && !$this->dispatcher->hasListeners($hook)) {
            return $responses;
        }
        $event = new HookEvent($hook, $context);
        $this->dispatcher->dispatch($event, $hook);

        return [...$responses, ...$event->getResponses()];
    }

    /**
     * View hook: responses joined with a space (Redmine's call_hook helper in views).
     *
     * @param array<string, mixed> $context
     */
    public function render(string $hook, array $context = []): string
    {
        $out = [];
        foreach ($this->call($hook, $context) as $r) {
            if (\is_array($r)) {
                $r = implode(' ', array_map('strval', $r));
            }
            if (null !== $r && '' !== (string) $r) {
                $out[] = (string) $r;
            }
        }

        return implode(' ', $out);
    }

    /** True when at least one listener (or event listener) handles the hook */
    public function hasListeners(string $hook): bool
    {
        if ([] !== $this->hookListeners($hook)) {
            return true;
        }

        return $this->dispatcher instanceof \Symfony\Component\EventDispatcher\EventDispatcherInterface && $this->dispatcher->hasListeners($hook);
    }

    /** Registers a listener at runtime (Redmine::Hook.add_listener) */
    public function addListener(HookListenerInterface $listener): void
    {
        $this->listeners();
        $this->listeners[] = $listener;
        $this->hookListeners = [];
    }

    /** @return list<HookListenerInterface> */
    private function listeners(): array
    {
        if (null === $this->listeners) {
            $this->listeners = [];
            foreach ($this->taggedListeners as $l) {
                $this->listeners[] = $l;
            }
        }

        return $this->listeners;
    }

    /** @return list<HookListenerInterface> */
    private function hookListeners(string $hook): array
    {
        return $this->hookListeners[$hook] ??= array_values(array_filter(
            $this->listeners(),
            static fn (HookListenerInterface $l) => method_exists($l, $hook) || ($l instanceof ViewListener && $l->rendersOn($hook)),
        ));
    }
}
