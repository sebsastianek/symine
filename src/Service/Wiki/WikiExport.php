<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\Wiki;
use App\Service\WikiService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the download of all the pages of a wiki as a zip archive of text files (wiki#export, format zip).
 */
final class WikiExport
{
    public function __construct(private readonly WikiService $wikis)
    {
    }

    public function zip(Wiki $wiki): Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wikizip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $names = [];
        foreach ($this->wikis->pages($wiki) as $page) {
            $base = preg_replace('/[\/?%*:|"\'<>\n\r\x00]+/', '_', str_replace('\\', '_', $page->title));
            $name = $base.'.txt';
            $dup = 0;
            while (isset($names[$name])) {
                $name = $base.'('.++$dup.').txt';
            }
            $names[$name] = true;
            $zip->addFromString($name, (string) $page->content?->text);
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);

        return new Response($data, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$wiki->project->identifier.'-wiki.zip"']);
    }
}
