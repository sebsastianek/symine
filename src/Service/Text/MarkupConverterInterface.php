<?php

declare(strict_types=1);

namespace App\Service\Text;

/**
 * Converts the markup of a text to HTML (Redmine::WikiFormatting formatters: Textile, CommonMark).
 *
 * This is the conversion alone: the result depends on the formatting and on the text and on nothing else — not
 * on who reads it, not on the project, not on the time. What does depend on the reader (sanitisation context,
 * attachments, macros, links to issues and pages the reader may see) is done by TextFormatter on the result,
 * every time. The output is not safe to send to a browser as it is.
 *
 * Extension point: decorate this service to cache the conversion (CachedMarkupConverter does) or to add to a
 * markup language (docs/PLUGINS.md, "Decorating core services").
 */
interface MarkupConverterInterface
{
    public const string TEXTILE = 'textile';
    public const string COMMON_MARK = 'common_mark';

    /** @param self::TEXTILE|self::COMMON_MARK $formatting */
    public function toHtml(string $formatting, string $text): string;
}
