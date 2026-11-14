<?php

declare(strict_types=1);

namespace App\Service\Admin\Webhooks;

use App\Entity\Project;
use App\Entity\Webhook;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use App\Service\Admin\WebhookManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The webhook form (webhooks#new, #create, #edit, #update): a webhook of the current user filled with
 * webhook[...], saving it, and what the form shows.
 */
final class WebhookForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebhookManager $webhooks,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
    ) {
    }

    /** The webhook of the empty form, with what the URL already gives (webhook[...]) */
    public function blank(Request $request): Webhook
    {
        return $this->build($request->query->all('webhook'));
    }

    public function create(Request $request): WebhookSubmission
    {
        $webhook = $this->build($request->request->all('webhook'));
        $errors = $this->webhooks->validate($webhook);
        if ([] === $errors) {
            $this->webhooks->save($webhook);
        }

        return new WebhookSubmission($webhook, $errors);
    }

    public function update(Webhook $webhook, Request $request): WebhookSubmission
    {
        $this->webhooks->assign($webhook, $request->request->all('webhook'), $this->currentUser->get());
        $errors = $this->webhooks->validate($webhook);
        if ([] !== $errors) {
            $this->em->refresh($webhook);

            return new WebhookSubmission($webhook, $errors, $request->request->all('webhook'));
        }
        $this->webhooks->save($webhook);

        return new WebhookSubmission($webhook, []);
    }

    /**
     * Variables of the form page ("new" or "edit").
     *
     * @param list<string>              $errors
     * @param array<string, mixed>|null $submitted refused values to show instead of those of the webhook
     *
     * @return array<string, mixed>
     */
    public function view(string $action, Webhook $webhook, Request $request, array $errors = [], ?array $submitted = null): array
    {
        $projects = $this->webhooks->setableProjects($webhook->user);
        $selected = null !== $submitted && isset($submitted['project_ids'])
            ? array_map('intval', array_filter((array) $submitted['project_ids']))
            : $this->webhooks->projectIds($webhook);
        $events = null !== $submitted && isset($submitted['events']) ? array_filter((array) $submitted['events']) : $webhook->getEvents();
        $url = null !== $submitted ? (string) ($submitted['url'] ?? $webhook->url) : $webhook->url;
        $active = null !== $submitted ? !empty($submitted['active']) : $webhook->active;

        return [
            'webhook' => $webhook,
            'form_url' => $url,
            'form_active' => $active,
            'other_user' => null !== $webhook->id && $webhook->user->id !== $this->currentUser->get()->id,
            'setable_events' => WebhookManager::EVENTS,
            'selected_events' => array_values($events),
            'project_tree' => $this->nestedProjects($projects, $selected),
            'errors' => $errors,
            'back_url' => $request->request->get('back_url') ?? $request->query->get('back_url'),
            'page_title' => $this->i18n->l('new' === $action ? 'label_webhook_new' : 'label_webhook_edit'),
            'body_css' => 'controller-webhooks action-'.$action,
        ];
    }

    /** @param array<string, mixed> $attrs */
    private function build(array $attrs): Webhook
    {
        $webhook = new Webhook();
        $webhook->user = $this->currentUser->get();
        $webhook->events = [];
        $this->webhooks->assign($webhook, $attrs, $this->currentUser->get());

        return $webhook;
    }

    /**
     * render_project_nested_lists with a checkbox per project
     *
     * @param list<Project> $projects
     * @param list<int>     $selectedIds
     */
    private function nestedProjects(array $projects, array $selectedIds): string
    {
        $s = '';
        $ancestors = [];
        usort($projects, static fn (Project $a, Project $b) => ($a->lft ?? 0) <=> ($b->lft ?? 0));
        foreach ($projects as $project) {
            if ([] === $ancestors || $project->isDescendantOf(end($ancestors))) {
                $s .= "<ul class='projects ".([] === $ancestors ? 'root' : '')."'>\n";
            } else {
                array_pop($ancestors);
                $s .= '</li>';
                while ([] !== $ancestors && !$project->isDescendantOf(end($ancestors))) {
                    array_pop($ancestors);
                    $s .= "</ul></li>\n";
                }
            }
            $classes = [] === $ancestors ? 'root' : 'child';
            if ($project->isArchived()) {
                $classes .= ' archived';
            }
            $checked = \in_array($project->id, $selectedIds, true) ? ' checked="checked"' : '';
            $s .= "<li class='$classes'><div class='$classes'><label><input type=\"checkbox\" name=\"webhook[project_ids][]\" value=\"{$project->id}\"$checked /> ".htmlspecialchars($project->name)."</label></div>\n";
            $ancestors[] = $project;
        }
        $s .= str_repeat("</li></ul>\n", \count($ancestors));

        return $s;
    }
}
