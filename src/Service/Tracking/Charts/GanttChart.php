<?php

declare(strict_types=1);

namespace App\Service\Tracking\Charts;

use App\Entity\Project;
use App\Helper\Gantt;
use App\Pdf\PdfExport;
use App\Query\IssueQuery;
use App\Query\QueryContext;
use App\Query\QueryFactory;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\Settings;
use App\Service\VersionService;
use App\Twig\AppExtension;
use App\Twig\QueryExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Gantt chart of the issues of a query (GanttsController#show): the chart for the requested period and
 * zoom, which are remembered in the preferences of the user.
 */
final class GanttChart
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly QueryContext $ctx,
        private readonly AppExtension $app,
        private readonly QueryExtension $qx,
        private readonly VersionService $versions,
        private readonly PdfExport $pdfExport,
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Parameters: year, month, months, zoom, and those of the issue query.
     *
     * @return array{project: ?Project, query: IssueQuery, gantt: Gantt, query_valid: bool, can_save_queries: bool, gantt_months_limit: int, export_allowed: bool}
     */
    public function view(Request $request, ?Project $project): array
    {
        $user = $this->currentUser->get();
        $pref = $user->isLogged() ? $user->getPref() : null;
        $prefs = null !== $pref ? ['gantt_zoom' => $pref->get('gantt_zoom'), 'gantt_months' => $pref->get('gantt_months')] : [];

        $gantt = new Gantt($request->query->all(), $prefs, $this->ctx, $this->app, $this->qx, $this->versions);
        $gantt->project = $project;
        // Save gantt parameters as user preference (zoom and months count)
        if (null !== $pref && ((int) $pref->get('gantt_zoom') !== $gantt->zoom || (int) $pref->get('gantt_months') !== $gantt->months)) {
            $pref->set('gantt_zoom', $gantt->zoom);
            $pref->set('gantt_months', $gantt->months);
            if (null === $pref->id) {
                $this->em->persist($pref);
            }
            $this->em->flush();
        }

        /** @var IssueQuery $query */
        $query = $this->queries->retrieve($request, 'IssueQuery', $project, true, false);
        $query->setGroupBy(null);
        $valid = $query->isValid();
        if ($valid) {
            $gantt->query = $query;
        }

        return [
            'project' => $project,
            'query' => $query,
            'gantt' => $gantt,
            'query_valid' => $valid,
            'can_save_queries' => $this->auth->allowedTo('save_queries', $project, null, true),
            'gantt_months_limit' => $this->settings->int('gantt_months_limit'),
            'export_allowed' => $this->pdfExport->exportAllowed($valid ? $query : null, $project),
        ];
    }
}
