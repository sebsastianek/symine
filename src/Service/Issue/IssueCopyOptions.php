<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\Service\IssueService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What a request says about copying an issue: the issue copied (copy_from) and what is copied along with it,
 * which the application settings may impose.
 */
final class IssueCopyOptions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueService $issues,
        private readonly Settings $settings,
    ) {
    }

    /** The issue being copied, if any (404 when it does not exist or is not visible) */
    public function source(Request $request): ?Issue
    {
        $id = IssueParams::get($request, 'copy_from');
        if (null === $id || '' === $id) {
            return null;
        }
        $source = $this->em->find(Issue::class, (int) $id);
        if (null === $source || !$this->issues->isVisible($source)) {
            throw new NotFoundHttpException();
        }

        return $source;
    }

    /** Whether the copy is linked to its source ("Link copied issue" setting: yes, no or ask) */
    public function link(Request $request): bool
    {
        return match ($this->settings->string('link_copied_issue')) {
            'yes' => true,
            'no' => false,
            default => '1' === (string) IssueParams::get($request, 'link_copy'),
        };
    }

    /** Whether the attachments are copied ("Copy attachments when copying an issue" setting: yes, no or ask) */
    public function attachments(Request $request): bool
    {
        return match ($this->settings->string('copy_attachments_on_issue_copy')) {
            'yes' => true,
            'no' => false,
            default => '1' === (string) IssueParams::get($request, 'copy_attachments'),
        };
    }

    public function subtasks(Request $request): bool
    {
        return '' !== (string) IssueParams::get($request, 'copy_subtasks', '');
    }
}
