<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Import;

/** An import together with the importer of its type: what every step of the import wizard works on. */
final readonly class LoadedImport
{
    public function __construct(
        public Import $import,
        public AbstractImporter $importer,
    ) {
    }
}
