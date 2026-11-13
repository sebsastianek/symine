<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Api\ApiRenderer;
use App\Entity\CustomField;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\News;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\Entity\TimeEntryCustomField;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\VersionCustomField;
use App\Entity\WikiContent;
use App\Entity\WikiPage;
use App\Security\Authorization;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Port of WebhookPayload + acts_as_webhookable#webhook_payload: the JSON payload of a webhook call
 * is the REST API representation of the object (issues/show.api.rsb, news/show.api.rsb, wiki/show.api.rsb,
 * timelog/show.api.rsb, versions/show.api.rsb) rendered as the webhook owner, without includes.
 *
 *   {"type": "issue.updated", "timestamp": "...Z", "data": {"issue": {...}, "journal": {...}}}
 */
final class WebhookPayloadBuilder
{
    /** Events per object type (WebhookPayload.events) */
    public const array EVENTS = [
        'issue' => ['created', 'updated', 'deleted'],
        'wiki_page' => ['created', 'updated', 'deleted'],
        'time_entry' => ['created', 'updated', 'deleted'],
        'news' => ['created', 'updated', 'deleted'],
        'version' => ['created', 'updated', 'deleted'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
        private readonly CustomFieldService $customFields,
    ) {
    }

    /** @return list<string> "type.action" event names */
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

    /** Project of a webhookable object */
    public function projectOf(object $object): ?Project
    {
        return match (true) {
            $object instanceof Issue, $object instanceof News, $object instanceof TimeEntry, $object instanceof Version => $object->project ?? null,
            $object instanceof WikiPage => $object->wiki->project,
            default => null,
        };
    }

    /** object.visible?(user) */
    public function isVisible(object $object, User $user): bool
    {
        $project = $this->projectOf($object);
        if (null === $project) {
            return false;
        }

        return match (true) {
            $object instanceof Issue => $this->issues->isVisible($object, $user),
            $object instanceof News => $this->auth->allowedTo('view_news', $project, $user),
            $object instanceof WikiPage => $this->auth->allowedTo('view_wiki_pages', $project, $user),
            $object instanceof Version => $this->auth->allowedTo('view_issues', $project, $user),
            $object instanceof TimeEntry => $this->auth->allowedTo('view_time_entries', $project, $user, false,
                static fn ($role, User $u) => 'all' === $role->timeEntriesVisibility || ('own' === $role->timeEntriesVisibility && $object->user->id === $u->id)),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $event, object $object, User $user, ?Journal $journal = null): array
    {
        [$type, $action] = explode('.', $event, 2) + [1 => ''];
        if (!\in_array($action, self::EVENTS[$type] ?? [], true)) {
            throw new \InvalidArgumentException('invalid event: '.$event);
        }
        $now = new \DateTimeImmutable();
        $payload = match (true) {
            $object instanceof Issue => ['issue' => $this->issue($object, $user)],
            $object instanceof News => ['news' => $this->news($object)],
            $object instanceof WikiPage => ['wiki_page' => $this->wikiPage($object)],
            $object instanceof TimeEntry => ['time_entry' => $this->timeEntry($object, $user)],
            $object instanceof Version => ['version' => $this->version($object, $user)],
            default => throw new \InvalidArgumentException('Object is not webhookable: '.$object::class),
        };
        $timestamp = match ($action) {
            'created' => $object->createdOn ?? $now,
            'updated' => $object instanceof News ? $now : ($object instanceof WikiPage ? ($object->content?->updatedOn ?? $now) : ($object->updatedOn ?? $now)),
            default => $now,
        };
        $h = ['type' => $event, 'timestamp' => ApiRenderer::time($timestamp), 'data' => $payload];
        if ($object instanceof Issue && 'updated' === $action && null !== $journal && $this->journalVisible($journal, $user)) {
            $h['data']['journal'] = $this->journal($journal, $user);
            $h['timestamp'] = ApiRenderer::time($journal->createdOn);
        }

        return $h;
    }

    private function journalVisible(Journal $journal, User $user): bool
    {
        return !$journal->privateNotes || $journal->user->id === $user->id || $this->auth->allowedTo('view_private_notes', $journal->issue->project, $user);
    }

    /** @return array<string, mixed> */
    private function journal(Journal $journal, User $user): array
    {
        $details = [];
        foreach ($journal->details as $d) {
            if ('cf' === $d->property) {
                $cf = $this->em->find(CustomField::class, (int) $d->propKey);
                if (null === $cf || !$cf->isVisibleBy($journal->issue->project, $user, fn ($p) => $this->auth->rolesForProject($p, $user))) {
                    continue;
                }
            } elseif ('relation' === $d->property) {
                $other = $this->em->find(Issue::class, (int) ($d->value ?? $d->oldValue));
                if (null === $other || !$this->issues->isVisible($other, $user)) {
                    continue;
                }
            }
            $details[] = ['property' => $d->property, 'prop_key' => $d->propKey, 'old_value' => $d->oldValue, 'value' => $d->value];
        }

        return [
            'id' => $journal->id,
            'created_on' => ApiRenderer::time($journal->createdOn),
            'notes' => $journal->notes,
            'user' => ['id' => $journal->user->id, 'name' => $journal->user->getName()],
            'details' => $details,
        ];
    }

    /** @return array<string, mixed> issues/show.api.rsb */
    private function issue(Issue $i, User $user): array
    {
        $h = ['id' => $i->id];
        $h['project'] = ['id' => $i->project->id, 'name' => $i->project->name];
        $h['tracker'] = ['id' => $i->tracker->id, 'name' => $i->tracker->name];
        $h['status'] = ['id' => $i->status->id, 'name' => $i->status->name, 'is_closed' => $i->status->isClosed];
        $h['priority'] = ['id' => $i->priority->id, 'name' => $i->priority->name];
        $h['author'] = ['id' => $i->author->id, 'name' => $i->author->getName()];
        if ($i->assignedTo) {
            $h['assigned_to'] = ['id' => $i->assignedTo->id, 'name' => $i->assignedTo->getName()];
        }
        if ($i->category) {
            $h['category'] = ['id' => $i->category->id, 'name' => $i->category->name];
        }
        if ($i->fixedVersion) {
            $h['fixed_version'] = ['id' => $i->fixedVersion->id, 'name' => $i->fixedVersion->name];
        }
        if ($i->parent) {
            $h['parent'] = ['id' => $i->parent->id];
        }
        $h += [
            'subject' => $i->subject,
            'description' => $i->description,
            'start_date' => ApiRenderer::date($i->startDate),
            'due_date' => ApiRenderer::date($i->dueDate),
            'done_ratio' => $i->doneRatio,
            'is_private' => $i->isPrivate,
            'estimated_hours' => $i->estimatedHours,
            'total_estimated_hours' => $this->safe(fn () => $i->isLeaf() ? $i->estimatedHours : $this->issues->totalEstimatedHours($i), $i->estimatedHours),
        ];
        // spent hours are only rendered when User.current.allowed_to?(:view_time_entries, @project);
        // @project is not set when rendering webhook payloads, so they are never included (like Redmine)
        if ($cf = $this->customValues('Issue', $i->id, $this->safe(fn () => $this->customFields->visibleIssueFields($i, $user), []))) {
            $h['custom_fields'] = $cf;
        }
        $h['created_on'] = ApiRenderer::time($i->createdOn);
        $h['updated_on'] = ApiRenderer::time($i->updatedOn);
        $h['closed_on'] = ApiRenderer::time($i->closedOn);

        return $h;
    }

    /** @return array<string, mixed> news/show.api.rsb */
    private function news(News $n): array
    {
        $h = ['id' => $n->id];
        if ($n->project) {
            $h['project'] = ['id' => $n->project->id, 'name' => $n->project->name];
        }
        $h['author'] = ['id' => $n->author->id, 'name' => $n->author->getName()];
        $h['title'] = $n->title;
        if (null !== $n->summary && '' !== trim($n->summary)) {
            $h['summary'] = $n->summary;
        }
        $h['description'] = $n->description;
        $h['created_on'] = ApiRenderer::time($n->createdOn);

        return $h;
    }

    /** @return array<string, mixed> wiki/show.api.rsb */
    private function wikiPage(WikiPage $page): array
    {
        $content = $page->content ?? new WikiContent();
        $h = ['title' => $page->title];
        if ($page->parent) {
            $h['parent'] = ['title' => $page->parent->title];
        }
        $h['text'] = $content->text;
        $h['version'] = $content->version;
        if (null !== $content->author) {
            $h['author'] = ['id' => $content->author->id, 'name' => $content->author->getName()];
        }
        $h['comments'] = $content->comments;
        $project = $page->wiki->project;
        $h['project'] = ['id' => $project->id, 'name' => $project->name];
        $h['created_on'] = ApiRenderer::time($page->createdOn);
        $h['updated_on'] = ApiRenderer::time(isset($content->updatedOn) ? $content->updatedOn : null);

        return $h;
    }

    /** @return array<string, mixed> timelog/show.api.rsb */
    private function timeEntry(TimeEntry $t, User $user): array
    {
        $h = ['id' => $t->id, 'project' => ['id' => $t->project->id, 'name' => $t->project->name]];
        if ($t->issue) {
            $h['issue'] = ['id' => $t->issue->id];
        }
        $h['user'] = ['id' => $t->user->id, 'name' => $t->user->getName()];
        $h['activity'] = ['id' => $t->activity->id, 'name' => $t->activity->name];
        $h += [
            'hours' => round($t->hours, 2),
            'comments' => $t->comments,
            'spent_on' => ApiRenderer::date($t->spentOn),
            'created_on' => ApiRenderer::time($t->createdOn),
            'updated_on' => ApiRenderer::time($t->updatedOn),
        ];
        $fields = array_values(array_filter($this->customFields->fieldsOf(TimeEntryCustomField::class), fn (CustomField $f) => $f->isVisibleBy($t->project, $user, fn ($p) => $this->auth->rolesForProject($p, $user))));
        if ($cf = $this->customValues('TimeEntry', $t->id, $fields)) {
            $h['custom_fields'] = $cf;
        }

        return $h;
    }

    /** @return array<string, mixed> versions/show.api.rsb */
    private function version(Version $v, User $user): array
    {
        $h = [
            'id' => $v->id,
            'project' => ['id' => $v->project->id, 'name' => $v->project->name],
            'name' => $v->name,
            'description' => $v->description,
            'status' => $v->status,
            'due_date' => ApiRenderer::date($v->effectiveDate),
            'sharing' => $v->sharing,
            'wiki_page_title' => $v->wikiPageTitle,
        ];
        $fields = array_values(array_filter($this->customFields->fieldsOf(VersionCustomField::class), fn (CustomField $f) => $f->isVisibleBy($v->project, $user, fn ($p) => $this->auth->rolesForProject($p, $user))));
        if ($cf = $this->customValues('Version', $v->id, $fields)) {
            $h['custom_fields'] = $cf;
        }
        $h['created_on'] = ApiRenderer::time($v->createdOn);
        $h['updated_on'] = ApiRenderer::time($v->updatedOn);

        return $h;
    }

    /**
     * render_api_custom_values.
     *
     * @param list<CustomField> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function customValues(string $type, ?int $id, array $fields): array
    {
        if (null === $id || [] === $fields) {
            return [];
        }
        $values = $this->customFields->values($type, $id);
        $out = [];
        foreach ($fields as $f) {
            $attrs = ['id' => $f->id, 'name' => $f->name];
            if ($f->isMultiple()) {
                $attrs['multiple'] = true;
                $attrs['value'] = array_values(array_filter((array) ($values[$f->id] ?? []), static fn ($v) => null !== $v && '' !== $v));
            } else {
                $attrs['value'] = $values[$f->id] ?? null;
            }
            $out[] = $attrs;
        }

        return $out;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $f
     * @param T             $default
     *
     * @return T
     */
    private function safe(\Closure $f, mixed $default): mixed
    {
        try {
            return $f();
        } catch (\Throwable) {
            return $default;
        }
    }
}
