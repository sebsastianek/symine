<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * attachments_visibility_test.rb, search_custom_fields_visibility_test.rb, versions_custom_fields_visibility_test.rb,
 * watchers on invisible objects and session invalidation (sessions_test.rb).
 */
final class ContentVisibilityTest extends SecurityWebTestCase
{
    /** Attachment pointing to the seeded notes.txt file, attached to the given container */
    private function attachment(string $containerType, int $containerId): int
    {
        $this->conn()->executeStatement("INSERT INTO attachments (container_id, container_type, filename, disk_filename, filesize, content_type, digest, downloads, created_on, disk_directory, author_id)
            SELECT ?, ?, 'secret.txt', disk_filename, filesize, content_type, digest, 0, datetime('now'), disk_directory, 5 FROM attachments WHERE id = 1", [$containerId, $containerType]);

        return (int) $this->dbOne('SELECT MAX(id) FROM attachments');
    }

    /** @return iterable<string, array{0: string, 1: int, 2: array<string, bool>}> container, id, login => can see */
    public static function attachmentContainers(): iterable
    {
        yield 'private issue #17' => ['Issue', 17, ['anonymous' => false, 'anna' => false, 'dlopper' => false, 'rhill' => true, 'jsmith' => true, 'admin' => true]];
        yield 'issue of the private project' => ['Issue', 18, ['anonymous' => false, 'anna' => false, 'dlopper' => false, 'rhill' => false, 'jsmith' => true, 'admin' => true]];
        yield 'public issue' => ['Issue', 13, ['anonymous' => true, 'anna' => true, 'dlopper' => true, 'rhill' => true, 'jsmith' => true, 'admin' => true]];
        yield 'news' => ['News', 1, ['anonymous' => true, 'anna' => true, 'dlopper' => true, 'rhill' => true, 'jsmith' => true, 'admin' => true]];
        yield 'wiki page' => ['WikiPage', 1, ['anonymous' => true, 'anna' => true, 'dlopper' => true, 'rhill' => true, 'jsmith' => true, 'admin' => true]];
        yield 'document' => ['Document', 1, ['anonymous' => true, 'anna' => true, 'dlopper' => true, 'rhill' => true, 'jsmith' => true, 'admin' => true]];
    }

    #[DataProvider('attachmentContainers')]
    public function testAttachmentVisibilityFollowsTheContainer(string $type, int $containerId, array $expected): void
    {
        $id = $this->attachment($type, $containerId);
        $actual = [];
        foreach (array_keys($expected) as $login) {
            $this->as('anonymous' === $login ? null : $login);
            $codes = [];
            foreach (["/attachments/$id", "/attachments/download/$id/secret.txt", "/attachments/download/$id"] as $url) {
                $this->get($url);
                $codes[] = $this->outcome();
            }
            $visible = array_unique($codes);
            self::assertCount(1, $visible, "same answer for every attachment URL as $login: ".implode(',', $codes));
            $actual[$login] = 'ok' === $visible[0];
            if (!$actual[$login]) {
                self::assertStringNotContainsString('hello world', $this->body());
            }
        }
        self::assertSame($expected, $actual, "attachment of $type #$containerId");
    }

    /** The view_files / view_documents permissions also guard the attachments of their containers */
    public function testAttachmentOfDocumentNeedsViewDocuments(): void
    {
        $id = $this->attachment('Document', 1);
        $this->rolePermissions(self::ROLE_NON_MEMBER, [], ['view_documents']);
        $this->as('anna');
        $this->get("/attachments/download/$id");
        self::assertSame('403', $this->outcome());
        $this->as('jsmith');
        $this->get("/attachments/download/$id");
        self::assertSame('ok', $this->outcome());
    }

    /** Deleting an attachment needs the edit permission of the container */
    public function testAttachmentDeletionPermission(): void
    {
        $id = $this->attachment('Issue', 13);
        $this->as('rhill');
        $token = $this->csrf();
        $this->client->request('POST', "/attachments/$id", ['_method' => 'DELETE', '_token' => $token]);
        self::assertSame('403', $this->outcome());
        self::assertSame(1, (int) $this->dbOne('SELECT COUNT(*) FROM attachments WHERE id = ?', [$id]));
        $this->as('jsmith');
        $token = $this->csrf();
        $this->client->request('POST', "/attachments/$id", ['_method' => 'DELETE', '_token' => $token]);
        self::assertSame(0, (int) $this->dbOne('SELECT COUNT(*) FROM attachments WHERE id = ?', [$id]));
    }

    /** search_custom_fields_visibility_test.rb: hidden custom field values are not searchable */
    public function testSearchIgnoresHiddenCustomFieldValues(): void
    {
        // "Database" (#1, value PostgreSQL on #13) becomes searchable and visible to the Manager role only
        $this->conn()->executeStatement('UPDATE custom_fields SET searchable = 1, visible = 0 WHERE id = 1');
        $this->conn()->executeStatement('INSERT INTO custom_fields_roles (custom_field_id, role_id) VALUES (1, 3)');
        foreach (['jsmith' => true, 'admin' => true, 'rhill' => false, 'dlopper' => false, 'anna' => false] as $login => $found) {
            $this->as($login);
            $this->get('/search?q=PostgreSQL&issues=1');
            self::assertSame($found, str_contains($this->body(), 'Cart total is wrong'), "search PostgreSQL as $login");
        }
        $this->conn()->executeStatement('UPDATE custom_fields SET visible = 1 WHERE id = 1');
        $this->as('anna');
        $this->get('/search?q=PostgreSQL&issues=1');
        self::assertStringContainsString('Cart total is wrong', $this->body(), 'visible searchable field');
    }

    /** versions_custom_fields_visibility_test.rb */
    public function testVersionCustomFieldVisibility(): void
    {
        $this->conn()->executeStatement("INSERT INTO custom_fields (type, name, field_format, possible_values, regexp, min_length, max_length, is_required, is_for_all, is_filter, position, searchable, default_value, editable, visible, multiple, format_store, description)
            VALUES ('VersionCustomField', 'Release manager notes', 'string', NULL, '', NULL, NULL, 0, 0, 0, 1, 0, '', 1, 0, 0, '{}', '')");
        $cf = (int) $this->dbOne('SELECT MAX(id) FROM custom_fields');
        $this->conn()->executeStatement('INSERT INTO custom_fields_roles (custom_field_id, role_id) VALUES (?, 3)', [$cf]);
        $this->conn()->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Version', 1, ?, 'Top secret plan')", [$cf]);
        foreach (['jsmith' => true, 'admin' => true, 'dlopper' => false, 'rhill' => false] as $login => $visible) {
            $this->as($login);
            foreach (['/versions/1', '/projects/ecommerce/roadmap'] as $url) {
                $this->get($url);
                self::assertSame(200, $this->code());
                self::assertSame($visible, str_contains($this->body(), 'Top secret plan'), "$url as $login");
            }
            $key = substr(hash('sha1', 'api'.$login), 0, 40);
            $this->conn()->executeStatement("INSERT OR IGNORE INTO tokens (user_id, action, value, created_on, updated_on) SELECT id, 'api', ?, datetime('now'), datetime('now') FROM users WHERE login = ?", [$key, $login]);
            $this->client->restart();
            $this->get('/versions/1.json', ['HTTP_X_REDMINE_API_KEY' => $key]);
            self::assertSame($visible, str_contains($this->body(), 'Top secret plan'), "/versions/1.json as $login");
        }
    }

    /** watchers: an invisible object cannot be watched or listed */
    public function testWatchersOfInvisibleObjects(): void
    {
        $this->as('anna');
        $token = $this->csrf();
        foreach ([['issue', '18'], ['issue', '17'], ['issue', '999']] as [$type, $id]) {
            $this->client->request('POST', '/watchers/watch', ['object_type' => $type, 'object_id' => $id, '_token' => $token]);
            self::assertContains($this->code(), [403, 404], "watch $type #$id");
        }
        $this->get('/watchers/new?object_type=issue&object_id=17');
        self::assertContains($this->code(), [403, 404]);
        self::assertSame(0, (int) $this->dbOne('SELECT COUNT(*) FROM watchers WHERE user_id = 9'));
    }

    /** sessions_test.rb: locking the user or changing the password ends the other sessions */
    public function testSessionsEndWhenTheAccountChanges(): void
    {
        $this->as('jsmith');
        $this->get('/my/page');
        self::assertSame('ok', $this->outcome());
        $this->conn()->executeStatement("UPDATE users SET hashed_password = ? WHERE login = 'jsmith'", [password_hash('another-password', \PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->get('/my/page');
        self::assertSame('login', $this->outcome(), 'the password was changed elsewhere');

        $this->as('dlopper');
        $this->get('/my/page');
        self::assertSame('ok', $this->outcome());
        $this->conn()->executeStatement("UPDATE users SET status = 3 WHERE login = 'dlopper'");
        $this->get('/my/page');
        self::assertSame('login', $this->outcome(), 'the account was locked');
        $this->conn()->executeStatement("UPDATE users SET status = 2 WHERE login = 'dlopper'");
        $this->as('dlopper');
        $this->get('/my/page');
        $this->get('/my/page');
        self::assertSame('login', $this->outcome(), 'registered (not activated) accounts have no session');
    }
}
