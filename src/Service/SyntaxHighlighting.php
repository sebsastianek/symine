<?php

declare(strict_types=1);

namespace App\Service;

use Highlight\Highlighter;

/**
 * Port of lib/redmine/syntax_highlighting.rb with highlight.php instead of Rouge.
 *
 * highlight.php (highlight.js 9) produces nested <span class="hljs-*"> elements; they are flattened
 * into Rouge's short token classes (k, s2, c1, nf, ...) that Redmine's application.css styles
 * under .syntaxhl, one token per span and - for files - each line self-contained
 * (Rouge CustomHTMLLinewise).
 */
final class SyntaxHighlighting
{
    /** Alias names used by CodeRay and not supported by Rouge (SyntaxHighlighting::Rouge::LANG_ALIASES) */
    public const array LANG_ALIASES = [
        'delphi' => 'pascal',
        'cplusplus' => 'cpp',
        'ecmascript' => 'javascript',
        'ecma_script' => 'javascript',
        'java_script' => 'javascript',
        'xhtml' => 'html',
    ];

    /** Rouge lexer tags/aliases that highlight.php names differently */
    private const array ROUGE_TAGS = [
        'html' => 'xml', 'xhtml' => 'xml', 'svg' => 'xml', 'xsl' => 'xml', 'xslt' => 'xml', 'plist' => 'xml',
        'c' => 'cpp', 'c++' => 'cpp', 'cplusplus' => 'cpp', 'objc' => 'objectivec', 'objective_c' => 'objectivec', 'objective-c' => 'objectivec',
        'shell' => 'bash', 'sh' => 'bash', 'zsh' => 'bash', 'ksh' => 'bash', 'console' => 'shell', 'terminal' => 'shell', 'shell_session' => 'shell',
        'csharp' => 'cs', 'c#' => 'cs', 'pascal' => 'delphi', 'objectpascal' => 'delphi',
        'text' => 'plaintext', 'plain' => 'plaintext', 'txt' => 'plaintext',
        'make' => 'makefile', 'mf' => 'makefile', 'bsdmake' => 'makefile', 'docker' => 'dockerfile',
        'jsonc' => 'json', 'json-doc' => 'json', 'json5' => 'json', 'toml' => 'ini', 'conf' => 'ini', 'config' => 'ini', 'cfg' => 'ini',
        'postgresql' => 'pgsql', 'psql' => 'pgsql', 'plsql' => 'sql', 'mysql' => 'sql', 'sqlite' => 'sql', 'tsql' => 'sql',
        'golang' => 'go', 'viml' => 'vim', 'vimscript' => 'vim', 'latex' => 'tex', 'erlang' => 'erlang', 'elixir' => 'elixir', 'exs' => 'elixir',
        'posh' => 'powershell', 'ps1' => 'powershell', 'batchfile' => 'dos', 'bat' => 'dos', 'sass' => 'scss',
        'tsx' => 'typescript', 'jsx' => 'javascript', 'ecmascript' => 'javascript', 'node' => 'javascript',
        'rhtml' => 'erb', 'eruby' => 'erb', 'irb' => 'ruby', 'rails' => 'ruby', 'py' => 'python', 'py3' => 'python', 'python3' => 'python',
        'apacheconf' => 'apache', 'nginxconf' => 'nginx', 'proto' => 'protobuf', 'gherkin' => 'gherkin', 'cucumber' => 'gherkin',
        'vb' => 'vbnet', 'visualbasic' => 'vbnet', 'jinja' => 'django', 'liquid' => 'django', 'nim' => 'nimrod', 'ocaml' => 'ocaml', 'sml' => 'sml',
        'patch' => 'diff', 'udiff' => 'diff', 'common_lisp' => 'lisp', 'cl' => 'lisp', 'elisp' => 'lisp', 'emacs-lisp' => 'lisp',
        'clj' => 'clojure', 'cljs' => 'clojure', 'f90' => 'fortran', 'kt' => 'kotlin', 'kts' => 'kotlin', 'rs' => 'rust', 'rb' => 'ruby',
        'pl' => 'perl', 'pm' => 'perl', 'hs' => 'haskell', 'ts' => 'typescript', 'yml' => 'yaml', 'md' => 'markdown', 'mkd' => 'markdown',
        'properties' => 'properties', 'http' => 'http', 'actionscript' => 'actionscript', 'as3' => 'actionscript', 'vue' => 'xml',
    ];

    /** Rouge::Lexer.guess by filename: extension / file name => highlight.php language */
    private const array EXTENSIONS = [
        'rb' => 'ruby', 'rbw' => 'ruby', 'rbx' => 'ruby', 'rake' => 'ruby', 'gemspec' => 'ruby', 'ru' => 'ruby', 'podspec' => 'ruby', 'thor' => 'ruby', 'jbuilder' => 'ruby', 'rabl' => 'ruby',
        'erb' => 'erb', 'rhtml' => 'erb',
        'py' => 'python', 'pyw' => 'python', 'pyi' => 'python', 'wsgi' => 'python', 'tac' => 'python', 'gyp' => 'python',
        'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript', 'jsx' => 'javascript', 'es6' => 'javascript',
        'ts' => 'typescript', 'tsx' => 'typescript', 'mts' => 'typescript', 'cts' => 'typescript',
        'json' => 'json', 'jsonc' => 'json', 'json5' => 'json', 'geojson' => 'json', 'jsonld' => 'json', 'webmanifest' => 'json',
        'php' => 'php', 'php3' => 'php', 'php4' => 'php', 'php5' => 'php', 'php7' => 'php', 'phtml' => 'php', 'inc' => 'php',
        'java' => 'java', 'jsp' => 'java', 'kt' => 'kotlin', 'kts' => 'kotlin', 'scala' => 'scala', 'sc' => 'scala', 'groovy' => 'groovy', 'gradle' => 'gradle',
        'c' => 'cpp', 'h' => 'cpp', 'cpp' => 'cpp', 'cc' => 'cpp', 'cxx' => 'cpp', 'c++' => 'cpp', 'hpp' => 'cpp', 'hh' => 'cpp', 'hxx' => 'cpp', 'h++' => 'cpp', 'ino' => 'arduino',
        'cs' => 'cs', 'go' => 'go', 'rs' => 'rust', 'swift' => 'swift', 'm' => 'objectivec', 'mm' => 'objectivec', 'd' => 'd', 'dart' => 'dart',
        'sh' => 'bash', 'bash' => 'bash', 'zsh' => 'bash', 'ksh' => 'bash', 'ebuild' => 'bash', 'eclass' => 'bash', 'exheres-0' => 'bash', 'exlib' => 'bash',
        'ps1' => 'powershell', 'psm1' => 'powershell', 'psd1' => 'powershell', 'bat' => 'dos', 'cmd' => 'dos',
        'sql' => 'sql', 'ddl' => 'sql', 'dml' => 'sql',
        'html' => 'xml', 'htm' => 'xml', 'xhtml' => 'xml', 'xml' => 'xml', 'xsl' => 'xml', 'xslt' => 'xml', 'xsd' => 'xml', 'svg' => 'xml', 'rss' => 'xml',
        'atom' => 'xml', 'plist' => 'xml', 'wsdl' => 'xml', 'xaml' => 'xml', 'xul' => 'xml', 'rdf' => 'xml', 'kml' => 'xml', 'vue' => 'xml', 'csproj' => 'xml', 'pom' => 'xml',
        'css' => 'css', 'scss' => 'scss', 'sass' => 'scss', 'less' => 'less', 'styl' => 'stylus',
        'yml' => 'yaml', 'yaml' => 'yaml', 'md' => 'markdown', 'markdown' => 'markdown', 'mkd' => 'markdown', 'mkdn' => 'markdown', 'mdown' => 'markdown',
        'ini' => 'ini', 'cfg' => 'ini', 'toml' => 'ini', 'conf' => 'ini', 'properties' => 'properties', 'prefs' => 'ini', 'editorconfig' => 'ini',
        'mk' => 'makefile', 'mak' => 'makefile', 'make' => 'makefile', 'dockerfile' => 'dockerfile', 'cmake' => 'cmake',
        'diff' => 'diff', 'patch' => 'diff', 'pl' => 'perl', 'pm' => 'perl', 't' => 'perl', 'lua' => 'lua', 'r' => 'r', 'hs' => 'haskell', 'lhs' => 'haskell',
        'ex' => 'elixir', 'exs' => 'elixir', 'erl' => 'erlang', 'hrl' => 'erlang', 'clj' => 'clojure', 'cljs' => 'clojure', 'cljc' => 'clojure', 'edn' => 'clojure',
        'ml' => 'ocaml', 'mli' => 'ocaml', 'fs' => 'fsharp', 'fsi' => 'fsharp', 'fsx' => 'fsharp', 'vb' => 'vbnet', 'vbs' => 'vbscript',
        'pas' => 'delphi', 'dpr' => 'delphi', 'lpr' => 'delphi', 'tex' => 'tex', 'sty' => 'tex', 'cls' => 'tex', 'coffee' => 'coffeescript', 'tcl' => 'tcl',
        'vim' => 'vim', 'proto' => 'protobuf', 'thrift' => 'thrift', 'haml' => 'haml', 'twig' => 'twig', 'hbs' => 'handlebars', 'handlebars' => 'handlebars',
        'mustache' => 'handlebars', 'jl' => 'julia', 'nim' => 'nimrod', 'cr' => 'crystal', 'f90' => 'fortran', 'f95' => 'fortran', 'f' => 'fortran', 'for' => 'fortran',
        'adb' => 'ada', 'ads' => 'ada', 'asm' => 'x86asm', 's' => 'armasm', 'v' => 'verilog', 'sv' => 'verilog', 'vhd' => 'vhdl', 'vhdl' => 'vhdl',
        'scm' => 'scheme', 'ss' => 'scheme', 'lisp' => 'lisp', 'lsp' => 'lisp', 'el' => 'lisp', 'awk' => 'awk', 'nix' => 'nix', 'pp' => 'puppet',
        'feature' => 'gherkin', 'elm' => 'elm', 'sml' => 'sml', 'st' => 'smalltalk', 'glsl' => 'glsl', 'vert' => 'glsl', 'frag' => 'glsl', 'geom' => 'glsl',
        'dts' => 'dts', 'dtsi' => 'dts', 'http' => 'http', 'nginx' => 'nginx', 'gcode' => 'gcode', 'sas' => 'sas', 'stan' => 'stan', 'tf' => 'ini',
        'hx' => 'haxe', 'vala' => 'vala', 'mel' => 'mel', 'moon' => 'moonscript', 'ls' => 'livescript', 'pony' => 'pony', 're' => 'reasonml', 'zep' => 'zephir',
        'applescript' => 'applescript', 'scpt' => 'applescript', 'ahk' => 'autohotkey', 'au3' => 'autoit', 'bf' => 'brainfuck', 'prolog' => 'prolog',
        'mo' => 'mercury', 'nsi' => 'nsis', 'nsh' => 'nsis', 'qml' => 'qml', 'rsc' => 'routeros', 'smali' => 'smali', 'tcsh' => 'bash', 'csh' => 'bash',
        'lisp-expr' => 'lisp', 'jbs' => 'ruby', 'cmake.in' => 'cmake', 'pgsql' => 'pgsql', 'ldif' => 'ldif', 'abnf' => 'abnf', 'bnf' => 'bnf', 'ebnf' => 'ebnf',
    ];

    private const array FILENAMES = [
        'rakefile' => 'ruby', 'gemfile' => 'ruby', 'guardfile' => 'ruby', 'capfile' => 'ruby', 'vagrantfile' => 'ruby', 'podfile' => 'ruby',
        'brewfile' => 'ruby', 'berksfile' => 'ruby', 'thorfile' => 'ruby', 'appraisals' => 'ruby', 'fastfile' => 'ruby', 'dangerfile' => 'ruby',
        'makefile' => 'makefile', 'gnumakefile' => 'makefile', 'dockerfile' => 'dockerfile', 'containerfile' => 'dockerfile', 'cmakelists.txt' => 'cmake',
        'sconstruct' => 'python', 'sconscript' => 'python', '.bashrc' => 'bash', '.bash_profile' => 'bash', '.zshrc' => 'bash', '.profile' => 'bash',
        'pkgbuild' => 'bash', 'apkbuild' => 'bash', '.vimrc' => 'vim', '_vimrc' => 'vim', '.gitconfig' => 'ini', '.editorconfig' => 'ini',
        '.htaccess' => 'apache', 'httpd.conf' => 'apache', 'apache.conf' => 'apache', 'apache2.conf' => 'apache', 'nginx.conf' => 'nginx', 'jenkinsfile' => 'groovy',
    ];

    /** Display names of the languages for the help page */
    private const array NAMES = [
        'xml' => 'HTML, XML', 'cpp' => 'C/C++', 'cs' => 'C#', 'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'php' => 'PHP', 'sql' => 'SQL',
        'css' => 'CSS', 'scss' => 'SCSS', 'json' => 'JSON', 'yaml' => 'YAML', 'objectivec' => 'Objective-C', 'pgsql' => 'PostgreSQL SQL dialect',
        'dos' => 'Batch file (DOS)', 'bash' => 'Bash', 'shell' => 'Shell Session', 'plaintext' => 'Plain text', 'erb' => 'ERB (Embedded Ruby)', 'ini' => 'INI, TOML',
        'http' => 'HTTP', 'vbnet' => 'VB.NET', 'vbscript' => 'VBScript', 'fsharp' => 'F#', 'x86asm' => 'Intel x86 Assembly', 'armasm' => 'ARM Assembly',
        'mipsasm' => 'MIPS Assembly', 'avrasm' => 'AVR Assembly', 'dockerfile' => 'Dockerfile', 'makefile' => 'Makefile', 'cmake' => 'CMake', 'llvm' => 'LLVM IR',
        'glsl' => 'GLSL', 'hsp' => 'HSP', 'vhdl' => 'VHDL', 'ocaml' => 'OCaml', 'sml' => 'SML (Standard ML)', 'nimrod' => 'Nim', 'reasonml' => 'ReasonML',
        'ldif' => 'LDIF', 'abnf' => 'Augmented Backus-Naur Form', 'bnf' => 'Backus-Naur Form', 'ebnf' => 'Extended Backus-Naur Form', 'dns' => 'DNS Zone',
        'n1ql' => 'N1QL', 'qml' => 'QML', 'sas' => 'SAS', 'sqf' => 'SQF', 'tp' => 'TP', 'xl' => 'XL', 'xquery' => 'XQuery', 'rsl' => 'RenderMan RSL',
        'rib' => 'RenderMan RIB', 'pf' => 'PF', 'dts' => 'Device Tree', 'gcode' => 'G-code (ISO 6983)', 'gml' => 'GML', 'isbl' => 'ISBL', '1c' => '1C:Enterprise',
        'cos' => 'Caché Object Script', 'jboss-cli' => 'JBoss CLI', 'crmsh' => 'crmsh', 'coffeescript' => 'CoffeeScript', 'livescript' => 'LiveScript',
        'moonscript' => 'MoonScript', 'powershell' => 'PowerShell', 'actionscript' => 'ActionScript', 'angelscript' => 'AngelScript', 'applescript' => 'AppleScript',
        'autohotkey' => 'AutoHotkey', 'autoit' => 'AutoIt', 'capnproto' => "Cap'n Proto", 'clojure-repl' => 'Clojure REPL', 'erlang-repl' => 'Erlang REPL',
        'julia-repl' => 'Julia REPL', 'openscad' => 'OpenSCAD', 'purebasic' => 'PureBASIC', 'routeros' => 'Microtik RouterOS script', 'vbscript-html' => 'VBScript in HTML',
        'tex' => 'TeX', 'htmlbars' => 'HTMLBars', 'livecodeserver' => 'LiveCode', 'mathematica' => 'Mathematica', 'matlab' => 'Matlab', 'delphi' => 'Delphi / Pascal',
    ];

    private ?Highlighter $highlighter = null;
    /** @var array<string, bool>|null */
    private ?array $languages = null;

    public function __construct(private readonly ?Settings $settings = null)
    {
    }

    private function highlighter(): Highlighter
    {
        return $this->highlighter ??= new Highlighter();
    }

    /** @return array<string, bool> registered languages and aliases (lowercase) */
    private function languages(): array
    {
        if (null === $this->languages) {
            $this->languages = [];
            foreach (Highlighter::listRegisteredLanguages(true) as $l) {
                $this->languages[strtolower((string) $l)] = true;
            }
        }

        return $this->languages;
    }

    /** highlight.php language for a Rouge/CodeRay language name, null when unsupported (find_lexer) */
    public function findLanguage(?string $language): ?string
    {
        $language = strtolower(trim((string) $language));
        if ('' === $language) {
            return null;
        }
        foreach ([$language, self::LANG_ALIASES[$language] ?? null] as $l) {
            if (null === $l) {
                continue;
            }
            if (isset(self::ROUGE_TAGS[$l])) {
                return self::ROUGE_TAGS[$l];
            }
            if (isset($this->languages()[$l])) {
                return $l;
            }
        }

        return null;
    }

    public function languageSupported(?string $language): bool
    {
        return null !== $this->findLanguage($language);
    }

    /** Language guessed from the file name (and the shebang line of the content) */
    public function languageForFilename(string $filename, ?string $source = null): ?string
    {
        $base = strtolower(basename(str_replace('\\', '/', $filename)));
        if (isset(self::FILENAMES[$base])) {
            return self::FILENAMES[$base];
        }
        if (str_starts_with($base, 'dockerfile.') || str_ends_with($base, '.dockerfile')) {
            return 'dockerfile';
        }
        $ext = str_contains($base, '.') ? substr($base, (int) strrpos($base, '.') + 1) : '';
        if ('' !== $ext && isset(self::EXTENSIONS[$ext])) {
            return self::EXTENSIONS[$ext];
        }
        if (null !== $source && preg_match('/\A#!\s*\S*?(?:env\s+)?\b(python[0-9.]*|ruby|perl|bash|sh|zsh|ksh|node|nodejs|php|lua|tclsh|Rscript)\b/', $source, $m)) {
            return match (true) {
                str_starts_with($m[1], 'python') => 'python',
                \in_array($m[1], ['sh', 'zsh', 'ksh', 'bash'], true) => 'bash',
                \in_array($m[1], ['node', 'nodejs'], true) => 'javascript',
                'tclsh' === $m[1] => 'tcl',
                'Rscript' === $m[1] => 'r',
                default => $m[1],
            };
        }

        return null;
    }

    public function filenameSupported(string $filename): bool
    {
        return null !== $this->languageForFilename($filename);
    }

    /**
     * Highlights $text as the content of $filename (line by line, no line numbers nor <pre>).
     * Every line ends with "\n" and its spans are closed on the line.
     */
    public function highlightByFilename(string $text, string $filename): string
    {
        $text = (string) preg_replace('/\r\n?/', "\n", $text);
        $language = $this->languageForFilename($filename, $text);
        $tokens = null !== $language ? $this->tokens($language, $text) : null;
        $tokens ??= [[null, htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')]];
        $out = '';
        $line = '';
        foreach ($tokens as [$class, $value]) {
            $parts = explode("\n", $value);
            foreach ($parts as $i => $part) {
                if ($i > 0) {
                    $out .= $line."\n";
                    $line = '';
                }
                if ('' !== $part) {
                    $line .= null === $class ? $part : '<span class="'.$class.'">'.$part.'</span>';
                }
            }
        }
        if ('' !== $line) {
            $out .= $line."\n";
        }

        return $out;
    }

    /** ApplicationHelper#syntax_highlight_lines: highlighted lines (each with its "\n") */
    public function highlightLines(string $filename, ?string $content): array
    {
        $html = $this->highlightByFilename($this->toUtf8BySetting((string) $content), $filename);

        return preg_split('/(?<=\n)/', $html, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Highlights $text using the $language syntax (no outer <pre>); escaped plain text when unsupported */
    public function highlightByLanguage(string $text, string $language): string
    {
        $lang = $this->findLanguage($language);
        $tokens = null !== $lang ? $this->tokens($lang, $text) : null;
        if (null === $tokens) {
            return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }
        $out = '';
        foreach ($tokens as [$class, $value]) {
            $out .= null === $class ? $value : '<span class="'.$class.'">'.$value.'</span>';
        }

        return $out;
    }

    /**
     * Supported languages for the help page (Rouge::Lexer.all sorted by tag).
     *
     * @return list<array{tag: string, desc: string, aliases: list<string>}>
     */
    public function lexers(): array
    {
        $out = [];
        $hl = $this->highlighter();
        foreach (Highlighter::listRegisteredLanguages() as $lang) {
            $lang = (string) $lang;
            $aliases = array_values(array_unique(array_filter(array_map('strval', $hl->getAliasesForLanguage($lang) ?: []), static fn ($a) => $a !== $lang)));
            foreach (self::ROUGE_TAGS as $rouge => $target) {
                if ($target === $lang && !\in_array($rouge, $aliases, true) && $rouge !== $lang) {
                    $aliases[] = $rouge;
                }
            }
            sort($aliases);
            $out[] = ['tag' => $lang, 'desc' => self::NAMES[$lang] ?? ucfirst($lang), 'aliases' => $aliases];
        }
        usort($out, static fn ($a, $b) => strcmp($a['tag'], $b['tag']));

        return $out;
    }

    /** Redmine::CodesetUtil.to_utf8_by_setting (repositories_encodings) */
    public function toUtf8BySetting(string $str): string
    {
        if ('' === $str || preg_match('/\A[\r\n\t\x20-\x7e]*\z/', $str)) {
            return $str;
        }
        $encodings = array_values(array_filter(array_map('trim', explode(',', (string) $this->settings?->string('repositories_encodings')))));
        foreach ($encodings as $encoding) {
            try {
                if (0 === strcasecmp($encoding, 'UTF-8')) {
                    if (mb_check_encoding($str, 'UTF-8')) {
                        return $str;
                    }
                    continue;
                }
                $utf8 = @mb_convert_encoding($str, 'UTF-8', $encoding);
                if (false !== $utf8 && mb_check_encoding($utf8, 'UTF-8')) {
                    return $utf8;
                }
            } catch (\ValueError) {
                // invalid encoding name: try the next one
            }
        }

        return mb_check_encoding($str, 'UTF-8') ? $str : mb_scrub($str, 'UTF-8');
    }

    /**
     * Flat token stream [rouge class|null, escaped html text] of the highlight.php output.
     *
     * @return list<array{0: ?string, 1: string}>|null
     */
    private function tokens(string $language, string $text): ?array
    {
        if ('plaintext' === $language) {
            return [[null, htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')]];
        }
        try {
            $html = (string) $this->highlighter()->highlight($language, $text)->value;
        } catch (\Throwable) {
            return null;
        }
        $parts = preg_split('#(<span class="[^"]*">|</span>)#', $html, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [];
        $stack = []; // list of [hljs class, rouge class|null]
        $tokens = [];
        $n = \count($parts);
        for ($i = 0; $i < $n; ++$i) {
            $part = $parts[$i];
            if ('</span>' === $part) {
                array_pop($stack);
                continue;
            }
            if (str_starts_with($part, '<span class="')) {
                $hljs = (string) preg_replace('/^hljs-/', '', explode(' ', substr($part, 13, -2))[0]);
                $parent = [] === $stack ? null : $stack[\count($stack) - 1][0];
                $next = $i + 1 < $n && !str_starts_with($parts[$i + 1], '<') ? html_entity_decode($parts[$i + 1], \ENT_QUOTES | \ENT_HTML5) : '';
                $stack[] = [$hljs, self::rougeClass($hljs, $parent, $next)];
                continue;
            }
            $class = null;
            for ($j = \count($stack) - 1; $j >= 0; --$j) {
                if (null !== $stack[$j][1]) {
                    $class = $stack[$j][1];
                    break;
                }
            }
            if ('m' === $class) {
                $class = self::numberClass(html_entity_decode($part));
            }
            $last = \count($tokens) - 1;
            if ($last >= 0 && $tokens[$last][0] === $class) {
                $tokens[$last][1] .= $part; // adjacent tokens of the same class
            } else {
                $tokens[] = [$class, $part];
            }
        }

        return $tokens;
    }

    /** hljs scope => Rouge short token class (null for containers) */
    private static function rougeClass(string $hljs, ?string $parent, string $text): ?string
    {
        return match ($hljs) {
            'keyword' => 'k',
            'built_in', 'builtin-name' => 'nb',
            'type' => 'kt',
            'literal' => 'kc',
            'number' => 'm',
            'regexp' => 'sr',
            'string' => match (substr($text, 0, 1)) {
                '"' => 's2',
                "'" => 's1',
                '`' => 'sb',
                default => 's',
            },
            'subst' => 'si',
            'symbol' => 'ss',
            'title' => 'class' === $parent ? 'nc' : ('tag' === $parent ? 'nt' : 'nf'),
            'comment' => match (true) {
                str_starts_with($text, '/*'), str_starts_with($text, '<!--'), str_starts_with($text, '(*'), str_starts_with($text, '{-') => 'cm',
                str_starts_with($text, '#!') => 'ch',
                str_starts_with($text, '#'), str_starts_with($text, '//'), str_starts_with($text, '--'), str_starts_with($text, ';'), str_starts_with($text, '%') => 'c1',
                default => 'c',
            },
            'doctag' => 'cs',
            'quote' => 'c',
            'meta', 'meta-keyword' => 'cp',
            'meta-string' => 'cpf',
            'section' => 'gh',
            'name', 'selector-tag' => 'nt',
            'attr', 'attribute', 'selector-attr' => 'na',
            'variable', 'template-variable' => 'nv',
            'template-tag' => 'cp',
            'bullet' => 'k',
            'code' => 'sb',
            'emphasis' => 'ge',
            'strong' => 'gs',
            'formula' => 'm',
            'link' => 'nl',
            'selector-id' => 'nn',
            'selector-class' => 'nc',
            'selector-pseudo' => 'nd',
            'addition' => 'gi',
            'deletion' => 'gd',
            default => null, // class, function, params, tag, ... (containers)
        };
    }

    private static function numberClass(string $text): string
    {
        $t = strtolower(trim($text));

        return match (true) {
            1 === preg_match('/^0x[0-9a-f_]+$/', $t) => 'mh',
            1 === preg_match('/^0b[01_]+$/', $t) => 'mb',
            1 === preg_match('/^0o?[0-7_]+$/', $t) && \strlen($t) > 1 => 'mo',
            1 === preg_match('/^[+-]?\d[\d_]*$/', $t) => 'mi',
            1 === preg_match('/^[+-]?[\d_]*\.?\d[\d_]*(e[+-]?\d+)?[fd]?$/', $t) => 'mf',
            default => 'm',
        };
    }
}
