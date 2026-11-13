<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Import;

/** Maps Import::$type (IssueImport, TimeEntryImport, UserImport) to the importer service. */
final class ImporterRegistry
{
    /** @var array<string, AbstractImporter> */
    private array $importers;

    public function __construct(IssueImporter $issues, TimeEntryImporter $timeEntries, UserImporter $users)
    {
        $this->importers = [
            IssueImporter::type() => $issues,
            TimeEntryImporter::type() => $timeEntries,
            UserImporter::type() => $users,
        ];
    }

    public function get(?string $type): ?AbstractImporter
    {
        return null === $type ? null : ($this->importers[$type] ?? null);
    }

    public function for(Import $import): ?AbstractImporter
    {
        return $this->get($import->type);
    }
}
