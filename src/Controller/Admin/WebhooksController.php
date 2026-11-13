<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Entity\Project;
use App\Entity\Webhook;
use App\Service\Admin\WebhookManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine 7's WebhooksController (/webhooks: webhooks of the current user, all of them for admins). */
final class WebhooksController extends AbstractController
{
    public function __construct(private readonly WebhookManager $webhooks)
    {
    }

    #[Route('/webhooks', name: 'webhooks_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->check(false);
        $list = $this->em->createQuery('SELECT w FROM App\Entity\Webhook w WHERE w.user = :u ORDER BY w.url')->setParameter('u', $this->user()->id)->getResult();

        return $this->render('admin/webhooks/index.html.twig', [
            'webhooks' => $list,
            'page_title' => $this->l('label_webhook_plural'),
            'body_css' => 'controller-webhooks action-index',
        ]);
    }

    #[Route('/webhooks/new', name: 'webhook_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->check(false);
        $webhook = new Webhook();
        $webhook->user = $this->user();
        $webhook->events = [];
        $this->webhooks->assign($webhook, $request->query->all('webhook'), $this->user());

        return $this->renderForm($webhook, 'new');
    }

    #[Route('/webhooks', name: 'webhook_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->check(false);
        $webhook = new Webhook();
        $webhook->user = $this->user();
        $webhook->events = [];
        $this->webhooks->assign($webhook, $request->request->all('webhook'), $this->user());
        $errors = $this->webhooks->validate($webhook);
        if ([] !== $errors) {
            return $this->renderForm($webhook, 'new', $errors);
        }
        $this->webhooks->save($webhook);
        $this->flashNotice('notice_successful_create');

        return $this->redirectBackOr($request, $this->generateUrl('webhooks_index'));
    }

    #[Route('/webhooks/{id}/edit', name: 'webhook_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->check(true);

        return $this->renderForm($this->find($id), 'edit');
    }

    #[Route('/webhooks/{id}', name: 'webhook_update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT', 'POST'])]
    public function update(Request $request, int $id): Response
    {
        $this->check(true);
        $webhook = $this->find($id);
        $this->webhooks->assign($webhook, $request->request->all('webhook'), $this->user());
        $errors = $this->webhooks->validate($webhook);
        if ([] !== $errors) {
            $this->em->refresh($webhook);

            return $this->renderForm($webhook, 'edit', $errors, $request->request->all('webhook'));
        }
        $this->webhooks->save($webhook);
        $this->flashNotice('notice_successful_update');

        return $this->redirectBackOr($request, $this->generateUrl('webhooks_index'));
    }

    #[Route('/webhooks/{id}', name: 'webhook_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        $this->check(true);
        $this->webhooks->delete($this->find($id));
        $this->flashNotice('notice_successful_delete');

        return $this->redirectBackOr($request, $this->generateUrl('webhooks_index'));
    }

    /** require_login + check_enabled(_or_admin) + authorize(:use_webhooks globally) */
    private function check(bool $adminBypassesDisabled): void
    {
        $this->requireLogin();
        $user = $this->user();
        if (!$this->webhooks->enabled() && !($adminBypassesDisabled && $user->admin)) {
            throw new HttpException(403, $this->l('notice_not_authorized'));
        }
        if (!$this->auth->allowedTo('use_webhooks', null, null, true)) {
            $this->deny();
        }
    }

    private function find(int $id): Webhook
    {
        $webhook = $this->em->find(Webhook::class, $id);
        if (null === $webhook || !$this->webhooks->isEditable($webhook, $this->user())) {
            throw $this->createNotFoundException();
        }

        return $webhook;
    }

    /** @param list<string> $errors */
    private function renderForm(Webhook $webhook, string $action, array $errors = [], ?array $submitted = null): Response
    {
        $projects = $this->webhooks->setableProjects($webhook->user);
        $selected = null !== $submitted && isset($submitted['project_ids'])
            ? array_map('intval', array_filter((array) $submitted['project_ids']))
            : $this->webhooks->projectIds($webhook);
        $events = null !== $submitted && isset($submitted['events']) ? array_filter((array) $submitted['events']) : $webhook->getEvents();
        $url = null !== $submitted ? (string) ($submitted['url'] ?? $webhook->url) : $webhook->url;
        $active = null !== $submitted ? !empty($submitted['active']) : $webhook->active;

        return $this->render('admin/webhooks/'.$action.'.html.twig', [
            'webhook' => $webhook,
            'form_url' => $url,
            'form_active' => $active,
            'other_user' => null !== $webhook->id && $webhook->user->id !== $this->user()->id,
            'setable_events' => WebhookManager::EVENTS,
            'selected_events' => array_values($events),
            'project_tree' => $this->nestedProjects($projects, $selected),
            'errors' => $errors,
            'back_url' => ($r = $this->requestStack->getCurrentRequest()) ? ($r->request->get('back_url') ?? $r->query->get('back_url')) : null,
            'page_title' => $this->l('new' === $action ? 'label_webhook_new' : 'label_webhook_edit'),
            'body_css' => 'controller-webhooks action-'.$action,
        ], new Response(null, [] === $errors ? 200 : 422));
    }

    /** render_project_nested_lists with a checkbox per project */
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
