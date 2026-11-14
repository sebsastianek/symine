<?php

declare(strict_types=1);

namespace App\Service\Admin\AuthSources;

use App\Service\LdapService;

/**
 * The directory entries offered while typing the login of a new user (auth_sources#autocomplete_for_new_user):
 * choosing one fills the user form.
 */
final class LdapUserSuggestions
{
    public function __construct(private readonly LdapService $ldap)
    {
    }

    /** @return list<array{value: string, label: string, login: string, firstname: string, lastname: string, mail: string, auth_source_id: string}> */
    public function matching(string $term): array
    {
        $json = [];
        foreach ($this->ldap->search($term) as $r) {
            $json[] = [
                'value' => $r['login'],
                'label' => \sprintf('%s (%s %s)', $r['login'], $r['firstname'], $r['lastname']),
                'login' => (string) $r['login'],
                'firstname' => (string) $r['firstname'],
                'lastname' => (string) $r['lastname'],
                'mail' => (string) $r['mail'],
                'auth_source_id' => (string) $r['auth_source_id'],
            ];
        }

        return $json;
    }
}
