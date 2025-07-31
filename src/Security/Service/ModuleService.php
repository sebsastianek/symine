<?php

declare(strict_types=1);

namespace App\Security\Service;

use App\Entity\Project;
use App\Entity\EnabledModule;
use App\Repository\EnabledModuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing project modules and their availability
 * 
 * Implements Redmine's module system where different features (wiki, issues, etc.)
 * can be enabled/disabled per project
 */
class ModuleService
{
    // Standard Redmine modules
    public const MODULE_ISSUE_TRACKING = 'issue_tracking';
    public const MODULE_TIME_TRACKING = 'time_tracking';
    public const MODULE_NEWS = 'news';
    public const MODULE_DOCUMENTS = 'documents';
    public const MODULE_FILES = 'files';
    public const MODULE_WIKI = 'wiki';
    public const MODULE_REPOSITORY = 'repository';
    public const MODULE_BOARDS = 'boards';
    public const MODULE_CALENDAR = 'calendar';
    public const MODULE_GANTT = 'gantt';

    public function __construct(
        private EnabledModuleRepository $enabledModuleRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Check if a module is enabled for a project
     */
    public function isModuleEnabled(Project $project, string $moduleName): bool
    {
        // Global modules (project_id is null) are available to all projects
        $globalModule = $this->enabledModuleRepository->findOneBy([
            'project' => null,
            'name' => $moduleName
        ]);

        if ($globalModule) {
            return true;
        }

        // Check project-specific module
        $projectModule = $this->enabledModuleRepository->findOneBy([
            'project' => $project,
            'name' => $moduleName
        ]);

        return $projectModule !== null;
    }

    /**
     * Get all enabled modules for a project
     */
    public function getEnabledModules(Project $project): array
    {
        $modules = [];

        // Get global modules
        $globalModules = $this->enabledModuleRepository->findBy(['project' => null]);
        foreach ($globalModules as $module) {
            $modules[] = $module->getName();
        }

        // Get project-specific modules
        $projectModules = $this->enabledModuleRepository->findBy(['project' => $project]);
        foreach ($projectModules as $module) {
            $modules[] = $module->getName();
        }

        return array_unique($modules);
    }

    /**
     * Enable a module for a project
     */
    public function enableModule(Project $project, string $moduleName): bool
    {
        // Check if module is already enabled
        if ($this->isModuleEnabled($project, $moduleName)) {
            return true;
        }

        try {
            $enabledModule = new EnabledModule();
            $enabledModule->setProject($project);
            $enabledModule->setName($moduleName);

            $this->entityManager->persist($enabledModule);
            $this->entityManager->flush();

            $this->logger->info('Module enabled for project', [
                'project_id' => $project->getId(),
                'module_name' => $moduleName
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to enable module', [
                'project_id' => $project->getId(),
                'module_name' => $moduleName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Disable a module for a project
     */
    public function disableModule(Project $project, string $moduleName): bool
    {
        try {
            $enabledModule = $this->enabledModuleRepository->findOneBy([
                'project' => $project,
                'name' => $moduleName
            ]);

            if ($enabledModule) {
                $this->entityManager->remove($enabledModule);
                $this->entityManager->flush();

                $this->logger->info('Module disabled for project', [
                    'project_id' => $project->getId(),
                    'module_name' => $moduleName
                ]);
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to disable module', [
                'project_id' => $project->getId(),
                'module_name' => $moduleName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Enable global module (available to all projects)
     */
    public function enableGlobalModule(string $moduleName): bool
    {
        // Check if global module is already enabled
        $globalModule = $this->enabledModuleRepository->findOneBy([
            'project' => null,
            'name' => $moduleName
        ]);

        if ($globalModule) {
            return true;
        }

        try {
            $enabledModule = new EnabledModule();
            $enabledModule->setProject(null);
            $enabledModule->setName($moduleName);

            $this->entityManager->persist($enabledModule);
            $this->entityManager->flush();

            $this->logger->info('Global module enabled', [
                'module_name' => $moduleName
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to enable global module', [
                'module_name' => $moduleName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get all available module names
     */
    public function getAllAvailableModules(): array
    {
        return [
            self::MODULE_ISSUE_TRACKING,
            self::MODULE_TIME_TRACKING,
            self::MODULE_NEWS,
            self::MODULE_DOCUMENTS,
            self::MODULE_FILES,
            self::MODULE_WIKI,
            self::MODULE_REPOSITORY,
            self::MODULE_BOARDS,
            self::MODULE_CALENDAR,
            self::MODULE_GANTT,
        ];
    }

    /**
     * Initialize default modules for a new project
     */
    public function initializeDefaultModules(Project $project): void
    {
        $defaultModules = [
            self::MODULE_ISSUE_TRACKING,
            self::MODULE_TIME_TRACKING,
            self::MODULE_NEWS,
            self::MODULE_DOCUMENTS,
            self::MODULE_FILES,
            self::MODULE_WIKI,
        ];

        try {
            $this->entityManager->beginTransaction();

            foreach ($defaultModules as $moduleName) {
                if (!$this->isModuleEnabled($project, $moduleName)) {
                    $this->enableModule($project, $moduleName);
                }
            }

            $this->entityManager->commit();

            $this->logger->info('Default modules initialized for project', [
                'project_id' => $project->getId(),
                'modules' => $defaultModules
            ]);

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to initialize default modules', [
                'project_id' => $project->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if module requires specific permissions
     */
    public function getModuleRequiredPermissions(string $moduleName): array
    {
        $modulePermissions = [
            self::MODULE_ISSUE_TRACKING => ['view_issues', 'edit_issues', 'create_issues'],
            self::MODULE_TIME_TRACKING => ['view_time_entries', 'log_time'],
            self::MODULE_NEWS => ['view_news', 'manage_news'],
            self::MODULE_DOCUMENTS => ['view_documents', 'manage_documents'],
            self::MODULE_FILES => ['view_files', 'manage_files'],
            self::MODULE_WIKI => ['view_wiki_pages', 'edit_wiki_pages'],
            self::MODULE_REPOSITORY => ['browse_repository', 'commit_access'],
            self::MODULE_BOARDS => ['view_messages', 'add_messages'],
            self::MODULE_CALENDAR => ['view_calendar'],
            self::MODULE_GANTT => ['view_gantt'],
        ];

        return $modulePermissions[$moduleName] ?? [];
    }
}