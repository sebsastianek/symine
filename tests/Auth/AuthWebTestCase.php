<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Security\Twofa\Totp;
use App\Tests\Timelog\TimelogWebTestCase;

/** Helpers for the 2FA / OAuth functional tests (fresh copy of the seeded database per test). */
abstract class AuthWebTestCase extends TimelogWebTestCase
{
    /** Key created by the reference Redmine for rhill (see TotpTest) */
    public const string KEY = 'OTB2X5TNLC2E6QORKFFCNDELYQGTIMIT';

    /** Pairs a TOTP scheme directly in the database (as if migrated from Redmine) */
    protected function pairTotp(string $login, string $key = self::KEY): void
    {
        $this->em->getConnection()->executeStatement("UPDATE users SET twofa_scheme = 'totp', twofa_totp_key = ?, twofa_totp_last_used_at = NULL WHERE login = ?", [$key, $login]);
        $this->em->clear();
    }

    protected function totpCode(string $key = self::KEY, int $offset = 0): string
    {
        return (new Totp($key))->at(time() + $offset);
    }

    /** Forgets the last used TOTP step (replay protection) so that the current code can be used again */
    protected function resetTotpReplay(string $login): void
    {
        $this->em->getConnection()->executeStatement('UPDATE users SET twofa_totp_last_used_at = NULL WHERE login = ?', [$login]);
        $this->em->clear();
    }

    /** CSRF token of the meta tag of $url (or of the current response when null) */
    protected function csrfFrom(?string $url = null): string
    {
        if (null !== $url) {
            $this->client->request('GET', $url);
        }
        $html = $this->content();
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) || preg_match('/name="_token" value="([^"]+)"/', $html, $m)) {
            return html_entity_decode($m[1]);
        }
        self::fail('No CSRF token in '.($url ?? 'response').': '.substr(strip_tags($html), 0, 300));
    }

    protected function post(string $url, array $params = [], ?string $csrfUrl = '/my/account'): int
    {
        $params['_token'] ??= $this->csrfFrom($csrfUrl);
        $this->client->request('POST', $url, $params);

        return $this->code();
    }

    protected function isLoggedIn(): bool
    {
        $this->client->request('GET', '/my/account');

        return 200 === $this->code();
    }

    protected function flash(): string
    {
        $this->client->followRedirect();

        return html_entity_decode(strip_tags(implode(' ', preg_match_all('/<div class="flash [^"]*"[^>]*>(.*?)<\/div>/s', $this->content(), $m) ? $m[1] : [])), \ENT_QUOTES);
    }

    protected function userRow(string $login): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM users WHERE login = ?', [$login]) ?: [];
    }
}
