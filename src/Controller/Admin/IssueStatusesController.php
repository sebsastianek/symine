<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Controller\AbstractController;
use App\Entity\IssueStatus;
use App\Service\Admin\ActsAsPositioned;
use App\Service\Admin\WorkflowService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's IssueStatusesController. */
final class IssueStatusesController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly WorkflowService $workflows,
        private readonly ActsAsPositioned $positioned,
    ) {
    }

    private function useStatusForDoneRatio(): bool
    {
        return 'issue_status' === $this->settings->string('issue_done_ratio');
    }

    #[Route('/issue_statuses.{_format}', name: 'issue_statuses_index', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        $statuses = $this->workflows->sortedStatuses();
        if ($this->isApi($request)) {
            if ($this->settings->loginRequired() && !$this->user()->isLogged()) {
                $this->deny();
            }

            return $this->api->render($request, ['issue_statuses' => array_map(static fn (IssueStatus $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'is_closed' => $s->isClosed,
                'description' => $s->description,
            ], $statuses)]);
        }
        $this->requireAdmin();
        $used = $this->workflows->statusIdsWithTransitions();

        return $this->render('admin/issue_statuses/index.html.twig', [
            'issue_statuses' => $statuses,
            'used_ids' => $used,
            'use_status_for_done_ratio' => $this->useStatusForDoneRatio(),
            'admin_menu_selected' => 'issue_statuses',
            'page_title' => $this->l('label_issue_status_plural'),
            'body_css' => 'controller-issue_statuses action-index',
        ]);
    }

    #[Route('/issue_statuses/new', name: 'issue_status_new', methods: ['GET'])]
    public function new(): Response
    {
        $this->requireAdmin();

        return $this->renderForm(new IssueStatus(), 'new');
    }

    #[Route('/issue_statuses', name: 'issue_status_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $status = new IssueStatus();
        $this->assign($status, $request->request->all('issue_status'));
        $errors = $this->validate($status);
        if ([] !== $errors) {
            return $this->renderForm($status, 'new', $errors, 422);
        }
        $status->position ??= $this->positioned->nextPosition('issue_statuses');
        $this->em->persist($status);
        $this->em->flush();
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('issue_statuses_index');
    }

    #[Route('/issue_statuses/{id}/edit', name: 'issue_status_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();

        return $this->renderForm($this->findStatus($id), 'edit');
    }

    #[Route('/issue_statuses/{id}', name: 'issue_status_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $status = $this->findStatus($id);
        $oldPosition = $status->position;
        $wasClosed = $status->isClosed;
        $this->assign($status, $request->request->all('issue_status'));
        $errors = $this->validate($status);
        $xhr = $request->isXmlHttpRequest();
        if ([] !== $errors) {
            if ($xhr) {
                return new Response('', 422);
            }

            return $this->renderForm($status, 'edit', $errors, 422);
        }
        $this->em->flush();
        if (null !== $status->position && $oldPosition !== $status->position) {
            $this->positioned->positionChanged('issue_statuses', (int) $status->id, $oldPosition, $status->position);
        }
        if (!$wasClosed && $status->isClosed) {
            $this->handleIsClosedChange($status);
        }
        if ($xhr) {
            return new Response('', 200);
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('issue_statuses_index', array_filter(['page' => $request->query->get('page')]));
    }

    #[Route('/issue_statuses/{id}', name: 'issue_status_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        $status = $this->findStatus($id);
        $conn = $this->em->getConnection();
        $error = null;
        if (false !== $conn->fetchOne('SELECT 1 FROM issues WHERE status_id = ? LIMIT 1', [$status->id])) {
            $error = $this->l('error_issue_status_in_use_by_issues');
        } elseif (false !== $conn->fetchOne('SELECT 1 FROM trackers WHERE default_status_id = ? LIMIT 1', [$status->id])) {
            $error = $this->l('error_issue_status_default_for_trackers');
        }
        if (null !== $error) {
            $this->flashError($this->l('error_unable_delete_issue_status', ['value' => $error]));

            return $this->redirectToRoute('issue_statuses_index');
        }
        $sid = (int) $status->id;
        $position = $status->position;
        $this->workflows->deleteForStatus($sid);
        $this->em->remove($status);
        $this->em->flush();
        $this->positioned->removePosition('issue_statuses', $sid, $position);

        return $this->redirectToRoute('issue_statuses_index');
    }

    #[Route('/issue_statuses/update_issue_done_ratio', name: 'issue_statuses_update_issue_done_ratio', methods: ['POST'])]
    public function updateIssueDoneRatio(): Response
    {
        $this->requireAdmin();
        if ($this->useStatusForDoneRatio()) {
            // IssueStatus.update_issue_done_ratios
            $conn = $this->em->getConnection();
            foreach ($conn->fetchAllAssociative('SELECT id, default_done_ratio FROM issue_statuses WHERE default_done_ratio >= 0') as $row) {
                $conn->executeStatement('UPDATE issues SET done_ratio = ? WHERE status_id = ?', [(int) $row['default_done_ratio'], (int) $row['id']]);
            }
            $this->flashNotice('notice_issue_done_ratios_updated');
        } else {
            $this->flashError($this->l('error_issue_done_ratios_not_updated'));
        }

        return $this->redirectToRoute('issue_statuses_index');
    }

    // ------------------------------------------------------------------

    private function findStatus(int $id): IssueStatus
    {
        return $this->em->find(IssueStatus::class, $id) ?? throw $this->createNotFoundException();
    }

    private function assign(IssueStatus $status, array $p): void
    {
        if (\array_key_exists('name', $p)) {
            $status->name = trim((string) $p['name']);
        }
        if (\array_key_exists('description', $p)) {
            $status->description = (string) $p['description'];
        }
        if (\array_key_exists('is_closed', $p)) {
            $status->isClosed = \in_array((string) $p['is_closed'], ['1', 'true'], true);
        }
        if (\array_key_exists('position', $p) && '' !== (string) $p['position']) {
            $status->position = (int) $p['position'];
        }
        if (\array_key_exists('default_done_ratio', $p)) {
            $v = (string) $p['default_done_ratio'];
            $status->defaultDoneRatio = '' === $v ? null : (int) $v;
        }
    }

    /** @return list<string> */
    private function validate(IssueStatus $status): array
    {
        $errors = [];
        $label = $this->l('field_name');
        if ('' === $status->name) {
            $errors[] = $label.' '.$this->l('activerecord.errors.messages.blank');
        } else {
            $other = $this->em->getRepository(IssueStatus::class)->findOneBy(['name' => $status->name]);
            if (null !== $other && $other->id !== $status->id) {
                $errors[] = $label.' '.$this->l('activerecord.errors.messages.taken');
            }
            if (mb_strlen($status->name) > 30) {
                $errors[] = $label.' '.$this->l('activerecord.errors.messages.too_long', ['count' => 30]);
            }
        }
        if (mb_strlen((string) $status->description) > 255) {
            $errors[] = $this->l('field_description').' '.$this->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if (null !== $status->defaultDoneRatio && ($status->defaultDoneRatio < 0 || $status->defaultDoneRatio > 100)) {
            $errors[] = $this->l('field_done_ratio').' '.$this->l('activerecord.errors.messages.inclusion');
        }

        return $errors;
    }

    /** IssueStatus#handle_is_closed_change: sets closed_on of the issues having this status */
    private function handleIsClosedChange(IssueStatus $status): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "UPDATE issues SET closed_on = (SELECT MAX(j.created_on) FROM journals j JOIN journal_details d ON d.journal_id = j.id WHERE j.journalized_type = 'Issue' AND j.journalized_id = issues.id AND d.property = 'attr' AND d.prop_key = 'status_id' AND d.value = ?) WHERE status_id = ? AND closed_on IS NULL",
            [(string) $status->id, $status->id],
        );
        $conn->executeStatement('UPDATE issues SET closed_on = created_on WHERE status_id = ? AND closed_on IS NULL', [$status->id]);
    }

    private function renderForm(IssueStatus $status, string $action, array $errors = [], int $status_code = 200): Response
    {
        $interval = max(1, $this->settings->int('issue_done_ratio_interval') ?: 10);

        return $this->render('admin/issue_statuses/'.$action.'.html.twig', [
            'issue_status' => $status,
            'errors' => $errors,
            'use_status_for_done_ratio' => $this->useStatusForDoneRatio(),
            'done_ratios' => range(0, 100, $interval),
            'admin_menu_selected' => 'issue_statuses',
            'page_title' => 'new' === $action ? $this->l('label_issue_status_new') : $status->name,
            'body_css' => 'controller-issue_statuses action-'.$action,
        ], new Response('', $status_code));
    }
}
