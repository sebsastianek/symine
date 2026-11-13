<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Attachment;
use App\Entity\Board;
use App\Entity\Project;
use App\Entity\Repository;
use App\Entity\Wiki;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\WatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * View helpers shared by the content modules (wiki, news, documents, files, forums, repository):
 * attachments, watchers, project settings tabs data.
 */
final class ContentExtension extends AbstractExtension
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
        private readonly RouterInterface $router,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('attachments_for', $this->attachments->forContainer(...)),
            new TwigFunction('attachment_url', $this->attachmentUrl(...)),
            new TwigFunction('watchers_of', $this->watchers->watchers(...)),
            new TwigFunction('is_watching', fn (string $type, int $id) => $this->watchers->isWatching($type, $id, $this->currentUser->get())),
            new TwigFunction('watch_url', $this->watchUrl(...)),
            new TwigFunction('route_for_path', $this->routeForPath(...)),
            new TwigFunction('project_wiki', fn (Project $p) => $this->em->getRepository(Wiki::class)->findOneBy(['project' => $p])),
            new TwigFunction('project_boards', $this->projectBoards(...)),
            new TwigFunction('project_repositories', fn (Project $p) => $this->em->getRepository(Repository::class)->findBy(['project' => $p], ['isDefault' => 'DESC', 'identifier' => 'ASC'])),
            new TwigFunction('mime_icon', $this->mimeIcon(...)),
        ];
    }

    /** Same URLs as Redmine's attachment_path / download_named_attachment_path */
    public function attachmentUrl(Attachment $a, bool $download = false): string
    {
        return $download ? '/attachments/download/'.$a->id.'/'.rawurlencode($a->filename) : '/attachments/'.$a->id;
    }

    /** /watchers/watch?object_type=..&object_id=.. if a watchers controller is installed, null otherwise */
    public function watchUrl(string $objectType, int $id): ?string
    {
        if (null === $this->routeForPath('/watchers/watch')) {
            return null;
        }

        return '/watchers/watch?'.http_build_query(['object_type' => $objectType, 'object_id' => $id]);
    }

    public function routeForPath(string $path): ?string
    {
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            if ($route->getPath() === $path) {
                return $name;
            }
        }

        return null;
    }

    /** Boards of a project in tree order: list of [board, level] */
    public function projectBoards(Project $project): array
    {
        $boards = $this->em->getRepository(Board::class)->findBy(['project' => $project], ['position' => 'ASC', 'id' => 'ASC']);
        $out = [];
        $walk = function (?Board $parent, int $level) use (&$walk, &$out, $boards): void {
            foreach ($boards as $b) {
                if (($b->parent?->id) === ($parent?->id)) {
                    $out[] = ['board' => $b, 'level' => $level];
                    $walk($b, $level + 1);
                }
            }
        };
        $walk(null, 0);

        return $out;
    }

    private const array MIME_ICONS = [
        'application/gzip' => 'application-gzip', 'application/javascript' => 'application-javascript', 'text/javascript' => 'application-javascript',
        'application/pdf' => 'application-pdf', 'application/zip' => 'application-zip', 'application/illustrator' => 'file-ai',
        'audio' => 'file-music', 'video' => 'movie', 'image' => 'photo', 'text/css' => 'text-css', 'text/html' => 'text-html',
        'text' => 'text-plain', 'application/sql' => 'text-plain', 'application/x-csh' => 'text-plain', 'application/x-sh' => 'text-plain',
        'text/x-c' => 'text-x-c', 'text/x-c++hdr' => 'text-x-c', 'text/x-c++src' => 'text-x-c', 'text/x-csharp' => 'text-x-csharp',
        'text/x-java' => 'text-x-java', 'text/x-java-source' => 'text-x-java', 'text/x-php' => 'text-x-php', 'text/x-ruby' => 'text-x-ruby',
        'text/xml' => 'text-xml', 'application/xml' => 'text-xml',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'file-type-docx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'file-type-ppt',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'file-type-xls',
    ];

    /** Redmine's icon_for_mime_type (sprite name) */
    public function mimeIcon(?string $contentType, ?string $filename = null): string
    {
        $mime = (string) $contentType;
        if (null !== $filename && ('' === $mime || 'application/octet-stream' === $mime)) {
            $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'txt', 'log', 'md', 'textile', 'csv' => 'text/plain', 'png', 'gif', 'jpg', 'jpeg', 'webp', 'bmp', 'svg' => 'image/'.$ext,
                'pdf' => 'application/pdf', 'zip' => 'application/zip', 'gz', 'tgz' => 'application/gzip', 'html', 'htm' => 'text/html',
                'css' => 'text/css', 'js' => 'application/javascript', 'xml' => 'application/xml', 'php' => 'text/x-php', 'rb' => 'text/x-ruby',
                'c', 'h', 'cpp' => 'text/x-c', 'java' => 'text/x-java', 'sql' => 'application/sql', 'sh' => 'application/x-sh',
                default => $mime,
            };
        }

        return self::MIME_ICONS[$mime] ?? self::MIME_ICONS[explode('/', $mime)[0]] ?? 'file';
    }
}
