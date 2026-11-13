<?php

declare(strict_types=1);

namespace App\Tests\Admin;

/**
 * Logs in through the real login form. KernelBrowser::loginUser() does not work for users that still
 * have a legacy (salted SHA1) Redmine hash: User::__serialize() drops the salt, so the security
 * context listener sees a changed user on the next request and logs it out.
 */
trait FormLoginTrait
{
    protected function loginAs(string $login): void
    {
        $password = 'admin' === $login ? 'admin' : 'password123';
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['username' => $login, 'password' => $password, '_csrf_token' => $token, 'back_url' => '/']);
        self::assertTrue($this->client->getResponse()->isRedirect(), 'login failed for '.$login);
        $this->em->clear();
    }
}
