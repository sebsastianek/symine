<?php

declare(strict_types=1);

namespace App\Tests\Query;

use App\Query\QueryFactory;
use App\Tests\Integration\SymineTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Filters / operators / sorting / grouping checked against the reference Redmine:
 * redmine-issue-query-expectations.json holds the issue ids returned by the reference
 * /issues.json API for each user and query string (non date-relative filters only).
 */
final class IssueQueryParityTest extends SymineTestCase
{
    public static function cases(): iterable
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/redmine-issue-query-expectations.json'), true);
        foreach ($data as $login => $queries) {
            foreach ($queries as $q => $ids) {
                yield "$login: $q" => [$login, $q, $ids];
            }
        }
    }

    #[DataProvider('cases')]
    public function testSameResultsAsRedmine(string $login, string $queryString, array $expected): void
    {
        $user = 'anonymous' === $login ? $this->service(\App\Security\CurrentUser::class)->anonymous() : $this->user($login);
        $this->actAs($user);
        parse_str($queryString, $params);
        $project = null;
        if (isset($params['project_id'])) {
            // IssuesController#find_optional_project: /issues.json?project_id=x lists the project's issues
            $project = $this->em->find(\App\Entity\Project::class, (int) $params['project_id']);
            unset($params['project_id']);
        }
        $query = $this->service(QueryFactory::class)->issueQuery($project);
        $query->buildFromParams($params);
        self::assertTrue($query->isValid(), implode(', ', $query->errors));
        self::assertSame($expected, $query->issueIds(0, 100), $queryString.' / '.$query->statement());
        self::assertSame(\count($expected), $query->issueCount());
    }
}
