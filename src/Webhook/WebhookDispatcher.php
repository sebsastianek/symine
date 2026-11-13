<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\AnonymousUser;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\News;
use App\Entity\Principal;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\Webhook;
use App\Entity\WikiContent;
use App\Entity\WikiPage;
use App\Event\ContentEvent;
use App\Event\IssueEvent;
use App\Mailer\DeferredTasks;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Port of Webhook.trigger / Webhook.hooks_for / WebhookJob / Webhook::Executor: when an issue, wiki page,
 * time entry, news or version is created/updated/deleted, every active webhook linked to the object's
 * project, subscribed to the event, whose (active) owner can see the object and has the use_webhooks
 * permission, receives a POST with the JSON payload. The payload is computed immediately (as the hook
 * owner) and delivered after the response; failures are logged and never break the request.
 *
 * Headers: Accept: *\/*, Content-Type: application/json, User-Agent: Redmine and, when the hook has a
 * secret, X-Redmine-Signature-256: sha256=<hex HMAC-SHA256 of the body>.
 */
final class WebhookDispatcher implements EventSubscriberInterface
{
    public const string SIGNATURE_HEADER = 'X-Redmine-Signature-256';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Security $security,
        private readonly WebhookPayloadBuilder $payloads,
        private readonly WebhookEndpointValidator $validator,
        private readonly HttpClientInterface $httpClient,
        private readonly DeferredTasks $deferred,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            IssueEvent::class => ['onIssue', -20],
            ContentEvent::class => ['onContent', -20],
        ];
    }

    public function onIssue(IssueEvent $event): void
    {
        $action = match ($event->action) {
            IssueEvent::CREATED => 'created',
            IssueEvent::UPDATED => 'updated',
            IssueEvent::DELETED => 'deleted',
            default => null,
        };
        if (null !== $action) {
            $this->trigger('issue.'.$action, $event->issue, $event->journal);
        }
    }

    public function onContent(ContentEvent $event): void
    {
        if (!isset(WebhookPayloadBuilder::EVENTS[$event->type]) || !\in_array($event->action, WebhookPayloadBuilder::EVENTS[$event->type], true)) {
            return;
        }
        $object = $event->subject;
        if ($object instanceof WikiContent) {
            $object = $object->page;
        }
        $expected = match ($event->type) {
            'wiki_page' => WikiPage::class,
            'time_entry' => TimeEntry::class,
            'news' => News::class,
            'version' => Version::class,
            default => null,
        };
        if (null !== $expected && $object instanceof $expected) {
            $this->trigger($event->type.'.'.$event->action, $object);
        }
    }

    /**
     * Webhook.trigger.
     *
     * @return int number of hooks scheduled
     */
    public function trigger(string $event, object $object, ?Journal $journal = null): int
    {
        if (!$this->settings->bool('webhooks_enabled')) {
            return 0;
        }
        try {
            $hooks = $this->hooksFor($event, $object);
        } catch (\Throwable $e) {
            $this->logger?->error('Webhook: could not find hooks: '.$e->getMessage(), ['exception' => $e]);

            return 0;
        }
        $n = 0;
        foreach ($hooks as $hook) {
            try {
                $payload = $this->asUser($hook->user, fn () => $this->payloads->build($event, $object, $hook->user, $journal));
                $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $this->logger?->error(\sprintf('Webhook #%d: could not build the payload for %s: %s', $hook->id, $event, $e->getMessage()), ['exception' => $e]);
                continue;
            }
            $url = $hook->url;
            $secret = $hook->secret;
            $hookId = (int) $hook->id;
            $this->deferred->defer('Webhook #'.$hookId, fn () => $this->call($hookId, $url, $json, $secret));
            ++$n;
        }

        return $n;
    }

    /**
     * Webhook.hooks_for: active hooks linked to the object's project, with an active owner who can
     * see the object and use webhooks in the project.
     *
     * @return list<Webhook>
     */
    public function hooksFor(string $event, object $object): array
    {
        $project = $this->payloads->projectOf($object);
        if (null === $project || null === $project->id) {
            return [];
        }
        $hooks = $this->em->createQuery(
            'SELECT w, u FROM App\Entity\Webhook w JOIN w.user u JOIN App\Entity\ProjectWebhook pw WITH pw.webhook = w
             WHERE w.active = true AND u.status = :active AND IDENTITY(pw.project) = :project ORDER BY w.id'
        )->setParameter('active', Principal::STATUS_ACTIVE)->setParameter('project', $project->id)->getResult();

        return array_values(array_filter($hooks, fn (Webhook $h) => \in_array($event, $h->getEvents(), true)
            && $this->payloads->isVisible($object, $h->user)
            && $this->auth->allowedTo('use_webhooks', $project, $h->user)));
    }

    /**
     * Webhook#call (Executor): POSTs the payload; returns true on a 2xx response, logs and returns false otherwise.
     */
    public function call(int $hookId, string $url, string $payloadJson, ?string $secret = null): bool
    {
        try {
            $ips = $this->validator->isSafe($url) ? $this->validator->ipsForUrl($url) : [];
            if ([] === $ips) {
                throw new \RuntimeException('Could not connect to any IP (invalid or blocked endpoint)');
            }
            $headers = ['Accept' => '*/*', 'Content-Type' => 'application/json', 'User-Agent' => 'Redmine'];
            if (null !== $secret && '' !== $secret) {
                $headers[self::SIGNATURE_HEADER] = self::signature($payloadJson, $secret);
            }
            $parts = parse_url($url);
            $options = [
                'headers' => $headers,
                'body' => $payloadJson,
                'timeout' => 60,
                'max_duration' => 180,
                'max_redirects' => 0,
                // connect to the validated address only (no DNS rebinding)
                'resolve' => [(string) $parts['host'] => $ips[0]],
            ];
            if (isset($parts['user'])) {
                $options['auth_basic'] = [rawurldecode($parts['user']), rawurldecode($parts['pass'] ?? '')];
            }
            $response = $this->httpClient->request('POST', $url, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(\sprintf('HTTP %d %s', $status, mb_substr($response->getContent(false), 0, 500)));
            }
            $this->logger?->debug(\sprintf('Webhook #%d: POST %s -> %d', $hookId, $url, $status));

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning(\sprintf('Webhook Error: #%d %s (%s)', $hookId, $e->getMessage(), $e::class));

            return false;
        }
    }

    public static function signature(string $payload, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $f
     *
     * @return T
     */
    private function asUser(User $user, \Closure $f): mixed
    {
        $prev = $this->currentUser->get();
        $this->currentUser->set($user);
        try {
            return $f();
        } finally {
            $this->currentUser->set($prev === $this->security->getUser() || $prev instanceof AnonymousUser ? null : $prev);
        }
    }

    /** Payload for an object (exposed for the admin "test" feature and tests) */
    public function payloadFor(string $event, Issue|News|WikiPage|TimeEntry|Version $object, User $user, ?Journal $journal = null): array
    {
        return $this->payloads->build($event, $object, $user, $journal);
    }
}
