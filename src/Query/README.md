# Query engine (port of Redmine's Query / IssueQuery)

Raw-SQL query engine using Redmine's table names, so conditions are ported 1:1 from
`app/models/query.rb` / `issue_query.rb`. Saved queries are `App\Entity\Query` records (STI on
`queries.type`); filters / column_names / sort_criteria / group_by / options keep Redmine's structure.

| Class | Role |
|---|---|
| `AbstractQuery` | Query: operators, filter types, `buildFromParams()` (f[]/op[]/v[], short filters, c[], t[], sort, group_by), validation, `statement()` (WHERE), `sqlForField()` for every operator, date clauses, custom field filters (incl. `assoc.cf_x`, `cf_x.cf_y`, `cf_x.due_date/status`), columns/sorting/grouping helpers, saved query visibility/editability |
| `IssueQuery` | IssueQuery: filters, columns, specific `sql_for_*` statements, `issueCount()`, `issueIds()`, `issues()`, `columnValue()`, `resultCountByGroup()`, totals, `versions()`, `journals()` |
| `QueryColumn`, `QueryFilter`, `SortCriteria`, `Tokenizer` | value objects (QueryColumn kinds: standard, timestamp, watcher, association, custom_field) |
| `QueryContext` | shared services + SQL helpers: `issueVisibleSql()` (IssueService::visibleCondition converted from DQL), `timeEntryVisibleSql()`, `journalVisibleNotesSql()`, `like()`, `timestampToDate()`, platform booleans |
| `QueryFactory` | `create($type, $project)`, `wrap($record, $project)`, `retrieve($request, $type, $project, $useSession, $applyDefault)` (= retrieve_query + retrieve_default_query, session key `issue_query`), `visibleQueries()`, `defaultIssueQuery()` |

Twig helpers (`src/Twig/QueryExtension.php`): `query_column_header`, `query_column_content`,
`render_query_totals`, `filters_options_for_select`, `query_as_hidden_field_tags`, `render_sidebar_queries`,
`format_object`, ... Templates: `templates/queries/_query_form.html.twig`, `_filters.html.twig`,
`_columns.html.twig`, `_form.html.twig` (saved query form, generic on `query.type()`).

## Adding another query type (TimeEntryQuery, ProjectQuery, UserQuery)

1. `final class TimeEntryQuery extends AbstractQuery` (mark it `#[Exclude]`), implement:
   `queriedTable()` (`time_entries`), `queriedClass()` (`TimeEntry`), `viewPermission()` (`view_time_entries`),
   `initializeAvailableFilters()` (use `addAvailableFilter($field, $type, $values|Closure, $name, $label)`,
   `addCustomFieldsFilters()`, `addAssociationsCustomFieldsFilters()`), `buildAvailableColumns()` (list of
   `QueryColumn` with SQL `sortable` expressions / `groupStatement`), `baseFromSql()` (FROM + joins, must join
   `projects`), `baseConditionSql()` (e.g. `$ctx->timeEntryVisibleSql()`), and optionally `defaultColumnsNames()`,
   `defaultSortCriteria()`, `defaultTotalableNames()`, `sqlForSpecificField()` (sql_for_<field>_field hooks),
   `joinsForOrderStatement()`, `associationCustomizedClass()`.
2. Name it `App\Query\TimeEntryQuery` (or `ProjectQuery`, `UserQuery`, `ProjectAdminQuery`): `QueryFactory`
   registers such classes automatically (otherwise call `$queryFactory->register($type, $recordClass, $engineClass)`).
   Then `QueriesController` (/queries/new?type=TimeEntryQuery, edit, update, destroy, /queries/filter) works
   for it and redirects to `project_time_entries` / `time_entries_index`.
3. Execute with the protected `fetch($select, $joins, $groupBy, $order, $limit, $offset, $extraWhere)` helper
   and `count()`; `whereSql()` gives visibility + filters + project scope.

The project scope (`projectStatement()`) honours `subproject_id` and Setting `display_subprojects_issues`.
"me" / "mine" / "bookmarks" values are substituted in `statement()`.
