<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\WikiContent;
use App\Entity\WikiContentVersion;
use App\Entity\WikiPage;

/**
 * A wiki page as it is shown: its current content, or one of its versions when a version was asked.
 */
final class WikiPageRevision
{
    public function __construct(
        public readonly WikiPage $page,
        /** The content shown: the version when one was asked, the current content otherwise */
        public readonly WikiContent|WikiContentVersion $content,
        public readonly ?WikiContentVersion $version = null,
    ) {
    }

    /** Whether what is shown is the current content of the page */
    public function isCurrent(): bool
    {
        return null === $this->version || $this->version->version === $this->page->content->version;
    }
}
