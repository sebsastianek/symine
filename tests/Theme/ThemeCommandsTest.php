<?php

declare(strict_types=1);

namespace App\Tests\Theme;

use App\Service\Settings;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/** bin/console symine:themes:* */
final class ThemeCommandsTest extends KernelTestCase
{
    private Application $application;

    protected function setUp(): void
    {
        $project = \dirname(__DIR__, 2);
        copy($project.'/tests/fixtures/symine-seeded.db', $project.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->application = new Application(self::bootKernel());
    }

    private function run_(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->application->find($command));
        $tester->execute($input);

        return $tester;
    }

    public function testList(): void
    {
        $tester = $this->run_('symine:themes:list');
        self::assertSame(0, $tester->getStatusCode());
        $out = $tester->getDisplay();
        self::assertMatchesRegularExpression('/default \*\s+Default\s+built-in/', $out);
        self::assertMatchesRegularExpression('/strata\s+Strata\s+Symine\s+1\.0\.0/', $out);
        self::assertMatchesRegularExpression('/classic\s+Classic\s+Redmine/', $out);
        self::assertMatchesRegularExpression('/fx_child\s+Fixture child\s+Symine\s+fx_base/', $out);
        self::assertStringContainsString('Theme "fx_broken" cannot be used', $out);
    }

    public function testLint(): void
    {
        $tester = $this->run_('symine:themes:lint');
        self::assertSame(1, $tester->getStatusCode(), 'the fixtures contain broken themes');
        $out = $tester->getDisplay();
        self::assertMatchesRegularExpression('/OK\s+strata \(theme\.yaml\)/', $out);
        self::assertMatchesRegularExpression('/OK\s+classic \(Redmine theme\)/', $out);
        self::assertMatchesRegularExpression('/ERR\s+fx_broken/', $out);
        self::assertStringContainsString('unknown key "colour"', $out);

        $tester = $this->run_('symine:themes:lint', ['id' => 'strata']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('fx_broken', $tester->getDisplay());

        self::assertSame(1, $this->run_('symine:themes:lint', ['id' => 'fx_broken'])->getStatusCode());
        self::assertSame(1, $this->run_('symine:themes:lint', ['id' => 'no_such_theme'])->getStatusCode());
    }

    public function testUse(): void
    {
        $settings = self::getContainer()->get(Settings::class);
        self::assertSame(0, $this->run_('symine:themes:use', ['id' => 'strata'])->getStatusCode());
        self::assertSame('strata', $settings->string('ui_theme'));
        self::assertSame(0, $this->run_('symine:themes:use', ['id' => 'default'])->getStatusCode());
        self::assertSame('', $settings->string('ui_theme'));

        $tester = $this->run_('symine:themes:use', ['id' => 'fx_broken']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('cannot be used', $tester->getDisplay());
        self::assertSame(1, $this->run_('symine:themes:use', ['id' => 'nope'])->getStatusCode());
        self::assertSame('', $settings->string('ui_theme'));
    }

    public function testCreate(): void
    {
        $id = 'zz_created_'.bin2hex(random_bytes(3));
        $dir = \dirname(__DIR__, 2).'/themes/'.$id;
        try {
            $tester = $this->run_('symine:themes:create', ['id' => $id, '--parent' => 'strata', '--name' => "Acme's theme"]);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertFileExists($dir.'/theme.yaml');
            self::assertFileExists($dir.'/stylesheets/application.css');
            self::assertDirectoryExists($dir.'/templates');

            // what was created is a valid theme that inherits from its parent
            self::assertSame(0, $this->run_('symine:themes:lint', ['id' => $id])->getStatusCode());
            $theme = self::getContainer()->get(\App\Theme\ThemeRegistry::class)->get($id);
            self::assertNotNull($theme);
            self::assertSame("Acme's theme", $theme->name);
            self::assertSame('strata', $theme->parent);
            self::assertArrayHasKey('accent', $theme->options);
            self::assertSame('stylesheets/application.css', $theme->stylesheets[array_key_last($theme->stylesheets)]['file']);

            self::assertSame(1, $this->run_('symine:themes:create', ['id' => $id])->getStatusCode(), 'already exists');
        } finally {
            (new Filesystem())->remove($dir);
        }
        self::assertSame(1, $this->run_('symine:themes:create', ['id' => 'default'])->getStatusCode());
        self::assertSame(1, $this->run_('symine:themes:create', ['id' => '../evil'])->getStatusCode());
        self::assertSame(1, $this->run_('symine:themes:create', ['id' => 'zz_x', '--parent' => 'classic'])->getStatusCode(), 'Redmine themes cannot be extended');
        self::assertDirectoryDoesNotExist(\dirname(__DIR__, 2).'/themes/zz_x');
    }
}
