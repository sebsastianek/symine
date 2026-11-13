<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Token;
use App\Entity\User;
use App\Query\IssueQuery;
use App\Query\QueryFactory;
use App\Query\StatementInvalid;
use App\Service\AtomFeed;
use App\Twig\QueryExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Issue list (IssuesController#index): HTML list with filters/options, CSV export, Atom feed and
 * the issues REST API index.
 */
final class IssueListController extends AbstractController
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly ApiRenderer $api,
        private readonly IssueApiPresenter $presenter,
        private readonly AtomFeed $feed,
        private readonly QueryExtension $helpers,
        private readonly \App\Pdf\PdfExport $pdfExport,
    ) {
    }

    #[Route('/issues.{_format}', name: 'issues_index', requirements: ['_format' => 'html|csv|atom|json|xml|pdf'], defaults: ['_format' => 'html'], methods: ['GET'], priority: 10)]
    public function index(Request $request, string $_format = 'html'): Response
    {
        // find_optional_project: /issues?project_id=x is the project's issue list
        $projectId = $request->query->get('project_id');
        if (\is_string($projectId) && '' !== $projectId) {
            if (null === $this->projects->findByIdentifierOrId($projectId) && !$this->user()->isLogged()) {
                $this->deny();
            }

            return $this->doIndex($request, $this->findProject($projectId), $_format);
        }

        return $this->doIndex($request, null, $_format);
    }

    #[Route('/projects/{project_id}/issues.{_format}', name: 'project_issues', requirements: ['_format' => 'html|csv|atom|json|xml|pdf'], defaults: ['_format' => 'html'], methods: ['GET'], priority: 10)]
    public function projectIndex(Request $request, string $project_id, string $_format = 'html'): Response
    {
        return $this->doIndex($request, $this->findProject($project_id), $_format);
    }

    private function doIndex(Request $request, ?Project $project, string $format): Response
    {
        $this->authorize('view_issues', $project, null === $project);
        $useSession = \in_array($format, ['html', 'pdf'], true);
        /** @var IssueQuery $query */
        $query = $this->queries->retrieve($request, 'IssueQuery', $project, $useSession, true);

        if (!$query->isValid()) {
            return match ($format) {
                'json', 'xml' => $this->api->errors($request, $query->errors),
                'csv', 'atom', 'pdf' => new Response('', 422),
                default => $this->renderIndex($request, $query, $project, [], 0, null),
            };
        }
        try {
            switch ($format) {
                case 'csv':
                    if (!$this->exportAllowed($query, $project)) {
                        $this->deny();
                    }

                    return $this->csv($request, $query);
                case 'pdf':
                    if (!$this->exportAllowed($query, $project)) {
                        $this->deny();
                    }

                    return $this->pdfExport->issues($request, $query, $project);
                case 'atom':
                    return $this->atom($request, $query, $project);
                case 'json':
                case 'xml':
                    return $this->apiIndex($request, $query);
            }
            $count = $query->issueCount();
            $pager = $this->paginate($request, $count);
            $issues = $query->issues($pager['offset'], $pager['limit']);

            return $this->renderIndex($request, $query, $project, $issues, $count, $pager);
        } catch (StatementInvalid $e) {
            return $this->render('common/error.html.twig', ['message' => $this->l('error_query_statement_invalid'), 'status' => 500], new Response('', 500));
        }
    }

    private function renderIndex(Request $request, IssueQuery $query, ?Project $project, array $issues, int $count, ?array $pager): Response
    {
        $user = $this->user();
        $atomKey = $this->atomKey($user);
        $vars = [
            'project' => $project,
            'query' => $query,
            'issues' => $issues,
            'issue_count' => $count,
            'pager' => $pager,
            'main_menu_selected' => 'issues',
            'body_css' => 'controller-issues action-index',
            'page_title' => $query->isNew() ? $this->l('label_issue_plural') : $query->name(),
            'atom_key' => $atomKey,
            'export_allowed' => $this->exportAllowed($query, $project),
            'can_add_issues' => $this->auth->allowedTo('add_issues', $project, null, null === $project) && (null === $project || [] !== $this->projects->allowedTargetTrackers($project)),
            'can_import' => $this->auth->allowedTo('import_issues', $project, null, true) && $this->auth->allowedTo('add_issues', $project, null, true),
            'can_save_queries' => $this->auth->allowedTo('save_queries', $project, null, true),
            'issues_export_limit' => $this->settings->int('issues_export_limit'),
        ];
        if ($request->isXmlHttpRequest()) {
            return $this->render('issues/_index_content.html.twig', $vars);
        }

        return $this->render('issues/index.html.twig', $vars);
    }

    /** ApplicationController#export_allowed? */
    private function exportAllowed(IssueQuery $query, ?Project $project): bool
    {
        $user = $this->user();
        if ($user->admin) {
            return true;
        }
        if (null !== $project) {
            if (!$this->auth->allowedTo('export_issues', $project)) {
                return false;
            }
            if (($project->rgt ?? 0) - ($project->lft ?? 0) <= 1) {
                return true;
            }
        } elseif (!$this->auth->allowedToGlobally('export_issues')) {
            return false;
        }
        if (!$query->isValid()) {
            return false;
        }
        $allowed = $this->auth->allowedToCondition('export_issues', 'projects.id');
        try {
            $rows = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$query->baseFromSql().' WHERE '.$query->whereSql().' AND NOT ('.$allowed.')');
        } catch (\Throwable) {
            return false;
        }

        return 0 === (int) $rows;
    }

    private function apiIndex(Request $request, IssueQuery $query): Response
    {
        $limit = max(1, min(100, (int) ($request->query->get('limit') ?: 25)));
        $offset = max(0, (int) $request->query->get('offset', 0));
        if ($request->query->has('page') && !$request->query->has('offset')) {
            $offset = max(0, ((int) $request->query->get('page') - 1) * $limit);
        }
        $query->setColumnNames(['author']);
        $count = $query->issueCount();
        $issues = $query->issues($offset, $limit);
        $include = array_filter(array_map('trim', explode(',', (string) $request->query->get('include', ''))));
        $include = array_values(array_intersect($include, ['attachments', 'relations']));
        $list = [];
        foreach ($issues as $issue) {
            $data = $this->presenter->issue($issue, $include);
            if (\in_array('relations', $include, true) && !isset($data['relations'])) {
                $data['relations'] = [];
            }
            $list[] = $data;
        }

        return $this->api->render($request, ['issues' => $list, 'total_count' => $count, 'offset' => $offset, 'limit' => $limit], 200, ['relation' => IssueApiPresenter::ISSUE_XML_ATTRIBUTES['relation']]);
    }

    private function atom(Request $request, IssueQuery $query, ?Project $project): Response
    {
        $limit = $this->settings->int('feeds_limit') ?: 15;
        $issues = $query->issues(null, $limit);
        usort($issues, static fn (Issue $a, Issue $b) => $b->createdOn <=> $a->createdOn);
        $items = array_map(fn (Issue $i) => [
            'title' => $i->tracker->name.' #'.$i->id.' ('.$i->status->name.'): '.$i->subject,
            'url' => $this->generateUrl('issue_show', ['id' => $i->id]),
            'updated' => $i->createdOn ?? new \DateTimeImmutable(),
            'author' => $i->author,
            'content' => $i->description,
            'project' => $i->project,
        ], \array_slice($issues, 0, $limit));
        $title = ($project?->name ?? $this->settings->appTitle()).': '.$this->l('label_issue_plural');

        return $this->feed->render($request, $title, $items, $project);
    }

    private function csv(Request $request, IssueQuery $query): Response
    {
        $issues = $query->issues(null, $this->settings->int('issues_export_limit') ?: 500);
        $columns = $query->columns();
        $sep = (string) ($request->query->get('field_separator') ?: $this->l('general_csv_separator'));
        $encoding = (string) ($request->query->get('encoding') ?: $this->l('general_csv_encoding'));
        $rows = [array_map(static fn ($c) => $c->caption, $columns)];
        foreach ($issues as $issue) {
            $rows[] = array_map(fn ($c) => $this->csvContent($query, $c, $issue), $columns);
        }
        $csv = self::csvString($rows, $sep);
        $isUtf8 = 0 === strcasecmp($encoding, 'UTF-8');
        if ($isUtf8) {
            $csv = "\xEF\xBB\xBF".$csv;
        } else {
            $converted = @mb_convert_encoding($csv, $encoding, 'UTF-8');
            $csv = false === $converted ? $csv : $converted;
        }
        $name = (string) ($request->query->get('query_name') ?: $query->name());
        if ('_' === $name || '' === trim($name)) {
            $name = 'issues';
        }
        $filename = mb_strtolower(self::titleize($name)).'.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; header=present',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($filename, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($filename),
        ]);
    }

    /** Wiki.titleize */
    private static function titleize(string $title): string
    {
        $title = trim(preg_replace('/[\s,.\/\?\;\|\:]+/u', '_', $title) ?? $title);
        $title = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $title) ?? $title;

        return mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1);
    }

    /** QueriesHelper#csv_content: null => empty cell, '' => "" */
    private function csvContent(IssueQuery $query, \App\Query\QueryColumn $column, Issue $issue): ?string
    {
        $value = $query->columnValue($column, $issue);
        if (\is_array($value) && !isset($value['custom_field'])) {
            $parts = array_filter(array_map(fn ($v) => $this->csvValue($query, $column, $issue, $v), $value), static fn ($p) => null !== $p);
            if (\in_array($column->name, ['attachments', 'watcher_users'], true)) {
                return implode("\n", array_map(fn ($v) => $v instanceof \App\Entity\Attachment ? $v->filename : ($v instanceof \App\Entity\Principal ? $v->getName() : (string) $v), $value));
            }

            return implode(', ', $parts);
        }

        return $this->csvValue($query, $column, $issue, $value);
    }

    private function csvValue(IssueQuery $query, \App\Query\QueryColumn $column, Issue $issue, mixed $value): ?string
    {
        $dec = $this->l('general_csv_decimal_separator');
        if (\is_array($value) && isset($value['custom_field'])) {
            $cf = $value['custom_field'];
            $raw = array_values(array_filter((array) $value['value'], static fn ($v) => null !== $v && '' !== (string) $v));
            if ([] === $raw) {
                return null;
            }
            if ('float' === $cf->fieldFormat) {
                return implode(', ', array_map(static fn ($v) => str_replace('.', $dec, \sprintf('%.2f', (float) $v)), $raw));
            }

            return html_entity_decode(strip_tags($this->helpers->formatObject($value, false)), \ENT_QUOTES);
        }

        return match (true) {
            null === $value => '',
            \is_float($value) => str_replace('.', $dec, \sprintf('%.2f', $value)),
            \is_int($value) => (string) $value,
            \is_bool($value) => $this->l($value ? 'general_text_Yes' : 'general_text_No'),
            $value instanceof \App\Entity\IssueRelation => html_entity_decode(strip_tags($this->helpers->relationToS($value, $issue, false)), \ENT_QUOTES),
            $value instanceof Issue => (string) $value->id,
            $value instanceof \App\Entity\Principal => $value->getName(),
            $value instanceof \DateTimeInterface => html_entity_decode($this->helpers->formatObject($value, false), \ENT_QUOTES),
            \is_object($value) => method_exists($value, '__toString') ? (string) $value : ((property_exists($value, 'name') ? (string) $value->name : '')),
            default => (string) $value,
        };
    }

    /** Ruby CSV generation: quotes empty strings and fields with separators/quotes/newlines; null gives an empty field */
    public static function csvString(array $rows, string $sep): string
    {
        $out = '';
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $v) {
                if (null === $v) {
                    $cells[] = '';
                    continue;
                }
                $v = (string) $v;
                if ('' === $v || str_contains($v, $sep) || str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r")) {
                    $v = '"'.str_replace('"', '""', $v).'"';
                }
                $cells[] = $v;
            }
            $out .= implode($sep, $cells)."\n";
        }

        return $out;
    }

    /** User#atom_key */
    private function atomKey(User $user): ?string
    {
        if (!$user->isLogged() || null === $user->id) {
            return null;
        }
        $token = $this->em->getRepository(Token::class)->findOneBy(['user' => $user, 'action' => 'feeds']);
        if (null === $token) {
            $token = new Token($user, 'feeds');
            $this->em->persist($token);
            $this->em->flush();
        }

        return $token->value;
    }
}
