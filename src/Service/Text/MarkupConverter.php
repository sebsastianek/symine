<?php

declare(strict_types=1);

namespace App\Service\Text;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** The formatters of Symine: Textile (TextileRenderer) and CommonMark (league/commonmark). */
#[AsAlias(MarkupConverterInterface::class)]
final class MarkupConverter implements MarkupConverterInterface
{
    private ?TextileRenderer $textile = null;
    private ?MarkdownConverter $markdown = null;

    public function toHtml(string $formatting, string $text): string
    {
        return match ($formatting) {
            self::TEXTILE => ($this->textile ??= new TextileRenderer())->render($text),
            self::COMMON_MARK => $this->markdown()->convert($text)->getContent(),
            default => throw new \InvalidArgumentException(sprintf('Unknown text formatting "%s".', $formatting)),
        };
    }

    /**
     * Same extensions as Redmine::WikiFormatting::CommonMark::PIPELINE_CONFIG: tables, strikethrough, autolinks,
     * task lists, footnotes (alerts are built by HtmlPipeline); raw HTML is allowed and sanitized afterwards;
     * a line break is a <br> (common_mark_enable_hardbreaks, true by default).
     */
    private function markdown(): MarkdownConverter
    {
        if (null === $this->markdown) {
            $environment = new Environment([
                'html_input' => 'allow',
                'allow_unsafe_links' => true,
                'renderer' => ['soft_break' => "<br />\n"],
                'footnote' => ['ref_id_prefix' => 'fnref-', 'footnote_id_prefix' => 'fn-', 'container_add_hr' => false, 'backref_symbol' => '↩'],
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new GithubFlavoredMarkdownExtension());
            $environment->addExtension(new FootnoteExtension());
            $this->markdown = new MarkdownConverter($environment);
        }

        return $this->markdown;
    }
}
