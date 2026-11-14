<?php

declare(strict_types=1);

namespace App\Service\Content\Attachments;

use App\Entity\Attachment;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\Scm\UnifiedDiff;
use App\Service\Settings;
use App\Service\TextFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the page of an attachment shows (attachments#show): the file as a diff, an image, text, rendered
 * markup, a video, an audio or a PDF player depending on what it is, with its neighbours in the container.
 */
final class AttachmentPreview
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly AttachmentContainers $containers,
        private readonly Settings $settings,
        private readonly TextFormatter $formatter,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundHttpException when the file is missing
     */
    public function view(Attachment $a, Request $request): array
    {
        $path = $this->attachments->fullPath($a);
        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }
        $container = $this->containers->of($a);
        $siblings = $a->containerType && $a->containerId ? $this->attachments->forContainer((string) $a->containerType, $a->containerId) : [$a];
        $index = 0;
        foreach ($siblings as $i => $s) {
            if ($s->id === $a->id) {
                $index = $i;
            }
        }
        $kind = 'other';
        $content = null;
        $diff = null;
        $diffType = null;
        if (preg_match('/\.(patch|diff)$/i', $a->filename)) {
            // Attachment#is_diff?: unified diff viewer, inline or side by side (the choice is remembered)
            $kind = 'diff';
            $user = $this->currentUser->get();
            $diffType = (string) ($request->query->get('type') ?: ($user->isLogged() ? $user->getPref()->get('diff_type') : null) ?: 'inline');
            if (!\in_array($diffType, ['inline', 'sbs'], true)) {
                $diffType = 'inline';
            }
            if ($user->isLogged() && $diffType !== $user->getPref()->get('diff_type')) {
                $pref = $user->getPref();
                $pref->set('diff_type', $diffType);
                $this->em->persist($pref);
                $this->em->flush();
            }
            $raw = (string) file_get_contents($path);
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            }
            $diff = new UnifiedDiff($raw, $diffType, $this->settings->int('diff_max_lines_displayed') ?: null);
        } elseif ($a->isImage()) {
            $kind = 'image';
        } elseif ($a->isText() && $a->getFilesize() <= ($this->settings->int('file_max_size_displayed') ?: 512) * 1024) {
            $kind = preg_match('/\.(md|markdown)$/i', $a->filename) || preg_match('/\.textile$/i', $a->filename) ? 'markup' : 'file';
            $content = (string) file_get_contents($path);
            if (!mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
            }
        } elseif (preg_match('/\.(mp4|webm|ogv|mov)$/i', $a->filename)) {
            $kind = 'video';
        } elseif (preg_match('/\.(mp3|ogg|oga|wav|m4a|flac)$/i', $a->filename)) {
            $kind = 'audio';
        } elseif ($a->isPdf()) {
            $kind = 'pdf';
        }
        $markup = null;
        if ('markup' === $kind) {
            // a Markdown file is rendered as CommonMark and a Textile file as Textile, whatever the text formatting setting is
            $markup = $this->formatter->format($content, $this->containers->projectOf($a), ['formatting' => preg_match('/\.textile$/i', $a->filename) ? 'textile' : 'common_mark']);
        }

        return [
            'attachment' => $a,
            'project' => $this->containers->projectOf($a),
            'container' => $container,
            'container_link' => $this->containers->link($a),
            'kind' => $kind,
            'content_lines' => null !== $content ? preg_split('/\r\n|\r|\n/', $content) : [],
            'content' => $content,
            'markup' => $markup,
            'diff' => $diff,
            'diff_type' => $diffType,
            'siblings' => $siblings,
            'index' => $index,
            'main_menu_selected' => $this->containers->menuItem($a),
        ];
    }
}
