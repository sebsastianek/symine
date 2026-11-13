<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Attachment;
use App\Entity\CustomField;
use App\Entity\Issue;
use App\Entity\IssueRelation;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Version;
use App\I18n\Localizer;
use App\Query\AbstractQuery;
use App\Query\IssueQuery;
use App\Query\QueryColumn;
use App\Query\QueryContext;
use App\Query\QueryFactory;
use App\Service\Settings;
use App\Service\TextFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Port of QueriesHelper / IssuesHelper (list related parts) and ApplicationHelper#format_object.
 */
final class QueryExtension extends AbstractExtension
{
    public function __construct(
        private readonly AppExtension $app,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requests,
        private readonly TextFormatter $formatter,
        private readonly QueryContext $ctx,
        private readonly QueryFactory $factory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('format_object', $this->formatObject(...), $safe),
            new TwigFunction('query_column_header', $this->columnHeader(...), $safe),
            new TwigFunction('query_column_content', $this->columnContent(...), $safe),
            new TwigFunction('query_total_tag', $this->totalTag(...), $safe),
            new TwigFunction('render_query_totals', $this->renderQueryTotals(...), $safe),
            new TwigFunction('filters_options_for_select', $this->filtersOptionsForSelect(...), $safe),
            new TwigFunction('query_columns_hidden_tags', $this->queryColumnsHiddenTags(...), $safe),
            new TwigFunction('query_filters_hidden_tags', $this->queryFiltersHiddenTags(...), $safe),
            new TwigFunction('query_as_hidden_field_tags', $this->queryAsHiddenFieldTags(...), $safe),
            new TwigFunction('query_operators_labels', fn () => AbstractQuery::operatorsLabels($this->ctx)),
            new TwigFunction('query_operators_by_filter_type', static fn () => AbstractQuery::OPERATORS_BY_FILTER_TYPE),
            new TwigFunction('grouped_issue_list', $this->groupedIssueList(...)),
            new TwigFunction('render_sidebar_queries', $this->renderSidebarQueries(...), $safe),
            new TwigFunction('link_to_attachment', $this->linkToAttachment(...), $safe),
            new TwigFunction('relation_to_s', $this->relationToS(...), $safe),
            new TwigFunction('issue_query_url', $this->listUrl(...)),
            new TwigFunction('current_url_with', $this->currentUrlWith(...)),
        ];
    }

    private static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, \ENT_QUOTES);
    }

    /** ApplicationHelper#format_object */
    public function formatObject(mixed $object, bool $html = true, bool $thousandsDelimiter = false): string
    {
        $delim = $thousandsDelimiter ? $this->i18n->l('number.format.delimiter') : '';
        if (\is_array($object) && isset($object['custom_field']) && $object['custom_field'] instanceof CustomField) {
            return $this->formatCustomValue($object['custom_field'], $object['value'], $html);
        }
        if (\is_array($object)) {
            $parts = array_map(fn ($o) => $this->formatObject($o, $html, $thousandsDelimiter), $object);

            return implode(', ', $parts);
        }

        return match (true) {
            null === $object => '',
            $object instanceof \DateTimeInterface && '00:00:00' === $object->format('H:i:s') && 'UTC' === $object->getTimezone()->getName() && !$this->isTimestamp($object) => self::h($this->i18n->formatDate($object)),
            $object instanceof \DateTimeInterface => self::h($this->isTimestamp($object) ? $this->i18n->formatTime($object) : $this->i18n->formatDate($object)),
            \is_int($object) => number_format($object, 0, '.', $delim),
            \is_float($object) => number_format($object, 2, '.', $delim),
            true === $object => self::h($this->i18n->l('general_text_Yes')),
            false === $object => self::h($this->i18n->l('general_text_No')),
            $object instanceof Principal => $html ? $this->app->linkToUser($object) : self::h($object->getName()),
            $object instanceof Project => $html ? $this->app->linkToProject($object) : self::h($object->name),
            $object instanceof Version => $html ? $this->app->linkToVersion($object) : self::h((string) $object),
            $object instanceof Issue => $html && $this->ctx->issues->isVisible($object) ? $this->app->linkToIssue($object) : '#'.$object->id,
            $object instanceof Attachment => $html ? '<span class="attachment-filename">'.$this->linkToAttachment($object).$this->linkToAttachment($object, ['class' => 'icon-only icon-download', 'icon' => 'download', 'title' => $this->i18n->l('button_download'), 'download' => true]).'</span>' : self::h($object->filename),
            default => $html ? self::h((string) $object) : (string) $object,
        };
    }

    /** Time values carry a time part; dates are loaded by Doctrine at midnight (date type) */
    private function isTimestamp(\DateTimeInterface $d): bool
    {
        return '00:00:00.000000' !== $d->format('H:i:s.u');
    }

    private function formatCustomValue(CustomField $cf, mixed $value, bool $html): string
    {
        $values = array_values(array_filter((array) $value, static fn ($v) => null !== $v && '' !== (string) $v));
        if ([] === $values) {
            return '';
        }
        if (\count($values) > 1) {
            sort($values);
        }
        $out = [];
        foreach ($values as $v) {
            $out[] = match ($cf->fieldFormat) {
                'user' => ($p = $this->em->find(Principal::class, (int) $v)) ? ($html ? $this->app->linkToUser($p) : self::h($p->getName())) : self::h((string) $v),
                'version' => ($ver = $this->em->find(Version::class, (int) $v)) ? ($html ? $this->app->linkToVersion($ver) : self::h((string) $ver)) : self::h((string) $v),
                'link' => $html ? '<a href="'.self::h(preg_match('#\A[a-z]+://#i', (string) $v) ? (string) $v : 'http://'.$v).'" class="external">'.self::h(mb_strimwidth((string) $v, 0, 40, '...')).'</a>' : self::h((string) $v),
                'float' => number_format((float) $v, 2, '.', '1' === (string) $cf->getFormatOption('thousands_delimiter') ? $this->i18n->l('number.format.delimiter') : ''),
                'int' => number_format((int) $v, 0, '.', '1' === (string) $cf->getFormatOption('thousands_delimiter') ? $this->i18n->l('number.format.delimiter') : ''),
                'text' => $html && 'full' === $cf->getFormatOption('text_formatting') ? '<div class="wiki">'.$this->formatter->format((string) $v).'</div>' : self::h((string) $v),
                default => self::h($this->ctx->customFields->formatValue($cf, $v)),
            };
        }

        return implode(', ', $out);
    }

    public function linkToAttachment(Attachment $a, array $options = []): string
    {
        $text = $options['text'] ?? $a->filename;
        $download = $options['download'] ?? false;
        $route = $download ? 'attachment_download' : 'attachment_show';
        $url = '#';
        try {
            $url = $download ? $this->urls->generate($route, ['id' => $a->id, 'filename' => $a->filename]) : $this->urls->generate($route, ['id' => $a->id, 'filename' => $a->filename]);
        } catch (\Throwable) {
            try {
                $url = $this->urls->generate($route, ['id' => $a->id]);
            } catch (\Throwable) {
            }
        }
        $label = isset($options['icon']) ? $this->app->spriteIcon($options['icon'], $text) : self::h($text);
        $attrs = '';
        if (isset($options['class'])) {
            $attrs .= ' class="'.self::h($options['class']).'"';
        }
        if (isset($options['title'])) {
            $attrs .= ' title="'.self::h($options['title']).'"';
        }

        return '<a'.$attrs.' href="'.self::h($url).'">'.$label.'</a>';
    }

    /** IssueRelation#to_s(issue) with a link to the other issue */
    public function relationToS(IssueRelation $relation, Issue $issue, bool $link = true): string
    {
        $other = $relation->otherIssue($issue);
        $text = $link ? $this->app->linkToIssue($other, ['subject' => false, 'tracker' => false]) : '#'.$other->id;
        $s = [self::h($this->i18n->l($relation->labelFor($issue)))];
        if (null !== $relation->delay && 0 !== $relation->delay) {
            $s[] = '('.self::h($this->i18n->l('datetime.distance_in_words.x_days', ['count' => $relation->delay])).')';
        }
        $s[] = $text;

        return implode(' ', $s);
    }

    /** URL of the current page with merged query parameters */
    public function currentUrlWith(array $merge = []): string
    {
        $request = $this->requests->getCurrentRequest();
        $params = array_replace_recursive($request?->query->all() ?? [], $merge);
        $params = array_filter($params, static fn ($v) => null !== $v);
        $qs = http_build_query($params);

        return ($request?->getPathInfo() ?? '').('' !== $qs ? '?'.$qs : '');
    }

    /** _project_issues_path(project, params) */
    public function listUrl(?Project $project, array $params = [], ?string $format = null): string
    {
        $params = array_filter($params, static fn ($v) => null !== $v);
        if (null !== $project) {
            return $this->urls->generate('project_issues', ['project_id' => $project->identifier] + ($format ? ['_format' => $format] : []) + $params);
        }

        return $this->urls->generate('issues_index', ($format ? ['_format' => $format] : []) + $params);
    }

    /** QueriesHelper#column_header */
    public function columnHeader(AbstractQuery $query, QueryColumn $column, array $options = []): string
    {
        if (!$column->isSortable()) {
            return '<th class="'.self::h($column->cssClasses()).'">'.self::h($column->caption).'</th>';
        }
        $css = null;
        $icon = null;
        $order = $column->defaultOrder;
        $criteria = $query->sortCriteria();
        if ($column->name === $criteria->firstKey()) {
            if ($criteria->isFirstAsc()) {
                $css = 'sort asc icon icon-sorted-desc';
                $icon = 'angle-up';
                $order = 'desc';
            } else {
                $css = 'sort desc icon icon-sorted-asc';
                $icon = 'angle-down';
                $order = 'asc';
            }
        }
        $paramKey = $options['sort_param'] ?? 'sort';
        $sortValue = $criteria->add($column->name, $order)->toParam();
        $request = $this->requests->getCurrentRequest();
        $params = $request?->query->all() ?? [];
        if (preg_match('/^(.+)\[(.+)\]$/', $paramKey, $m)) {
            $params[$m[1]][$m[2]] = $sortValue;
        } else {
            $params[$paramKey] = $sortValue;
        }
        $url = ($options['url'] ?? $request?->getPathInfo() ?? '').'?'.self::toQuery($params);
        $content = null !== $icon ? $this->app->spriteIcon($icon, $column->caption) : self::h($column->caption);
        $title = $this->i18n->l('label_sort_by', ['value' => '"'.$column->caption.'"']);
        $a = '<a title="'.self::h($title).'"'.(null !== $css ? ' class="'.$css.'"' : '').' href="'.self::h($url).'"';
        foreach ($options['sort_link_options'] ?? [] as $k => $v) {
            $a .= ' '.$k.'="'.self::h((string) $v).'"';
        }

        return '<th class="'.self::h($column->cssClasses()).'">'.$a.'>'.$content.'</a></th>';
    }

    /** Hash#to_query (Rails): sorted keys, arrays as name[]=value */
    public static function toQuery(array $params, ?string $prefix = null): string
    {
        if (null === $prefix) {
            ksort($params);
        }
        $parts = [];
        $isList = array_is_list($params);
        foreach ($params as $k => $v) {
            $key = null === $prefix ? (string) $k : ($isList ? $prefix.'[]' : $prefix.'['.$k.']');
            if (\is_array($v)) {
                if ([] === $v) {
                    $parts[] = rawurlencode($key.'[]').'=';
                    continue;
                }
                $parts[] = self::toQuery($v, $key);
            } elseif (null !== $v) {
                $parts[] = rawurlencode($key).'='.rawurlencode((string) $v);
            }
        }

        return implode('&', array_filter($parts, static fn ($p) => '' !== $p));
    }

    /** QueriesHelper#column_content */
    public function columnContent(AbstractQuery $query, QueryColumn $column, object $item): string
    {
        $value = $query instanceof IssueQuery && $item instanceof Issue ? $query->columnValue($column, $item) : null;
        if (\is_array($value) && !isset($value['custom_field'])) {
            $parts = [];
            foreach ($value as $v) {
                $parts[] = $this->columnValue($query, $column, $item, $v);
            }

            return implode(', ', array_filter($parts, static fn ($p) => '' !== $p));
        }

        return $this->columnValue($query, $column, $item, $value);
    }

    private function columnValue(AbstractQuery $query, QueryColumn $column, object $item, mixed $value): string
    {
        $issueUrl = $item instanceof Issue ? $this->urls->generate('issue_show', ['id' => $item->id]) : '#';
        switch ($column->name) {
            case 'id':
            case 'subject':
                return '<a href="'.self::h($issueUrl).'">'.self::h((string) $value).'</a>';
            case 'parent':
                if (!$value instanceof Issue) {
                    return '';
                }

                return $this->ctx->issues->isVisible($value) ? $this->app->linkToIssue($value, ['subject' => false]) : '#'.$value->id;
            case 'description':
                return $item instanceof Issue && null !== $item->description && '' !== trim($item->description) ? '<div class="wiki">'.$this->formatter->format($item->description, $item->project).'</div>' : '';
            case 'last_notes':
                return null !== $value && '' !== $value && $item instanceof Issue ? '<div class="wiki">'.$this->formatter->format((string) $value, $item->project).'</div>' : '';
            case 'done_ratio':
                return $this->app->progressBar((float) $value);
            case 'relations':
                return $value instanceof IssueRelation && $item instanceof Issue
                    ? '<span class="rel-'.self::h($this->relationTypeFor($value, $item)).'">'.$this->relationToS($value, $item).'</span>' : '';
            case 'hours':
            case 'estimated_hours':
            case 'total_estimated_hours':
            case 'estimated_remaining_hours':
                return self::h($this->i18n->formatHours(null === $value ? null : (float) $value));
            case 'spent_hours':
            case 'total_spent_hours':
                if ((float) $value <= 0 || !$item instanceof Issue) {
                    return '';
                }
                $hours = self::h($this->i18n->formatHours((float) $value));
                try {
                    $url = $this->urls->generate('project_time_entries', ['project_id' => $item->project->identifier, 'issue_id' => ('total_spent_hours' === $column->name ? '~' : '').$item->id]);
                } catch (\Throwable) {
                    return $hours;
                }

                return '<a href="'.self::h($url).'">'.$hours.'</a>';
            case 'attachments':
                return $value instanceof Attachment ? $this->formatObject($value) : '';
            case 'watcher_users':
                return $value instanceof Principal ? '<li>'.$this->formatObject($value).'</li>' : '';
            default:
                return $this->formatObject($value);
        }
    }

    private function relationTypeFor(IssueRelation $r, Issue $issue): string
    {
        if ($r->issueFrom->id === $issue->id) {
            return $r->relationType;
        }
        $sym = [IssueRelation::TYPE_RELATES => IssueRelation::TYPE_RELATES, IssueRelation::TYPE_DUPLICATES => IssueRelation::TYPE_DUPLICATED,
            IssueRelation::TYPE_DUPLICATED => IssueRelation::TYPE_DUPLICATES, IssueRelation::TYPE_BLOCKS => IssueRelation::TYPE_BLOCKED,
            IssueRelation::TYPE_BLOCKED => IssueRelation::TYPE_BLOCKS, IssueRelation::TYPE_PRECEDES => IssueRelation::TYPE_FOLLOWS,
            IssueRelation::TYPE_FOLLOWS => IssueRelation::TYPE_PRECEDES, IssueRelation::TYPE_COPIED_TO => IssueRelation::TYPE_COPIED_FROM,
            IssueRelation::TYPE_COPIED_FROM => IssueRelation::TYPE_COPIED_TO];

        return $sym[$r->relationType] ?? $r->relationType;
    }

    /** QueriesHelper#total_tag */
    public function totalTag(QueryColumn $column, mixed $value): string
    {
        $label = '<span>'.self::h($column->caption).':</span>';
        if (\in_array($column->name, ['hours', 'spent_hours', 'total_spent_hours', 'estimated_hours', 'total_estimated_hours', 'estimated_remaining_hours'], true)) {
            $v = self::h($this->i18n->formatHours((float) $value));
        } elseif ($column->isCustomField()) {
            $v = $this->formatObject('int' === $column->customField?->fieldFormat ? (int) $value : (float) $value, true, '1' === (string) $column->customField?->getFormatOption('thousands_delimiter'));
        } else {
            $v = $this->formatObject($value);
        }

        return '<span class="total-for-'.str_replace('_', '-', $column->name).'">'.$label.' <span class="value">'.$v.'</span></span>';
    }

    public function renderQueryTotals(AbstractQuery $query): string
    {
        if (!$query instanceof IssueQuery || [] === $query->totalableColumns()) {
            return '';
        }
        $totals = array_map(fn (array $t) => $this->totalTag($t[0], $t[1]), $query->totals());

        return '<p class="query-totals">'.implode(' ', $totals).'</p>';
    }

    /**
     * IssuesHelper#grouped_issue_list + QueriesHelper#grouped_query_results.
     *
     * @return list<array{issue: Issue, level: int, group_name: ?string, group_count: ?int, group_totals: ?string}>
     */
    public function groupedIssueList(array $issues, IssueQuery $query): array
    {
        $counts = $query->resultCountByGroup();
        $totalsByGroup = [];
        if ($query->isGrouped()) {
            foreach ($query->totalableColumns() as $c) {
                $totalsByGroup[] = [$c, $query->totalByGroupFor($c) ?? []];
            }
        }
        $rows = [];
        $ancestors = [];
        $previous = null;
        $first = true;
        foreach ($issues as $issue) {
            $groupName = null;
            $groupCount = null;
            $groupTotals = null;
            if ($query->isGrouped()) {
                $key = $query->groupKey($issue);
                if ($first || $key !== $previous) {
                    $value = $query->groupValue($issue);
                    $blank = null === $value || '' === $value || (\is_array($value) && isset($value['custom_field']) && (null === $value['value'] || '' === $value['value'] || [] === $value['value']));
                    if ($blank && false !== $value) {
                        $groupName = '('.self::h($this->i18n->l('label_blank_value')).')';
                    } else {
                        $groupName = $this->formatObject($value);
                    }
                    $groupCount = $counts[$key] ?? null;
                    $groupTotals = implode(' ', array_map(fn ($ct) => $this->totalTag($ct[0], $ct[1][$key] ?? 0), $totalsByGroup));
                }
                $previous = $key;
            }
            $first = false;
            while ([] !== $ancestors && !$issue->isDescendantOf(end($ancestors))) {
                array_pop($ancestors);
            }
            $rows[] = ['issue' => $issue, 'level' => \count($ancestors), 'group_name' => $groupName, 'group_count' => $groupCount, 'group_totals' => $groupTotals];
            if (!$issue->isLeaf()) {
                $ancestors[] = $issue;
            }
        }

        return $rows;
    }

    /** QueriesHelper#filters_options_for_select */
    public function filtersOptionsForSelect(AbstractQuery $query): string
    {
        $ungrouped = [];
        $grouped = ['label_string' => [], 'label_date' => [], 'label_time_tracking' => [], 'label_attachment' => []];
        foreach ($query->availableFilters() as $field => $f) {
            $group = null;
            if (preg_match('/^cf_\d+\./', $field)) {
                $group = '#'.($f->through ?? $f->customField)?->name;
            } elseif (preg_match('/^(.+)\./', $field, $m)) {
                $group = 'field_'.$m[1];
            } elseif ('relation' === $f->type) {
                $group = 'label_relations';
            } elseif ('tree' === $f->type) {
                $group = $query instanceof IssueQuery ? 'label_relations' : null;
            } elseif (\in_array($field, ['member_of_group', 'assigned_to_role'], true)) {
                $group = 'field_assigned_to';
            } elseif (\in_array($f->type, ['date_past', 'date'], true)) {
                $group = 'label_date';
            } elseif (\in_array($field, ['estimated_hours', 'spent_time'], true)) {
                $group = 'label_time_tracking';
            } elseif (\in_array($field, ['attachment', 'attachment_description'], true)) {
                $group = 'label_attachment';
            } elseif (\in_array($f->type, ['string', 'text', 'search'], true)) {
                $group = 'label_string';
            }
            if (null !== $group) {
                $grouped[$group][] = [$f->name, $field];
            } else {
                $ungrouped[] = [$f->name, $field];
            }
        }
        $grouped = array_filter($grouped, static fn ($v) => [] !== $v);
        if (1 === \count($grouped['label_date'] ?? [])) {
            $ungrouped[] = $grouped['label_date'][0];
            unset($grouped['label_date']);
        }
        $s = '<option value=""></option>';
        foreach ($ungrouped as [$name, $field]) {
            $s .= "\n".'<option value="'.self::h($field).'">'.self::h($name).'</option>';
        }
        foreach ($grouped as $key => $options) {
            $label = str_starts_with($key, '#') ? substr($key, 1) : $this->i18n->l($key);
            $s .= '<optgroup label="'.self::h($label).'">'.implode("\n", array_map(static fn ($o) => '<option value="'.self::h($o[1]).'">'.self::h($o[0]).'</option>', $options)).'</optgroup>';
        }

        return $s;
    }

    private static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="'.self::h($name).'" value="'.self::h($value).'" autocomplete="off" />';
    }

    public function queryFiltersHiddenTags(AbstractQuery $query): string
    {
        $tags = '';
        foreach ($query->getFilters() as $field => $f) {
            $tags .= self::hidden('f[]', $field).self::hidden("op[$field]", $f['operator']);
            foreach ($f['values'] as $v) {
                $tags .= self::hidden("v[$field][]", $v);
            }
        }

        return $tags;
    }

    public function queryColumnsHiddenTags(AbstractQuery $query): string
    {
        return implode('', array_map(static fn (QueryColumn $c) => self::hidden('c[]', $c->name), $query->columns()));
    }

    /** QueriesHelper#query_as_hidden_field_tags */
    public function queryAsHiddenFieldTags(AbstractQuery $query): string
    {
        $tags = self::hidden('set_filter', '1');
        if ([] !== $query->getFilters()) {
            $tags .= $this->queryFiltersHiddenTags($query);
        } else {
            $tags .= self::hidden('f[]', '');
        }
        $tags .= $this->queryColumnsHiddenTags($query);
        foreach ($query->totalableNames() as $n) {
            $tags .= self::hidden('t[]', $n);
        }
        if ($query->getGroupBy()) {
            $tags .= self::hidden('group_by', (string) $query->getGroupBy());
        }
        if (!$query->sortCriteria()->isEmpty()) {
            $tags .= self::hidden('sort', $query->sortCriteria()->toParam());
        }

        return $tags;
    }

    /** QueriesHelper#render_sidebar_queries */
    public function renderSidebarQueries(?AbstractQuery $current, ?Project $project, string $type = 'IssueQuery', ?string $listRoute = null): string
    {
        $queries = $this->factory->visibleQueries($type, $project);
        $private = array_values(array_filter($queries, static fn ($q) => 0 === (int) $q->visibility));
        $public = array_values(array_filter($queries, static fn ($q) => 0 !== (int) $q->visibility));

        return $this->queryLinks($this->i18n->l('label_my_queries'), $private, $current, $project, $type, $listRoute)
            .$this->queryLinks($this->i18n->l('label_query_plural'), $public, $current, $project, $type, $listRoute);
    }

    private function queryLinks(string $title, array $queries, ?AbstractQuery $current, ?Project $project, string $type, ?string $listRoute): string
    {
        if ([] === $queries) {
            return '';
        }
        $default = 'IssueQuery' === $type ? $this->factory->defaultIssueQuery($project) : null;
        $request = $this->requests->getCurrentRequest();
        $route = $listRoute;
        if (null === $route) {
            $currentRoute = (string) $request?->attributes->get('_route');
            $route = \in_array($currentRoute, ['calendar', 'project_calendar', 'gantt', 'project_gantt'], true) ? $currentRoute : (null !== $project ? 'project_issues' : 'issues_index');
        }
        $base = static function (array $params) use ($project, $route): array {
            return (null !== $project && str_starts_with($route, 'project_') ? ['project_id' => $project->identifier] : []) + $params;
        };
        $items = [];
        foreach ($queries as $q) {
            $css = 'query';
            $clear = '';
            $clearParams = ['set_filter' => 1, 'sort' => ''];
            if (null !== $default && $default->id === $q->id) {
                $css .= ' default';
                $clearParams['without_default'] = 1;
            }
            if (null !== $current && $current->record->id === $q->id) {
                $css .= ' selected';
                $clear = '<a class="icon-only icon-clear-query" title="'.self::h($this->i18n->l('button_clear')).'" href="'.self::h($this->urls->generate($route, $base($clearParams))).'">'.$this->app->spriteIcon('clear-query', $this->i18n->l('button_clear')).'</a>';
            }
            $url = $this->urls->generate($route, $base(['query_id' => $q->id]));
            $items[] = '<li><a class="'.$css.'"'.(null !== $q->description && '' !== $q->description ? ' title="'.self::h($q->description).'"' : '').' data-disable-with="'.self::h(htmlspecialchars($q->name)).'" href="'.self::h($url).'">'.self::h($q->name).'</a>'.$clear.'</li>';
        }

        return '<h3>'.self::h($title)."</h3>\n".'<ul class="queries">'.implode("\n", $items)."</ul>\n";
    }
}
