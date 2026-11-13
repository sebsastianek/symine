<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Entity\Attachment;
use App\Entity\Document;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DocumentsTest extends ContentWebTestCase
{
    public function testPagesForAdminAndJsmith(): void
    {
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            $this->assertPagesOk([
                '/projects/ecommerce/documents', '/projects/ecommerce/documents?sort_by=date', '/projects/ecommerce/documents?sort_by=title',
                '/projects/ecommerce/documents?sort_by=author', '/documents/1', '/documents/1/edit', '/projects/ecommerce/documents/new',
            ]);
        }
        $html = $this->get('/projects/ecommerce/documents');
        self::assertStringContainsString('<h3 class="group-name">User documentation</h3>', $html);
        self::assertStringContainsString('<a href="/documents/1">Specs</a>', $html);
        $html = $this->get('/projects/ecommerce/documents?sort_by=title');
        self::assertStringContainsString('<h3 class="group-name">S</h3>', $html);
    }

    public function testCreateWithAttachmentEditAddAttachmentDelete(): void
    {
        $this->login('jsmith');
        self::assertSame(422, $this->submit('/projects/ecommerce/documents', ['document' => ['title' => '', 'category_id' => 6]]));

        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp, 'hello document');
        $file = new UploadedFile($tmp, 'manual.txt', 'text/plain', null, true);
        self::assertSame(302, $this->submit('/projects/ecommerce/documents', ['document' => ['title' => 'Manual', 'category_id' => 7, 'description' => 'Desc']], 'POST', ['attachments' => ['1' => ['file' => $file]]]));
        self::assertSame('/projects/ecommerce/documents', $this->location());
        $doc = $this->em->getRepository(Document::class)->findOneBy(['title' => 'Manual']);
        self::assertNotNull($doc);
        self::assertSame(7, $doc->category->id);
        $atts = $this->em->getRepository(Attachment::class)->findBy(['containerType' => 'Document', 'containerId' => $doc->id]);
        self::assertCount(1, $atts);
        self::assertSame('manual.txt', $atts[0]->filename);

        $html = $this->get('/projects/ecommerce/documents?sort_by=author');
        self::assertStringContainsString('John Smith', $html);

        self::assertSame(302, $this->submit('/documents/'.$doc->id, ['_method' => 'PATCH', 'document' => ['title' => 'Manual v2']]));
        $this->em->clear();
        self::assertSame('Manual v2', $this->em->find(Document::class, $doc->id)->title);

        $tmp2 = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp2, 'second');
        $file2 = new UploadedFile($tmp2, 'second.txt', 'text/plain', null, true);
        self::assertSame(302, $this->submit('/documents/'.$doc->id.'/add_attachment', [], 'POST', ['attachments' => ['1' => ['file' => $file2]]]));
        self::assertCount(2, $this->em->getRepository(Attachment::class)->findBy(['containerType' => 'Document', 'containerId' => $doc->id]));
        self::assertStringContainsString('second.txt', $this->get('/documents/'.$doc->id));

        self::assertSame(302, $this->submit('/documents/'.$doc->id, ['_method' => 'DELETE']));
        $this->em->clear();
        self::assertNull($this->em->find(Document::class, $doc->id));
        self::assertCount(0, $this->em->getRepository(Attachment::class)->findBy(['containerType' => 'Document', 'containerId' => $doc->id]));
    }

    public function testApi(): void
    {
        $data = $this->api('GET', '/projects/ecommerce/documents.json');
        self::assertSame('Specs', $data['documents'][0]['title']);
        $data = $this->api('GET', '/documents/1.json?include=attachments');
        self::assertSame('User documentation', $data['document']['category']['name']);
        self::assertSame([], $data['document']['attachments']);

        $data = $this->api('POST', '/projects/ecommerce/documents.json', ['document' => ['title' => 'Api doc', 'category_id' => 6]]);
        self::assertSame(201, $this->httpStatus());
        self::assertSame('Api doc', $data['document']['title']);
        $this->api('DELETE', '/documents/'.$data['document']['id'].'.json');
        self::assertSame(204, $this->httpStatus());
    }
}
