<?php

declare(strict_types=1);

namespace App\Tests\Notifications;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Service\IssueService;
use App\Service\Settings;
use App\Tests\Integration\SymineTestCase;
use Symfony\Component\Mime\Email;

abstract class NotificationTestCase extends SymineTestCase
{
    protected function settings(): Settings
    {
        return $this->service(Settings::class);
    }

    protected function issues(): IssueService
    {
        return $this->service(IssueService::class);
    }

    protected function project(string $identifier): Project
    {
        $p = $this->em->getRepository(Project::class)->findOneBy(['identifier' => $identifier]);
        self::assertNotNull($p);

        return $p;
    }

    /** @param array<string, mixed> $attrs */
    protected function createIssue(User $author, string $project, array $attrs): Issue
    {
        $this->actAs($author);
        $issue = $this->issues()->newIssue($this->project($project), $author);
        $this->issues()->setSafeAttributes($issue, $attrs + ['tracker_id' => 1, 'subject' => 'Notification test'], $author);
        $errors = $this->issues()->save($issue, $author);
        self::assertSame([], $errors);

        return $issue;
    }

    /** @param array<string, mixed> $attrs */
    protected function updateIssue(Issue $issue, User $user, array $attrs): void
    {
        $this->actAs($user);
        $this->issues()->setSafeAttributes($issue, $attrs, $user);
        self::assertSame([], $this->issues()->save($issue, $user));
    }

    /** @return list<Email> */
    protected function emails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email));
    }

    /** @return array<string, Email> first recipient address => email */
    protected function emailsByRecipient(): array
    {
        $out = [];
        foreach ($this->emails() as $e) {
            $out[$e->getTo()[0]->getAddress()] = $e;
        }
        ksort($out);

        return $out;
    }

    protected function header(Email $e, string $name): ?string
    {
        $h = $e->getHeaders()->get($name);

        return $h?->getBodyAsString();
    }

    protected function clearEmails(): void
    {
        self::getContainer()->get('mailer.message_logger_listener')->reset();
    }
}
