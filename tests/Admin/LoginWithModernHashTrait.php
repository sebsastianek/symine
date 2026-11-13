<?php

declare(strict_types=1);

namespace App\Tests\Admin;

/**
 * KernelBrowser::loginUser() fails for users whose password is still a legacy Redmine SHA1 hash:
 * the salt is not part of User::__serialize(), so the ContextListener sees a changed user and
 * deauthenticates the token. Re-hash the password (like a real login would) before logging in.
 */
trait LoginWithModernHashTrait
{
    protected function loginAs(string $login): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET hashed_password = ?, salt = NULL WHERE login = ?',
            [password_hash('password123', \PASSWORD_BCRYPT, ['cost' => 4]), $login]
        );
        $this->em->clear();
        parent::loginAs($login);
    }
}
