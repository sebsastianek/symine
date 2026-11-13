<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Project;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of app/views/common/feed.atom.builder.
 *
 * Items: list of ['title' => string, 'url' => absolute or relative url, 'updated' => DateTimeInterface,
 * 'author' => ?User, 'content' => raw text (formatted with textilizable), 'project' => ?Project]
 */
final class AtomFeed
{
    public function __construct(
        private readonly Settings $settings,
        private readonly TextFormatter $formatter,
    ) {
    }

    /** @param list<array{title: string, url: string, updated: \DateTimeInterface, author?: ?User, content?: ?string, project?: ?Project}> $items */
    public function render(Request $request, string $title, array $items, ?Project $project = null): Response
    {
        $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();
        $abs = static fn (string $url) => str_starts_with($url, 'http') ? $url : $base.$url;
        $e = static fn (?string $s) => htmlspecialchars((string) $s, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        $trunc = static function (string $s): string {
            $s = trim(preg_replace('/[\r\n]+/', ' ', $s) ?? $s);

            return mb_strlen($s) > 300 ? mb_substr($s, 0, 297).'...' : $s;
        };
        $query = $request->query->all();
        $alternate = $query;
        unset($alternate['key'], $alternate['format']);
        $self = $abs($request->getPathInfo()).($query ? '?'.http_build_query($query) : '');
        $altUrl = $abs(preg_replace('/\.atom$/', '', $request->getPathInfo()) ?? '').($alternate ? '?'.http_build_query($alternate) : '');
        $updated = isset($items[0]) ? $items[0]['updated'] : new \DateTimeImmutable();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">'."\n";
        $xml .= '  <title>'.$e($trunc($title)).'</title>'."\n";
        $xml .= '  <link rel="self" href="'.$e($self).'"/>'."\n";
        $xml .= '  <link rel="alternate" href="'.$e($altUrl).'"/>'."\n";
        $xml .= '  <id>'.$e($base.'/').'</id>'."\n";
        $xml .= '  <icon>'.$e($base.'/assets/favicon.ico').'</icon>'."\n";
        $xml .= '  <updated>'.$updated->format(\DATE_ATOM).'</updated>'."\n";
        $xml .= '  <author>'."\n".'    <name>'.$e($this->settings->appTitle()).'</name>'."\n".'  </author>'."\n";
        $xml .= '  <generator uri="https://www.redmine.org/">Redmine</generator>'."\n";
        foreach ($items as $item) {
            $url = $abs($item['url']);
            $itemProject = $item['project'] ?? null;
            $t = (null !== $itemProject && $itemProject !== $project) ? $itemProject->name.' - '.$item['title'] : $item['title'];
            $xml .= '  <entry>'."\n";
            $xml .= '    <title>'.$e($trunc($t)).'</title>'."\n";
            $xml .= '    <link rel="alternate" href="'.$e($url).'"/>'."\n";
            $xml .= '    <id>'.$e($url).'</id>'."\n";
            $xml .= '    <updated>'.$item['updated']->format(\DATE_ATOM).'</updated>'."\n";
            $author = $item['author'] ?? null;
            if (null !== $author) {
                $xml .= '    <author>'."\n".'      <name>'.$e($author->getName()).'</name>'."\n";
                if ($author instanceof User && $author->getMail() && !$author->getPref()->hideMail) {
                    $xml .= '      <email>'.$e($author->getMail()).'</email>'."\n";
                }
                $xml .= '    </author>'."\n";
            }
            $xml .= '    <content type="html">'.$e($this->formatter->format($item['content'] ?? '', $itemProject ?? $project)).'</content>'."\n";
            $xml .= '  </entry>'."\n";
        }
        $xml .= '</feed>'."\n";

        return new Response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
