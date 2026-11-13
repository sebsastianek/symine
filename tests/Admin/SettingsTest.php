<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Service\Settings;

final class SettingsTest extends AdminWebTestCase
{
    use LoginWithModernHashTrait;

    protected function setUp(): void
    {
        ini_set('memory_limit', '512M');
        parent::setUp();
    }

    private function setting(string $name): mixed
    {
        $s = self::getContainer()->get(Settings::class);
        $s->clearCache();

        return $s->get($name);
    }

    public function testPagesForAdminAndNonAdmin(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/settings');
        $this->assertStatus(200);
        foreach (['general', 'display', 'authentication', 'integrations', 'projects', 'users', 'issues', 'timelog', 'attachments', 'notifications', 'mail_handler', 'repositories'] as $tab) {
            self::assertSame(1, $crawler->filter('#tab-'.$tab)->count(), $tab);
            self::assertSame(1, $crawler->filter('#tab-content-'.$tab.' form')->count(), $tab);
        }
        $crawler = $this->get('/settings?tab=issues');
        self::assertSame('selected', $crawler->filter('#tab-issues')->attr('class'));
        self::assertSame(1, $crawler->filter('select#selected_settings_issue_list_default_columns')->count());
        $this->loginAs('jsmith');
        $this->get('/settings');
        $this->assertStatus(403);
        $this->post('/settings/edit', ['settings' => ['app_title' => 'Hacked']]);
        $this->assertStatus(403);
    }

    public function testSaveGeneralAndCheckboxes(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/settings/edit', ['settings' => ['app_title' => 'My Symine', 'per_page_options' => '10,20', 'cache_formatted_text' => '1', 'reactions_enabled' => '0']]);
        self::assertSame('/settings', $r->headers->get('Location'));
        self::assertSame('My Symine', $this->setting('app_title'));
        self::assertSame('10,20', $this->setting('per_page_options'));
        self::assertSame('1', $this->setting('cache_formatted_text'));
        self::assertSame('0', $this->setting('reactions_enabled'));
        $r = $this->post('/settings/edit?tab=integrations', ['settings' => ['rest_api_enabled' => '0', 'jsonp_enabled' => '1', 'webhooks_enabled' => '1']]);
        self::assertSame('/settings?tab=integrations', $r->headers->get('Location'));
        self::assertFalse(self::getContainer()->get(Settings::class)->restApiEnabled());
        self::assertSame('1', $this->setting('webhooks_enabled'));
    }

    public function testArraysAndHashes(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/settings/edit?tab=projects', ['settings' => [
            'default_projects_modules' => ['', 'issue_tracking', 'wiki'],
            'default_projects_tracker_ids' => ['', '2'],
            'project_list_defaults' => ['column_names' => ['name', 'identifier']],
            'project_list_display_type' => 'list',
        ]]);
        self::assertTrue($r->isRedirect());
        self::assertSame(['issue_tracking', 'wiki'], $this->setting('default_projects_modules'));
        self::assertSame(['2'], $this->setting('default_projects_tracker_ids'));
        self::assertSame(['column_names' => ['name', 'identifier']], $this->setting('project_list_defaults'));
        self::assertSame('list', $this->setting('project_list_display_type'));

        $r = $this->post('/settings/edit?tab=repositories', ['settings' => [
            'enabled_scm' => ['', 'Git'],
            'commit_update_keywords' => ['if_tracker_id' => ['', '1'], 'keywords' => ['fixes, closes', ''], 'status_id' => ['5', '0'], 'done_ratio' => ['100', '']],
        ]]);
        self::assertTrue($r->isRedirect());
        self::assertSame(['Git'], $this->setting('enabled_scm'));
        self::assertSame([['status_id' => '5', 'done_ratio' => '100', 'keywords' => 'fixes, closes']], $this->setting('commit_update_keywords'));
        $crawler = $this->get('/settings?tab=repositories');
        self::assertSame('fixes, closes', $crawler->filter('input[name="settings[commit_update_keywords][keywords][]"]')->attr('value'));
    }

    public function testValidationErrors(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/settings/edit?tab=notifications', ['settings' => ['mail_from' => 'not an email']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('errorExplanation', (string) $r->getContent());
        self::assertNotSame('not an email', $this->setting('mail_from'));

        $r = $this->post('/settings/edit?tab=issues', ['settings' => ['default_issue_due_date_offset_enabled' => '1', 'default_issue_due_date_offset' => '-3']]);
        self::assertSame(422, $r->getStatusCode());
        $r = $this->post('/settings/edit?tab=issues', ['settings' => ['default_issue_due_date_offset_enabled' => '1', 'default_issue_due_date_offset' => '7']]);
        self::assertTrue($r->isRedirect());
        self::assertSame('7', $this->setting('default_issue_due_date_offset'));
        $r = $this->post('/settings/edit?tab=issues', ['settings' => ['default_issue_due_date_offset_enabled' => '0', 'default_issue_due_date_offset' => '7']]);
        self::assertTrue($r->isRedirect());
        self::assertSame('', (string) $this->setting('default_issue_due_date_offset'));

        $r = $this->post('/settings/edit?tab=mail_handler', ['settings' => ['mail_handler_enable_regex_delimiters' => '1', 'mail_handler_body_delimiters' => "valid\n(unclosed"]]);
        self::assertSame(422, $r->getStatusCode());
    }

    public function testUnknownSettingsAreIgnored(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/settings/edit', ['settings' => ['not_a_setting' => 'x', 'app_title' => 'T']]);
        self::assertTrue($r->isRedirect());
        self::assertFalse((bool) $this->em->getConnection()->fetchOne("SELECT 1 FROM settings WHERE name = 'not_a_setting'"));
    }
}
