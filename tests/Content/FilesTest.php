<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Entity\Attachment;
use App\Entity\Version;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FilesTest extends ContentWebTestCase
{
    public function testPagesForAdminAndJsmith(): void
    {
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            $this->assertPagesOk(['/projects/ecommerce/files', '/projects/ecommerce/files?sort=size:desc,filename', '/projects/ecommerce/files/new']);
        }
        $html = $this->get('/projects/ecommerce/files');
        self::assertStringContainsString('class="sort asc icon icon-sorted-desc" href="/projects/ecommerce/files?sort=filename%3Adesc"', $html);
        self::assertStringContainsString('href="/projects/ecommerce/files?sort=created_on%3Adesc%2Cfilename"', $html);
    }

    public function testUploadToProjectAndVersion(): void
    {
        $this->login('jsmith');
        $tmp = tempnam(sys_get_temp_dir(), 'f');
        file_put_contents($tmp, 'release archive');
        self::assertSame(302, $this->submit('/projects/ecommerce/files', [], 'POST', ['attachments' => ['1' => ['file' => new UploadedFile($tmp, 'release.zip', 'application/zip', null, true)]]]));
        self::assertSame('/projects/ecommerce/files', $this->location());
        $a = $this->em->getRepository(Attachment::class)->findOneBy(['filename' => 'release.zip']);
        self::assertSame('Project', $a->containerType);
        self::assertSame(hash('sha256', 'release archive'), $a->digest);

        $version = $this->em->getRepository(Version::class)->findOneBy([]);
        if (null !== $version) {
            $tmp2 = tempnam(sys_get_temp_dir(), 'f');
            file_put_contents($tmp2, 'v');
            $pid = $version->project->identifier;
            self::assertSame(302, $this->submit('/projects/'.$pid.'/files', ['version_id' => $version->id], 'POST', ['attachments' => ['1' => ['file' => new UploadedFile($tmp2, 'v.txt', 'text/plain', null, true)]]]));
            $b = $this->em->getRepository(Attachment::class)->findOneBy(['filename' => 'v.txt']);
            self::assertSame('Version', $b->containerType);
            self::assertSame($version->id, $b->containerId);
        }

        $html = $this->get('/projects/ecommerce/files');
        self::assertStringContainsString('release.zip', $html);
        self::assertStringContainsString('SHA256: '.hash('sha256', 'release archive'), $html);

        // no file => error
        self::assertSame(422, $this->submit('/projects/ecommerce/files', []));

        $data = $this->api('GET', '/projects/ecommerce/files.json');
        self::assertContains('release.zip', array_column($data['files'], 'filename'));
    }

    public function testAnonymousCannotUpload(): void
    {
        $this->client->request('GET', '/projects/ecommerce/files/new');
        self::assertSame(302, $this->httpStatus());
    }
}
