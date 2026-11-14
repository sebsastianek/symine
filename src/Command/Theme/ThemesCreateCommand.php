<?php

declare(strict_types=1);

namespace App\Command\Theme;

use App\Theme\Theme;
use App\Theme\ThemeManifest;
use App\Theme\ThemeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Scaffolds a theme in themes/<id>: a manifest, a stylesheet and a templates directory */
#[AsCommand(name: 'symine:themes:create', description: 'Creates a new theme in themes/')]
final class ThemesCreateCommand extends Command
{
    public function __construct(private readonly ThemeRegistry $registry, private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Directory name of the theme (letters, digits, "-" and "_")')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Theme to start from, e.g. "strata"')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name shown to people');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');
        $parent = $input->getOption('parent');
        $dir = $this->projectDir.'/themes/'.$id;

        if (!ThemeManifest::isThemeId($id)) {
            $io->error(\sprintf('"%s" cannot be used as a theme id: use letters, digits, "-" and "_" ("default" is taken).', $id));

            return Command::FAILURE;
        }
        if (file_exists($dir)) {
            $io->error(\sprintf('%s already exists.', $dir));

            return Command::FAILURE;
        }
        if (null !== $parent) {
            $parentTheme = $this->registry->get((string) $parent);
            if (null === $parentTheme || $parentTheme->legacy) {
                $io->error(\sprintf('"%s" is not a theme that can be extended (it needs a %s).', $parent, Theme::MANIFEST));

                return Command::FAILURE;
            }
        }
        $name = (string) ($input->getOption('name') ?? ThemeManifest::humanize(str_replace('-', ' ', $id)));

        if (!@mkdir($dir.'/stylesheets', 0o775, true) || !@mkdir($dir.'/templates', 0o775, true)) {
            $io->error(\sprintf('Cannot create %s.', $dir));

            return Command::FAILURE;
        }

        $manifest = "# See docs/THEMES.md for everything a theme can declare.\n"
            .'name: '.self::yamlString($name)."\n"
            ."description: ''\n"
            ."version: 0.1.0\n"
            .(null !== $parent ? 'parent: '.$parent."\n" : '')
            ."stylesheets:\n  - stylesheets/application.css\n"
            ."\n# CSS custom properties, without the leading \"--\"\ntokens: {}\n"
            .(null === $parent ? "\n# color_schemes:\n#   light: {label: Light}\n#   dark: {label: Dark, dark: true}\n" : '')
            ."\n# Options that administrators (and users, with \"user: true\") can change\noptions: {}\n";
        file_put_contents($dir.'/'.Theme::MANIFEST, $manifest);
        file_put_contents($dir.'/stylesheets/application.css', \sprintf("/* %s: loaded after %s. */\n", $name, null !== $parent ? 'the stylesheets of "'.$parent.'"' : "Redmine's stylesheets"));
        file_put_contents($dir.'/templates/.gitkeep', '');

        $this->registry->reset();
        $io->success(\sprintf('Created themes/%s. Select it in Administration > Themes, or run: bin/console symine:themes:use %s', $id, $id));

        return Command::SUCCESS;
    }

    private static function yamlString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
