<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Converts Redmine's config/locales/*.yml into Symfony translation catalogues (translations/messages.<locale>.yaml).
 * Keys are flattened ("date.formats.default"), plural forms become "<key>.one" / "<key>.other",
 * Ruby interpolation "%{name}" is kept verbatim and resolved by the RedmineTranslator.
 */
#[AsCommand(name: 'symine:locales:import', description: 'Import Redmine locale files as Symfony translations')]
final class ImportRedmineLocalesCommand
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to Redmine config/locales')] string $path = 'reference/redmine/config/locales',
    ): int {
        $path = str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
        $count = 0;
        foreach (glob($path.'/*.yml') ?: [] as $file) {
            $raw = (string) file_get_contents($file);
            // Ruby symbols in arrays ("- :year") are plain strings for us
            $raw = preg_replace('/^(\s*-\s+):(\w+)\s*$/m', '$1$2', $raw) ?? $raw;
            try {
                $data = Yaml::parse($raw);
            } catch (\Throwable) {
                // Some Redmine locales contain duplicate keys (accepted by Ruby): keep the last one
                try {
                    $data = Yaml::parse($this->dedupe($raw));
                } catch (\Throwable) {
                    // Last resort: let Ruby's own YAML parser read it (dev-time only)
                    $json = shell_exec('ruby -ryaml -rjson -e '.escapeshellarg('puts JSON.generate(YAML.load_file(ARGV[0]))').' '.escapeshellarg($file));
                    $data = json_decode((string) $json, true);
                    if (!\is_array($data)) {
                        $io->warning('Skipping unparsable locale '.basename($file));
                        continue;
                    }
                }
            }
            $locale = (string) array_key_first($data);
            $flat = [];
            $this->flatten($data[$locale] ?? [], '', $flat);
            ksort($flat);
            file_put_contents(
                \sprintf('%s/translations/messages.%s.yaml', $this->projectDir, $locale),
                "# Generated from Redmine ".basename($file)." by symine:locales:import\n".Yaml::dump($flat, 1, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)
            );
            ++$count;
        }
        $io->success(\sprintf('%d locales imported', $count));

        return 0;
    }

    private function dedupe(string $raw): string
    {
        $lines = explode("\n", $raw);
        $stack = [];
        $seen = [];
        $drop = [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/^(\s*)([^\s#:-][^:]*):(\s|$)/', $line, $m)) {
                continue;
            }
            $indent = \strlen($m[1]);
            while ($stack && end($stack)[0] >= $indent) {
                array_pop($stack);
            }
            $path = implode('.', array_column($stack, 1)).'.'.$m[2];
            if (isset($seen[$path])) {
                $drop[$seen[$path]] = true;
            }
            $seen[$path] = $i;
            $stack[] = [$indent, $m[2]];
        }

        return implode("\n", array_filter($lines, static fn ($i) => !isset($drop[$i]), \ARRAY_FILTER_USE_KEY));
    }

    /** @param array<string, string> $out */
    private function flatten(mixed $node, string $prefix, array &$out): void
    {
        if (\is_array($node) && array_is_list($node)) {
            foreach ($node as $i => $v) {
                $out[$prefix.'.'.$i] = null === $v ? '' : (\is_scalar($v) ? (string) $v : json_encode($v));
            }

            return;
        }
        if (\is_array($node)) {
            foreach ($node as $k => $v) {
                $this->flatten($v, '' === $prefix ? (string) $k : $prefix.'.'.$k, $out);
            }

            return;
        }
        $out[$prefix] = null === $node ? '' : (\is_bool($node) ? ($node ? 'true' : 'false') : (string) $node);
    }
}
