<?php

declare(strict_types=1);

namespace App\Mailer;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Equivalent of Redmine's deliver_later / perform_later for e-mails and webhooks: during an HTTP
 * request, tasks run after the response has been sent (kernel.terminate), so a slow SMTP server
 * or webhook endpoint never delays nor breaks the request. Outside of a request (console commands,
 * tests) tasks run immediately. Failures are logged and swallowed.
 */
final class DeferredTasks
{
    /** @var list<array{string, \Closure}> */
    private array $queue = [];

    public function __construct(
        private readonly RequestStack $requests,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function defer(string $label, \Closure $task): void
    {
        if (null === $this->requests->getMainRequest()) {
            $this->run($label, $task);

            return;
        }
        $this->queue[] = [$label, $task];
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -1024)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -1024)]
    public function flush(): void
    {
        while ([] !== $this->queue) {
            [$label, $task] = array_shift($this->queue);
            $this->run($label, $task);
        }
    }

    public function pending(): int
    {
        return \count($this->queue);
    }

    private function run(string $label, \Closure $task): void
    {
        try {
            $task();
        } catch (\Throwable $e) {
            $this->logger?->error(\sprintf('%s failed: %s', $label, $e->getMessage()), ['exception' => $e]);
        }
    }
}
