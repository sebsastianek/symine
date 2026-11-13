<?php

declare(strict_types=1);

namespace App\Plugin;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Base class of Symine plugins (a Symfony bundle).
 *
 * Conventions (all optional), relative to the plugin root (the directory above src/):
 * - src/                 PHP code, registered as autowired/autoconfigured services (controllers with #[Route]
 *                        are routed automatically; hook listeners, activity/search providers and field formats
 *                        are discovered through their interfaces)
 * - src/Entity/          Doctrine entities (attribute mapping, mapping name = bundle name)
 * - config/services.yaml|php   extra service configuration
 * - templates/           Twig templates, namespace @<BundleName> (e.g. @HelloPlugin/hello/index.html.twig)
 * - translations/        messages.<locale>.yaml (Redmine keys: label_..., permission_..., project_module_...)
 * - migrations/          Doctrine migrations in the <Namespace>\Migrations namespace (symine:plugins:migrate)
 * - assets/              public files served from /plugin_assets/<id>/ (symine:plugins:assets)
 */
abstract class AbstractSyminePlugin extends AbstractBundle implements SyminePluginInterface
{
    public function getMigrationsNamespace(): string
    {
        return $this->getNamespace().'\\Migrations';
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // rebuild the container when plugins are added/removed in plugins/ (bundles discovered by PluginLoader)
        $projectDir = (string) ($container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : '');
        if ('' !== $projectDir && is_dir($projectDir.'/plugins')) {
            $container->addResource(new GlobResource($projectDir.'/plugins', '/*/composer.json', false));
        }
        if (is_file($this->getPath().'/composer.json')) {
            $container->addResource(new FileResource($this->getPath().'/composer.json'));
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $path = $this->getPath();
        if (is_dir($path.'/src')) {
            $services = $container->services();
            $services->defaults()->autowire()->autoconfigure();
            $exclude = [(string) (new \ReflectionClass($this))->getFileName()];
            foreach (['Entity', 'Migrations', 'Tests'] as $dir) {
                if (is_dir($path.'/src/'.$dir)) {
                    $exclude[] = $path.'/src/'.$dir.'/';
                }
            }
            $services->load($this->getNamespace().'\\', $path.'/src/')->exclude($exclude);
        }
        foreach (['services.yaml', 'services.php'] as $file) {
            if (is_file($path.'/config/'.$file)) {
                $container->import($path.'/config/'.$file);
            }
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $path = $this->getPath();
        if (is_dir($path.'/src/Entity') && $builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => [$this->getName() => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => $path.'/src/Entity',
                'prefix' => $this->getNamespace().'\\Entity',
                'alias' => $this->getName(),
            ]]]]);
        }
        if (is_dir($path.'/migrations') && $builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig('doctrine_migrations', ['migrations_paths' => [$this->getMigrationsNamespace() => $path.'/migrations']]);
        }
    }

    public function boot(): void
    {
        // registers the plugins' permissions/modules before the first authorization check
        if (null !== $this->container && $this->container->has(PluginRegistry::class)) {
            $this->container->get(PluginRegistry::class)->boot();
        }
    }
}
