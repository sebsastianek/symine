<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EnabledModule;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class EnabledModuleFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Standard Redmine modules to enable for different projects

        // E-commerce Platform - Full featured project
        $modules = [
            'issue_tracking', 'time_tracking', 'news', 'documents', 'files', 
            'wiki', 'repository', 'boards', 'calendar', 'gantt'
        ];
        
        foreach ($modules as $moduleName) {
            $enabledModule = new EnabledModule();
            $enabledModule->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
            $enabledModule->setName($moduleName);
            
            $manager->persist($enabledModule);
            $this->addReference("enabled-module-ecommerce-{$moduleName}", $enabledModule);
        }

        // Mobile App Development - Development focused
        $mobileModules = [
            'issue_tracking', 'time_tracking', 'documents', 'wiki', 
            'repository', 'boards', 'calendar'
        ];
        
        foreach ($mobileModules as $moduleName) {
            $enabledModule = new EnabledModule();
            $enabledModule->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
            $enabledModule->setName($moduleName);
            
            $manager->persist($enabledModule);
            $this->addReference("enabled-module-mobile-{$moduleName}", $enabledModule);
        }

        // CRM System - Enterprise features
        $crmModules = [
            'issue_tracking', 'time_tracking', 'news', 'documents', 'files',
            'wiki', 'boards', 'calendar', 'gantt'
        ];
        
        foreach ($crmModules as $moduleName) {
            $enabledModule = new EnabledModule();
            $enabledModule->setProject($this->getReference('project-crm', \App\Entity\Project::class));
            $enabledModule->setName($moduleName);
            
            $manager->persist($enabledModule);
            $this->addReference("enabled-module-crm-{$moduleName}", $enabledModule);
        }

        // Internal Tools - Minimal setup
        $internalModules = [
            'issue_tracking', 'time_tracking', 'wiki', 'boards'
        ];
        
        foreach ($internalModules as $moduleName) {
            $enabledModule = new EnabledModule();
            $enabledModule->setProject($this->getReference('project-tools', \App\Entity\Project::class));
            $enabledModule->setName($moduleName);
            
            $manager->persist($enabledModule);
            $this->addReference("enabled-module-internal-{$moduleName}", $enabledModule);
        }

        // Documentation Portal - Documentation focused
        $docsModules = [
            'issue_tracking', 'time_tracking', 'documents', 'files',
            'wiki', 'boards', 'calendar'
        ];
        
        foreach ($docsModules as $moduleName) {
            $enabledModule = new EnabledModule();
            $enabledModule->setProject($this->getReference('project-docs', \App\Entity\Project::class));
            $enabledModule->setName($moduleName);
            
            $manager->persist($enabledModule);
            $this->addReference("enabled-module-docs-{$moduleName}", $enabledModule);
        }

        // Backend API - Development focused
        $backendModules = [
            'issue_tracking', 'time_tracking', 'documents', 'wiki',
            'repository', 'boards'
        ];
        
        foreach ($backendModules as $moduleName) {
            $enabledModule = new EnabledModule();
            $enabledModule->setProject($this->getReference('project-backend', \App\Entity\Project::class));
            $enabledModule->setName($moduleName);
            
            $manager->persist($enabledModule);
            $this->addReference("enabled-module-backend-{$moduleName}", $enabledModule);
        }

        // Frontend Components - Library project
        $frontendModules = [
            'issue_tracking', 'documents', 'files', 'wiki', 'repository'
        ];
        
        foreach ($frontendModules as $moduleName) {
            $enabledModule = new EnabledModule();
            $enabledModule->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
            $enabledModule->setName($moduleName);
            
            $manager->persist($enabledModule);
            $this->addReference("enabled-module-frontend-{$moduleName}", $enabledModule);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ProjectFixtures::class,
        ];
    }
}