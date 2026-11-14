<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Journal;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Query\IssueQuery;
use App\Query\QueryFactory;
use App\Query\StatementInvalid;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * The content of the Atom feed of the issue changes (JournalsController#index): the last 25 journals of the
 * issues matching the query (query_id, filter parameters or the query kept in the session), subprojects
 * included.
 */
final class IssueChangesFeed
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * Variables of journals/index.atom.
     *
     * @return array{journals: list<Journal>, title: string}
     */
    public function view(Request $request, ?Project $project): array
    {
        /** @var IssueQuery $query */
        $query = $this->queries->retrieve($request, 'IssueQuery', $project);
        $journals = [];
        if ($query->isValid()) {
            try {
                $journals = $query->journals(25);
            } catch (StatementInvalid) {
                $journals = [];
            }
        }

        return [
            'journals' => $journals,
            'title' => ($project ? $project->name : $this->settings->appTitle()).': '.($query->isNew() ? $this->i18n->l('label_changes_details') : $query->name()),
        ];
    }
}
