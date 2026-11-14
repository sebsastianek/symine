<?php

declare(strict_types=1);

namespace App\Service\Admin\IssueStatuses;

use App\Entity\IssueStatus;
use App\I18n\Localizer;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The issue status form of Administration > Issue statuses: takes the submitted issue_status[...] parameters,
 * validates them like Redmine's IssueStatus model, saves the status and gives the template its variables.
 */
final class IssueStatusForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly IssueStatusManager $statuses,
    ) {
    }

    /**
     * Assigns the submitted parameters and saves the status when they are valid.
     *
     * @param array<string, mixed> $params the issue_status[...] parameters
     *
     * @return list<string> the validation errors; empty when the status was saved
     */
    public function submit(IssueStatus $status, array $params): array
    {
        $oldPosition = $status->position;
        $wasClosed = $status->isClosed;
        $this->assign($status, $params);
        $errors = $this->validate($status);
        if ([] !== $errors) {
            return $errors;
        }
        if (null === $status->id) {
            $this->statuses->create($status);
        } else {
            $this->statuses->update($status, $oldPosition, $wasClosed);
        }

        return [];
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(IssueStatus $status, array $errors = []): array
    {
        $interval = max(1, $this->settings->int('issue_done_ratio_interval') ?: 10);

        return [
            'issue_status' => $status,
            'errors' => $errors,
            'use_status_for_done_ratio' => $this->statuses->useStatusForDoneRatio(),
            'done_ratios' => range(0, 100, $interval),
        ];
    }

    /** @param array<string, mixed> $p */
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
        $label = $this->i18n->l('field_name');
        if ('' === $status->name) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.blank');
        } else {
            $other = $this->em->getRepository(IssueStatus::class)->findOneBy(['name' => $status->name]);
            if (null !== $other && $other->id !== $status->id) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.taken');
            }
            if (mb_strlen($status->name) > 30) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 30]);
            }
        }
        if (mb_strlen((string) $status->description) > 255) {
            $errors[] = $this->i18n->l('field_description').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if (null !== $status->defaultDoneRatio && ($status->defaultDoneRatio < 0 || $status->defaultDoneRatio > 100)) {
            $errors[] = $this->i18n->l('field_done_ratio').' '.$this->i18n->l('activerecord.errors.messages.inclusion');
        }

        return $errors;
    }
}
