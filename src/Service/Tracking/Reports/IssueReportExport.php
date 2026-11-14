<?php

declare(strict_types=1);

namespace App\Service\Tracking\Reports;

use App\Entity\IssueStatus;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Query\QueryContext;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CSV export of an issue report, for those who may export every issue it counts.
 */
final class IssueReportExport
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QueryContext $ctx,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /** ApplicationController#export_allowed? on the report scope */
    public function isAllowed(Project $project): bool
    {
        $with = $this->settings->displaySubprojectsIssues();
        $user = $this->currentUser->get();
        if ($user->admin) {
            return true;
        }
        if (!$this->auth->allowedTo('export_issues', $project)) {
            return false;
        }
        if (($project->rgt ?? 0) - ($project->lft ?? 0) <= 1) {
            return true;
        }
        $scope = $with ? 'projects.lft >= '.(int) $project->lft.' AND projects.rgt <= '.(int) $project->rgt : 'projects.id = '.(int) $project->id;
        $allowed = $this->auth->allowedToCondition('export_issues', 'projects.id');
        $n = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM issues INNER JOIN projects ON projects.id = issues.project_id WHERE '
            .$this->ctx->issueVisibleSql()." AND ($scope) AND NOT ($allowed)");

        return 0 === (int) $n;
    }

    /**
     * The download of a report. Parameters: field_separator, encoding (defaults of the language).
     *
     * @param array{statuses: list<IssueStatus>, rows: list<array>, data: list<array>, field: string, detail: string} $report IssueReport::details()
     */
    public function csv(Request $request, array $report): Response
    {
        ['statuses' => $statuses, 'rows' => $rows, 'data' => $data, 'field' => $field, 'detail' => $detail] = $report;
        $sep = (string) ($request->query->get('field_separator') ?: $this->i18n->l('general_csv_separator'));
        $encoding = (string) ($request->query->get('encoding') ?: $this->i18n->l('general_csv_encoding'));
        $lines = [array_merge([''], array_map(static fn (IssueStatus $s) => $s->name, $statuses), [$this->i18n->l('label_open_issues_plural'), $this->i18n->l('label_closed_issues_plural'), $this->i18n->l('label_total')])];
        foreach ($rows as $row) {
            $line = [$row['name']];
            foreach ($statuses as $s) {
                $line[] = (string) ReportAggregator::aggregate($data, [$field => $row['id'], 'status_id' => $s->id]);
            }
            $line[] = (string) ReportAggregator::aggregate($data, [$field => $row['id'], 'closed' => false]);
            $line[] = (string) ReportAggregator::aggregate($data, [$field => $row['id'], 'closed' => true]);
            $line[] = (string) ReportAggregator::aggregate($data, [$field => $row['id']]);
            $lines[] = $line;
        }
        $csv = '';
        foreach ($lines as $line) {
            $csv .= implode($sep, array_map(static function (string $v) use ($sep) {
                return '' === $v || str_contains($v, $sep) || str_contains($v, '"') || str_contains($v, "\n") ? '"'.str_replace('"', '""', $v).'"' : $v;
            }, $line))."\n";
        }
        if (0 === strcasecmp($encoding, 'UTF-8')) {
            $csv = "\xEF\xBB\xBF".$csv;
        } else {
            $converted = @mb_convert_encoding($csv, $encoding, 'UTF-8');
            $csv = false === $converted ? $csv : $converted;
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; header=present',
            'Content-Disposition' => 'attachment; filename="report-'.$detail.'.csv"',
        ]);
    }
}
