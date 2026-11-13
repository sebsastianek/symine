<?php

declare(strict_types=1);

namespace App\Tests\Pdf;

use App\Entity\Role;
use App\Entity\User;
use App\Pdf\PdfSupport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Functional tests of the PDF/PNG exports on a fresh copy of the seeded database. */
abstract class PdfWebTestCase extends WebTestCase
{
    use PdfAssertions;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2);
        copy($dir.'/tests/fixtures/symine-seeded.db', $dir.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        PdfSupport::$compress = false;
    }

    protected function tearDown(): void
    {
        PdfSupport::$compress = true;
        parent::tearDown();
    }

    protected function login(string $login): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        self::assertNotNull($user);
        $this->client->loginUser($user, 'main');

        return $user;
    }

    protected function fetch(string $url): Response
    {
        $this->client->request('GET', $url);

        return $this->client->getResponse();
    }

    /** GET returning a PDF document */
    protected function pdf(string $url, ?string $filename = null): string
    {
        $response = $this->fetch($url);
        self::assertSame(200, $response->getStatusCode(), $url);
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        if (null !== $filename) {
            self::assertSame('attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename), $response->headers->get('Content-Disposition'));
        }
        $content = (string) $response->getContent();
        self::assertPdf($content);

        return $content;
    }

    protected function removePermission(string $roleName, string ...$permissions): void
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]);
        self::assertNotNull($role);
        $role->removePermission(...$permissions);
        $this->em->flush();
    }
}
