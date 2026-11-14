<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Entity\Attachment;
use App\Entity\Changeset;
use App\Entity\Project;
use App\Entity\Repository;
use App\Entity\User;
use App\Entity\Version;
use App\Service\AttachmentService;
use App\Service\Settings;

/**
 * Text rendering parity with Redmine (textilizable, Redmine::WikiFormatting::*): Redmine links, macros, sanitizer,
 * Textile and CommonMark specifics. The expected output comes from Redmine's own tests where they exist
 * (test/helpers/application_helper_test.rb, test/unit/lib/redmine/wiki_formatting/**).
 *
 * The text goes through the preview action (POST /issues/preview), i.e. the same TextFormatter as every page.
 */
final class TextRenderingParityTest extends ContentWebTestCase
{
    private ?string $token = null;
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
        parent::tearDown();
    }

    private function actAs(string $login): void
    {
        $this->login($login);
        $this->token = null;
    }

    private function textile(): void
    {
        static::getContainer()->get(Settings::class)->set('text_formatting', 'textile');
    }

    /** @param array<string, mixed> $params */
    private function render(string $text, array $params = [], string $project = 'ecommerce'): string
    {
        $this->token ??= $this->csrf();
        $this->client->request('POST', '/issues/preview?project_id='.$project, ['text' => $text, '_token' => $this->token] + $params);
        self::assertSame(200, $this->httpStatus());

        return trim((string) $this->client->getResponse()->getContent());
    }

    private static function squash(string $html): string
    {
        return (string) preg_replace('/\s+/', '', $html);
    }

    private function attach(?string $type, ?int $id, string $filename, string $content, string $mime, ?string $description = null): Attachment
    {
        $service = static::getContainer()->get(AttachmentService::class);
        $author = $this->em->getRepository(User::class)->findOneBy(['login' => 'admin']);
        $attachment = $service->createFromContent($content, $filename, $author, $mime, $description);
        if (null !== $type && null !== $id) {
            $service->attachTo([$attachment], $type, $id);
        }
        $this->em->flush();
        $this->files[] = $service->fullPath($attachment);

        return $attachment;
    }

    private static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }

    // ------------------------------------------------------------------------------------------
    // Security
    // ------------------------------------------------------------------------------------------

    /** Finding 7: Issue.visible in parse_redmine_links and in the issue macro (macros_test: test_issue_macro_should_not_render_link_if_not_visible) */
    public function testPrivateIssuesAreNotDisclosedByIssueLinks(): void
    {
        // #17 is a private issue of the public "checkout" project: author rhill, assigned to jsmith
        $this->actAs('dlopper');
        $html = $this->render('#17 ##17 {{issue(17)}} #13');
        self::assertStringNotContainsString('Payment fails on Safari', $html);
        self::assertStringNotContainsString('/issues/17', $html);
        self::assertStringContainsString('#17 ##17 #17 ', $html);
        self::assertStringContainsString('href="/issues/13"', $html);

        $this->actAs('jsmith');
        $html = $this->render('#17 ##17 {{issue(17)}}');
        self::assertStringContainsString('title="Bug: Payment fails on Safari (New)">#17</a>', $html);
        self::assertStringContainsString('>Bug #17: Payment fails on Safari</a>', $html);
        self::assertStringContainsString('>Bug #17</a>: Payment fails on Safari', $html);
    }

    /** Finding 8: SanitizationFilter allowlists (common_mark formatter_test / sanitization_filter_test) */
    public function testCommonMarkSanitizer(): void
    {
        $this->actAs('admin');
        // test_should_support_html_tables
        self::assertSame('<table><tbody><tr><td>Cell</td></tr></tbody></table>', $this->render('<table style="position: fixed"><tr><td>Cell</td></tr></table>'));
        // test_should_remove_unsafe_uris
        self::assertSame('<img>', $this->render('<img src="data:foobar">'));
        self::assertSame('<p><a>click me</a></p>', $this->render('<a href="javascript:bla">click me</a>'));
        // test_should_sanitize_attributes
        self::assertSame('<p><a href="foo">link</a></p>', $this->render('<a href="foo" onclick="bar" baz="foo">link</a>'));
        // test_should_escape_unwanted_tags
        self::assertSame(
            '<p>sit<br>amet &lt;style&gt;.foo { color: #fff; }&lt;/style&gt; &lt;script&gt;alert("hello world");&lt;/script&gt;</p>',
            $this->render('sit<br/>amet <style>.foo { color: #fff; }</style> <script>alert("hello world");</script>')
        );
        // a full page overlay: only the harmless CSS properties survive, classes and ids are removed
        $html = $this->render('<div id="content" class="flash error" style="position:fixed;top:0;left:0;z-index:9999;width:100%;color:red">Session expired</div>');
        self::assertSame('<div style="width:100%;color:red;">Session expired</div>', $html);
        $html = $this->render('<span class="icon icon-del" style="background-image: url(http://x/y.png); color: red">x</span> <video src="x"></video> <a href="/x" id="main" name="n">t</a>');
        self::assertSame('<p><span style="color: red;">x</span>  <a href="/x" name="n">t</a></p>', $html);
        // test_should_allow_class_on_code_only / test_should_remove_invalid_ids
        self::assertSame('<p><code>x</code></p>', $this->render('<code class="foo">x</code>'));
        self::assertSame('<ul><li>y</li></ul>', $this->render('<ul><li id="foo" class="bar">y</li></ul>'));
    }

    /**
     * Redmine's pre_wrapper test helper (CopypreScrubber): every <pre> block of $html in its wrapper with the copy button
     */
    private static function preWrapper(string $html): string
    {
        $html = (string) preg_replace('/<pre(?=[ >])/', '<div class="pre-wrapper" data-controller="clipboard"><a class="copy-pre-content-link icon-only" title="Copy" data-action="clipboard#copyPre">'
            .'<svg class="s18 icon-svg" aria-hidden="true"><use href="/assets/icons.svg#icon--copy-pre-content"></use></svg></a><pre data-clipboard-target="pre"', $html);

        return str_replace('</pre>', '</pre></div>', $html);
    }

    private static function squashBetweenTags(string $html): string
    {
        return (string) preg_replace('/>\s+</', '><', str_replace("\n", '', $html));
    }

    /** Finding 8, Textile: raw HTML is escaped, styles are filtered, classes and ids are prefixed (textile_formatter_test) */
    public function testTextileSanitizer(): void
    {
        $this->actAs('admin');
        $this->textile();
        // test_escaping, test_kbd
        self::assertSame('<p>this is a &lt;script&gt;</p>', $this->render('this is a <script>'));
        self::assertSame('<kbd>test</kbd>', $this->render('<kbd>test</kbd>'));
        // test_styles, test_invalid_styles_should_be_filtered
        self::assertSame('<p style="color:red;">text</p>', $this->render('p{color:red}. text'));
        self::assertSame('<p style="color:red;">text</p>', $this->render('p{color:red;invalid:blue}. text'));
        self::assertSame('<p>text</p>', $this->render('p{invalid:red}. text'));
        self::assertSame('<p>text</p>', $this->render('p{position:fixed;top:0}. text'));
        // test_should_prefix_class_attribute_on_tags, test_should_prefix_id_attribute_on_tags
        self::assertSame('<p class="wiki-class-foo">test</p>', $this->render('p(foo). test'));
        self::assertSame('<p id="wiki-id-foo">test</p>', $this->render('p(#foo). test'));
        self::assertSame('<p><span class="wiki-class-foo">test</span></p>', $this->render('%(foo)test%'));
        // raw HTML is displayed, not interpreted
        $html = $this->render('<div style="position:fixed">x</div> <b>bold</b> <img src="x" onerror="alert(1)">');
        self::assertStringNotContainsString('<div', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        // test_should_preserve_code_language_class_attribute_in_data_language, test_should_not_allow_xss_in_code_class_attribute
        self::assertSame('<code data-language="foolang">unsupported language</code>', $this->render('<code class="foolang">unsupported language</code>'));
        $html = $this->render('<pre><code class="unknownlang"><script>alert(\'XSS-3\')</script></code></pre>');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /** Finding 20: a wiki link into a project the user cannot see stays text (link_project.wiki && allowed_to?(:view_wiki_pages)) */
    public function testWikiLinksDoNotLeakPrivateProjects(): void
    {
        $this->actAs('dlopper');
        self::assertSame('<p>[[internal:Wiki]] [[internal:]]</p>', $this->render('[[internal:Wiki]] [[internal:]]'));
        self::assertStringStartsWith('<p>project:internal project#2 <a href="/versions/1"', $this->render('project:internal project#2 version#1'));
    }

    // ------------------------------------------------------------------------------------------
    // Broken output
    // ------------------------------------------------------------------------------------------

    /** Finding 9: the text after an unknown tag is kept (sanitization_filter_test: test_should_filter_tags; textile: test_escaping) */
    public function testTextAfterUnknownTagsIsKept(): void
    {
        $this->actAs('admin');
        self::assertSame('<p>Generic List items and more, end of sentence. dont blink</p>', $this->render('Generic List<String> items and more, end of sentence. <blink>dont blink</blink>'));

        $this->textile();
        self::assertSame(
            '<p>Generic List&lt;String&gt; items, use &lt;Location /redmine&gt; in apache conf, then restart</p>',
            $this->render('Generic List<String> items, use <Location /redmine> in apache conf, then restart')
        );
        // test_inline_code
        self::assertSame('<p><code>&lt;Location /redmine&gt;</code></p>', $this->render('@<Location /redmine>@'));
        // test_pre_tags: nothing is interpreted in a <pre>, blank lines included, and the block ends the paragraph
        self::assertSame(
            self::preWrapper('<p>before</p><pre>block *x* &lt;b&gt; #13still pre</pre><p>after</p>'),
            self::squashBetweenTags($this->render("before\n<pre>\nblock *x* <b> #13\n\nstill pre</pre>\nafter"))
        );
        // test_should_escape_tags_that_start_with_pre
        self::assertSame('<p>&lt;preä demo&gt;Text</p>', $this->render('<preä demo>Text'));
    }

    /** Finding 3: @login (application_helper_test: test_link_to_mention, LINKS_RE sep4) */
    public function testMentionsAreLinked(): void
    {
        $this->actAs('admin');
        $html = $this->render('@jsmith hello [@dlopper] and @nobody and user:JSMITH and user#5');
        self::assertStringContainsString('<a href="/users/5" class="user active user-mention">@John Smith</a> hello', $html);
        self::assertStringContainsString('[<a href="/users/6" class="user active user-mention">@Dave Lopper</a>]', $html);
        self::assertStringContainsString('and @nobody and', $html);
        // User.visible.find_by_login(name.downcase)
        self::assertStringContainsString('and <a href="/users/5" class="user active">John Smith</a> and <a href="/users/5" class="user active">John Smith</a>', $html);
        // formatter_test: test_links_to_users_should_be_preserved, test_files_with_at_should_not_end_up_as_mailto_links
        self::assertSame('<p>@user@example.org</p>', $this->render('@user@example.org'));
        self::assertSame('<p>user:user@example.org</p>', $this->render('user:user@example.org'));
        self::assertSame('<p>printscreen@2x.png</p>', $this->render('printscreen@2x.png'));
        // a locked user is linked for an administrator only, with its own class (link_to_principal)
        self::assertStringContainsString('class="user locked"', $this->render('user#8'));

        $this->textile();
        $html = $this->render('@jsmith and @some code@ and user@example.org and @user@example.org');
        self::assertStringContainsString('<a href="/users/5" class="user active user-mention">@John Smith</a> and <code>some code</code>', $html);
        self::assertStringContainsString('<a class="email" href="mailto:user@example.org">user@example.org</a> and @user@example.org', $html);

        $this->actAs('dlopper');
        self::assertSame('<p>user#8</p>', $this->render('user#8'));
    }

    /** Finding 2: SyntaxHighlightScrubber (application_helper_test: test_syntax_highlight, test_syntax_highlight_common_mark) */
    public function testSyntaxHighlighting(): void
    {
        $this->actAs('admin');
        foreach (['ruby' => "def foo\nend", 'php' => '<?php echo "x";', 'python' => "def f():\n  return 1", 'javascript' => 'var x = 1;', 'sql' => 'SELECT 1;', 'java' => 'class A {}'] as $lang => $code) {
            $html = $this->render("```$lang\n$code\n```");
            self::assertStringContainsString('<pre data-clipboard-target="pre"><code class="'.$lang.' syntaxhl" data-language="'.$lang.'">', $html, $lang);
            self::assertStringContainsString('<span class="', $html, $lang);
        }
        self::assertStringContainsString('<span class="k">def</span>', $this->render("```ruby\ndef foo\nend\n```"));
        // syntax_highlight_scrubber_test: unknown language, mermaid, links and macros are left alone in code
        self::assertSame(self::preWrapper("<pre><code data-language=\"foolang\">x &lt; y\n</code></pre>"), $this->render("```foolang\nx < y\n```"));
        self::assertStringContainsString('<code data-language="mermaid" data-controller="mermaid">', $this->render("```mermaid\ngraph TD; A-->B;\n```"));
        self::assertStringContainsString('#13 [[Wiki]]', $this->render("```\n#13 [[Wiki]]\n```"));

        $this->textile();
        $html = $this->render("<pre><code class=\"ruby\">\ndef foo\nend\n</code></pre>");
        self::assertStringStartsWith(self::preWrapper('<pre><code class="ruby syntaxhl" data-language="ruby"><span class="k">def</span>'), $html);
        // test_should_allow_valid_language_class_attribute_on_code_tags
        self::assertStringContainsString('<code class="ruby syntaxhl" data-language="ruby">', $this->render("<code class='ruby'>test</code>"));
    }

    /** Finding 12: "#13 text" is not a list, no typographic glyphs (textile_formatter_test: test_double_dashes_should_not_strikethrough) */
    public function testTextileListsAndGlyphs(): void
    {
        $this->actAs('admin');
        $this->textile();
        $html = $this->render('#13 at line start');
        self::assertStringStartsWith('<p><a href="/issues/13"', $html);
        self::assertStringEndsWith('>#13</a> at line start</p>', $html);
        self::assertStringContainsString('<li>13 is a list item</li>', $this->render('# 13 is a list item'));
        self::assertSame('<p>double -- dashes -- test</p>', $this->render('double -- dashes -- test'));
        self::assertSame('<p>"quoted" text... (c) 2x3 it\'s SOME_CONSTANT</p>', $this->render('"quoted" text... (c) 2x3 it\'s SOME_CONSTANT'));
        // test_abbreviations
        self::assertSame('<p>this is an abbreviation: <abbr title="General Public License">GPL</abbr></p>', $this->render('this is an abbreviation: GPL(General Public License)'));
        // markdown_should_not_require_surrounded_empty_line: same for a Textile list
        // a block that contains a list is not a paragraph (RedCloth3#blocks)
        self::assertSame('This is a list:<ul><li>One</li><li>Two</li></ul>', self::squashBetweenTags($this->render("This is a list:\n* One\n* Two")));
    }

    /** Finding 14: macros_test */
    public function testMacros(): void
    {
        $this->actAs('admin');
        // test_macros_should_not_be_executed_in_pre_tags
        $html = $this->render("```\n{{issue(13)}} {{hello_world}}\n```\n\n`{{issue(13)}}` inline");
        self::assertStringContainsString(self::preWrapper("<pre><code>{{issue(13)}} {{hello_world}}\n</code></pre>"), $html);
        self::assertStringContainsString('<code>{{issue(13)}}</code> inline', $html);
        self::assertStringNotContainsString('<a href', $html);
        // test_exclamation_mark_should_not_run_macros, test_exclamation_mark_should_escape_macros
        self::assertSame('<p>{{hello_world}}</p>', $this->render('!{{hello_world}}'));
        self::assertSame('<p>{{issue(13)}}</p>', $this->render('!{{issue(13)}}'));
        // test_unknown_macros_should_not_be_replaced
        self::assertSame('<p>{{unknown}}</p>', $this->render('{{unknown}}'));
        // test_macro_hello_world, test_macro_hello_world_should_escape_arguments, test_macro_macro_list
        self::assertStringContainsString('Hello world! Object: NilClass, Called with no argument and no block of text.', $this->render('{{hello_world}}'));
        self::assertStringContainsString('Arguments: &lt;tag&gt;', $this->render('{{hello_world(<tag>)}}'));
        $list = $this->render('{{macro_list}}');
        self::assertMatchesRegularExpression('#<code>hello_world</code>#', $list);
        self::assertStringContainsString('<dt><code>child_pages</code></dt>', $list);
        // test_issue_macro_should_render_link_to_issue
        self::assertMatchesRegularExpression('#^<p><a href="/issues/13" class="issue [^"]*">Bug \#13</a>: Cart total is wrong with discounts</p>$#', $this->render('{{issue(13)}}'));
        self::assertMatchesRegularExpression('#^<p>eCommerce - <a href="/issues/13" class="issue [^"]*">Bug \#13</a>: Cart total is wrong with discounts</p>$#', $this->render('{{issue(13, project=true)}}'));
        self::assertMatchesRegularExpression('#^<p><a href="/issues/13" class="issue [^"]*">\#13</a>: Cart total is wrong with discounts</p>$#', $this->render('{{issue(13, tracker=false)}}'));
        self::assertMatchesRegularExpression('#^<p>eCommerce - <a href="/issues/13" class="issue [^"]*" title="Cart total is wrong with discounts">Bug \#13</a></p>$#', $this->render('{{issue(13, subject=false, project=true)}}'));
        self::assertSame('<p>#999</p>', $this->render('{{issue(999)}}'));
        // a macro that does not accept a block of text
        self::assertStringContainsString('This macro does not accept a block of text', $this->render("{{issue(13)\ntext\n}}"));

        $this->textile();
        // test_exclamation_mark_should_escape_macros
        self::assertSame('<p>{{hello_world(&lt;tag&gt;)}}</p>', $this->render('!{{hello_world(<tag>)}}'));
        $html = $this->render("<pre>\n{{hello_world}}\n</pre>");
        self::assertStringContainsString('{{hello_world}}', $html);
        self::assertStringNotContainsString('Hello world', $html);
    }

    /**
     * Macros run after the HTML is sanitized: one written inside an attribute is shown, not run, or its (empty) output
     * would turn a harmless address into a script (href="java{{macro}}script:...").
     */
    public function testMacrosAreNotRunInsideAttributes(): void
    {
        $this->actAs('admin');
        // a page without children: the macro outputs nothing, which is what would leave "javascript:" behind
        foreach (['{{child_pages(Checkout_flow)}}', '{{child_pages(Wiki)}}', '{{macro_list}}', '{{hello_world}}'] as $macro) {
            $html = $this->render('<a href="java'.$macro.'script:alert(1)">click</a> and <a href="/ok" title="'.$macro.'">t</a>');
            self::assertDoesNotMatchRegularExpression('~href="\s*javascript:~i', $html, $macro);
            self::assertStringNotContainsString('xmacro', $html, $macro);
            self::assertStringNotContainsString('<div', $html, 'no macro output inside a tag: '.$macro);
            $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html.'</body>', \LIBXML_NOERROR, 'UTF-8');
            foreach ($doc->querySelectorAll('a') as $a) {
                self::assertDoesNotMatchRegularExpression('~^\s*javascript:~i', (string) $a->getAttribute('href'), $macro);
            }
            self::assertSame($macro, $doc->querySelectorAll('a')[1]?->getAttribute('title'), 'the text of the macro is kept');
        }
        $this->textile();
        $html = $this->render('"click":java{{hello_world}}script:alert(1) and !{{hello_world}}');
        self::assertDoesNotMatchRegularExpression('~href="\s*javascript:~i', $html);
    }

    /** Finding 15: the headings of an included page are not sections of the page (hide_macro_output) */
    public function testSectionEditLinksIgnoreIncludedPages(): void
    {
        $this->actAs('admin');
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/IncPage', ['content' => ['text' => "# Included title\n\n## Included sub\n\ninc body"]], 'PUT'));
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/HostPage', ['content' => ['text' => "{{toc}}\n\n# Host title\n\n{{include(IncPage)}}\n\n## Host second\n\nsecond body\n\n## Host third\n\nthird body"]], 'PUT'));
        $html = $this->get('/projects/ecommerce/wiki/HostPage');
        preg_match_all('#edit\?section=(\d+)"[^>]*>.*?</div><a name="([^"]*)"#s', $html, $m, \PREG_SET_ORDER);
        self::assertSame([['2', 'Host-second'], ['3', 'Host-third']], array_map(static fn (array $x): array => [$x[1], $x[2]], $m));
        // the included headings are displayed, without edit links, and listed in the table of contents
        self::assertStringContainsString('<h1>Included title<a href="#Included-title" class="wiki-anchor">', $html);
        self::assertStringContainsString('<a href="#Included-sub">Included sub</a>', $html);
        $edit = $this->get('/projects/ecommerce/wiki/HostPage/edit?section=2');
        self::assertStringContainsString("## Host second\n\nsecond body</textarea>", $edit);
    }

    // ------------------------------------------------------------------------------------------
    // Missing features
    // ------------------------------------------------------------------------------------------

    /** Findings 1 and 4: InlineAttachmentsScrubber, attachment: links, {{thumbnail}} on any object (application_helper_test: test_attached_images, test_attachment_links) */
    public function testInlineAttachmentImagesAttachmentLinksAndThumbnails(): void
    {
        $this->actAs('admin');
        $image = $this->attach('Issue', 13, 'Red.png', self::png(), 'image/png', 'This is a "screenshot"');
        $this->attach('Issue', 13, 'logo@2x.png', self::png(), 'image/png');
        $params = ['issue_id' => '13'];
        $src = '/attachments/download/'.$image->id.'/Red.png';

        // the file name is not case sensitive, the description is the title unless the image has an alternate text
        self::assertSame('<p><img src="'.$src.'" alt="This is a screenshot" title="This is a screenshot" loading="lazy"></p>', $this->render('![](red.png)', $params));
        self::assertSame('<p><img src="'.$src.'" alt="shot" loading="lazy"></p>', $this->render('![shot](Red.png)', $params));
        // images that are not attachments are left alone
        self::assertSame('<p><img src="other.png" alt=""> <img src="http://example.com/red.png" alt=""></p>', $this->render('![](other.png) ![](http://example.com/red.png)', $params));
        // test_textilizable_should_apply_hires_images_scrubber_in_common_mark
        $html = $this->render('![](logo@2x.png)', $params);
        self::assertStringContainsString('srcset="/attachments/download/', $html);
        self::assertStringContainsString(' 2x"', $html);

        // test_attachment_links
        self::assertSame('<p><a href="/attachments/'.$image->id.'/Red.png" class="attachment">Red.png</a> and attachment:missing.zip</p>', $this->render('attachment:red.png and attachment:missing.zip', $params));
        self::assertStringContainsString('<a href="/attachments/1/notes.txt" class="attachment">notes.txt</a>', $this->render('attachment:"notes.txt"', $params));

        // test_macro_thumbnail, test_macro_thumbnail_with_size, test_macro_thumbnail_with_title
        self::assertStringContainsString(
            '<a class="thumbnail" title="Red.png (This is a &quot;screenshot&quot;)" href="/attachments/'.$image->id.'"><img alt="Red.png" src="/attachments/thumbnail/'.$image->id.'/200"',
            $this->render('{{thumbnail(red.png)}}', $params)
        );
        self::assertStringContainsString('title="Cool" href="/attachments/'.$image->id.'"><img alt="Red.png" src="/attachments/thumbnail/'.$image->id.'/400"', $this->render('{{thumbnail(red.png, size=400, title=Cool)}}', $params));
        self::assertStringContainsString('Attachment nope.png not found', $this->render('{{thumbnail(nope.png)}}', $params));

        // without the object there is nothing to resolve
        self::assertSame('<p><img src="red.png" alt=""></p>', $this->render('![](red.png)'));

        // the issue page: description and notes use the attachments of the issue
        self::assertSame(302, $this->submit('/issues/13', ['_method' => 'patch', 'issue' => ['notes' => 'Note ![](red.png) attachment:red.png {{thumbnail(red.png)}}']]));
        $page = $this->get('/issues/13');
        self::assertStringContainsString('Note <img src="'.$src.'" alt="This is a screenshot" title="This is a screenshot" loading="lazy"> <a href="/attachments/'.$image->id.'/Red.png" class="attachment">Red.png</a> <a class="thumbnail"', $page);

        // files uploaded in the form that is previewed (ApplicationController#find_attachments)
        $pending = $this->attach(null, null, 'pending.png', self::png(), 'image/png');
        $token = static::getContainer()->get(AttachmentService::class)->token($pending);
        self::assertStringContainsString('<img src="/attachments/download/'.$pending->id.'/pending.png"', $this->render('![](pending.png)', ['attachments' => ['1' => ['token' => $token]]]));

        $this->textile();
        self::assertStringContainsString('src="'.$src.'"', $this->render('!red.png!', $params));
        // test_textilizable_should_apply_hires_images_scrubber_in_textile
        self::assertStringContainsString(' 2x"', $this->render('!logo@2x.png!', $params));
        self::assertStringContainsString('class="attachment">Red.png</a>', $this->render('attachment:red.png', $params));
    }

    /** Finding 5: r52, commit:abc, source:file, export:file (application_helper_test: test_redmine_links, test_multiple_repositories_redmine_links, test_redmine_links_git_commit) */
    public function testRepositoryLinks(): void
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $repositories = [];
        foreach (['' => true, 'svn2' => false] as $identifier => $default) {
            $repository = new Repository();
            $repository->project = $project;
            $repository->url = 'file:///nowhere/'.$identifier;
            $repository->rootUrl = $repository->url;
            $repository->type = 'Repository::Subversion';
            $repository->identifier = $identifier;
            $repository->isDefault = $default;
            $repository->createdOn = new \DateTimeImmutable();
            $this->em->persist($repository);
            $changeset = new Changeset();
            $changeset->repository = $repository;
            $changeset->revision = '5';
            $changeset->scmid = '' === $identifier ? 'abc123def456' : 'fed987';
            $changeset->committer = 'jsmith';
            $changeset->committedOn = new \DateTimeImmutable();
            $changeset->commitDate = new \DateTimeImmutable();
            $changeset->comments = "My very first commit do not escaping #<>&\nsecond line";
            $this->em->persist($changeset);
            $repositories[$identifier] = $repository;
        }
        $this->em->flush();
        $base = '/projects/ecommerce/repository/'.$repositories['']->id;
        $title = 'title="My very first commit do not escaping #&lt;&gt;&amp; second line"';

        $this->actAs('admin');
        self::assertSame('<p><a href="'.$base.'/revisions/5" class="changeset" '.$title.'>r5</a> r6 <a href="'.$base.'/revisions/5" class="changeset" '.$title.'>ecommerce:r5</a> r5</p>', $this->render('r5 r6 ecommerce:r5 !r5'));
        self::assertSame('<p><a href="'.$base.'/revisions/abc123def456" class="changeset" '.$title.'>abc123</a> commit:zzz</p>', $this->render('commit:abc123 commit:zzz'));
        self::assertSame('<p><a href="/projects/ecommerce/repository/svn2/revisions/5" class="changeset" '.$title.'>svn2|r5</a> <a href="/projects/ecommerce/repository/svn2/revisions/fed987" class="changeset" '.$title.'>svn2|fed</a></p>', $this->render('svn2|r5 commit:svn2|fed'));
        self::assertSame('<p><a href="'.$base.'/entry/some/file" class="source">source:/some/file</a></p>', $this->render('source:/some/file'));
        self::assertSame('<p><a href="'.$base.'/revisions/52/entry/some/file" class="source">source:/some/file@52</a></p>', $this->render('source:/some/file@52'));
        self::assertSame('<p><a href="'.$base.'/revisions/52/entry/some/file#L110" class="source">source:/some/file@52#L110</a></p>', $this->render('source:/some/file@52#L110'));
        self::assertSame('<p><a href="'.$base.'/entry/some/file%20with%20spaces" class="source">source:/some/file with spaces</a></p>', $this->render('source:"/some/file with spaces"'));
        self::assertSame('<p><a href="'.$base.'/raw/some/file" class="source download">export:/some/file</a></p>', $this->render('export:/some/file'));
        self::assertSame('<p><a href="/projects/ecommerce/repository/svn2/entry/some/file" class="source">source:svn2|some/file</a></p>', $this->render('source:svn2|some/file'));
        // the links of another project, from a project without repository
        self::assertStringContainsString('class="changeset" '.$title.'>ecommerce:r5</a> r5 source:x', $this->render('ecommerce:r5 r5 source:x', [], 'checkout'));

        // Changeset.visible, browse_repository: nothing is linked without the permissions (here: module disabled)
        $this->em->getConnection()->executeStatement("DELETE FROM enabled_modules WHERE project_id = ? AND name = 'repository'", [$project->id]);
        $this->em->clear();
        $this->actAs('jsmith');
        self::assertSame('<p>r5 commit:abc123 source:/some/file export:/some/file</p>', $this->render('r5 commit:abc123 source:/some/file export:/some/file'));
    }

    /** Finding 6: document:, news:, forum:, quoted names, links to another project (application_helper_test: test_redmine_links, test_cross_project_redmine_links) */
    public function testNameBasedAndCrossProjectLinks(): void
    {
        $checkout = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'checkout']);
        $version = new Version();
        $version->project = $checkout;
        $version->name = 'CO 2.0 beta';
        $this->em->persist($version);
        $this->em->flush();

        $this->actAs('admin');
        self::assertSame('<p><a href="/documents/1" class="document">Specs</a> <a href="/documents/1" class="document">Specs</a> <a href="/documents/1" class="document">Specs</a> document:Nope</p>', $this->render('document#1 document:Specs document:"Specs" document:Nope'));
        self::assertSame('<p><a href="/news/1" class="news">Release 0.9</a> <a href="/news/1" class="news">Release 0.9</a> news:"Nope"</p>', $this->render('news#1 news:"Release 0.9" news:"Nope"'));
        self::assertSame('<p><a href="/projects/ecommerce/boards/1" class="board">General</a> <a href="/projects/ecommerce/boards/1" class="board">General</a> forum:Nope</p>', $this->render('forum#1 forum:General forum:Nope'));
        self::assertSame('<p><a href="/boards/1/topics/1" class="message">Hello</a> <a href="/boards/1/topics/1?r=2#message-2" class="message">RE: Hello</a></p>', $this->render('message#1 message#2'));
        self::assertSame('<p><a href="/versions/1" class="version">1.0</a> <a href="/versions/1" class="version">1.0</a> <a href="/versions/1" class="version">1.0</a> ok</p>', $this->render('version#1 version:1.0 version:"1.0" ok'));
        self::assertSame('<p><a href="/projects/ecommerce" class="project">eCommerce</a> <a href="/projects/ecommerce" class="project">eCommerce</a> <a href="/projects/ecommerce" class="project">eCommerce</a></p>', $this->render('project#1 project:ecommerce project:"eCommerce"'));
        // formatter_test: test_redmine_links_with_double_quotes_should_be_preserved; a name with spaces
        self::assertSame('<p><a href="/versions/'.$version->id.'" class="version">CO 2.0 beta</a></p>', $this->render('version:"CO 2.0 beta"', [], 'checkout'));

        // test_cross_project_redmine_links: the project prefix selects the project, an unknown or invisible one gives no link
        self::assertSame('<p><a href="/versions/'.$version->id.'" class="version">CO 2.0 beta</a></p>', $this->render('checkout:version:"CO 2.0 beta"'));
        self::assertSame('<p>version:"CO 2.0 beta" checkout:version:0.9 nosuchproject:version:1.0 checkout:document:Specs</p>', $this->render('version:"CO 2.0 beta" checkout:version:0.9 nosuchproject:version:1.0 checkout:document:Specs'));
        self::assertStringContainsString('<a href="/documents/1" class="document">Specs</a>', $this->render('ecommerce:document:Specs', [], 'checkout'));

        $this->textile();
        // textile_formatter_test: test_restore_redmine_links_restores_quoted_links_in_text
        self::assertSame('<p><a href="/versions/1" class="version">1.0</a> <a href="/documents/1" class="document">Specs</a> <a href="/news/1" class="news">Release 0.9</a></p>', $this->render('version:"1.0" document:"Specs" news:"Release 0.9"'));

        $this->actAs('dlopper');
        self::assertSame('<p>internal:version:1.0 internal:document:Specs</p>', $this->render('internal:version:1.0 internal:document:Specs'));
    }

    /** Finding 13: #52-3, #52#note-3, ##52-3, #note-3 (application_helper_test: test_redmine_links, test_link_to_note_within_the_same_page) */
    public function testIssueNoteLinks(): void
    {
        $this->actAs('admin');
        $html = $this->render('#13 ##13 #13-2 #13#note-2 ##13-2 #note-3 !#13 #999 #0013 (#19)');
        $class = 'class="issue tracker-1 status-2 [^"]*"';
        self::assertMatchesRegularExpression('@^<p><a href="/issues/13" '.$class.' title="Bug: Cart total is wrong with discounts \(In Progress\)">#13</a> '
            .'<a href="/issues/13" '.$class.' title="Status: In Progress">Bug #13: Cart total is wrong with discounts</a> '
            .'<a href="/issues/13#note-2" '.$class.' title="Bug: Cart total is wrong with discounts \(In Progress\)">#13-2</a> '
            .'<a href="/issues/13#note-2" '.$class.' title="Bug: Cart total is wrong with discounts \(In Progress\)">#13#note-2</a> '
            .'<a href="/issues/13#note-2" '.$class.' title="Status: In Progress">Bug #13-2: Cart total is wrong with discounts</a> '
            .'<a href="#note-3">#note-3</a> #13 #999 #0013 \(<a href="/issues/19" class="issue tracker-3 status-5 [^"]*closed[^"]*" title="Support: Customer cannot login \(Closed\)">#19</a>\)</p>$@', $html);
    }

    /** Finding 10: block_textile_quotes (textile_formatter_test: test_blockquote) */
    public function testTextileBlockQuotes(): void
    {
        $this->actAs('admin');
        $this->textile();
        self::assertSame('<p>Dave wrote:</p><blockquote><p>first quoted line<br>second quoted line</p></blockquote><p>My reply</p>',
            self::squashBetweenTags($this->render("Dave wrote:\n> first quoted line\n> second quoted line\n\nMy reply")));
        $html = self::squashBetweenTags($this->render("John said:\n> Lorem ipsum dolor sit amet.\n> Nullam commodo metus accumsan nulla.\n> * Donec odio lorem,\n> * sagittis ac,\n>\n> >Nulla varius pulvinar diam.\n> Proin a tellus. Nam vel neque.\n\nHe's right."));
        self::assertStringNotContainsString('&gt;', $html);
        self::assertStringStartsWith('<p>John said:</p><blockquote>Lorem ipsum dolor sit amet.<br>Nullam commodo metus accumsan nulla.<ul><li>Donec odio lorem,</li><li>sagittis ac,</li></ul><blockquote><p>Nulla varius pulvinar diam.</p></blockquote><p>Proin a tellus. Nam vel neque.</p></blockquote>', $html);
        self::assertStringEndsWith("<p>He's right.</p>", $html);
        // links are resolved in a quote, a quote does not start in the middle of a line
        self::assertStringContainsString('<blockquote><p><a href="/issues/13"', self::squashBetweenTags($this->render('> #13 is fixed')));
        self::assertSame('<p>a &gt; b</p>', $this->render('a > b'));
    }

    /** Finding 11: auto_link!, auto_mailto! (application_helper_test: test_auto_links, test_auto_mailto) */
    public function testTextileAutoLinks(): void
    {
        $this->actAs('admin');
        $this->textile();
        $expected = [
            'http://foo.bar' => '<a class="external" href="http://foo.bar">http://foo.bar</a>',
            'http://foo.bar/~user' => '<a class="external" href="http://foo.bar/~user">http://foo.bar/~user</a>',
            'http://foo.bar.' => '<a class="external" href="http://foo.bar">http://foo.bar</a>.',
            'https://foo.bar.' => '<a class="external" href="https://foo.bar">https://foo.bar</a>.',
            'This is a link: http://foo.bar.' => 'This is a link: <a class="external" href="http://foo.bar">http://foo.bar</a>.',
            'A link (eg. http://foo.bar).' => 'A link (eg. <a class="external" href="http://foo.bar">http://foo.bar</a>).',
            'http://foo.bar/foo.bar#foo.bar.' => '<a class="external" href="http://foo.bar/foo.bar#foo.bar">http://foo.bar/foo.bar#foo.bar</a>.',
            'http://www.foo.bar/Test_(foobar)' => '<a class="external" href="http://www.foo.bar/Test_(foobar)">http://www.foo.bar/Test_(foobar)</a>',
            '(see inline link : http://www.foo.bar/Test_(foobar))' => '(see inline link : <a class="external" href="http://www.foo.bar/Test_(foobar)">http://www.foo.bar/Test_(foobar)</a>)',
            '(see inline link : http://www.foo.bar/Test)' => '(see inline link : <a class="external" href="http://www.foo.bar/Test">http://www.foo.bar/Test</a>)',
            'www.foo.bar' => '<a class="external" href="http://www.foo.bar">www.foo.bar</a>',
            'http://foo.bar/page?p=1&t=z&s=' => '<a class="external" href="http://foo.bar/page?p=1&amp;t=z&amp;s=">http://foo.bar/page?p=1&amp;t=z&amp;s=</a>',
            'http://foo@www.bar.com' => '<a class="external" href="http://foo@www.bar.com">http://foo@www.bar.com</a>',
            'ftp://foo.bar' => '<a class="external" href="ftp://foo.bar">ftp://foo.bar</a>',
            'ftps://foo.bar' => '<a class="external" href="ftps://foo.bar">ftps://foo.bar</a>',
            'sftp://foo.bar' => '<a class="external" href="sftp://foo.bar">sftp://foo.bar</a>',
            // test_auto_mailto
            'test@foo.bar' => '<a class="email" href="mailto:test@foo.bar">test@foo.bar</a>',
            'test@www.foo.bar' => '<a class="email" href="mailto:test@www.foo.bar">test@www.foo.bar</a>',
        ];
        foreach ($expected as $text => $html) {
            self::assertSame('<p>'.$html.'</p>', $this->render($text), $text);
        }
        // test_textile_external_links
        self::assertSame('<p>This is a <a href="http://foo.bar" class="external">link</a></p>', $this->render('This is a "link":http://foo.bar'));
        self::assertSame('<p>This is an intern <a href="/foo/bar">link</a></p>', $this->render('This is an intern "link":/foo/bar'));
    }

    /** Finding 16: footnotes, alerts and task lists (common_mark formatter_test: test_footnotes, test_should_render_alert_blocks, test_should_support_task_list) */
    public function testCommonMarkFootnotesAlertsAndTaskLists(): void
    {
        $this->actAs('admin');
        self::assertSame(
            '<p>This is some text<sup><a href="#fn-1" id="fnref-1">1</a></sup>.</p><ol><li id="fn-1"><p>This is the foot note <a href="#fnref-1" aria-label="Back to reference 1">↩</a></p></li></ol>',
            self::squashBetweenTags($this->render("This is some text[^1].\n\n[^1]: This is the foot note"))
        );
        // sanitization_filter_test: test_should_remove_invalid_ids
        self::assertSame('<p><a href="#">x</a></p>', $this->render('<a href="#" id="fnref-x">x</a>'));

        $html = $this->render("> [!note]\n> This is a note.\n\n> [!tip]\n> This is a tip.\n\n> [!warning]\n> This is a warning.\n\n> [!caution]\n> This is a caution.\n\n> [!important]\n> This is a important.");
        foreach (['note' => 'help', 'tip' => 'bulb', 'warning' => 'warning', 'caution' => 'alert-circle', 'important' => 'message-report'] as $alert => $icon) {
            self::assertMatchesRegularExpression('@<div class="markdown-alert markdown-alert-'.$alert.'">\n<p class="markdown-alert-title"><svg class="s18 icon-svg" aria-hidden="true"><use href="/assets/icons[\w-]*.svg#icon--'.$icon.'"></use></svg><span class="icon-label">'.ucfirst($alert).'</span></p>\n<p>This is a '.$alert.'.</p>\n</div>@', $html);
        }
        // test_should_not_render_unknown_alert_type
        $html = $this->render("> [!unknown]\n> This should not become an alert.");
        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('[!unknown]', $html);
        self::assertStringNotContainsString('markdown-alert', $html);
        // sanitization_filter_test: test_should_remove_invalid_div_class
        self::assertSame('<div>x</div>', $this->render('<div class="markdown-alert">x</div>'));

        $crawler = new \Symfony\Component\DomCrawler\Crawler($this->render("Task list:\n* [ ] Task 1\n* [x] Task 2"));
        self::assertCount(1, $crawler->filter('ul.contains-task-list'));
        self::assertCount(2, $crawler->filter('li.task-list-item > input.task-list-item-checkbox[type="checkbox"][disabled]'));
        self::assertCount(1, $crawler->filter('input.task-list-item-checkbox[checked]'));
        // an input that is not a task is removed
        self::assertSame('<p>x</p>', $this->render('<input type="text" value="v">x'));

        // test_should_autolink_urls_and_emails, test_external_links_should_have_external_css_class
        self::assertSame('<p><a href="http://example.org" class="external">http://example.org</a></p>', $this->render('http://example.org'));
        self::assertSame('<p><a href="http://www.example.org" class="external">www.example.org</a></p>', $this->render('www.example.org'));
        self::assertSame('<p><a href="mailto:user@example.org" class="email">user@example.org</a></p>', $this->render('user@example.org'));
        self::assertSame('<p><a href="/issues/13">local</a></p>', $this->render('[local](/issues/13)'));
        // external_links_scrubber_test: test_external_links_with_target_get_rel_noopener
        self::assertSame('<p><a href="http://example.org" target="_blank" class="external" rel="noopener">x</a></p>', $this->render('<a href="http://example.org" target="_blank">x</a>'));
    }

    /** Finding 18: TablesortScrubber (tablesort_scrubber_test) */
    public function testSortableTables(): void
    {
        $this->actAs('admin');
        $table = "| a | b |\n|---|---|\n| 2 | x |\n| 1 | y |";
        self::assertStringStartsWith('<table>', $this->render($table));
        static::getContainer()->get(Settings::class)->set('wiki_tablesort_enabled', '1');
        $html = $this->render($table);
        self::assertStringStartsWith('<table data-controller="tablesort">', $html);
        self::assertStringContainsString('<tr data-sort-method="none">', $html);
        // less than 3 rows: not sortable
        self::assertStringStartsWith('<table>', $this->render("| a | b |\n|---|---|\n| 2 | x |"));

        $this->textile();
        self::assertStringStartsWith('<table data-controller="tablesort">', $this->render("|_. a|_. b|\n|2|x|\n|1|y|"));
        self::assertStringStartsWith('<table>', $this->render("|a|b|\n|2|x|\n|1|y|"));
    }

    /** Finding 19: textilizable(item, :event_description, :only_path => false) in feeds */
    public function testAtomFeedLinksAreAbsolute(): void
    {
        $this->actAs('admin');
        $image = $this->attach('News', 1, 'chart.png', self::png(), 'image/png');
        self::assertSame(302, $this->submit('/news/1', ['_method' => 'put', 'news' => ['title' => 'Release 0.9', 'description' => 'See #13 and [[Wiki]] and version:1.0 and ![](chart.png)']]));
        $feed = html_entity_decode($this->get('/projects/ecommerce/news.atom'));
        self::assertStringContainsString('<a href="http://localhost/issues/13" class="issue', $feed);
        self::assertStringContainsString('<a href="http://localhost/projects/ecommerce/wiki/Wiki" class="wiki-page">Wiki</a>', $feed);
        self::assertStringContainsString('<a href="http://localhost/versions/1" class="version">1.0</a>', $feed);
        self::assertStringContainsString('<img src="http://localhost/attachments/download/'.$image->id.'/chart.png"', $feed);
        // the page itself keeps relative links
        self::assertStringContainsString('<a href="/issues/13" class="issue', $this->get('/news/1'));
    }

    /** Finding 20: parse_wiki_links, parse_headings (application_helper_test: test_wiki_links, test_wiki_links_within_wiki_page_context, test_headings) */
    public function testWikiLinkEdgeCasesAndHeadingAnchors(): void
    {
        $this->actAs('admin');
        self::assertSame(
            '<p><a href="/projects/ecommerce/wiki/Checkout_flow" class="wiki-page">Checkout flow</a> '
            .'<a href="/projects/ecommerce/wiki/Checkout_flow" class="wiki-page">the flow</a> '
            .'<a href="/projects/ecommerce/wiki/Nope" class="wiki-page new">Nope</a> '
            .'<a href="#anchor" class="wiki-page">#anchor</a> '
            .'<a href="/projects/ecommerce/wiki/Wiki#Section-one" class="wiki-page">Wiki</a> '
            .'<a href="/projects/ecommerce/wiki" class="wiki-page">ecommerce</a> '
            .'<a href="/projects/ecommerce/wiki/Wiki" class="wiki-page">eCommerce start</a> '
            .'[[Wiki]] [[unknownproj:Page]]</p>',
            $this->render('[[Checkout flow]] [[checkout_flow|the flow]] [[Nope]] [[#anchor]] [[Wiki#Section one!]] [[ecommerce:]] [[eCommerce:Wiki|eCommerce start]] ![[Wiki]] [[unknownproj:Page]]')
        );
        // a project without wiki
        self::assertSame('<p>[[checkout:Foo]]</p>', $this->render('[[checkout:Foo]]'));

        // a renamed page is still found through its redirect
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/Checkout_flow/rename', ['wiki_page' => ['title' => 'Checkout process', 'redirect_existing_links' => '1']]));
        self::assertSame('<p><a href="/projects/ecommerce/wiki/Checkout_flow" class="wiki-page">Checkout flow</a></p>', $this->render('[[Checkout flow]]'));

        // within a wiki page: links to pages to create carry the parent, links to the page itself are anchors
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/Wiki', ['content' => ['text' => "# Home\n\n[[New child]] [[Wiki#Home|top]] [[Checkout process#Step]]"]], 'PUT'));
        $page = $this->get('/projects/ecommerce/wiki/Wiki');
        self::assertStringContainsString('<a href="/projects/ecommerce/wiki/New_child?parent=Wiki" class="wiki-page new">New child</a> <a href="#Home" class="wiki-page">top</a> <a href="/projects/ecommerce/wiki/Checkout_process#Step" class="wiki-page">Checkout process</a>', $page);

        // test_headings: anchors everywhere, not only on wiki pages with a table of contents
        self::assertSame("<a name=\"Some-heading\"></a>\n<h1>Some heading<a href=\"#Some-heading\" class=\"wiki-anchor\">&para;</a></h1>", $this->render('# Some heading'));
    }
    // ------------------------------------------------------------------------------------------
    // Second pass: Redmine's own formatter tests, case by case
    // ------------------------------------------------------------------------------------------

    /** The same HTML whatever the order of the attributes and the white space between tags */
    private static function canonical(string $html): string
    {
        $html = (string) preg_replace('#<a name="[^"]*"></a>\n?|<a href="\#[^"]*" class="wiki-anchor">(?:&para;|¶)</a>#u', '', $html);
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', \LIBXML_NOERROR, 'UTF-8');
        $walk = static function (\Dom\Node $node) use (&$walk): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof \Dom\Element) {
                    $attributes = [];
                    foreach (iterator_to_array($child->attributes) as $attribute) {
                        $attributes[$attribute->name] = $attribute->value;
                        $child->removeAttribute($attribute->name);
                    }
                    ksort($attributes);
                    foreach ($attributes as $name => $value) {
                        $child->setAttribute($name, $value);
                    }
                    $walk($child);
                }
            }
        };
        $walk($doc->body);

        return (string) preg_replace('/\s+/', '', $doc->body->innerHTML);
    }

    /** @param array<string, string> $cases text => expected HTML */
    private function assertRendered(array $cases, bool $paragraph = true, array $params = []): void
    {
        foreach ($cases as $text => $expected) {
            self::assertSame(self::canonical(self::preWrapper($paragraph ? '<p>'.$expected.'</p>' : $expected)), self::canonical($this->render((string) $text, $params)), "Formatting the following text failed:\n===\n$text\n===\n");
        }
    }

    /** test/unit/lib/redmine/wiki_formatting/textile_formatter_test.rb: inline cases */
    public function testTextileFormatterInlineCases(): void
    {
        $this->actAs('admin');
        $this->textile();
        // test_modifiers, test_modifier_should_work_with_one_non_ascii_character, test_inline_code, test_lang_attribute(_should_ignore_invalid_value)
        $this->assertRendered([
            '*bold*' => '<strong>bold</strong>', 'before *bold*' => 'before <strong>bold</strong>', '*bold* after' => '<strong>bold</strong> after',
            '*two words*' => '<strong>two words</strong>', '*two*words*' => '<strong>two*words</strong>', '*two * words*' => '<strong>two * words</strong>',
            '*two* *words*' => '<strong>two</strong> <strong>words</strong>', '*(two)* *(words)*' => '<strong>(two)</strong> <strong>(words)</strong>',
            '*Ä*' => '<strong>Ä</strong>',
            'this is @some code@' => 'this is <code>some code</code>', '@<Location /redmine>@' => '<code>&lt;Location /redmine&gt;</code>',
            '*[fr]French*' => '<strong lang="fr">French</strong>', '*[fr-fr]French*' => '<strong lang="fr-fr">French</strong>', '*[fr_fr]French*' => '<strong lang="fr_fr">French</strong>',
            '*[fr3]French*' => '<strong>[fr3]French</strong>', '*[WIP] title*' => '<strong>[WIP] title</strong>',
            // test_double_dashes_should_not_strikethrough, test_abbreviations, test_textile_should_not_mangle_brackets
            'double -- *dashes* -- test' => 'double -- <strong>dashes</strong> -- test',
            'this is an abbreviation: GPL(General Public License)' => 'this is an abbreviation: <abbr title="General Public License">GPL</abbr>',
            '2 letters JP(Jean-Philippe) abbreviation' => '2 letters <abbr title="Jean-Philippe">JP</abbr> abbreviation',
            'GPL(This is a double-quoted "title")' => '<abbr title="This is a double-quoted &quot;title&quot;">GPL</abbr>',
            '[msg1][msg2]' => '[msg1][msg2]',
            // application_helper_test: test_text_formatting, test_wiki_horizontal_rule
            '*_+bold, italic and underline+_*' => '<strong><em><ins>bold, italic and underline</ins></em></strong>',
            '(_text within parentheses_)' => '(<em>text within parentheses</em>)',
            'a H *umane* W *eb* T *ext* G *enerator*' => 'a H <strong>umane</strong> W <strong>eb</strong> T <strong>ext</strong> G <strong>enerator</strong>',
            'Dashes: ---' => 'Dashes: ---',
            // "@|ruby|code@" keeps the language (inline_textile_code)
            '@|ruby|puts 1@' => '<code lang="ruby">puts 1</code>',
        ]);
        // test_modifiers_combination
        $modifiers = ['*' => 'strong', '_' => 'em', '+' => 'ins', '-' => 'del', '^' => 'sup', '~' => 'sub'];
        foreach ($modifiers as $m1 => $tag1) {
            foreach ($modifiers as $m2 => $tag2) {
                if ($m1 !== $m2) {
                    $this->assertRendered([$m2.$m1.'Phrase modifiers'.$m1.$m2 => "<$tag2><$tag1>Phrase modifiers</$tag1></$tag2>"]);
                }
            }
        }
        // test_styles: one declaration each (the order of several declarations is not kept, see the report)
        $this->assertRendered([
            'p{color:#f00}. text' => '<p style="color:#f00;">text</p>', 'p{border:10px}. text' => '<p style="border:10px;">text</p>',
            'p{border:1.5em}. text' => '<p style="border:1.5em;">text</p>', 'p{max-width:100px}. text' => '<p style="max-width:100px;">text</p>',
            'p{text-decoration: line-through}. text' => '<p style="text-decoration: line-through;">text</p>',
            'p{border:1px solid red;}. text' => '<p style="border:1px solid red;">text</p>',
            'p{border-top-left-radius: 10px 5px;}. text' => '<p style="border-top-left-radius: 10px 5px;">text</p>',
            // test_invalid_styles_should_be_filtered
            'p{invalid}. text' => '<p>text</p>', 'p{color:(red)}. text' => '<p>text</p>', 'p{invalid:blue;color:red}. text' => '<p style="color:red;">text</p>',
            'p{color:"}. text' => '<p>p{color:"}. text</p>',
            // test_use_of_backslashes_followed_by_numbers_in_headers, application_helper_test: test_wiki_horizontal_rule
            'h1. 2009\02\09' => '<h1>2009\02\09</h1>', '---' => '<hr>',
            // test_should_prefix_class_attribute_on_tags, test_should_prefix_id_attribute_on_tags, test_should_not_prefix_class_and_id_attributes_already_prefixed
            '!(foo)test.png!' => '<p><img src="test.png" class="wiki-class-foo" alt=""></p>',
            '|(foo). test|' => '<table><tbody><tr><td class="wiki-class-foo">test</td></tr></tbody></table>',
            '!(#foo)test.png!' => '<p><img src="test.png" id="wiki-id-foo" alt=""></p>',
            '|(#foo). test|' => '<table><tbody><tr><td id="wiki-id-foo">test</td></tr></tbody></table>',
            '!(wiki-class-foo#wiki-id-bar)test.png!' => '<p><img src="test.png" class="wiki-class-foo" id="wiki-id-bar" alt=""></p>',
            // test_should_escape_bq_citations
            'bq.:http://x/"onmouseover="alert(document.domain) Hover me' => '<blockquote cite="http://x/&quot;onmouseover=&quot;alert(document.domain)"><p>Hover me</p></blockquote>',
        ], false);
        // test_should_not_allow_arbitrary_class_attribute_on_offtags, test_should_not_allow_valid_language_class_attribute_on_non_code_offtags:
        // a block that starts with one of these tags is not a paragraph
        foreach (['class="foo"' => 'data-language="foo"', "class='foo'" => 'data-language="foo"', 'class="ruby foo"' => 'data-language="ruby foo"', 'class="ruby "foo" bar"' => 'data-language="ruby "', 'class="c-k&r"' => 'data-language="c-k&amp;r"'] as $attribute => $expected) {
            $this->assertRendered([
                "<code $attribute>test</code>" => "<code $expected>test</code>",
                "<pre $attribute>test</pre>" => '<pre>test</pre>',
                "<kbd $attribute>test</kbd>" => '<kbd>test</kbd>',
                "<notextile $attribute>test</notextile>" => 'test',
            ], false);
        }
        // test_restore_redmine_links_*: the quotes of a URL never get out of the href attribute
        foreach (['https://example.com/?a:"onmouseover=alert(document.domain)//"', 'https://a.com/?p:"X https://b.com/?q:"onmouseover=alert(document.domain)//"'] as $payload) {
            $crawler = new \Symfony\Component\DomCrawler\Crawler($this->render($payload));
            self::assertCount(0, $crawler->filter('[onmouseover]'), $payload);
        }
        self::assertStringContainsString('version:"foo &lt;bar&gt;"', $this->render('version:"foo <bar>"'));
        // test_nested_notextile_tag_boundaries
        foreach (['<<notextile>div class=foo >', '<</notextile>div class=foo >'] as $payload) {
            self::assertStringNotContainsString('<div', $this->render($payload));
        }
        // test_should_not_crash_with_special_input
        $this->render(" \f.");
    }

    /** textile_formatter_test.rb and application_helper_test.rb: block cases */
    public function testTextileFormatterBlockCases(): void
    {
        $this->actAs('admin');
        $this->textile();
        $this->assertRendered([
            // test_nested_lists
            "# Item 1\n# Item 2\n** Item 2a\n** Item 2b\n# Item 3\n** Item 3a\n" => '<ol><li>Item 1</li><li>Item 2<ul><li>Item 2a</li><li>Item 2b</li></ul></li><li>Item 3<ul><li>Item 3a</li></ul></li></ol>',
            "* Item-1\n\n  * Item-1a\n  * Item-1b\n" => '<ul><li>Item-1<ul><li>Item-1a</li><li>Item-1b</li></ul></li></ul>',
            // test_blockquote
            "John said:\n> Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Maecenas sed libero.\n> Nullam commodo metus accumsan nulla. Curabitur lobortis dui id dolor.\n> * Donec odio lorem,\n> * sagittis ac,\n> * malesuada in,\n> * adipiscing eu, dolor.\n>\n> >Nulla varius pulvinar diam. Proin id arcu id lorem scelerisque condimentum. Proin vehicula turpis vitae lacus.\n> Proin a tellus. Nam vel neque.\n\nHe's right.\n"
                => "<p>John said:</p><blockquote>Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Maecenas sed libero.<br>Nullam commodo metus accumsan nulla. Curabitur lobortis dui id dolor.<ul><li>Donec odio lorem,</li><li>sagittis ac,</li><li>malesuada in,</li><li>adipiscing eu, dolor.</li></ul><blockquote><p>Nulla varius pulvinar diam. Proin id arcu id lorem scelerisque condimentum. Proin vehicula turpis vitae lacus.</p></blockquote><p>Proin a tellus. Nam vel neque.</p></blockquote><p>He's right.</p>",
            // test_table, test_table_with_alignment, test_table_with_trailing_whitespace
            "This is a table with empty cells:\n\n|cell11|cell12||\n|cell21||cell23|\n|cell31|cell32|cell33|\n" => '<p>This is a table with empty cells:</p><table><tbody><tr><td>cell11</td><td>cell12</td><td></td></tr><tr><td>cell21</td><td></td><td>cell23</td></tr><tr><td>cell31</td><td>cell32</td><td>cell33</td></tr></tbody></table>',
            "|>. right|\n|<. left|\n|<>. justify|\n" => '<table><tbody><tr><td style="text-align:right;">right</td></tr><tr><td style="text-align:left;">left</td></tr><tr><td style="text-align:justify;">justify</td></tr></tbody></table>',
            "|cell11|cell12|\n|cell21|cell22| \n|cell31|cell32|\n" => '<table><tbody><tr><td>cell11</td><td>cell12</td></tr><tr><td>cell21</td><td>cell22</td></tr><tr><td>cell31</td><td>cell32</td></tr></tbody></table>',
            // test_table_with_line_breaks
            "|cell11\ncontinued|cell12||\n|-cell21-||cell23\ncell23 line2\ncell23 *line3*|\n|cell31|cell32\ncell32 line2|cell33|\n\n" => '<table><tbody><tr><td>cell11<br>continued</td><td>cell12</td><td></td></tr><tr><td><del>cell21</del></td><td></td><td>cell23<br>cell23 line2<br>cell23 <strong>line3</strong></td></tr><tr><td>cell31</td><td>cell32<br>cell32 line2</td><td>cell33</td></tr></tbody></table>',
            // test_tables_with_lists: the lines of a cell are not list items
            "|cell11|cell12|\n|cell21|ordered list\n# item\n# item 2|\n|cell31|unordered list\n* item\n* item 2|\n\n" => '<table><tbody><tr><td>cell11</td><td>cell12</td></tr><tr><td>cell21</td><td>ordered list<br># item<br># item 2</td></tr><tr><td>cell31</td><td>unordered list<br>* item<br>* item 2</td></tr></tbody></table>',
            // application_helper_test: test_wiki_links_in_tables
            "|[[Checkout flow|Link title]]|[[Other Page|Other title]]|\n|Cell 21|[[Last page]]|\n" => '<table><tbody><tr><td><a href="/projects/ecommerce/wiki/Checkout_flow" class="wiki-page">Link title</a></td><td><a href="/projects/ecommerce/wiki/Other_Page" class="wiki-page new">Other title</a></td></tr><tr><td>Cell 21</td><td><a href="/projects/ecommerce/wiki/Last_page" class="wiki-page new">Last page</a></td></tr></tbody></table>',
            // test_footnotes, test_should_allow_multiple_footnotes
            "This is some text[1].\n\nfn1. This is the foot note\n" => '<p>This is some text<sup><a href="#fn1">1</a></sup>.</p><p id="fn1" class="footnote"><sup>1</sup> This is the foot note</p>',
            "Some demo[1][2] And a sentence.[1]\n\nfn1. One\n\nfn2. Two\n" => '<p>Some demo<sup><a href="#fn1">1</a></sup><sup><a href="#fn2">2</a></sup> And a sentence.[1]</p><p id="fn1" class="footnote"><sup>1</sup> One</p><p id="fn2" class="footnote"><sup>2</sup> Two</p>',
            // test_should_not_handle_as_preformatted_text_tags_that_starts_with_pre
            "<pree>\n  This is some text\n</pree>\n" => '<p>&lt;pree&gt;<br>  This is some text<br>&lt;/pree&gt;</p>',
            // test_should_remove_html_comments
            "<!-- begin -->\nHello <!-- comment between words -->world.\n\n<!--\n  multi-line\ncomment -->Foo\n\n<pre>\nThis is a code block.\n<p>\n<!-- comments in a code block should be preserved -->\n</p>\n</pre>\n" => "<p>Hello world.</p><p>Foo</p><pre>\nThis is a code block.\n&lt;p&gt;\n&lt;!-- comments in a code block should be preserved --&gt;\n&lt;/p&gt;\n</pre>",
            // application_helper_test: test_html_tags, test_allowed_html_tags, test_pre_tags, test_non_closing_pre_blocks_should_be_closed
            '<div class="bold">content</div>' => '<p>&lt;div class="bold"&gt;content&lt;/div&gt;</p>',
            '<!-- opening comment' => '<p>&lt;!-- opening comment</p>',
            "<pre>\nline 1\nline2</pre>" => "<pre>line 1\nline2</pre>",
            '<pre><div class="<foo">content</div></pre>' => '<pre>&lt;div class="&lt;foo"&gt;content&lt;/div&gt;</pre>',
            "<pre onmouseover='alert(1)'>some text</pre>" => '<pre>some text</pre>',
            '<pre><code class=""onmouseover="alert(1)">text</code></pre>' => '<pre><code>text</code></pre>',
            '<notextile>this is <tag>a tag</tag></notextile>' => 'this is &lt;tag&gt;a tag&lt;/tag&gt;',
            "Before\n\n<pre>\n<prepared-statement-cache-size>32</prepared-statement-cache-size>\n</pre>\n\nAfter\n" => '<p>Before</p><pre>&lt;prepared-statement-cache-size&gt;32&lt;/prepared-statement-cache-size&gt;</pre><p>After</p>',
            "<pre><code>\n" => '<pre><code></code></pre>',
            // application_helper_test: test_textile_external_links (a link text does not go over two lines)
            "This is not a \"Link\":\n\nAnother paragraph" => '<p>This is not a "Link":</p><p>Another paragraph</p>',
            "This is a double quote \"on the first line\nand another on a second line\":test" => '<p>This is a double quote "on the first line<br>and another on a second line":test</p>',
            // test_inline_images_inside_tags
            "h1. !foo.png! Heading\n\nCentered image:\n\np=. !bar.gif!\n" => '<h1><img src="foo.png" alt=""> Heading</h1><p>Centered image:</p><p style="text-align:center;"><img src="bar.gif" alt=""></p>',
            // RedCloth3#blocks: text indented after an empty line is a code block
            "Some text\n\n    indented <b> *line*\n    second line\n\nMore text" => "<p>Some text</p><pre><code>indented &lt;b&gt; *line*\nsecond line</code></pre><p>More text</p>",
        ], false);
        // application_helper_test: test_unbalanced_closing_pre_tag_should_not_error
        self::assertStringContainsString('unbalanced', $this->render('unbalanced</pre>'));
    }

    /** application_helper_test.rb: test_redmine_links, test_auto_links, test_textile_external_links, test_inline_images (Textile) */
    public function testTextileLinkCases(): void
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $repository = new Repository();
        $repository->project = $project;
        $repository->url = $repository->rootUrl = 'file:///nowhere';
        $repository->type = 'Repository::Subversion';
        $repository->identifier = '';
        $repository->isDefault = true;
        $repository->createdOn = new \DateTimeImmutable();
        $this->em->persist($repository);
        foreach (['1' => 'My very first commit do not escaping #<>&', '2' => 'This commit fixes #1, #2 and references #1 & #3'] as $revision => $comments) {
            $changeset = new Changeset();
            $changeset->repository = $repository;
            $changeset->revision = (string) $revision;
            $changeset->scmid = '1' === (string) $revision ? '691322a8eb01e11fd7e8' : 'aaa2';
            $changeset->committedOn = new \DateTimeImmutable();
            $changeset->comments = $comments;
            $this->em->persist($changeset);
        }
        $this->em->flush();
        $base = '/projects/ecommerce/repository/'.$repository->id.'/';

        $this->actAs('admin');
        $this->textile();
        $class = 'issue tracker-1 status-2 priority-3 priority-high3';
        preg_match('/class="(issue [^"]*)"/', $this->render('#13'), $m);
        $class = $m[1] ?? $class;
        $issue = '<a href="/issues/13" class="'.$class.'" title="Bug: Cart total is wrong with discounts (In Progress)">#13</a>';
        $extended = '<a href="/issues/13" class="'.$class.'" title="Status: In Progress">Bug #13: Cart total is wrong with discounts</a>';
        $r1 = '<a href="'.$base.'revisions/1" class="changeset" title="My very first commit do not escaping #&lt;&gt;&amp;">r1</a>';
        $r2 = '<a href="'.$base.'revisions/2" class="changeset" title="This commit fixes #1, #2 and references #1 &amp; #3">r2</a>';
        $source = static fn (string $label, string $path, string $css = 'source'): string => '<a href="'.$base.$path.'" class="'.$css.'">'.$label.'</a>';
        $user = '<a href="/users/5" class="user active">John Smith</a>';
        $mention = '<a href="/users/5" class="user active user-mention">@John Smith</a>';
        $this->assertRendered([
            '#13, [#13], (#13) and #13.' => "$issue, [$issue], ($issue) and $issue.",
            '#013' => '#013',
            '##13, [##13], (##13) and ##13.' => "$extended, [$extended], ($extended) and $extended.",
            '##013' => '##013',
            'r1' => $r1, 'r1.' => "$r1.", 'r1, r2' => "$r1, $r2", 'r1,r2' => "$r1,$r2",
            'commit:691322a8eb01e11fd7' => '<a href="'.$base.'revisions/691322a8eb01e11fd7e8" class="changeset" title="My very first commit do not escaping #&lt;&gt;&amp;">691322a8eb01e11fd7</a>',
            'source:some/file' => $source('source:some/file', 'entry/some/file'),
            'source:/some/file.' => $source('source:/some/file', 'entry/some/file').'.',
            'source:/some/file.ext.' => $source('source:/some/file.ext', 'entry/some/file.ext').'.',
            'source:/some/file, ' => $source('source:/some/file', 'entry/some/file').',',
            'source:/some/file@branch' => $source('source:/some/file@branch', 'revisions/branch/entry/some/file'),
            'source:/some/file.ext@52' => $source('source:/some/file.ext@52', 'revisions/52/entry/some/file.ext'),
            'source:/some/file.ext#L110' => $source('source:/some/file.ext#L110', 'entry/some/file.ext#L110'),
            'export:/some/file.ext@52' => $source('export:/some/file.ext@52', 'revisions/52/raw/some/file.ext', 'source download'),
            'project:"checkOut"' => '<a href="/projects/checkout" class="project">Checkout</a>',
            '#0123456789' => '#0123456789', 'source:' => 'source:',
            'http://foo.bar/FAQ#3' => '<a class="external" href="http://foo.bar/FAQ#3">http://foo.bar/FAQ#3</a>',
            'user:jsmith' => $user, 'user:JSMITH' => $user, 'user#5' => $user, '@jsmith' => $mention, '@jsmith.' => "$mention.", '@JSMITH' => $mention,
            'user:foobar' => 'user:foobar',
            // test_escaped_redmine_links_should_not_be_parsed
            '!#13.' => '#13.', '!r1' => 'r1', '!document#1' => 'document#1', '!document:"Specs"' => 'document:"Specs"', '!version#1' => 'version#1',
            '!version:1.0' => 'version:1.0', '!version:"1.0"' => 'version:"1.0"', '!source:/some/file' => 'source:/some/file',
            // test_should_not_parse_redmine_links_inside_link
            'r1 should not be parsed in http://example.com/url-r1/' => $r1.' should not be parsed in <a class="external" href="http://example.com/url-r1/">http://example.com/url-r1/</a>',
            '"some link #13":http://example.com' => '<a href="http://example.com" class="external">some link #13</a>',
            // test_auto_links
            '(see inline link : http://www.foo.bar/Test).' => '(see inline link : <a class="external" href="http://www.foo.bar/Test">http://www.foo.bar/Test</a>).',
            '(see "inline link":http://www.foo.bar/Test)' => '(see <a href="http://www.foo.bar/Test" class="external">inline link</a>)',
            '(see "inline link":http://www.foo.bar/Test).' => '(see <a href="http://www.foo.bar/Test" class="external">inline link</a>).',
            'http://foo.bar/page#125' => '<a class="external" href="http://foo.bar/page#125">http://foo.bar/page#125</a>',
            'http://foo:bar@www.bar.com' => '<a class="external" href="http://foo:bar@www.bar.com">http://foo:bar@www.bar.com</a>',
            'http://example.net/path!602815048C7B5C20!302.html' => '<a class="external" href="http://example.net/path!602815048C7B5C20!302.html">http://example.net/path!602815048C7B5C20!302.html</a>',
            '<http://foo.bar>' => '&lt;<a class="external" href="http://foo.bar">http://foo.bar</a>&gt;',
            'http://' => 'http://', 'www.' => 'www.', 'test-www.bar.com' => 'test-www.bar.com',
            'http://www.redmine.org/example-' => '<a class="external" href="http://www.redmine.org/example-">http://www.redmine.org/example-</a>',
            'http://foo.bar/русский' => '<a class="external" href="http://foo.bar/русский">http://foo.bar/русский</a>',
            // test_textile_external_links
            '"link (Link title)":http://foo.bar' => '<a href="http://foo.bar" title="Link title" class="external">link</a>',
            '"system administrator":mailto:sysadmin@example.com?subject=redmine%20permissions' => '<a href="mailto:sysadmin@example.com?subject=redmine%20permissions">system administrator</a>',
            '(see "inline link":http://www.foo.bar/Test-)' => '(see <a href="http://www.foo.bar/Test-" class="external">inline link</a>)',
            'This is an intern "link":/foo/bar-' => 'This is an intern <a href="/foo/bar-">link</a>',
            // test_inline_images
            '!http://foo.bar/image.jpg!' => '<img src="http://foo.bar/image.jpg" alt="">',
            'floating !>http://foo.bar/image.jpg!' => 'floating <span style="float:right;"><img src="http://foo.bar/image.jpg" alt=""></span>',
            'with class !(some-class)http://foo.bar/image.jpg!' => 'with class <img src="http://foo.bar/image.jpg" class="wiki-class-some-class" alt="">',
            'with title !http://foo.bar/image.jpg(This is a title)!' => 'with title <img src="http://foo.bar/image.jpg" title="This is a title" alt="This is a title">',
            'with title !http://foo.bar/image.jpg(This is a double-quoted "title")!' => 'with title <img src="http://foo.bar/image.jpg" title="This is a double-quoted &quot;title&quot;" alt="This is a double-quoted &quot;title&quot;">',
            'with query string !http://foo.bar/image.cgi?a=1&b=2!' => 'with query string <img src="http://foo.bar/image.cgi?a=1&amp;b=2" alt="">',
            // test_attachment_links_to_images_with_email_format_should_not_be_parsed
            'attachment:image@2x.png should not be parsed' => 'attachment:image@2x.png should not be parsed',
            // test_wiki_links: the title is formatted, a link can be struck through
            '[[Checkout flow|With _styled_ *title*]]' => '<a href="/projects/ecommerce/wiki/Checkout_flow" class="wiki-page">With <em>styled</em> <strong>title</strong></a>',
            '[[Checkout flow|With title containing <strong>HTML entities &amp; markups</strong>]]' => '<a href="/projects/ecommerce/wiki/Checkout_flow" class="wiki-page">With title containing &lt;strong&gt;HTML entities &amp; markups&lt;/strong&gt;</a>',
            '-[[Checkout flow|Page]] link-' => '<del><a href="/projects/ecommerce/wiki/Checkout_flow" class="wiki-page">Page</a> link</del>',
        ]);

        // test_user_links_with_email_as_login_name_should_not_be_parsed_textile / _markdown
        $this->em->getConnection()->executeStatement("UPDATE users SET login = 'abcd@example.com' WHERE login = 'dlopper'");
        foreach (['textile', 'common_mark'] as $formatting) {
            static::getContainer()->get(Settings::class)->set('text_formatting', $formatting);
            $this->assertRendered([
                '@abcd@example.com' => '<a href="/users/6" class="user active user-mention">@Dave Lopper</a>',
                'user:abcd@example.com' => '<a href="/users/6" class="user active">Dave Lopper</a>',
                '@abcd@example.org' => '@abcd@example.org',
            ]);
        }
    }

    /** application_helper_test.rb: test_wiki_links, wiki_links_with_special_characters, test_headings_with_special_chars, test_default_formatter */
    public function testWikiLinksHeadingsAndNullFormatterInBothFormats(): void
    {
        $this->actAs('admin');
        $link = static fn (string $label, string $page, string $class = 'wiki-page'): string => '<a href="/projects/ecommerce/wiki/'.$page.'" class="'.$class.'">'.$label.'</a>';
        foreach (['common_mark', 'textile'] as $formatting) {
            static::getContainer()->get(Settings::class)->set('text_formatting', $formatting);
            $this->assertRendered([
                '[[Checkout flow#One-section]]' => $link('Checkout flow', 'Checkout_flow#One-section'),
                '[[Checkout flow#anchor|Page]]' => $link('Page', 'Checkout_flow#anchor'),
                '[[#anchor|One-section]]' => '<a href="#anchor" class="wiki-page">One-section</a>',
                '[[Unknown page|404]]' => $link('404', 'Unknown_page', 'wiki-page new'),
                '[[ecommerce:]]' => '<a href="/projects/ecommerce/wiki" class="wiki-page">ecommerce</a>',
                '[[ecommerce:|Wiki]]' => '<a href="/projects/ecommerce/wiki" class="wiki-page">Wiki</a>',
                '[[internal:Unknown page]]' => '<a href="/projects/internal/wiki/Unknown_page" class="wiki-page new">Unknown page</a>',
                '![[Checkout flow|Page]]' => '[[Checkout flow|Page]]',
                '[[unknowproject:Start|Page title]]' => '[[unknowproject:Start|Page title]]',
                '[[a "quoted" name]]' => $link('a "quoted" name', 'A_%22quoted%22_name', 'wiki-page new'),
                '[[broken < less]]' => $link('broken &lt; less', 'Broken_%3C_less', 'wiki-page new'),
                '[[broken > more]]' => $link('broken &gt; more', 'Broken_%3E_more', 'wiki-page new'),
                '[[[foo]Including [square brackets] in wiki title]]' => $link('[foo]Including [square brackets] in wiki title', '%5Bfoo%5DIncluding_%5Bsquare_brackets%5D_in_wiki_title', 'wiki-page new'),
            ]);
            // test_headings_with_special_chars: [anchor, text of the table of contents]
            $prefix = 'textile' === $formatting ? 'h1.' : '#';
            foreach ([
                'Some heading related to version 0.5' => ['Some-heading-related-to-version-05', 'Some heading related to version 0.5'],
                "Bob's guide" => ['Bobs-guide', 'Bob&#039;s guide'],
                'The "quoted" guide' => ['The-quoted-guide', 'The &quot;quoted&quot; guide'],
                "*Bob's* & B" => ['Bobs-amp-B', 'Bob&#039;s &amp; B'],
                '&lt;img src=x onerror=alert(1)&gt;' => ['ltimg-srcx-onerroralert1gt', '&lt;img src=x onerror=alert(1)&gt;'],
            ] as $heading => [$anchor, $label]) {
                $html = $this->render("{{toc}}\n\n$prefix $heading\n\n[[#$anchor|Wiki link]]");
                self::assertStringContainsString('<a name="'.$anchor.'"></a>', $html, $heading);
                self::assertStringContainsString('<a href="#'.$anchor.'" class="wiki-anchor">', $html, $heading);
                self::assertStringContainsString('<a href="#'.$anchor.'">'.$label.'</a>', $html, $heading);
                self::assertStringContainsString('<a href="#'.$anchor.'" class="wiki-page">Wiki link</a>', $html, $heading);
            }
        }
        // test_default_formatter (Redmine::WikiFormatting::NullFormatter): escaped text, auto links, paragraphs
        static::getContainer()->get(Settings::class)->set('text_formatting', '');
        $this->assertRendered(['a *link*: http://www.example.net/' => 'a *link*: <a class="external" href="http://www.example.net/">http://www.example.net/</a>']);
        $this->assertRendered(["first <b>\nline two\n\nsecond test@foo.bar" => '<p>first &lt;b&gt;<br>line two</p><p>second <a class="email" href="mailto:test@foo.bar">test@foo.bar</a></p>'], false);
    }

    /** macros_test.rb, second pass */
    public function testMacroCasesFromRedmineTests(): void
    {
        $this->actAs('admin');
        $this->textile();
        $hello = static fn (string $arguments, string $block = 'no block of text.'): string => "Hello world! Object: NilClass, $arguments and $block";
        $this->assertRendered([
            // test_macro_name_with_upper_case, test_multiple_macros_on_the_same_line
            '{{HELLO_WORLD}} {{hello_world}}' => $hello('Called with no argument').' '.$hello('Called with no argument'),
            '{{hello_world(a,b)}} {{hello_world}}' => $hello('Arguments: a, b').' '.$hello('Called with no argument'),
            // test_macro_arguments_should_not_be_parsed_by_formatters
            '{{hello_world(http://www.redmine.org, #1)}}' => $hello('Arguments: http://www.redmine.org, #1'),
            // test_unknown_macros_should_parsed_as_text, test_unknown_macros_should_be_escaped
            '{{unknown(*test*)}}' => '{{unknown(<strong>test</strong>)}}', '{{unknown(<tag>)}}' => '{{unknown(&lt;tag&gt;)}}',
            // test_macros_should_not_mangle_next_macros_outputs
            '{{macro(2)}} !{{macro(2)}} {{hello_world(foo)}}' => '{{macro(2)}} {{macro(2)}} '.$hello('Arguments: foo'),
            // test_macro_should_support_phrase_modifiers
            '*{{hello_world}}*' => '<strong>'.$hello('Called with no argument').'</strong>',
        ]);
        $this->assertRendered([
            // test_macros_should_not_be_executed_in_pre_tags, test_macros_should_be_escaped_in_pre_tags
            "{{hello_world(foo)}}\n\n<pre>\n{{hello_world(pre)}}\n!{{hello_world(pre)}}\n</pre>\n\n{{hello_world(bar)}}\n" => '<p>'.$hello('Arguments: foo')."</p><pre>\n{{hello_world(pre)}}\n!{{hello_world(pre)}}\n</pre><p>".$hello('Arguments: bar').'</p>',
            '<pre>{{hello_world(<tag>)}}</pre>' => '<pre>{{hello_world(&lt;tag&gt;)}}</pre>',
            // test_macros_with_text_should_not_mangle_following_macros
            "{{hello_world\nLine of text\n}}\n\n{{hello_world\nAnother line of text\n}}\n" => '<p>'.$hello('Called with no argument', 'a 12 bytes long block of text.').'</p><p>'.$hello('Called with no argument', 'a 20 bytes long block of text.').'</p>',
            // test_macro_include
            '{{include(unknowidentifier:somepage)}}' => '<div class="flash error">Error executing the <strong>include</strong> macro (Page not found)</div>',
        ], false);
        self::assertStringContainsString('Version 2 with more text', $this->render('{{include(ecommerce:Checkout flow)}}', [], 'checkout'));
        // test_macro_collapse_with_arg_contains_comma, test_macro_collapse_should_not_break_toc
        $crawler = new \Symfony\Component\DomCrawler\Crawler($this->render("{{collapse(\"Click here, to see the example\", Hide example)\n*Collapsed* block of text\n}}"));
        self::assertSame('Click here, to see the example', $crawler->filter('a.collapsible.icon-collapsed')->text());
        self::assertSame('Hide example', $crawler->filter('a.collapsible.icon-expanded')->text());
        self::assertSame('Collapsed', $crawler->filter('div.collapsed-text strong')->text());
        self::assertStringContainsString(
            '<ul class="toc"><li><strong>Table of contents</strong></li><li><a href="#Title">Title</a><ul><li><a href="#Heading">Heading</a></li></ul></li></ul>',
            str_replace("\n", '', $this->render("{{toc}}\n\nh1. Title\n\n{{collapse(Show example, Hide example)\nh2. Heading\n}}"))
        );
    }

    /** common_mark/formatter_test.rb, sanitization_filter_test.rb and the scrubber tests, second pass */
    public function testCommonMarkCasesFromRedmineTests(): void
    {
        $this->actAs('admin');
        $this->assertRendered([
            // test_should_render_hard_breaks, test_should_render_soft_breaks
            "foo\\\nbar" => "<p>foo<br>\nbar</p>", "foo  \nbar" => "<p>foo<br>\nbar</p>", "foo\nbar" => "<p>foo<br>\nbar</p>",
            // test_not_set_intra_emphasis, test_links_by_id_should_be_preserved, test_links_to_users_should_be_preserved
            'foo_bar_baz' => '<p>foo_bar_baz</p>', '[project#99]' => '<p>[project#99]</p>', '[@login]' => '<p>[@login]</p>', '[user:login]' => '<p>[user:login]</p>',
            '[user:user@example.org]' => '<p>[user:user@example.org]</p>', '[@user@example.org]' => '<p>[@user@example.org]</p>', '[printscreen@2x.png]' => '<p>[printscreen@2x.png]</p>',
            // test_markdown_should_not_require_surrounded_empty_line, test_should_emphasize_text, test_should_strike_through_text
            "This is a list:\n* One\n* Two\n" => '<p>This is a list:</p><ul><li>One</li><li>Two</li></ul>',
            'This _text_ should be emphasized' => '<p>This <em>text</em> should be emphasized</p>', 'This ~~text~~ should be striked through' => '<p>This <del>text</del> should be striked through</p>',
            // test_should_autolink_urls_and_emails
            'http://www.redmine.org/projects/redmine/issues?utf8=✓' => '<p><a href="http://www.redmine.org/projects/redmine/issues?utf8=%E2%9C%93" class="external">http://www.redmine.org/projects/redmine/issues?utf8=✓</a></p>',
            '[Letters](https://yandex.ru/search/?text=кол-во)' => '<p><a href="https://yandex.ru/search/?text=%D0%BA%D0%BE%D0%BB-%D0%B2%D0%BE" class="external">Letters</a></p>',
            // test_should_escape_unwanted_tags
            "Unclosed <script> tag\n\nNext paragraph" => '<p>Unclosed &lt;script&gt; tag</p><p>Next paragraph</p>',
            // sanitization_filter_test: test_should_support_footnotes, test_should_remove_invalid_ids, test_should_allow_class_on_code_only
            '<a href="#fn-1" id="fnref-1-2">foo</a>' => '<p><a href="#fn-1" id="fnref-1-2">foo</a></p>', '<ol><li id="fn-1">footnote</li></ol>' => '<ol><li id="fn-1">footnote</li></ol>',
            '<a href="#fn1" id="foo">foo</a>' => '<p><a href="#fn1">foo</a></p>', '<ol><li id="foo">footnote</li></ol>' => '<ol><li>footnote</li></ol>',
            '<p class="foo">bar</p>' => '<p>bar</p>', '<code class="language-ruby">foo</code>' => '<p><code class="language-ruby">foo</code></p>',
            // test_should_allow_links_with_safe_url_schemes, test_should_allow_mailto_links, test_should_remove_empty_link
            '<a href="ssh://example.org/">foo</a>' => '<p><a href="ssh://example.org/" class="external">foo</a></p>', '<a href="foo://example.org/">foo</a>' => '<p><a href="foo://example.org/" class="external">foo</a></p>',
            '<a href="mailto:foo@example.org">bar</a>' => '<p><a href="mailto:foo@example.org" class="email">bar</a></p>', '<a href=" ">bar</a>' => '<p><a>bar</a></p>',
            // test_should_sanitize_html_strings (samples of the Sanitize test suite)
            '<span style="color: #333; background: url(\'https://example.com/evil.svg\')">hello</span>' => '<p><span style="color: #333;">hello</span></p>',
            '<span style="background: #fdd; vertical-align: top; border-top-left-radius: 4px;">hello</span>' => '<p><span style="background: #fdd;vertical-align: top;border-top-left-radius: 4px;">hello</span></p>',
            '<p>a</p><blockquote>b' => '<p>a</p><blockquote>b</blockquote>',
            '<b>Lo<!-- comment -->rem</b> <a href="javascript:pants" title="foo">ipsum</a> <a href="http://example.com/"><strong>dolor</strong></a> sit<br/>amet' => '<p><b>Lorem</b> <a title="foo">ipsum</a> <a href="http://example.com/" class="external"><strong>dolor</strong></a> sit<br>amet</p>',
            // external_links_scrubber_test
            '<a target="_blank" href="http://example.net/" rel="nofollow">link</a>' => '<p><a target="_blank" href="http://example.net/" rel="nofollow noopener" class="external">link</a></p>',
            '<a href="relative">relative</a>' => '<p><a href="relative">relative</a></p>', '<a href="http://example.com/foo#bar#">Malformed URI</a>' => '<p><a href="http://example.com/foo#bar#" class="external">Malformed URI</a></p>',
            // alerts_icons_scrubber_test: test_should_not_translate_title_if_overridden
            "> [!note] Custom Note Title\n> This is a note.\n" => '<div class="markdown-alert markdown-alert-note"><p class="markdown-alert-title"><svg class="s18 icon-svg" aria-hidden="true"><use href="/assets/icons.svg#icon--help"></use></svg><span class="icon-label">Custom Note Title</span></p><p>This is a note.</p></div>',
            // common_mark/application_helper_test: test_toc_with_markdown_formatting_should_be_parsed
            "{{<toc}}\n\n# Heading" => '<ul class="toc left"><li><strong>Table of contents</strong></li><li><a href="#Heading">Heading</a></li></ul><h1>Heading</h1>',
        ], false);
        // test_should_support_syntax_highlight_for_language_with_special_chars
        $crawler = new \Symfony\Component\DomCrawler\Crawler($this->render("~~~c++\nint main() {\n}\n~~~\n"));
        self::assertSame('c++ syntaxhl', $crawler->filter('pre code')->attr('class'));
        self::assertSame('c++', $crawler->filter('pre code')->attr('data-language'));
        // test_syntax_error_in_image_reference_should_not_raise_exception, test_empty_image_should_not_raise_exception
        $this->render('!>[](foo.png)');
        $this->render('![]()');
        // common_mark/application_helper_test: test_attached_images_with_markdown_and_non_ascii_filename
        $image = $this->attach('Issue', 13, 'crème.jpg', self::png(), 'image/jpeg');
        self::assertStringContainsString('<img src="/attachments/download/'.$image->id.'/cr%C3%A8me.jpg" alt="" loading="lazy">', $this->render('![](crème.jpg)', ['issue_id' => '13']));
    }

    // ------------------------------------------------------------------------------------------
    // Previews, feeds, quote and search
    // ------------------------------------------------------------------------------------------

    /** messages#preview: @previewed is the message, find_attachments gives the pending uploads */
    public function testForumPreviewResolvesAttachments(): void
    {
        $this->actAs('admin');
        $image = $this->attach('Message', 1, 'topic.png', self::png(), 'image/png');
        $pending = $this->attach(null, null, 'pending.png', self::png(), 'image/png');
        $token = static::getContainer()->get(AttachmentService::class)->token($pending);
        $csrf = $this->csrf();
        $text = '![](topic.png) attachment:topic.png ![](pending.png)';

        $this->client->request('POST', '/boards/1/topics/preview?id=1', ['text' => $text, '_token' => $csrf, 'attachments' => ['1' => ['token' => $token]]]);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<img src="/attachments/download/'.$image->id.'/topic.png"', $html);
        self::assertStringContainsString('<a href="/attachments/'.$image->id.'/topic.png" class="attachment">topic.png</a>', $html);
        self::assertStringContainsString('<img src="/attachments/download/'.$pending->id.'/pending.png"', $html);

        // a new topic has no message yet: only the pending uploads
        $this->client->request('POST', '/boards/1/topics/preview', ['text' => $text, '_token' => $csrf, 'attachments' => ['1' => ['token' => $token]]]);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<img src="topic.png"', $html);
        self::assertStringContainsString('<img src="/attachments/download/'.$pending->id.'/pending.png"', $html);
        // a message of another board is not the previewed message
        $this->em->getConnection()->insert('boards', ['project_id' => 1, 'name' => 'Other', 'description' => 'x', 'position' => 2, 'topics_count' => 0, 'messages_count' => 0]);
        $other = (int) $this->em->getConnection()->lastInsertId();
        $this->client->request('POST', '/boards/'.$other.'/topics/preview?id=1', ['text' => $text, '_token' => $csrf]);
        self::assertStringContainsString('<img src="topic.png"', (string) $this->client->getResponse()->getContent());
    }

    /** common/feed.atom.builder: textilizable(item, :event_description, :only_path => false) in every feed */
    public function testFeedsResolveAttachmentsOfTheirItems(): void
    {
        $this->actAs('admin');
        $issueImage = $this->attach('Issue', 13, 'issue.png', self::png(), 'image/png');
        $projectImage = $this->attach('Project', 1, 'logo.png', self::png(), 'image/png');
        $messageImage = $this->attach('Message', 1, 'topic.png', self::png(), 'image/png');
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE issues SET description = 'Shot ![](issue.png) attachment:issue.png', created_on = ?, updated_on = ? WHERE id = 13", [date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $conn->executeStatement("UPDATE projects SET description = 'Logo ![](logo.png)' WHERE id = 1");
        $conn->executeStatement("UPDATE messages SET content = 'Topic ![](topic.png)', created_on = ?, updated_on = ? WHERE id = 1", [date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $this->em->clear();
        $issueSrc = '<img src="http://localhost/attachments/download/'.$issueImage->id.'/issue.png"';

        $feed = html_entity_decode($this->get('/projects/ecommerce/issues.atom'));
        self::assertStringContainsString($issueSrc, $feed);
        self::assertStringContainsString('<a href="http://localhost/attachments/'.$issueImage->id.'/issue.png" class="attachment">issue.png</a>', $feed);

        $feed = html_entity_decode($this->get('/projects/ecommerce/activity.atom?show_issues=1&show_messages=1'));
        self::assertStringContainsString($issueSrc, $feed);
        self::assertStringContainsString('<img src="http://localhost/attachments/download/'.$messageImage->id.'/topic.png"', $feed);

        $feed = html_entity_decode($this->get('/projects.atom'));
        self::assertStringContainsString('<img src="http://localhost/attachments/download/'.$projectImage->id.'/logo.png"', $feed);
    }

    /** routes.rb: match 'boards/:board_id/topics/quote/:id'; messages#quote answers format.js, format.html is a 404 */
    public function testQuoteRouteWithoutJsSuffix(): void
    {
        $this->actAs('admin');
        $csrf = $this->csrf();
        $this->client->request('POST', '/boards/1/topics/quote/1', ['_token' => $csrf], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/javascript, application/javascript, */*; q=0.01']);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString("$('#message_subject').val(\"RE: Hello\");", (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('text/javascript', (string) $this->client->getResponse()->headers->get('Content-Type'));
        // the ".js" URL of the page keeps working
        $this->client->request('POST', '/boards/1/topics/quote/1.js', ['_token' => $csrf]);
        self::assertSame(200, $this->httpStatus());
        // format.html { render_404 }
        $this->client->request('POST', '/boards/1/topics/quote/1', ['_token' => $csrf], [], ['HTTP_ACCEPT' => 'text/html']);
        self::assertSame(404, $this->httpStatus());
    }

    /** search#index: render :layout => false if request.xhr? */
    public function testSearchOverXhrIsRenderedWithoutTheLayout(): void
    {
        $this->actAs('admin');
        $this->client->request('GET', '/search?q=cart', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->httpStatus());
        self::assertStringNotContainsString('<html', $html);
        self::assertStringNotContainsString('id="main-menu"', $html);
        self::assertStringContainsString('id="search-results"', $html);
        self::assertStringContainsString('total is wrong with discounts', $html);

        $html = $this->get('/search?q=cart');
        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('id="search-results"', $html);
        $this->client->request('GET', '/projects/ecommerce/search?q=cart', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
    }
}