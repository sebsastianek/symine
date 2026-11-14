# Symine Kanban

Trello-like boards over the issues of one or several projects. A plugin: Symine works the same without it,
and nothing in the core schema changes.

## What it does

- **Boards over several projects.** A board belongs to a project (it shows in that project's *Kanban* tab)
  or sits above the projects (*Kanban* in the application menu). It shows the issues of the projects you
  select, with their subprojects if you want, limited to some trackers if you want.
- **Statuses mapped to columns, nothing lost.** Every issue status goes to a column or is *hidden on
  purpose*. A status that is neither — one you forgot, or one somebody adds to Redmine next year — still
  shows, in an automatic **Unmapped** column at the end of the board, with a notice naming it. Several
  statuses can share a column ("In review" = Resolved + Feedback).
- **Cards are issues.** Dragging a card to another column changes the status of the issue exactly as its
  edit form would: same permissions, same workflow, same validations, same journal entry, notifications
  and webhooks. Columns the workflow does not let *you* move *this* card to are dimmed while you drag,
  and the server refuses them anyway. When a column holds several statuses the card can take, you are
  asked which one.
- **Order, swimlanes, limits.** Cards keep the place you drop them at (per board). Swimlanes group cards
  by project, tracker, assignee, target version, priority, category or parent task; dropping a card in
  another assignee, version, priority or category lane changes the issue. Columns can have a
  work-in-progress limit and are flagged when over it.
- **Cards open in place.** A click on a card opens its dialog over the board (the address gets `#card-<id>`, so
  it can be linked to): the description, files with thumbnails, subtasks as a checklist with progress, and the
  activity of the issue. From there you can:
  - change status, assignee ("Assign to me"), priority, version, category, dates, % done and estimate — each
    field saves as soon as it changes, and the card behind moves or updates with it;
  - rename the issue and edit its description;
  - **comment** (privately when you may), with Ctrl/⌘+Enter to send;
  - add files (a picture becomes the cover of the card) and subtasks;
  - watch or unwatch.

  Every one of these is the same change the issue form would make, with the same checks; what the form would not
  let you change is not editable in the dialog either.
- **Badges and covers.** Cards show how many comments, files and subtasks (done / total) the issue has — counted
  only over what you may see — whether it has a description, and when it is due soon or overdue. The first
  picture attached to an issue can be shown as the cover of its card. Both are options of the board.
- **Add cards** from a column: subject, project and tracker; the rest takes Redmine's defaults.
- **Filters** (subject or #id, assignee, tracker, priority, version) live in the URL, so a filtered board
  can be bookmarked and shared.
- **Stays current.** The page checks for changes made by others and redraws the board (interval per
  board; 0 to turn it off).
- Closed issues leave the board after a number of days (per board; 0 keeps them for ever).

It follows whichever theme is in use, dark mode included.

## Install

The plugin is discovered from `plugins/symine-kanban/`. Create its tables, then enable it where you want it:

```
bin/console symine:plugins:migrate symine_kanban
```

1. *Project → Settings → Modules*: tick **Kanban**.
2. *Administration → Roles and permissions*: grant the Kanban permissions (below).
3. Open the **Kanban** tab of the project and create a board.

To remove it: `bin/console symine:plugins:migrate symine_kanban --target=0`, then delete the directory
(or list it in `SYMINE_PLUGINS_DISABLED`).

## Permissions

| Permission | Lets a member |
|---|---|
| View Kanban boards | open the boards of the project that are shared with them |
| Save Kanban boards | keep private boards (visible to them only) |
| Manage shared Kanban boards | share boards with everybody or with some roles, and change the shared ones |

They work like Redmine's saved queries: a board is *private*, visible *to these roles only*, or visible
*to any users*. Boards above the projects use the same permissions, held in any project.

What is on a card is never decided by the board: a user only sees the issues Redmine lets them see
(private issues, roles, tracker permissions), can only move the cards the workflow lets them move, and
can only add cards where they may add issues.

## Settings of a board

*Configure* on a board page:

- **Name, description, visibility.**
- **What the board shows:** projects (none selected: the project of the board, or every project you can
  see for a board above the projects), subprojects, trackers.
- **Columns and statuses:** add, rename, reorder (drag the handle) and delete columns, give them a limit,
  and choose for every issue status the column it is *shown in*, *Hidden*, or leave it unmapped.
- **Cards:** swimlanes, order (by hand, priority, due date, last updated), colour (priority, tracker,
  project), the fields shown on a card, how long closed issues stay, how many cards a column shows before
  *Show more*, and how often the page checks for changes.

*Administration → Plugins → Symine Kanban → Configure* sets the defaults of new boards.

## How it is built

```
src/Entity/        Board, BoardColumn, CardPosition (tables kanban_boards, kanban_columns, kanban_card_positions)
src/Board/         BoardAccess (who sees and changes boards), BoardScope (projects, trackers, statuses of a board),
                   BoardFinder, BoardFactory (templates, copies), BoardForm (settings), BoardView (what a page shows)
src/Card/          CardQuery (issues of a board), CardRules (where the workflow lets a card go), CardMover,
                   CardCreator, CardPositions (manual order), CardFilter, CardDetails (the dialog), CardEditor
                   (fields, comments, files, subtasks, watching), CardBadges (counts and covers)
src/Http/          BoardValueResolver: loads the board of a route and checks that the user may see it;
                   CardResponder: the JSON the board script gets after a change
src/Controller/    BoardsController, CardsController: thin, they only choose the response
assets/            kanban.js (drag and drop with jQuery UI sortable, bundled with Redmine), kanban.css
```

Issues are only ever changed through `App\Service\IssueService` (`setSafeAttributes()` and `save()`), which
is what makes a move indistinguishable from an edit.

Tests: `tests/Plugin/Kanban/` (PHPUnit) and `e2e/tests/plugins/kanban.spec.ts` (browser, real drag and drop).
