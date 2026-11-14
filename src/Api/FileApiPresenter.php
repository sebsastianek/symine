<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\Version;

/** The files of a project in the REST API (files/index.api.rsb). */
final class FileApiPresenter
{
    /**
     * @param list<array{container: Project|Version, version: ?Version, files: list<Attachment>}> $containers
     * @param string                                                                              $base       scheme, host and base path of the application, for the URLs of the files
     *
     * @return list<array<string, mixed>>
     */
    public function files(array $containers, string $base): array
    {
        $files = [];
        foreach ($containers as $c) {
            foreach ($c['files'] as $a) {
                $item = [
                    'id' => $a->id, 'filename' => $a->filename, 'filesize' => $a->getFilesize(), 'content_type' => $a->contentType,
                    'description' => $a->description, 'content_url' => $base.'/attachments/download/'.$a->id.'/'.rawurlencode($a->filename),
                ];
                if ($a->isImage()) {
                    $item['thumbnail_url'] = $base.'/attachments/thumbnail/'.$a->id;
                }
                $item['author'] = ['id' => $a->author->id, 'name' => $a->author->getName()];
                $item['created_on'] = ApiRenderer::time($a->createdOn);
                if (null !== $c['version']) {
                    $item['version'] = ['id' => $c['version']->id, 'name' => $c['version']->name];
                }
                $item['digest'] = $a->digest;
                $item['downloads'] = $a->downloads;
                $files[] = $item;
            }
        }

        return $files;
    }
}
