<?php

declare(strict_types=1);

namespace App\Import;

use App\I18n\Localizer;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/** The steps of the CSV import wizard that change an import: upload, settings, mapping, run. */
final class ImportWizard
{
    /** A run request imports a few items only, so that the page can show the progress */
    private const int MAX_ITEMS_PER_REQUEST = 5;
    private const float MAX_TIME = 10.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
    ) {
    }

    /** A new, unsaved import of the current user */
    public function begin(AbstractImporter $importer): LoadedImport
    {
        return new LoadedImport($importer->newImport($this->currentUser->get()), $importer);
    }

    /**
     * ImportsController#create: stores the uploaded file and saves the import with its default settings.
     *
     * @return bool false when there is no usable file (the import is then not saved)
     */
    public function upload(LoadedImport $loaded, mixed $file, string $projectId): bool
    {
        if (!$file instanceof UploadedFile || !$loaded->importer->storeFile($loaded->import, $file)) {
            return false;
        }
        $loaded->importer->setDefaultSettings($loaded->import, '' !== $projectId ? $projectId : null);
        $this->em->persist($loaded->import);
        $this->em->flush();

        return true;
    }

    /** ImportsController#update_from_params: merges params[:import_settings] into the settings of the import */
    public function updateSettings(LoadedImport $loaded, Request $request): void
    {
        $posted = $request->request->all('import_settings');
        if ([] !== $posted) {
            $loaded->importer->updateSettings($loaded->import, $posted);
            $this->em->flush();
        }
    }

    /**
     * Reads the file with the current settings (Import#parse_file).
     *
     * @return string|null the error to show when the file is empty or cannot be read with these settings
     */
    public function parse(LoadedImport $loaded): ?string
    {
        try {
            if (0 === $loaded->importer->parseFile($loaded->import)) {
                return $this->i18n->l('error_no_data_in_file');
            }
        } catch (ImportFileException $e) {
            return match ($e->kind) {
                ImportFileException::MALFORMED => $this->i18n->l('error_invalid_csv_file_or_settings', ['value' => $e->getMessage()]),
                ImportFileException::ENCODING => $this->i18n->l('error_invalid_file_encoding', ['encoding' => (string) ($loaded->importer->settingsOf($loaded->import)['encoding'] ?? '')]),
                default => $this->i18n->l('error_can_not_read_import_file'),
            };
        }

        return null;
    }

    /** Imports the next items (Import#run) and returns the position of the last processed one */
    public function run(LoadedImport $loaded): int
    {
        return $loaded->importer->run($loaded->import, ['max_items' => self::MAX_ITEMS_PER_REQUEST, 'max_time' => self::MAX_TIME]);
    }
}
