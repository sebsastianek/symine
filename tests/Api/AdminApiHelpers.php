<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Helpers shared by the project/admin API parity tests (used with ApiTestCase).
 * Roles: 1 Non member, 2 Anonymous, 3 Manager, 4 Developer, 5 Reporter.
 */
trait AdminApiHelpers
{
    /** Removes permissions from a role (roles.permissions is a JSON list) */
    protected function removePermissions(int $roleId, string ...$permissions): void
    {
        $perms = json_decode((string) $this->dbOne('SELECT permissions FROM roles WHERE id = ?', [$roleId]), true) ?: [];
        $perms = array_values(array_diff($perms, $permissions));
        $this->conn()->executeStatement('UPDATE roles SET permissions = ? WHERE id = ?', [json_encode($perms), $roleId]);
    }

    protected function addPermissions(int $roleId, string ...$permissions): void
    {
        $perms = json_decode((string) $this->dbOne('SELECT permissions FROM roles WHERE id = ?', [$roleId]), true) ?: [];
        $perms = array_values(array_unique([...$perms, ...$permissions]));
        $this->conn()->executeStatement('UPDATE roles SET permissions = ? WHERE id = ?', [json_encode($perms), $roleId]);
    }

    /** Form-encoded request (like Redmine's integration tests posting :params) */
    protected function form(string $method, string $url, array $params, ?string $as = 'admin'): \Symfony\Component\HttpFoundation\Response
    {
        return $this->request($method, $url, $params, $as, [], true);
    }

    /** Text of XPath matches */
    protected static function xpath(\SimpleXMLElement $xml, string $path): array
    {
        return array_map('strval', $xml->xpath($path) ?: []);
    }

    protected function lastId(string $table): int
    {
        return (int) $this->dbOne("SELECT MAX(id) FROM $table");
    }

    /** POST /uploads.json, returns the upload token */
    protected function upload(string $content, string $filename = 'test.txt', string $as = 'jsmith'): string
    {
        $this->client->request('POST', '/uploads.json?filename='.rawurlencode($filename), [], [], ['HTTP_X_REDMINE_API_KEY' => $this->key($as), 'CONTENT_TYPE' => 'application/octet-stream'], $content);
        $this->assertStatus(201);

        return (string) json_decode($this->body(), true)['upload']['token'];
    }
}
