<?php

declare(strict_types=1);

namespace App\Service\Text;

use App\Entity\Attachment;
use App\Entity\Project;

/** State of one TextFormatter::format() call (the formatter is re-entered by the include and collapse macros). */
final class RenderContext
{
    /** @var array<int, string> macros caught in the raw text: index => "{{macro(args)}}" as typed */
    public array $macros = [];
    public string $macroToken = '';
    /** @var list<string>|null output of macros hidden while section edit links are added (null: nothing is hidden) */
    public ?array $hiddenMacros = null;
    /** @var list<Attachment>|null */
    private ?array $attachments = null;

    /**
     * @param array<string, mixed>          $options
     * @param \Closure(): list<Attachment> $attachmentLoader
     */
    public function __construct(
        public readonly ?Project $project,
        public readonly ?object $object,
        public readonly array $options,
        public readonly bool $onlyPath,
        public readonly string $formatting,
        private readonly \Closure $attachmentLoader,
    ) {
    }

    /** @return list<Attachment> attachments of the formatted object, most recent first */
    public function attachments(): array
    {
        return $this->attachments ??= ($this->attachmentLoader)();
    }
}
