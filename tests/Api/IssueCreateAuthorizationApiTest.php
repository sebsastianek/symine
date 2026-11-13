<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Authorization of POST /issues.json and /projects/{id}/issues.json, like Redmine's IssuesController
 * (find_optional_project + authorize_global, then build_new_issue_from_params):
 * - project route without add_issues/copy_issues on that project => 403;
 * - global route for a user without add_issues on any project => 403;
 * - global route for a user with add_issues somewhere, but a missing or unusable project_id => 422
 *   (verified on the reference Redmine: "Project cannot be blank", "Tracker cannot be blank", "Status cannot be blank");
 * - anonymous users without the permission => 401.
 */
final class IssueCreateAuthorizationApiTest extends ApiTestCase
{
    private const array BLANK = ['Project cannot be blank', 'Tracker cannot be blank', 'Status cannot be blank'];

    public function testProjectRouteWithoutAddIssuesIs403(): void
    {
        // rhill is Reporter of ecommerce only: internal is private, checkout gives him the Non member role
        $this->json('POST', '/projects/internal/issues.json', ['issue' => ['tracker_id' => 1, 'subject' => 'x']], 'rhill');
        $this->assertStatus(403);
        $this->conn()->executeStatement("UPDATE roles SET permissions = REPLACE(permissions, '\"add_issues\",', '') WHERE id IN (1, 5)");
        $this->json('POST', '/projects/ecommerce/issues.json', ['issue' => ['tracker_id' => 1, 'subject' => 'x']], 'rhill');
        $this->assertStatus(403);
        $this->request('POST', '/projects/ecommerce/issues.xml', '<issue><tracker_id>1</tracker_id><subject>x</subject></issue>', 'rhill');
        $this->assertStatus(403);
        self::assertSame(0, $this->rows('issues', "subject = 'x'"));
    }

    public function testGlobalRouteWithoutAddIssuesAnywhereIs403(): void
    {
        $this->conn()->executeStatement("UPDATE roles SET permissions = REPLACE(permissions, '\"add_issues\",', '') WHERE id IN (1, 5)");
        $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'x']], 'rhill');
        $this->assertStatus(403);
        $this->json('POST', '/issues.json', ['issue' => ['subject' => 'x']], 'rhill');
        $this->assertStatus(403);
        $this->json('POST', '/issues.json', ['issue' => ['project_id' => 2, 'subject' => 'x']], 'anna');
        $this->assertStatus(403);
        self::assertSame(0, $this->rows('issues', "subject = 'x'"));
    }

    public function testGlobalRouteWithUnusableProjectIs422(): void
    {
        // rhill may add issues to ecommerce, not to internal (private) nor to an unknown project
        foreach ([['project_id' => 2], ['project_id' => 999], []] as $project) {
            $data = $this->json('POST', '/issues.json', ['issue' => $project + ['tracker_id' => 1, 'subject' => 'x']], 'rhill');
            $this->assertErrors($data, ...self::BLANK);
            self::assertSame(self::BLANK, $data['errors']);
        }
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 'ecommerce', 'tracker_id' => 1, 'subject' => 'x']], 'rhill');
        $this->assertStatus(201);
        self::assertSame(1, $data['issue']['project']['id']);
    }

    public function testAnonymousWithoutPermissionIs401(): void
    {
        $this->request('POST', '/projects/ecommerce/issues.json', ['issue' => ['tracker_id' => 1, 'subject' => 'x']], null);
        $this->assertStatus(401);
        $this->request('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => 'x']], null);
        $this->assertStatus(401);
    }

    public function testAnonymousWithAddIssuesCanCreate(): void
    {
        $this->conn()->executeStatement("UPDATE roles SET permissions = REPLACE(permissions, '\"view_issues\",', '\"view_issues\",\"add_issues\",') WHERE id = 2");
        $data = $this->json('POST', '/projects/ecommerce/issues.json', ['issue' => ['tracker_id' => 1, 'subject' => 'anonymous issue']], null);
        $this->assertStatus(201);
        self::assertSame(4, $data['issue']['author']['id']);
    }
}
