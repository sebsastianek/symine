<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Mapper given to AccessControlRegistry::projectModule() callbacks: permissions belong to the module. */
#[Exclude]
final class ModulePermissionMapper
{
    public function __construct(
        private readonly AccessControlRegistry $registry,
        public readonly string $module,
    ) {
    }

    /**
     * @param list<string>                                         $actions
     * @param array{public?: bool, read?: bool, require?: string} $options
     */
    public function permission(string $name, array $actions = [], array $options = []): self
    {
        $this->registry->permission($name, $actions, $options, $this->module);

        return $this;
    }
}
