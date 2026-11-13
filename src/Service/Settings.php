<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Setting;
use App\Plugin\PluginRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Application settings (port of Redmine's Setting model). Values are stored in the "settings"
 * table; defaults come from Redmine's config/settings.yml (config/redmine/settings.php).
 */
final class Settings
{
    /** @var array<string, array{default: mixed, serialized: bool, security_notifications: bool}> */
    private array $definitions;
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    private bool $pluginSettingsDefined = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        string $projectDir,
        private readonly ?PluginRegistry $plugins = null,
    ) {
        $this->definitions = require $projectDir.'/config/redmine/settings.php';
    }

    /**
     * Defines a setting at runtime (Setting.define_setting), e.g. "plugin_<id>" settings of plugins.
     *
     * @param array{default?: mixed, serialized?: bool, security_notifications?: bool} $definition
     */
    public function define(string $name, array $definition): void
    {
        $this->definePluginSettings();
        $this->definitions[$name] = ['default' => $definition['default'] ?? null, 'serialized' => $definition['serialized'] ?? false, 'security_notifications' => $definition['security_notifications'] ?? false];
    }

    public function has(string $name): bool
    {
        $this->definePluginSettings();

        return isset($this->definitions[$name]);
    }

    /** @return array<string, array{default: mixed, serialized: bool, security_notifications: bool}> */
    public function definitions(): array
    {
        $this->definePluginSettings();

        return $this->definitions;
    }

    /** Setting.define_plugin_setting: "plugin_<id>" (serialized hash) for every plugin declaring settings */
    private function definePluginSettings(): void
    {
        if ($this->pluginSettingsDefined || null === $this->plugins) {
            return;
        }
        $this->pluginSettingsDefined = true;
        foreach ($this->plugins->all() as $plugin) {
            if (null !== $plugin->settings) {
                $this->definitions[$plugin->settingName()] = ['default' => $plugin->defaultSettings(), 'serialized' => true, 'security_notifications' => false];
            }
        }
    }

    public function get(string $name): mixed
    {
        $this->load();
        if (str_starts_with($name, 'plugin_')) {
            $this->definePluginSettings();
        }
        if (\array_key_exists($name, $this->values)) {
            $v = $this->values[$name];
            if (($this->definitions[$name]['serialized'] ?? false) && !\is_array($v)) {
                $v = null === $v || '' === $v ? [] : [$v];
            }

            return $v;
        }

        return $this->definitions[$name]['default'] ?? null;
    }

    public function string(string $name): string
    {
        $v = $this->get($name);

        return \is_array($v) ? implode(',', $v) : (string) $v;
    }

    public function int(string $name): int
    {
        return (int) $this->get($name);
    }

    public function bool(string $name): bool
    {
        return \in_array($this->get($name), ['1', 1, true, 'true'], true);
    }

    /** @return list<mixed> */
    public function array(string $name): array
    {
        $v = $this->get($name);
        if (\is_array($v)) {
            return array_values($v);
        }

        return null === $v || '' === $v ? [] : [$v];
    }

    /** @return array<string, mixed> */
    public function hash(string $name): array
    {
        $v = $this->get($name);

        return \is_array($v) ? $v : [];
    }

    public function set(string $name, mixed $value): void
    {
        $this->load();
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['name' => $name]);
        if (null === $setting) {
            $setting = new Setting();
            $setting->name = $name;
            $this->em->persist($setting);
        }
        if (\is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        }
        $setting->value = $value;
        $setting->updatedOn = new \DateTimeImmutable();
        $this->values[$name] = $value;
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            if ($this->has($k)) {
                $this->set($k, $v);
            }
        }
        $this->em->flush();
    }

    public function clearCache(): void
    {
        $this->values = null;
    }

    private function load(): void
    {
        if (null !== $this->values) {
            return;
        }
        $this->values = [];
        try {
            foreach ($this->em->getRepository(Setting::class)->findAll() as $s) {
                $this->values[$s->name] = $s->value;
            }
        } catch (\Throwable) {
            // database not initialised yet
        }
    }

    // --- Shortcuts used across the application -----------------------------

    public function appTitle(): string
    {
        return $this->string('app_title');
    }

    public function loginRequired(): bool
    {
        return $this->bool('login_required');
    }

    public function restApiEnabled(): bool
    {
        return $this->bool('rest_api_enabled');
    }

    public function textFormatting(): string
    {
        return $this->string('text_formatting');
    }

    public function perPageOptions(): array
    {
        $opts = array_values(array_filter(array_map('intval', explode(',', $this->string('per_page_options')))));

        return $opts ?: [25, 50, 100];
    }

    public function displaySubprojectsIssues(): bool
    {
        return $this->bool('display_subprojects_issues');
    }

    public function selfRegistration(): string
    {
        return $this->string('self_registration');
    }
}
