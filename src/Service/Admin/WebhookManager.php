<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Project;
use App\Entity\ProjectWebhook;
use App\Entity\User;
use App\Entity\Webhook;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Webhook model logic (app/models/webhook.rb): safe attributes, validation (incl. the
 * WebhookEndpointValidator SSRF checks), setable projects and events.
 * Delivery itself is done by the notification/webhook listeners.
 */
final class WebhookManager
{
    /** WHATWG "bad ports" (lib/webhook_endpoint_validator.rb) */
    public const array BAD_PORTS = [1, 7, 9, 11, 13, 15, 17, 19, 20, 21, 22, 23, 25, 37, 42, 43, 53, 69, 77, 79, 87, 95, 101, 102, 103, 104, 109, 110, 111, 113, 115, 117, 119, 123, 135, 137, 139, 143, 161, 179, 389, 427, 465, 512, 513, 514, 515, 526, 530, 531, 532, 540, 548, 554, 556, 563, 587, 601, 636, 989, 990, 993, 995, 1719, 1720, 1723, 2049, 3659, 4045, 4190, 5060, 5061, 6000, 6566, 6665, 6666, 6667, 6668, 6669, 6679, 6697, 10080];

    /** @var array<string, list<string>> event types => actions (WebhookPayload.events) */
    public const array EVENTS = [
        'issue' => ['created', 'updated', 'deleted'],
        'news' => ['created', 'updated', 'deleted'],
        'time_entry' => ['created', 'updated', 'deleted'],
        'version' => ['created', 'updated', 'deleted'],
        'wiki_page' => ['created', 'updated', 'deleted'],
    ];

    /** @var list<string> hosts or IPs that are additionally blocked (Redmine's webhook_blocklist) */
    public static array $blocklist = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('webhooks_enabled');
    }

    public function isEditable(Webhook $webhook, User $user): bool
    {
        return $user->admin || $webhook->user->id === $user->id;
    }

    /** @return list<string> */
    public static function eventNames(): array
    {
        $out = [];
        foreach (self::EVENTS as $type => $actions) {
            foreach ($actions as $a) {
                $out[] = $type.'.'.$a;
            }
        }

        return $out;
    }

    /** Projects where the webhook owner has the use_webhooks permission, ordered by lft */
    public function setableProjects(User $owner): array
    {
        $cond = $this->auth->allowedToCondition('use_webhooks', 'p.id', $owner);

        return $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE $cond ORDER BY p.lft")->getResult();
    }

    /** @return list<int> */
    public function projectIds(Webhook $webhook): array
    {
        return array_values(array_map(static fn (ProjectWebhook $l) => (int) $l->project->id, $webhook->projectLinks->toArray()));
    }

    /**
     * Assigns safe attributes (url, secret, active, events, project_ids).
     *
     * @param array<string, mixed> $attrs
     */
    public function assign(Webhook $webhook, array $attrs, User $current): void
    {
        if (\array_key_exists('url', $attrs)) {
            $webhook->url = trim((string) $attrs['url']);
        }
        if (\array_key_exists('secret', $attrs)) {
            $secret = (string) $attrs['secret'];
            // the secret of another user's webhook is kept when left blank
            if (!('' === trim($secret) && null !== $webhook->id && $webhook->user->id !== $current->id)) {
                $webhook->secret = '' === $secret ? null : $secret;
            }
        }
        if (\array_key_exists('active', $attrs)) {
            $webhook->active = \in_array($attrs['active'], ['1', 1, true, 'true'], true);
        }
        if (\array_key_exists('events', $attrs)) {
            $webhook->events = array_values(array_filter(array_map('strval', (array) $attrs['events']), static fn ($e) => '' !== $e));
        }
        if (\array_key_exists('project_ids', $attrs)) {
            $wanted = array_map('intval', array_filter((array) $attrs['project_ids'], static fn ($v) => '' !== (string) $v));
            $setable = array_map(static fn (Project $p) => $p->id, $this->setableProjects($webhook->user));
            $wanted = array_values(array_intersect($wanted, $setable));
            foreach ($webhook->projectLinks->toArray() as $link) {
                if (!\in_array($link->project->id, $wanted, true)) {
                    $webhook->projectLinks->removeElement($link);
                }
            }
            $existing = $this->projectIds($webhook);
            foreach ($wanted as $pid) {
                if (!\in_array($pid, $existing, true) && ($p = $this->em->find(Project::class, $pid))) {
                    $link = new ProjectWebhook();
                    $link->project = $p;
                    $link->webhook = $webhook;
                    $webhook->projectLinks->add($link);
                }
            }
        }
    }

    /** @return list<string> full error messages */
    public function validate(Webhook $webhook): array
    {
        $errors = [];
        $label = fn (string $f) => $this->i18n->l('field_'.$f);
        if ('' === trim($webhook->url)) {
            $errors[] = $label('url').' '.$this->i18n->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($webhook->url) > 2000) {
            $errors[] = $label('url').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 2000]);
        } elseif (!self::isSafeUrl($webhook->url)) {
            $errors[] = $label('url').' '.$this->i18n->l('activerecord.errors.messages.invalid');
        }
        if (null !== $webhook->secret && mb_strlen($webhook->secret) > 255) {
            $errors[] = $label('secret').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if (!\is_array($webhook->events) || [] !== array_diff($webhook->getEvents(), self::eventNames())) {
            $errors[] = $this->i18n->l('label_webhook_events').' '.$this->i18n->l('activerecord.errors.messages.invalid');
        }

        return $errors;
    }

    public function save(Webhook $webhook): void
    {
        $webhook->updatedAt = new \DateTimeImmutable();
        $this->em->persist($webhook);
        $this->em->flush();
    }

    public function delete(Webhook $webhook): void
    {
        $this->em->remove($webhook);
        $this->em->flush();
    }

    /** WebhookEndpointValidator.safe_webhook_uri? */
    public static function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $port = $parts['port'] ?? ('https' === $scheme ? 443 : 80);
        if ($port < 1 || $port > 65535 || \in_array($port, self::BAD_PORTS, true)) {
            return false;
        }

        return null !== self::validIps(trim($parts['host'], '[]'));
    }

    /** @return list<string>|null resolved IPs, null when the host is not allowed */
    public static function validIps(string $host): ?array
    {
        if ('' === $host) {
            return null;
        }
        foreach (self::$blocklist as $block) {
            if (str_starts_with($block, '*.') ? preg_match('/(^|\.)'.preg_quote(substr($block, 2), '/').'$/i', $host) : 0 === strcasecmp($block, $host)) {
                return null;
            }
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            $records = @dns_get_record($host, \DNS_A | \DNS_AAAA) ?: [];
            foreach ($records as $r) {
                if (isset($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (isset($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
            if ([] === $ips) {
                $ips = @gethostbynamel($host) ?: [];
            }
        }
        if ([] === $ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isAllowedIp($ip) || \in_array($ip, self::$blocklist, true)) {
                return null;
            }
        }

        return $ips;
    }

    private static function isAllowedIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if (false === $bin) {
            return false;
        }
        if (4 === \strlen($bin)) {
            $long = ip2long($ip);

            return 0 !== $long
                && ($long & 0xFF000000) !== 0x7F000000          // 127.0.0.0/8 loopback
                && ($long & 0xFFFF0000) !== (int) 0xA9FE0000     // 169.254.0.0/16 link local
                && ($long & 0xFFFFFF00) !== (int) 0xE0000000;    // 224.0.0.0/24 multicast
        }
        // IPv4-mapped IPv6
        if (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {
            return self::isAllowedIp((string) inet_ntop(substr($bin, 12)));
        }

        return $bin !== str_repeat("\0", 16)                    // ::
            && $bin !== str_repeat("\0", 15)."\1"              // ::1
            && !(0xFE === \ord($bin[0]) && 0x80 === (\ord($bin[1]) & 0xC0)); // fe80::/10
    }
}
