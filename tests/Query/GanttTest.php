<?php

declare(strict_types=1);

namespace App\Tests\Query;

use App\Entity\Project;
use App\Helper\Gantt;
use App\Query\QueryContext;
use App\Query\QueryFactory;
use App\Service\VersionService;
use App\Tests\Integration\SymineTestCase;
use App\Twig\AppExtension;
use App\Twig\QueryExtension;

/** Gantt chart rendering (port of Redmine::Helpers::Gantt) on the seeded data. */
final class GanttTest extends SymineTestCase
{
    private function gantt(string $login, ?string $projectIdentifier, array $params = [], array $queryParams = ['status_id' => '*']): Gantt
    {
        $this->actAs($this->user($login));
        $project = null === $projectIdentifier ? null : $this->em->getRepository(Project::class)->findOneBy(['identifier' => $projectIdentifier]);
        $gantt = new Gantt($params + ['year' => 2026, 'month' => 11, 'months' => 2, 'zoom' => 2], [],
            $this->service(QueryContext::class), $this->service(AppExtension::class), $this->service(QueryExtension::class), $this->service(VersionService::class));
        $gantt->project = $project;
        $query = $this->service(QueryFactory::class)->issueQuery($project);
        $query->buildFromParams($queryParams);
        $gantt->query = $query;

        return $gantt;
    }

    public function testDatesAndLayout(): void
    {
        $g = $this->gantt('admin', 'ecommerce');
        self::assertSame('2026-11-01', $g->dateFrom->format('Y-m-d'));
        self::assertSame('2026-12-31', $g->dateTo->format('Y-m-d'));
        self::assertSame(4, $g->zoomPx);
        self::assertSame(61 * 4, $g->chartWidth());
        self::assertSame(['year' => 2027, 'month' => 1, 'zoom' => 2, 'months' => 2], $g->paramsNext());
        self::assertSame(['year' => 2026, 'month' => 9, 'zoom' => 2, 'months' => 2], $g->paramsPrevious());
        self::assertCount(2, $g->monthPeriods());
        self::assertNull($g->weekPeriods()[0]['date'], 'leading partial week (Nov 1st 2026 is a sunday)');
    }

    public function testRowsTreeAndRelations(): void
    {
        $g = $this->gantt('admin', 'ecommerce');
        $subjects = $g->subjects();
        preg_match_all('/data-gantt-row-key="([^"]+)"/', $subjects, $m);
        // project, issues without version (sorted by start date / tree), version and its issues, subproject
        self::assertSame(['project-1', 'issue-19', 'issue-14', 'issue-15', 'issue-16', 'version-1-project-1', 'issue-13', 'project-3', 'issue-17'], $m[1]);
        self::assertSame(9, $g->numberOfRows());
        self::assertFalse($g->truncated);
        $lines = $g->lines();
        self::assertStringContainsString('data-gantt-issue-id="15" data-gantt-relations="{&quot;precedes&quot;:[16]}"', $lines);
        self::assertStringContainsString('data-gantt-issue-id="13" data-gantt-relations="{&quot;blocks&quot;:[19]}"', $lines);
        self::assertStringContainsString('data-gantt-parent-row-key="issue-14"', $subjects);
    }

    public function testCoordinates(): void
    {
        $g = $this->gantt('admin', 'ecommerce');
        // issue 15: 2026-11-07 .. 2026-11-17, 50% done (progress date 11-12.5)
        $day = static fn (string $d) => intdiv((new \DateTimeImmutable($d, new \DateTimeZone('UTC')))->getTimestamp(), 86400);
        $c = $g->coordinates($day('2026-11-07'), $day('2026-11-17'), 50.0);
        self::assertSame(24, $c['start']);
        self::assertSame(68, $c['end']);
        self::assertSame(46, $c['bar_progress_end']);
        // starts before the chart: no start marker, bar from 0
        $c = $g->coordinates($day('2026-10-08'), $day('2026-11-02'), null);
        self::assertArrayNotHasKey('start', $c);
        self::assertSame(0, $c['bar_start']);
        self::assertSame(8, $c['bar_end']);
    }

    public function testItemsLimitTruncates(): void
    {
        $this->service(\App\Service\Settings::class)->set('gantt_items_limit', '3');
        $this->em->flush();
        $g = $this->gantt('admin', 'ecommerce');
        $g->subjects();
        self::assertTrue($g->truncated);
        self::assertSame(3, $g->numberOfRows());
    }
}
