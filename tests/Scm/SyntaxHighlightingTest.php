<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Service\SyntaxHighlighting;
use App\Service\TextFormatter;
use App\Tests\Content\ContentWebTestCase;

/** Redmine::SyntaxHighlighting port (highlight.php with Rouge token classes) */
final class SyntaxHighlightingTest extends ContentWebTestCase
{
    private function highlighter(): SyntaxHighlighting
    {
        return static::getContainer()->get(SyntaxHighlighting::class);
    }

    public function testHighlightByFilenameIsLinewise(): void
    {
        $html = $this->highlighter()->highlightByFilename("class Foo\r\n  # a comment\n  def bar\n    \"x\"\n  end\nend\n\n/* x", 'lib/foo.rb');
        $lines = explode("\n", $html);
        self::assertSame('<span class="k">class</span> <span class="nc">Foo</span>', $lines[0]);
        self::assertSame('  <span class="c1"># a comment</span>', $lines[1]);
        self::assertSame('  <span class="k">def</span> <span class="nf">bar</span>', $lines[2]);
        self::assertSame('    <span class="s2">"x"</span>', $lines[3]);
        self::assertSame('', $lines[6], 'empty line');
        // every span is closed on its line
        foreach ($lines as $l) {
            self::assertSame(substr_count($l, '<span'), substr_count($l, '</span>'), $l);
        }
        // multi line comment split per line
        $c = explode("\n", $this->highlighter()->highlightByFilename("/* a\nb */\nint x = 0x1F;\n", 'a.c'));
        self::assertSame(['<span class="cm">/* a</span>', '<span class="cm">b */</span>'], \array_slice($c, 0, 2));
        self::assertStringContainsString('<span class="mh">0x1F</span>', $c[2]);
    }

    public function testLanguageGuess(): void
    {
        $h = $this->highlighter();
        self::assertSame('ruby', $h->languageForFilename('Rakefile'));
        self::assertSame('makefile', $h->languageForFilename('dir/Makefile'));
        self::assertSame('dockerfile', $h->languageForFilename('Dockerfile'));
        self::assertSame('php', $h->languageForFilename('index.php'));
        self::assertSame('python', $h->languageForFilename('script', "#!/usr/bin/env python3\nprint(1)"));
        self::assertNull($h->languageForFilename('notes.txt'));
        self::assertSame("a &lt;b&gt;\n", $h->highlightByFilename('a <b>', 'notes.txt'), 'plain text is escaped');
        self::assertTrue($h->filenameSupported('a.js'));
        self::assertFalse($h->filenameSupported('a.unknown'));
    }

    public function testLanguageAliases(): void
    {
        $h = $this->highlighter();
        foreach (['ruby', 'rb', 'c++', 'cplusplus', 'delphi', 'ecmascript', 'ecma_script', 'java_script', 'xhtml', 'html', 'shell', 'yml', 'text', 'csharp', 'c#', 'objc'] as $lang) {
            self::assertTrue($h->languageSupported($lang), $lang);
        }
        self::assertFalse($h->languageSupported('nosuchlang'));
        self::assertFalse($h->languageSupported('mermaid'));
        self::assertSame('<span class="k">int</span> x;', $h->highlightByLanguage('int x;', 'cplusplus'));
        self::assertSame('a &amp; b', $h->highlightByLanguage('a & b', 'nosuchlang'));
    }

    public function testCommonMarkCodeBlocks(): void
    {
        $f = static::getContainer()->get(TextFormatter::class);
        $html = $f->format("```ruby\ndef foo\n  \"x\" # c\nend\n```\n\n```nosuchlang\na < b\n```\n\n```\nplain\n```\n\n```mermaid\ngraph TD\n```\n");
        self::assertStringContainsString('<code class="ruby syntaxhl" data-language="ruby"><span class="k">def</span> <span class="nf">foo</span>', $html);
        self::assertStringContainsString('<span class="s2">"x"</span> <span class="c1"># c</span>', $html);
        self::assertStringContainsString('<code data-language="nosuchlang">a &lt; b', $html);
        self::assertStringContainsString('<code>plain', $html);
        self::assertStringContainsString('<code data-language="mermaid" data-controller="mermaid">', $html);
        // HTML in code blocks stays escaped
        $xss = $f->format("```ruby\n<script>alert(1)</script>\n```");
        self::assertStringNotContainsString('<script>', $xss);
    }

    public function testTextileCodeBlocks(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE settings SET value = 'textile' WHERE name = 'text_formatting'");
        static::getContainer()->get(\App\Service\Settings::class)->set('text_formatting', 'textile');
        $f = static::getContainer()->get(TextFormatter::class);
        $html = $f->format("<pre><code class=\"ruby\">\ndef foo; \"x\" < 3 end\n</code></pre>");
        self::assertStringContainsString('<pre data-clipboard-target="pre"><code class="ruby syntaxhl" data-language="ruby"><span class="k">def</span> <span class="nf">foo</span>', $html);
        self::assertStringStartsWith('<div class="pre-wrapper" data-controller="clipboard"><a class="copy-pre-content-link icon-only" title="Copy" data-action="clipboard#copyPre">', $html);
        self::assertStringContainsString('&lt; ', $html);
        $html = $f->format("<pre><code class=\"nosuch\">\n<b>x</b>\n</code></pre>");
        self::assertStringContainsString('<code data-language="nosuch">&lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function testHelpPageAndAttachmentPreview(): void
    {
        $this->get('/help/code_highlighting');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('<title>List of languages supported by Redmine code highlighter</title>', $this->html());
        self::assertStringContainsString('<td><code>ruby</code></td>', $this->html());
        self::assertMatchesRegularExpression('/\[aliases: [^\]]*\brb\b/', $this->html());
        $this->get('/help/wiki_syntax/detailed');
        self::assertStringContainsString('/help/code_highlighting', $this->html());

        // text attachment preview is highlighted by file name
        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative('SELECT id, disk_directory, disk_filename FROM attachments ORDER BY id LIMIT 1');
        $dir = static::getContainer()->getParameter('symine.files_path').'/'.$row['disk_directory'];
        @mkdir($dir, 0o777, true);
        file_put_contents($dir.'/test_hl.rb', "class Foo\nend\n");
        $conn->executeStatement("UPDATE attachments SET filename = 'foo.rb', disk_filename = 'test_hl.rb', filesize = 14, content_type = 'text/x-ruby', digest = '' WHERE id = ?", [$row['id']]);
        $this->login('admin');
        $this->get('/attachments/'.$row['id']);
        @unlink($dir.'/test_hl.rb');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('<div><span class="k">class</span> <span class="nc">Foo</span>', $this->html());
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
