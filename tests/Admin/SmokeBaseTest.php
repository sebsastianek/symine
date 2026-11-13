<?php

declare(strict_types=1);

namespace App\Tests\Admin;

final class SmokeBaseTest extends AdminWebTestCase
{
    public function testHomeAsAdmin(): void
    {
        $this->loginAs('admin');
        $this->get('/');
        $this->assertStatus(200);
        self::assertNotEmpty($this->csrfToken());
    }
}
