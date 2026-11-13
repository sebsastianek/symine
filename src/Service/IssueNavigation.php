<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Query as QueryRecord;
use App\Query\IssueQuery;
use App\Query\QueryFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Port of IssuesController#retrieve_previous_and_next_issue_ids / #previous_and_next_issue_ids_params:
 * "« Previous | 3 of 12 | Next »" on the issue page, computed from the issue query stored in the
 * session by the issue list (QueryFactory session key "issue_query"), or carried over an update
 * (Redmine's flash[:previous_and_next_issue_ids], stored here under a dedicated session key).
 */
final class IssueNavigation
{
    public const SESSION_KEY = 'previous_and_next_issue_ids';
    private const LIMIT = 500;

    public function __construct(
        private readonly QueryFactory $queries,
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return array{prev_issue_id: ?int, next_issue_id: ?int, issue_position: ?int, issue_count: ?int, query_path: ?string} */
    public function retrieve(Request $request, Issue $issue): array
    {
        $vars = ['prev_issue_id' => null, 'next_issue_id' => null, 'issue_position' => null, 'issue_count' => null, 'query_path' => null];
        $session = $request->hasSession() ? $request->getSession() : null;
        if (null === $session) {
            return $vars;
        }
        if ($session->has(self::SESSION_KEY)) {
            $info = (array) $session->remove(self::SESSION_KEY);
            foreach (['prev_issue_id', 'next_issue_id', 'issue_position', 'issue_count'] as $k) {
                $vars[$k] = isset($info[$k]) && '' !== (string) $info[$k] ? (int) $info[$k] : null;
            }

            return $vars;
        }
        $query = $this->queryFromSession($request);
        if (null === $query) {
            return $vars;
        }
        $perPage = $this->perPageOption($request);
        try {
            $ids = $query->issueIds(0, self::LIMIT + 1);
        } catch (\Throwable) {
            return $vars;
        }
        $idx = array_search($issue->id, $ids, true);
        if (false !== $idx && $idx < self::LIMIT) {
            if (\count($ids) < self::LIMIT) {
                $vars['issue_position'] = $idx + 1;
                $vars['issue_count'] = \count($ids);
            }
            if ($idx > 0) {
                $vars['prev_issue_id'] = $ids[$idx - 1];
            }
            if ($idx < \count($ids) - 1) {
                $vars['next_issue_id'] = $ids[$idx + 1];
            }
        }
        $params = $query->asParams();
        if (!$query->isNew()) {
            $params = ['query_id' => $query->record->id];
        } elseif (null === $query->record->columnNames) {
            unset($params['c']);
        }
        if (null !== $vars['issue_position']) {
            $params['page'] = intdiv($vars['issue_position'], $perPage) + 1;
            $params['per_page'] = $perPage;
        }
        ksort($params);
        $path = null !== $query->project
            ? $this->urls->generate('project_issues', ['project_id' => $query->project->identifier] + $params)
            : $this->urls->generate('issues_index', $params);
        // Hash#to_query style array parameters (f[]=..., v[status_id][]=...)
        $vars['query_path'] = (string) preg_replace('/%5B\d+%5D/', '%5B%5D', $path);

        return $vars;
    }

    /** Remembers the navigation of the edit form (hidden fields) across the redirect after an update. */
    public function rememberFromParams(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $info = [];
        foreach (['prev_issue_id', 'next_issue_id', 'issue_position', 'issue_count'] as $k) {
            $info[$k] = $request->request->get($k);
        }
        $request->getSession()->set(self::SESSION_KEY, $info);
    }

    /** QueriesHelper#retrieve_query_from_session */
    private function queryFromSession(Request $request): ?IssueQuery
    {
        $data = $request->getSession()->get(QueryFactory::sessionKey('IssueQuery'));
        if (!\is_array($data)) {
            return null;
        }
        $project = null;
        if (\array_key_exists('project_id', $data) && null !== $data['project_id']) {
            $project = $this->em->find(Project::class, (int) $data['project_id']);
        }
        if (!empty($data['id'])) {
            $record = $this->queries->find((int) $data['id']);
            if (!$record instanceof QueryRecord || 'IssueQuery' !== $record::typeName()) {
                return null;
            }
            $query = $this->queries->wrap($record, $project);
        } else {
            $query = $this->queries->create('IssueQuery', $project);
            $query->record->filters = $data['filters'] ?? null;
            $query->setGroupBy($data['group_by'] ?? null);
            $query->record->columnNames = $data['column_names'] ?? null;
            $query->setTotalableNames($data['totalable_names'] ?? null);
            $query->record->sortCriteria = $data['sort'] ?? null;
        }

        return $query instanceof IssueQuery ? $query : null;
    }

    /** ApplicationController#per_page_option */
    private function perPageOption(Request $request): int
    {
        $options = $this->settings->perPageOptions();
        $perPage = (int) $request->query->get('per_page', 0);
        if (!\in_array($perPage, $options, true)) {
            $perPage = (int) ($request->getSession()->get('per_page') ?? 0);
        }

        return \in_array($perPage, $options, true) ? $perPage : (int) ($options[0] ?? 25);
    }
}
