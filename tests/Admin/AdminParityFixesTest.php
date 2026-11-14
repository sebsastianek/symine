<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\CustomField;
use App\Entity\Issue;
use App\I18n\Localizer;
use App\Service\Settings;

/**
 * Behaviours of custom field formats and administration settings that differed from Redmine
 * (reference: lib/redmine/field_format.rb, test/unit/lib/redmine/field_format/*_test.rb,
 * test/unit/custom_field_test.rb, test/unit/custom_field_user_format_test.rb,
 * test/unit/custom_field_version_format_test.rb).
 */
final class AdminParityFixesTest extends AdminWebTestCase
{
    private const array ALL_TRACKERS = ['tracker_ids' => ['1', '2', '3'], 'is_for_all' => '1', 'visible' => '1'];

    /** Creates a custom field through the administration form and returns it */
    private function createField(string $type, string $format, string $name, array $attrs = []): CustomField
    {
        $attrs += ['field_format' => $format, 'name' => $name, 'visible' => '1'];
        if ('IssueCustomField' === $type) {
            $attrs += self::ALL_TRACKERS;
        }
        $this->post('/custom_fields', ['type' => $type, 'custom_field' => $attrs]);
        $this->assertStatus(302, 'custom field '.$name);
        $field = $this->em->getRepository(CustomField::class)->findOneBy(['name' => $name]);
        self::assertNotNull($field);

        return $field;
    }

    /** @return int id of the created issue */
    private function createIssue(array $customValues, string $project = 'ecommerce'): int
    {
        $this->post('/projects/'.$project.'/issues', ['issue' => ['tracker_id' => '1', 'subject' => 'Parity', 'status_id' => '1', 'priority_id' => '2', 'custom_field_values' => $customValues]]);
        $this->assertStatus(302);
        self::assertMatchesRegularExpression('#/issues/(\d+)$#', (string) $this->client->getResponse()->headers->get('Location'));

        return (int) basename((string) $this->client->getResponse()->headers->get('Location'));
    }

    /** @return list<string> error messages of the last response */
    private function errors(): array
    {
        return $this->client->getCrawler()->filter('#errorExplanation li')->each(static fn ($li) => trim($li->text()));
    }

    // --- finding 2: date fields with a default offset ----------------------------------------

    public function testDateFieldWithOffsetDefaultIsPrefilledWithTodayPlusOffset(): void
    {
        $this->loginAs('admin');
        $field = $this->createField('IssueCustomField', 'date', 'DueOffset', ['default_value_mode' => 'date_offset', 'default_value' => '7']);
        $fixed = $this->createField('IssueCustomField', 'date', 'FixedDate', ['default_value_mode' => 'fixed_date', 'default_value' => '2030-01-02']);

        $crawler = $this->get('/projects/ecommerce/issues/new');
        $this->assertStatus(200);
        $expected = self::getContainer()->get(Localizer::class)->today()->modify('+7 days')->format('Y-m-d');
        self::assertSame($expected, $crawler->filter('#issue_custom_field_values_'.$field->id)->attr('value'));
        self::assertSame('2030-01-02', $crawler->filter('#issue_custom_field_values_'.$fixed->id)->attr('value'));

        // the default is stored when the issue is created without the field
        $id = $this->createIssue([]);
        self::assertSame($expected, $this->em->getConnection()->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = ?", [$id, $field->id]));
    }

    // --- finding 3 / 14: the same widgets on every form ---------------------------------------

    public function testVersionFormUsesSelectsForKeyValueUserAndVersionFields(): void
    {
        $this->loginAs('admin');
        $user = $this->createField('VersionCustomField', 'user', 'VersionOwner');
        $version = $this->createField('VersionCustomField', 'version', 'VersionRef');
        $enum = $this->createField('VersionCustomField', 'enumeration', 'VersionKind');
        $this->post('/custom_fields/'.$enum->id.'/enumerations', ['custom_field_enumeration' => ['name' => 'Major']]);
        $enumId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM custom_field_enumerations WHERE custom_field_id = ?', [$enum->id]);

        $crawler = $this->get('/projects/ecommerce/versions/new');
        $this->assertStatus(200);
        self::assertSame('select', $crawler->filter('#version_custom_field_values_'.$user->id)->nodeName());
        self::assertContains('John Smith', $crawler->filter('#version_custom_field_values_'.$user->id.' option')->each(static fn ($o) => $o->text()));
        self::assertSame('select', $crawler->filter('#version_custom_field_values_'.$version->id)->nodeName());
        self::assertContains('1.0', $crawler->filter('#version_custom_field_values_'.$version->id.' option')->each(static fn ($o) => $o->text()));
        self::assertSame(['', (string) $enumId], $crawler->filter('#version_custom_field_values_'.$enum->id.' option')->each(static fn ($o) => (string) $o->attr('value')));

        $jsmith = $this->user('jsmith')->id;
        $this->post('/projects/ecommerce/versions', ['version' => ['name' => '2.0', 'custom_field_values' => [$user->id => (string) $jsmith, $enum->id => (string) $enumId]]]);
        $this->assertStatus(302);
        $versionId = (int) $this->em->getConnection()->fetchOne("SELECT id FROM versions WHERE name = '2.0'");
        self::assertSame((string) $jsmith, $this->em->getConnection()->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Version' AND customized_id = ? AND custom_field_id = ?", [$versionId, $user->id]));
        self::assertSame((string) $enumId, $this->em->getConnection()->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Version' AND customized_id = ? AND custom_field_id = ?", [$versionId, $enum->id]));
    }

    public function testCheckBoxStyleIsHonouredOnProjectTimeEntryVersionAndDocumentForms(): void
    {
        $this->loginAs('admin');
        $forms = [
            'Project' => ['/projects/ecommerce/settings', 'project'],
            'TimeEntry' => ['/projects/ecommerce/time_entries/new', 'time_entry'],
            'Version' => ['/projects/ecommerce/versions/new', 'version'],
            'Document' => ['/projects/ecommerce/documents/new', 'document'],
        ];
        $fields = [];
        foreach (array_keys($forms) as $class) {
            $fields[$class] = [
                $this->createField($class.'CustomField', 'list', $class.'Boxes', ['possible_values' => "one\ntwo", 'multiple' => '1', 'edit_tag_style' => 'check_box']),
                $this->createField($class.'CustomField', 'bool', $class.'Flag', ['edit_tag_style' => 'radio']),
                $this->createField($class.'CustomField', 'date', $class.'Day'),
            ];
        }
        foreach ($forms as $class => [$url, $object]) {
            [$list, $bool, $date] = $fields[$class];
            $crawler = $this->get($url);
            $this->assertStatus(200, $url);
            $name = $object.'[custom_field_values]['.$list->id.'][]';
            self::assertSame(['one', 'two'], $crawler->filter('input[type=checkbox][name="'.$name.'"]')->each(static fn ($i) => (string) $i->attr('value')), $url);
            self::assertCount(1, $crawler->filter('input[type=hidden][name="'.$name.'"][value=""]'), $url.' (blank value to clear the field)');
            self::assertCount(0, $crawler->filter('select[name="'.$name.'"]'), $url);
            self::assertSame(['', '1', '0'], $crawler->filter('input[type=radio][name="'.$object.'[custom_field_values]['.$bool->id.']"]')->each(static fn ($i) => (string) $i->attr('value')), $url);
            self::assertSame('date', $crawler->filter('#'.$object.'_custom_field_values_'.$date->id)->attr('type'), $url);
        }
    }

    // --- finding 9: regexp and length of numbers and dates ------------------------------------

    public function testRegexpAndLengthAreValidatedForIntFloatAndDateFields(): void
    {
        $this->loginAs('admin');
        $int = $this->createField('IssueCustomField', 'int', 'Num', ['min_length' => '3', 'max_length' => '5', 'regexp' => '^1']);
        $float = $this->createField('IssueCustomField', 'float', 'Ratio', ['max_length' => '4']);
        $date = $this->createField('IssueCustomField', 'date', 'Day', ['regexp' => '^2030']);
        $issue = ['tracker_id' => '1', 'subject' => 'Validation', 'status_id' => '1', 'priority_id' => '2'];

        $this->post('/projects/ecommerce/issues', ['issue' => $issue + ['custom_field_values' => [$int->id => '99999999', $float->id => '12.345', $date->id => '2026-01-01']]]);
        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        $errors = $this->errors();
        self::assertContains('Num is invalid', $errors);
        self::assertContains('Num is too long (maximum is 5 characters)', $errors);
        self::assertContains('Ratio is too long (maximum is 4 characters)', $errors);
        self::assertContains('Day is invalid', $errors);

        $this->post('/projects/ecommerce/issues', ['issue' => $issue + ['custom_field_values' => [$int->id => '12', $float->id => 'abc', $date->id => '2030-99-99']]]);
        $errors = $this->errors();
        self::assertContains('Num is too short (minimum is 3 characters)', $errors);
        self::assertContains('Ratio is invalid', $errors);
        self::assertContains('Day is not a valid date', $errors);

        $this->createIssue([$int->id => '12345', $float->id => '1.25', $date->id => '2030-05-06']);
    }

    // --- finding 6 / 16: URL patterns and thousands delimiter ----------------------------------

    public function testUrlPatternIsAppliedToEveryFormatWithAllTokens(): void
    {
        $this->loginAs('admin');
        $int = $this->createField('IssueCustomField', 'int', 'Ticket', ['url_pattern' => 'http://ex.test/%value%/%id%/%project_id%/%project_identifier%']);
        $list = $this->createField('IssueCustomField', 'list', 'Kinds', ['possible_values' => "bb\naa cc", 'multiple' => '1', 'url_pattern' => 'http://ex.test/l/%value%']);
        $link = $this->createField('IssueCustomField', 'link', 'Ref', ['regexp' => '^([A-Z]+)-(\d+)$', 'url_pattern' => 'http://ex.test/%m1%/%m2%?p=%project_id%']);
        $string = $this->createField('IssueCustomField', 'string', 'Code', ['url_pattern' => 'http://ex.test/s/%value%', 'text_formatting' => 'full']);
        $plainLink = $this->createField('IssueCustomField', 'link', 'Site');
        $bool = $this->createField('IssueCustomField', 'bool', 'Flag', ['url_pattern' => 'http://ex.test/b/%value%']);

        $id = $this->createIssue([$int->id => '42', $list->id => ['bb', 'aa cc'], $link->id => 'ABC-12', $string->id => 'x&y', $plainLink->id => 'example.org/page', $bool->id => '1']);
        $crawler = $this->get('/issues/'.$id);
        $this->assertStatus(200);
        $value = static fn (CustomField $f) => $crawler->filter('.cf_'.$f->id.' .value');

        self::assertSame('http://ex.test/42/'.$id.'/1/ecommerce', $value($int)->filter('a')->attr('href'));
        self::assertSame('42', $value($int)->filter('a')->text());
        // one link per value, sorted by text, values are URL-encoded
        self::assertSame(['http://ex.test/l/aa%20cc', 'http://ex.test/l/bb'], $value($list)->filter('a')->each(static fn ($a) => (string) $a->attr('href')));
        self::assertSame('http://ex.test/ABC/12?p=1', $value($link)->filter('a')->attr('href'));
        self::assertSame('ABC-12', $value($link)->filter('a')->text());
        // the URL pattern of a text field takes precedence over the text formatting
        self::assertSame('http://ex.test/s/x&y', $value($string)->filter('a')->attr('href'));
        self::assertSame('http://example.org/page', $value($plainLink)->filter('a.external')->attr('href'));
        self::assertSame('http://ex.test/b/true', $value($bool)->filter('a')->attr('href'));
        self::assertSame('Yes', $value($bool)->filter('a')->text());
    }

    public function testUrlPatternOnUserProfile(): void
    {
        $this->loginAs('admin');
        $field = $this->createField('UserCustomField', 'string', 'Badge', ['url_pattern' => 'http://ex.test/badge/%value%?u=%id%']);
        $jsmith = $this->user('jsmith');
        $this->post('/users/'.$jsmith->id, ['user' => ['custom_field_values' => [$field->id => 'B7']]], 'PUT');
        $this->assertStatus(302);
        $crawler = $this->get('/users/'.$jsmith->id);
        self::assertSame('http://ex.test/badge/B7?u='.$jsmith->id, $crawler->filter('li.cf_'.$field->id.' a')->attr('href'));
    }

    public function testThousandsDelimiterOnTheIssuePage(): void
    {
        $this->loginAs('admin');
        $int = $this->createField('IssueCustomField', 'int', 'Units', ['thousands_delimiter' => '1']);
        $float = $this->createField('IssueCustomField', 'float', 'Amount', ['thousands_delimiter' => '1']);
        $plain = $this->createField('IssueCustomField', 'int', 'Raw');
        $id = $this->createIssue([$int->id => '99999999', $float->id => '1234567.5', $plain->id => '1234567']);
        $crawler = $this->get('/issues/'.$id);
        self::assertSame('99,999,999', trim($crawler->filter('.cf_'.$int->id.' .value')->text()));
        self::assertSame('1,234,567.50', trim($crawler->filter('.cf_'.$float->id.' .value')->text()));
        self::assertSame('1234567', trim($crawler->filter('.cf_'.$plain->id.' .value')->text()));
    }

    // --- finding 7: user fields ---------------------------------------------------------------

    public function testUserFieldOffersGroupsAndMe(): void
    {
        $this->loginAs('admin');
        $both = $this->createField('IssueCustomField', 'user', 'Reviewer', ['possible_principals' => 'user_group', 'user_role' => ['']]);
        $groups = $this->createField('IssueCustomField', 'user', 'Team', ['possible_principals' => 'group']);
        $multi = $this->createField('IssueCustomField', 'user', 'Reviewers', ['multiple' => '1']);
        $managers = $this->createField('IssueCustomField', 'user', 'Lead', ['user_role' => ['3']]);
        $jsmith = (string) $this->user('jsmith')->id;

        $this->loginAs('jsmith');
        $crawler = $this->get('/projects/ecommerce/issues/new');
        $this->assertStatus(200);
        $select = $crawler->filter('#issue_custom_field_values_'.$both->id);
        // blank, "<< me >>", then users and groups in option groups
        self::assertSame(['', $jsmith], \array_slice($select->filter('option')->each(static fn ($o) => (string) $o->attr('value')), 0, 2));
        self::assertSame('<< me >>', $select->filter('option')->eq(1)->text());
        self::assertSame(['Users', 'Groups'], $select->filter('optgroup')->each(static fn ($g) => (string) $g->attr('label')));
        self::assertSame(['Dave Lopper', 'John Smith', 'Robert Hill'], $select->filter('optgroup[label=Users] option')->each(static fn ($o) => $o->text()));
        self::assertSame(['Developers'], $select->filter('optgroup[label=Groups] option')->each(static fn ($o) => $o->text()));

        // groups only: no "<< me >>", no option group
        $select = $crawler->filter('#issue_custom_field_values_'.$groups->id);
        self::assertSame(['Developers'], array_values(array_filter($select->filter('option')->each(static fn ($o) => trim($o->text(), " \u{a0}")))));
        self::assertCount(0, $select->filter('optgroup'));

        // multiple values: users only, no "<< me >>"
        self::assertSame(['Dave Lopper', 'John Smith', 'Robert Hill'], $crawler->filter('#issue_custom_field_values_'.$multi->id.' option')->each(static fn ($o) => $o->text()));

        // role restriction: jsmith is the only Manager of the project
        self::assertSame(['<< me >>', 'John Smith'], array_values(array_filter($crawler->filter('#issue_custom_field_values_'.$managers->id.' option')->each(static fn ($o) => trim($o->text(), " \u{a0}")))));

        // a group can be selected and is shown on the issue
        $this->loginAs('admin');
        $id = $this->createIssue([$both->id => '10']);
        self::assertSame('Developers', trim($this->get('/issues/'.$id)->filter('.cf_'.$both->id.' .value')->text()));
        // the current value stays selectable although it is not a possible value any more (possible_custom_value_options)
        $this->em->getConnection()->executeStatement("UPDATE custom_values SET value = ? WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = ?", [(string) $this->user('anna')->id, $id, $both->id]);
        $option = $this->get('/issues/'.$id.'/edit')->filter('#issue_custom_field_values_'.$both->id.' option[selected]');
        self::assertSame((string) $this->user('anna')->id, $option->attr('value'));
    }

    // --- finding 8: version fields ------------------------------------------------------------

    public function testVersionFieldOffersSharedVersions(): void
    {
        $this->loginAs('admin');
        $any = $this->createField('IssueCustomField', 'version', 'FoundIn', ['version_status' => ['']]);
        $closed = $this->createField('IssueCustomField', 'version', 'ClosedIn', ['version_status' => ['closed']]);
        $options = fn (string $project, CustomField $f) => array_values(array_filter($this->get('/projects/'.$project.'/issues/new')->filter('#issue_custom_field_values_'.$f->id.' option')->each(static fn ($o) => trim($o->text(), " \u{a0}"))));

        // 1.0 of eCommerce is shared with its descendants, 0.9 is not shared
        self::assertSame(['1.0'], $options('checkout', $any));
        self::assertSame([], $options('checkout', $closed));
        self::assertEqualsCanonicalizing(['0.9', '1.0'], $options('ecommerce', $any));
        self::assertSame(['0.9'], $options('ecommerce', $closed));
        // not shared with unrelated projects
        self::assertSame([], $options('internal', $any));

        $id = $this->createIssue([$any->id => '1'], 'checkout');
        self::assertSame('1', $this->em->getConnection()->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = ?", [$id, $any->id]));
    }

    // --- finding 15: progress bar -------------------------------------------------------------

    public function testProgressBarFieldIsEditedWithASelect(): void
    {
        $this->loginAs('admin');
        $field = $this->createField('IssueCustomField', 'progressbar', 'Progress', ['ratio_interval' => '25']);
        $crawler = $this->get('/projects/ecommerce/issues/new');
        $select = $crawler->filter('select#issue_custom_field_values_'.$field->id);
        self::assertCount(1, $select);
        self::assertSame(['0', '25', '50', '75', '100'], $select->filter('option')->each(static fn ($o) => (string) $o->attr('value')));
        self::assertSame('25 %', $select->filter('option')->eq(1)->text());

        $id = $this->createIssue([$field->id => '75']);
        self::assertSame('75', $this->get('/issues/'.$id.'/edit')->filter('select#issue_custom_field_values_'.$field->id.' option[selected]')->attr('value'));

        // bulk edit: "no change" + the same steps
        $crawler = $this->get('/issues/bulk_edit?ids[]='.$id);
        $this->assertStatus(200);
        self::assertSame(['', '0', '25', '50', '75', '100'], $crawler->filter('select#issue_custom_field_values_'.$field->id.' option')->each(static fn ($o) => (string) $o->attr('value')));
    }

    // --- finding 10: user_format --------------------------------------------------------------

    public function testUserFormatSettingAppliesToSelectsFiltersCsvAndApi(): void
    {
        $this->loginAs('admin');
        $field = $this->createField('IssueCustomField', 'user', 'Reviewer');
        self::getContainer()->get(Settings::class)->set('user_format', 'lastname_comma_firstname');
        $this->em->flush();

        $crawler = $this->get('/projects/ecommerce/issues/new');
        $this->assertStatus(200);
        $assignees = $crawler->filter('#issue_assigned_to_id optgroup option, #issue_assigned_to_id > option')->each(static fn ($o) => $o->text());
        self::assertContains('Smith, John', $assignees);
        self::assertNotContains('John Smith', $assignees);
        // custom field options are sorted by the fields of the format (lastname first)
        self::assertSame(['Hill, Robert', 'Lopper, Dave', 'Smith, John'], array_values(array_filter($crawler->filter('#issue_custom_field_values_'.$field->id.' option')->each(static fn ($o) => trim($o->text(), " \u{a0}")))));

        $this->get('/queries/filter?type=IssueQuery&name=author_id');
        $this->assertStatus(200);
        self::assertStringContainsString('Smith, John', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('John Smith', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/issues.csv?set_filter=1&status_id=*&c[]=author');
        $this->assertStatus(200);
        self::assertStringContainsString('"Smith, John"', (string) $this->client->getInternalResponse()->getContent());

        $issue = $this->em->getRepository(Issue::class)->findOneBy(['author' => $this->user('jsmith')]);
        self::assertNotNull($issue);
        // (loginAs() replaced the legacy hash of admin by a hash of "password123")
        $this->api('GET', '/issues/'.$issue->id.'.json', null, 'admin', 'password123');
        $this->assertStatus(200);
        self::assertSame('Smith, John', json_decode((string) $this->client->getResponse()->getContent(), true)['issue']['author']['name']);

        // back to the default format
        self::getContainer()->get(Settings::class)->set('user_format', 'firstname_lastname');
        $this->em->flush();
        $this->api('GET', '/issues/'.$issue->id.'.json', null, 'admin', 'password123');
        self::assertSame('John Smith', json_decode((string) $this->client->getResponse()->getContent(), true)['issue']['author']['name']);
    }

    // --- round 2: URL patterns on every screen ------------------------------------------------

    public function testUrlPatternOnProjectVersionDocumentPagesAndListColumns(): void
    {
        $this->loginAs('admin');
        $pattern = 'http://ex.test/%value%/%id%/%project_id%/%project_identifier%';
        $projectField = $this->createField('ProjectCustomField', 'string', 'Contract', ['url_pattern' => $pattern]);
        $versionField = $this->createField('VersionCustomField', 'int', 'Build', ['url_pattern' => $pattern]);
        $documentField = $this->createField('DocumentCustomField', 'list', 'Shelf', ['possible_values' => "a1\nb2", 'url_pattern' => $pattern]);
        $entryField = $this->createField('TimeEntryCustomField', 'string', 'Invoice', ['url_pattern' => $pattern]);
        $issueField = $this->createField('IssueCustomField', 'string', 'Ticket', ['url_pattern' => $pattern]);
        $userField = $this->createField('UserCustomField', 'link', 'Homepage', ['url_pattern' => 'http://ex.test/u/%value%/%id%']);
        $db = $this->em->getConnection();

        // project overview and project list
        $this->post('/projects/ecommerce', ['project' => ['custom_field_values' => [$projectField->id => 'C7']]], 'PATCH');
        $this->assertStatus(302);
        $href = 'http://ex.test/C7/1/1/ecommerce';
        self::assertSame($href, $this->get('/projects/ecommerce')->filter('li.cf_'.$projectField->id.' a')->attr('href'));
        $crawler = $this->get('/projects?display_type=list&set_filter=1&c[]=name&c[]=cf_'.$projectField->id);
        $this->assertStatus(200);
        self::assertSame($href, $crawler->filter('td.cf_'.$projectField->id.' a')->attr('href'));

        // version page
        $this->post('/versions/1', ['version' => ['custom_field_values' => [$versionField->id => '42']]], 'PUT');
        $this->assertStatus(302);
        $crawler = $this->get('/versions/1');
        $this->assertStatus(200);
        self::assertContains('http://ex.test/42/1/1/ecommerce', $crawler->filter('.version-overview a')->each(static fn ($a) => (string) $a->attr('href')));

        // document page
        $this->post('/projects/ecommerce/documents', ['document' => ['title' => 'Linked doc', 'category_id' => '6', 'custom_field_values' => [$documentField->id => 'b2']]]);
        $this->assertStatus(302);
        $documentId = (int) $db->fetchOne("SELECT id FROM documents WHERE title = 'Linked doc'");
        $crawler = $this->get('/documents/'.$documentId);
        $this->assertStatus(200);
        self::assertContains('http://ex.test/b2/'.$documentId.'/1/ecommerce', $crawler->filter('#content a')->each(static fn ($a) => (string) $a->attr('href')));

        // issue list column, and time entry list columns (own field and field of the issue)
        $issueId = $this->createIssue([$issueField->id => 'T 1']);
        $crawler = $this->get('/issues?set_filter=1&status_id=*&c[]=subject&c[]=cf_'.$issueField->id);
        $this->assertStatus(200);
        self::assertSame('http://ex.test/T%201/'.$issueId.'/1/ecommerce', $crawler->filter('tr#issue-'.$issueId.' td.cf_'.$issueField->id.' a')->attr('href'));

        $this->post('/projects/ecommerce/time_entries', ['time_entry' => ['issue_id' => (string) $issueId, 'hours' => '1', 'activity_id' => '8', 'spent_on' => '2026-11-02', 'custom_field_values' => [$entryField->id => 'INV9']]]);
        $this->assertStatus(302);
        $entryId = (int) $db->fetchOne('SELECT MAX(id) FROM time_entries');
        $crawler = $this->get('/time_entries?set_filter=1&c[]=hours&c[]=cf_'.$entryField->id.'&c[]=issue.cf_'.$issueField->id);
        $this->assertStatus(200);
        $hrefs = $crawler->filter('tr#time-entry-'.$entryId.' a')->each(static fn ($a) => (string) $a->attr('href'));
        self::assertContains('http://ex.test/INV9/'.$entryId.'/1/ecommerce', $hrefs);
        self::assertContains('http://ex.test/T%201/'.$issueId.'/1/ecommerce', $hrefs);
        // CSV exports stay plain text
        $this->client->request('GET', '/time_entries.csv?set_filter=1&c[]=hours&c[]=cf_'.$entryField->id);
        self::assertStringContainsString('INV9', (string) $this->client->getInternalResponse()->getContent());
        self::assertStringNotContainsString('ex.test', (string) $this->client->getInternalResponse()->getContent());

        // user list column
        $jsmith = $this->user('jsmith');
        $this->post('/users/'.$jsmith->id, ['user' => ['custom_field_values' => [$userField->id => 'js']]], 'PUT');
        $this->assertStatus(302);
        $crawler = $this->get('/users?set_filter=1&c[]=login&c[]=cf_'.$userField->id);
        $this->assertStatus(200);
        self::assertContains('http://ex.test/u/js/'.$jsmith->id, $crawler->filter('td.cf_'.$userField->id.' a')->each(static fn ($a) => (string) $a->attr('href')));
    }

    public function testIssuePdfResolvesTheIdTokenOfUrlPatterns(): void
    {
        $this->loginAs('admin');
        $field = $this->createField('IssueCustomField', 'text', 'Notes', ['text_formatting' => 'full', 'full_width_layout' => '1']);
        $linked = $this->createField('IssueCustomField', 'string', 'Ref', ['text_formatting' => 'full', 'url_pattern' => 'http://ex.test/ref/%value%/%id%']);
        $id = $this->createIssue([$field->id => 'some *notes*', $linked->id => 'R5']);
        // the value renderer used by the PDF export gets the issue
        $html = self::getContainer()->get(\App\Twig\IssuesExtension::class)->customFieldValueHtml($linked, 'R5', null, $this->em->find(Issue::class, $id));
        self::assertStringContainsString('href="http://ex.test/ref/R5/'.$id.'"', $html);
        \App\Pdf\PdfSupport::$compress = false;
        try {
            $this->client->request('GET', '/issues/'.$id.'.pdf');
            $this->assertStatus(200);
            $pdf = (string) $this->client->getInternalResponse()->getContent();
            self::assertStringStartsWith('%PDF-', $pdf);
        } finally {
            \App\Pdf\PdfSupport::$compress = true;
        }
    }

    // --- round 2: the shared widget on My account, registration and time entry bulk edit --------

    public function testEditTagStyleOnMyAccountRegistrationAndTimeEntryBulkEdit(): void
    {
        $this->loginAs('admin');
        $boxes = $this->createField('UserCustomField', 'list', 'Skills', ['possible_values' => "php\nruby", 'multiple' => '1', 'edit_tag_style' => 'check_box', 'editable' => '1', 'is_required' => '1']);
        $flag = $this->createField('UserCustomField', 'bool', 'Remote', ['edit_tag_style' => 'radio', 'editable' => '1']);
        $entryList = $this->createField('TimeEntryCustomField', 'list', 'Billing', ['possible_values' => "internal\nexternal"]);
        $entryUser = $this->createField('TimeEntryCustomField', 'user', 'Approver');
        $entryDate = $this->createField('TimeEntryCustomField', 'date', 'Billed on');
        $settings = self::getContainer()->get(Settings::class);
        $settings->set('self_registration', '3');
        $settings->set('show_custom_fields_on_registration', '1');
        $this->em->flush();

        // time entry bulk edit: custom_field_tag_for_bulk_edit (selects with "no change" / "none", date input, clear box)
        $this->post('/projects/ecommerce/time_entries', ['time_entry' => ['hours' => '1', 'activity_id' => '8', 'spent_on' => '2026-11-02']]);
        $this->assertStatus(302);
        $entryId = (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM time_entries');
        $crawler = $this->get('/time_entries/bulk_edit?ids[]='.$entryId);
        $this->assertStatus(200);
        self::assertSame(['', '__none__', 'internal', 'external'], $crawler->filter('select#time_entry_custom_field_values_'.$entryList->id.' option')->each(static fn ($o) => (string) $o->attr('value')));
        self::assertContains('John Smith', $crawler->filter('select#time_entry_custom_field_values_'.$entryUser->id.' option')->each(static fn ($o) => $o->text()));
        self::assertSame('date', $crawler->filter('input#time_entry_custom_field_values_'.$entryDate->id)->attr('type'));
        self::assertCount(1, $crawler->filter('input[type=checkbox][name="time_entry[custom_field_values]['.$entryDate->id.']"][value="__none__"]'));
        $this->post('/time_entries/bulk_update', ['ids' => [$entryId], 'time_entry' => ['custom_field_values' => [$entryList->id => 'external']]]);
        $this->assertStatus(302);
        self::assertSame('external', $this->em->getConnection()->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'TimeEntry' AND customized_id = ? AND custom_field_id = ?", [$entryId, $entryList->id]));

        // My account
        $this->loginAs('jsmith');
        $crawler = $this->get('/my/account');
        $this->assertStatus(200);
        self::assertSame(['php', 'ruby'], $crawler->filter('input[type=checkbox][name="user[custom_field_values]['.$boxes->id.'][]"]')->each(static fn ($i) => (string) $i->attr('value')));
        self::assertSame(['', '1', '0'], $crawler->filter('input[type=radio][name="user[custom_field_values]['.$flag->id.']"]')->each(static fn ($i) => (string) $i->attr('value')));
        $this->post('/my/account', ['user' => ['custom_field_values' => [$boxes->id => ['ruby', ''], $flag->id => '1']]], 'PUT');
        $this->assertStatus(302);
        self::assertSame(['1', 'ruby'], $this->em->getConnection()->fetchFirstColumn("SELECT value FROM custom_values WHERE customized_type = 'Principal' AND customized_id = ? AND custom_field_id IN (?, ?) ORDER BY value", [$this->user('jsmith')->id, $boxes->id, $flag->id]));

        // registration form (anonymous)
        $this->client->restart();
        $crawler = $this->get('/account/register');
        $this->assertStatus(200);
        self::assertSame(['php', 'ruby'], $crawler->filter('input[type=checkbox][name="user[custom_field_values]['.$boxes->id.'][]"]')->each(static fn ($i) => (string) $i->attr('value')));
        self::assertCount(3, $crawler->filter('input[type=radio][name="user[custom_field_values]['.$flag->id.']"]'));
    }

    // --- round 2: RecordList#validate_custom_value --------------------------------------------

    public function testUserVersionAndKeyValueFieldsOnlyAcceptPossibleValuesOrTheCurrentValue(): void
    {
        $this->loginAs('admin');
        $db = $this->em->getConnection();
        $user = $this->createField('IssueCustomField', 'user', 'Reviewer');
        $version = $this->createField('IssueCustomField', 'version', 'FoundIn');
        $enum = $this->createField('IssueCustomField', 'enumeration', 'Kind');
        $other = $this->createField('IssueCustomField', 'string', 'Note');
        $entryUser = $this->createField('TimeEntryCustomField', 'user', 'Approver');
        $projectUser = $this->createField('ProjectCustomField', 'user', 'Sponsor');
        foreach (['Open', 'Retired'] as $name) {
            $this->post('/custom_fields/'.$enum->id.'/enumerations', ['custom_field_enumeration' => ['name' => $name]]);
        }
        [$openId, $retiredId] = array_map('intval', $db->fetchFirstColumn('SELECT id FROM custom_field_enumerations WHERE custom_field_id = ? ORDER BY id', [$enum->id]));
        $db->executeStatement('UPDATE custom_field_enumerations SET active = 0 WHERE id = ?', [$retiredId]);
        $this->em->clear();
        $this->post('/projects/internal/versions', ['version' => ['name' => 'internal-1']]);
        $this->assertStatus(302);
        $foreignVersion = (string) $db->fetchOne("SELECT id FROM versions WHERE name = 'internal-1'");
        $anna = (string) $this->user('anna')->id; // not a member of eCommerce
        $jsmith = (string) $this->user('jsmith')->id;
        $issue = ['tracker_id' => '1', 'subject' => 'Inclusion', 'status_id' => '1', 'priority_id' => '2'];

        // new issue: only the possible values
        $this->post('/projects/ecommerce/issues', ['issue' => $issue + ['custom_field_values' => [$user->id => $anna, $version->id => $foreignVersion, $enum->id => (string) $retiredId]]]);
        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        $errors = $this->errors();
        self::assertContains('Reviewer is not included in the list', $errors);
        self::assertContains('FoundIn is not included in the list', $errors);
        self::assertContains('Kind is not included in the list', $errors);
        $id = $this->createIssue([$user->id => $jsmith, $version->id => '1', $enum->id => (string) $openId]);

        // values that became impossible stay valid as long as they are the current values
        foreach ([[$user->id, $anna], [$version->id, $foreignVersion], [$enum->id, (string) $retiredId]] as [$fieldId, $value]) {
            $db->executeStatement("UPDATE custom_values SET value = ? WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = ?", [$value, $id, $fieldId]);
        }
        $this->em->clear();
        $this->post('/issues/'.$id, ['issue' => ['custom_field_values' => [$user->id => $anna, $version->id => $foreignVersion, $enum->id => (string) $retiredId, $other->id => 'changed']]], 'PATCH');
        $this->assertStatus(302);
        self::assertSame('changed', $db->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = ?", [$id, $other->id]));
        // ... but another impossible value is refused
        $this->post('/issues/'.$id, ['issue' => ['custom_field_values' => [$user->id => (string) $this->user('locked')->id]]], 'PATCH');
        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        self::assertContains('Reviewer is not included in the list', $this->errors());

        // REST API
        $this->api('PUT', '/issues/'.$id.'.json', ['issue' => ['custom_fields' => [['id' => $user->id, 'value' => (string) $this->user('locked')->id]]]], 'admin', 'password123');
        $this->assertStatus(422);
        self::assertStringContainsString('Reviewer is not included in the list', (string) $this->client->getResponse()->getContent());

        // time entries and projects
        $this->post('/projects/ecommerce/time_entries', ['time_entry' => ['hours' => '1', 'activity_id' => '8', 'spent_on' => '2026-11-02', 'custom_field_values' => [$entryUser->id => $anna]]]);
        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        self::assertContains('Approver is not included in the list', $this->errors());
        $this->post('/projects/ecommerce/time_entries', ['time_entry' => ['hours' => '1', 'activity_id' => '8', 'spent_on' => '2026-11-02', 'custom_field_values' => [$entryUser->id => $jsmith]]]);
        $this->assertStatus(302);

        $this->post('/projects/ecommerce', ['project' => ['custom_field_values' => [$projectUser->id => $anna]]], 'PATCH');
        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        self::assertContains('Sponsor is not included in the list', $this->errors());
        $this->post('/projects/ecommerce', ['project' => ['custom_field_values' => [$projectUser->id => $jsmith]]], 'PATCH');
        $this->assertStatus(302);
    }

    // --- round 2: imported administrators, toolbar languages ----------------------------------

    public function testImportedAdministratorIsReportedToTheAdministrators(): void
    {
        $this->loginAs('admin');
        $path = sys_get_temp_dir().'/symine-admin-import-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, "Login,Firstname,Lastname,Email,Administrator,Password\nimpadmin,Imp,Admin,impadmin@example.net,yes,Secret12345\nimpuser,Imp,User,impuser@example.net,no,Secret12345\n");
        try {
            $this->client->request('POST', '/imports', ['_token' => $this->csrfToken(), 'type' => 'UserImport', 'project_id' => ''], [
                'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'import.csv', 'text/csv', null, true),
            ]);
            $this->assertStatus(302);
            self::assertSame(1, preg_match('#/imports/([0-9a-f]{32})/settings$#', (string) $this->client->getResponse()->headers->get('Location'), $m));
            $id = $m[1];
            $this->post("/imports/$id/settings", ['import_settings' => ['separator' => ',', 'wrapper' => '"', 'encoding' => 'UTF-8', 'date_format' => '%Y-%m-%d', 'notifications' => '0']]);
            $this->post("/imports/$id/mapping", ['import_settings' => ['mapping' => ['login' => '0', 'firstname' => '1', 'lastname' => '2', 'mail' => '3', 'admin' => '4', 'password' => '5']]]);
            $this->assertStatus(302);
            self::getContainer()->get('mailer.message_logger_listener')->reset();
            $this->get("/imports/$id/run");
            for ($i = 0; $i < 20; ++$i) {
                $this->post("/imports/$id/run");
                if (str_ends_with((string) $this->client->getResponse()->headers->get('Location'), "/imports/$id")) {
                    break;
                }
            }
        } finally {
            @unlink($path);
        }
        $this->em->clear();
        self::assertTrue($this->user('impadmin')->admin);
        self::assertFalse($this->user('impuser')->admin);
        $mails = array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof \Symfony\Component\Mime\Email));
        $to = array_map(static fn ($m) => $m->getTo()[0]->getAddress(), $mails);
        sort($to);
        // one security notification per active administrator (the imported one included), none for the regular user
        self::assertSame(['admin@dummy.invalid', 'impadmin@example.net'], $to);
        self::assertStringContainsString('Administrator impadmin was added.', (string) $mails[0]->getTextBody());
    }

    public function testToolbarLanguageOptionsKeepOnlySupportedLanguages(): void
    {
        $pref = fn (string $login) => (string) $this->user($login)->getPref()->get('toolbar_language_options');

        $this->loginAs('jsmith');
        $this->post('/my/account', ['pref' => ['toolbar_language_options' => 'ruby, notalanguage,php,<script>']], 'PUT');
        $this->assertStatus(302);
        $this->em->clear();
        self::assertSame('ruby,php', $pref('jsmith'));

        $this->loginAs('admin');
        $this->post('/users/'.$this->user('dlopper')->id, ['pref' => ['toolbar_language_options' => 'python,zzz,sql'], 'user' => ['firstname' => 'Dave']], 'PUT');
        $this->assertStatus(302);
        $this->em->clear();
        self::assertSame('python,sql', $pref('dlopper'));
    }
}
