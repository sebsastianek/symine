<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\CustomField;
use App\Entity\Import;
use App\Entity\ImportItem;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Service\CustomFieldService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Port of Redmine's Import model (app/models/import.rb): CSV file storage in a tmp "imports"
 * directory (random hex filename), settings guessing, CSV reading, item-by-item processing with
 * resumable runs (import_items positions) and deferred callbacks (rows referencing later rows).
 *
 * Importers are stateless services working on an {@see Import} entity; the subclass (IssueImport,
 * TimeEntryImport, UserImport) is identified by Import::$type, exactly like Redmine's STI column.
 */
abstract class AbstractImporter
{
    public const array DATE_FORMATS = ['%Y-%m-%d', '%d/%m/%Y', '%m/%d/%Y', '%Y/%m/%d', '%d.%m.%Y', '%d-%m-%Y'];

    /** Setting::ENCODINGS */
    public const array ENCODINGS = [
        'US-ASCII', 'windows-1250', 'windows-1251', 'windows-1252', 'windows-1253', 'windows-1254', 'windows-1255',
        'windows-1256', 'windows-1257', 'windows-1258', 'windows-31j', 'windows-874', 'ISO-2022-JP', 'ISO-8859-1',
        'ISO-8859-2', 'ISO-8859-3', 'ISO-8859-4', 'ISO-8859-5', 'ISO-8859-6', 'ISO-8859-7', 'ISO-8859-8',
        'ISO-8859-9', 'ISO-8859-13', 'ISO-8859-15', 'KOI8-R', 'UTF-8', 'UTF-16', 'UTF-16BE', 'UTF-16LE', 'EUC-JP',
        'Shift_JIS', 'CP932', 'CP949', 'GB18030', 'GBK', 'EUC-KR', 'Big5', 'Big5-HKSCS', 'TIS-620',
    ];

    protected EntityManagerInterface $em;
    protected Localizer $i18n;
    protected Settings $settings;
    protected Authorization $auth;
    protected CustomFieldService $customFields;
    protected ?LoggerInterface $logger = null;
    private string $importsDir;

    #[Required]
    public function setImportCore(
        EntityManagerInterface $em,
        Localizer $i18n,
        Settings $settings,
        Authorization $auth,
        CustomFieldService $customFields,
        string $projectDir,
        ?LoggerInterface $logger = null,
    ): void {
        $this->em = $em;
        $this->i18n = $i18n;
        $this->settings = $settings;
        $this->auth = $auth;
        $this->customFields = $customFields;
        $this->logger = $logger;
        $this->importsDir = ($_SERVER['SYMINE_IMPORTS_PATH'] ?? null) ?: $projectDir.'/var/imports';
    }

    /** STI type stored in imports.type */
    abstract public static function type(): string;

    /** "issues", "time_entries", "users" (ImportsHelper#import_partial_prefix) */
    abstract public static function partialPrefix(): string;

    /** @return array<string, string> field => label key */
    abstract public function autoMappableFields(): array;

    abstract public function isAuthorized(User $user): bool;

    /** @return list<CustomField> */
    abstract public function mappableCustomFields(Import $import): array;

    /** @return list<object> */
    abstract public function savedObjects(Import $import): array;

    /**
     * Builds and saves the object for a row (Import#build_object + object.save).
     *
     * @param list<string|null> $row
     *
     * @return array{0: object|null, 1: list<string>} [saved object or null, error messages]
     */
    abstract protected function importRow(Import $import, array $row, ImportItem $item): array;

    /** Called after an object has been saved (Import#extend_object) @param list<string|null> $row */
    protected function extendObject(Import $import, array $row, ImportItem $item, object $object): void
    {
    }

    /** Executes a deferred callback (Import#<name>_callback) @param list<mixed> $args */
    protected function callback(Import $import, string $name, ?object $object, array $args): void
    {
    }

    /** Hooks around a run (e.g. notifications suppression) */
    protected function beforeRun(Import $import): void
    {
    }

    protected function afterRun(Import $import): void
    {
    }

    public function menuItem(): ?string
    {
        return null;
    }

    /** 'base' or 'admin' */
    public function layout(): string
    {
        return 'base';
    }

    public function titleKey(): string
    {
        return 'label_import_'.static::partialPrefix();
    }

    // ------------------------------------------------------------------------------------------
    // File
    // ------------------------------------------------------------------------------------------

    public function newImport(User $user): Import
    {
        $import = new Import();
        $import->type = static::type();
        $import->user = $user;
        $import->filename = null;
        $import->settings = [];

        return $import;
    }

    /** Import#file= : stores the uploaded file under a random hex name */
    public function storeFile(Import $import, ?UploadedFile $file): bool
    {
        if (null === $file || !$file->isValid() || 0 === $file->getSize()) {
            return false;
        }
        $import->filename = bin2hex(random_bytes(16));
        if (!is_dir($this->importsDir)) {
            @mkdir($this->importsDir, 0o775, true);
        }

        return false !== @copy($file->getPathname(), (string) $this->filepath($import));
    }

    /** Stores raw content (used by tests and console tools) */
    public function storeContent(Import $import, string $content): void
    {
        $import->filename = bin2hex(random_bytes(16));
        if (!is_dir($this->importsDir)) {
            @mkdir($this->importsDir, 0o775, true);
        }
        file_put_contents((string) $this->filepath($import), $content);
    }

    public function filepath(Import $import): ?string
    {
        $f = (string) $import->filename;

        return '' !== $f && preg_match('/\A[0-9a-f]+\z/', $f) ? $this->importsDir.'/'.$f : null;
    }

    public function fileExists(Import $import): bool
    {
        $p = $this->filepath($import);

        return null !== $p && is_file($p);
    }

    public function removeFile(Import $import): void
    {
        if ($this->fileExists($import)) {
            if (!@unlink((string) $this->filepath($import))) {
                $this->logger?->error('Unable to delete file '.$this->filepath($import));
            }
        }
    }

    // ------------------------------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------------------------------

    protected function userLocale(User $user): string
    {
        $lang = (string) $user->language;
        foreach ($this->i18n->availableLocales() as $l) {
            if ('' !== $lang && 0 === strcasecmp($l, $lang)) {
                return $l;
            }
        }

        return $this->settings->string('default_language') ?: 'en';
    }

    /** Import#set_default_settings */
    public function setDefaultSettings(Import $import, ?string $projectId = null): void
    {
        $locale = $this->userLocale($import->user);
        $separator = $this->i18n->l('general_csv_separator', [], $locale);
        $wrapper = '"';
        $encoding = $this->i18n->l('general_csv_encoding', [], $locale);
        $newline = null;
        if ($this->fileExists($import)) {
            $content = $this->readFileHead($import);
            $separator = substr_count($content, ';') > substr_count($content, ',') ? ';' : ',';
            $newline = str_contains($content, "\r\n") ? "\r\n" : '';
            $wrapper = substr_count($content, "'") > substr_count($content, '"') ? "'" : '"';
            $guessed = $this->guessEncoding($content);
            if (null !== $guessed) {
                foreach (self::ENCODINGS as $e) {
                    if (0 === strcasecmp($e, $guessed)) {
                        $encoding = $e;
                        break;
                    }
                }
            }
        }
        $dateFormat = $this->i18n->l('date.formats.default', [], $locale);
        if (!\in_array($dateFormat, self::DATE_FORMATS, true)) {
            $dateFormat = self::DATE_FORMATS[0];
        }
        $settings = \is_array($import->settings) ? $import->settings : [];
        $settings = array_merge($settings, [
            'separator' => $separator,
            'newline' => $newline,
            'wrapper' => $wrapper,
            'encoding' => $encoding,
            'date_format' => $dateFormat,
            'notifications' => '0',
        ]);
        if (null !== $projectId && '' !== $projectId) {
            $project = ctype_digit($projectId) ? $this->em->find(Project::class, (int) $projectId)
                : $this->em->getRepository(Project::class)->findOneBy(['identifier' => $projectId]);
            if (null !== $project) {
                $settings['mapping'] = ['project_id' => $project->id];
            }
        }
        $import->settings = $settings;
    }

    /** Redmine::CodesetUtil.guess_encoding (UTF-8 detection; other encodings fall back to the locale default) */
    private function guessEncoding(string $content): ?string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }
        if (str_starts_with($content, "\xFF\xFE")) {
            return 'UTF-16LE';
        }
        if (str_starts_with($content, "\xFE\xFF")) {
            return 'UTF-16BE';
        }
        if (mb_check_encoding($content, 'UTF-8') && preg_match('/[\x80-\xFF]/', $content)) {
            return 'UTF-8';
        }

        return null;
    }

    private function readFileHead(Import $import, int $maxBytes = 4096): string
    {
        $path = (string) $this->filepath($import);
        if (filesize($path) <= $maxBytes) {
            return (string) file_get_contents($path);
        }
        $chunk = (string) file_get_contents($path, false, null, 0, $maxBytes);
        $lf = strrpos($chunk, "\n");

        return false !== $lf ? substr($chunk, 0, $lf + 1) : $chunk;
    }

    /** @return array<string, mixed> */
    public function settingsOf(Import $import): array
    {
        return \is_array($import->settings) ? $import->settings : [];
    }

    /** @return array<string, mixed> */
    public function mapping(Import $import): array
    {
        $m = $this->settingsOf($import)['mapping'] ?? [];

        return \is_array($m) ? $m : [];
    }

    /** Merges posted import_settings (ImportsController#update_from_params, shallow merge) @param array<string, mixed> $posted */
    public function updateSettings(Import $import, array $posted): void
    {
        if ([] === $posted) {
            return;
        }
        $import->settings = array_merge($this->settingsOf($import), $posted);
        $import->updatedAt = new \DateTimeImmutable();
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /**
     * Reads all rows of the file (headers included) with the import settings.
     *
     * @return \Generator<int, list<string|null>>
     */
    public function readRows(Import $import): \Generator
    {
        if (!$this->fileExists($import)) {
            return;
        }
        $content = @file_get_contents((string) $this->filepath($import));
        if (false === $content) {
            throw new ImportFileException(ImportFileException::READ);
        }
        $settings = $this->settingsOf($import);
        $encoding = trim((string) ($settings['encoding'] ?? '')) ?: 'UTF-8';
        $content = $this->toUtf8($content, $encoding);
        $separator = (string) ($settings['separator'] ?? '');
        $separator = 1 === \strlen($separator) ? $separator : ',';
        $wrapper = (string) ($settings['wrapper'] ?? '');
        $wrapper = 1 === \strlen($wrapper) ? $wrapper : '"';
        if (0 !== substr_count($content, $wrapper) % 2) {
            throw new ImportFileException(ImportFileException::MALFORMED, 'Unclosed quoted field');
        }
        $h = fopen('php://temp', 'w+');
        fwrite($h, $content);
        rewind($h);
        try {
            while (false !== ($row = fgetcsv($h, null, $separator, $wrapper, ''))) {
                if ([null] === $row) {
                    $row = [];
                }
                yield array_map(static fn ($v) => null === $v ? null : (string) $v, $row);
            }
        } finally {
            fclose($h);
        }
    }

    private function toUtf8(string $content, string $encoding): string
    {
        $upper = strtoupper($encoding);
        if ('UTF-8' === $upper) {
            if (str_starts_with($content, "\xEF\xBB\xBF")) {
                $content = substr($content, 3);
            }
            if (!mb_check_encoding($content, 'UTF-8')) {
                throw new ImportFileException(ImportFileException::ENCODING);
            }

            return $content;
        }
        $map = ['WINDOWS-31J' => 'SJIS-win', 'CP932' => 'SJIS-win', 'SHIFT_JIS' => 'SJIS', 'CP949' => 'UHC', 'GB18030' => 'GB18030', 'GBK' => 'CP936', 'BIG5-HKSCS' => 'BIG-5', 'BIG5' => 'BIG-5', 'TIS-620' => 'TIS-620'];
        $mbName = $map[$upper] ?? $encoding;
        $supported = array_map('strtoupper', mb_list_encodings());
        try {
            if (\in_array(strtoupper($mbName), $supported, true)) {
                if (!mb_check_encoding($content, $mbName)) {
                    throw new ImportFileException(ImportFileException::ENCODING);
                }
                $out = mb_convert_encoding($content, 'UTF-8', $mbName);
            } else {
                $out = @iconv($encoding, 'UTF-8', $content);
            }
        } catch (\ValueError) {
            throw new ImportFileException(ImportFileException::ENCODING);
        }
        if (false === $out || !mb_check_encoding($out, 'UTF-8')) {
            throw new ImportFileException(ImportFileException::ENCODING);
        }
        if (str_starts_with($out, "\xEF\xBB\xBF")) {
            $out = substr($out, 3);
        }

        return $out;
    }

    /**
     * Import#read_items: data rows with their 1-based position (header row skipped).
     *
     * @return \Generator<int, list<string|null>> position => row
     */
    public function readItems(Import $import): \Generator
    {
        $i = 0;
        $first = true;
        foreach ($this->readRows($import) as $row) {
            if ($first) {
                $first = false;
                continue;
            }
            yield ++$i => $row;
        }
    }

    /** Import#parse_file: counts the items and stores total_items */
    public function parseFile(Import $import): int
    {
        $count = 0;
        foreach ($this->readItems($import) as $i => $row) {
            $count = $i;
        }
        $import->totalItems = $count;
        $import->updatedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $count;
    }

    /** @return list<list<string|null>> */
    public function firstRows(Import $import, int $count = 4): array
    {
        $rows = [];
        foreach ($this->readRows($import) as $row) {
            $rows[] = $row;
            if (\count($rows) >= $count) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<string|null> */
    public function headers(Import $import): array
    {
        try {
            return $this->firstRows($import, 1)[0] ?? [];
        } catch (ImportFileException) {
            return [];
        }
    }

    /** ImportsController#auto_map_fields (in memory only, like Redmine) */
    public function autoMapFields(Import $import): void
    {
        $settings = $this->settingsOf($import);
        if ('' === trim((string) ($settings['encoding'] ?? ''))) {
            return;
        }
        $mapping = \is_array($settings['mapping'] ?? null) ? $settings['mapping'] : [];
        $headers = array_map(static fn ($h) => null === $h ? null : mb_strtolower($h), $this->headers($import));
        $locale = $this->i18n->locale();
        foreach ($this->autoMappableFields() as $field => $label) {
            if (\array_key_exists($field, $mapping)) {
                continue;
            }
            $index = array_search($field, $headers, true);
            if (false === $index) {
                $index = array_search(mb_strtolower($this->i18n->l($label, [], $locale)), $headers, true);
            }
            if (false !== $index) {
                $mapping[$field] = $index;
            }
        }
        foreach ($this->mappableCustomFields($import) as $cf) {
            $key = 'cf_'.$cf->id;
            if (\array_key_exists($key, $mapping)) {
                continue;
            }
            $index = array_search($key, $headers, true);
            if (false === $index) {
                $index = array_search(mb_strtolower($cf->name), $headers, true);
            }
            if (false !== $index) {
                $mapping[$key] = $index;
            }
        }
        $settings['mapping'] = $mapping;
        $import->settings = $settings;
    }

    // ------------------------------------------------------------------------------------------
    // Running
    // ------------------------------------------------------------------------------------------

    /**
     * Import#run: imports items and returns the position of the last processed item.
     *
     * @param array{max_items?: int|null, max_time?: float|null} $options
     */
    public function run(Import $import, array $options = []): int
    {
        $maxItems = $options['max_items'] ?? null;
        $maxTime = $options['max_time'] ?? null;
        $current = 0;
        $imported = 0;
        $resumeAfter = (int) $this->em->createQuery('SELECT MAX(i.position) FROM App\Entity\ImportItem i WHERE i.import = :imp')
            ->setParameter('imp', $import)->getSingleScalarResult();
        $interrupted = false;
        $start = microtime(true);

        $this->beforeRun($import);
        try {
            foreach ($this->readItems($import) as $position => $row) {
                if ((null !== $maxItems && $imported >= $maxItems) || (null !== $maxTime && microtime(true) >= $start + $maxTime)) {
                    $interrupted = true;
                    break;
                }
                if ($position > $resumeAfter) {
                    $item = new ImportItem();
                    $item->import = $import;
                    $item->position = $position;
                    if ($this->useUniqueId($import)) {
                        $item->uniqueId = $this->rowValue($import, $row, 'unique_id');
                    }
                    try {
                        [$object, $errors] = $this->importRow($import, $row, $item);
                    } catch (\Throwable $e) {
                        $this->logger?->error('Import error: '.$e->getMessage(), ['exception' => $e]);
                        [$object, $errors] = [null, [$e->getMessage()]];
                        if (!$this->em->isOpen()) {
                            throw $e;
                        }
                    }
                    $persisted = null !== $object && isset($object->id) && null !== $object->id;
                    if ($persisted) {
                        $item->objId = $object->id;
                    } else {
                        $item->message = implode("\n", $errors ?: [$this->i18n->l('activerecord.errors.messages.invalid')]);
                    }
                    $this->em->persist($item);
                    $this->em->flush();
                    ++$imported;
                    if ($persisted) {
                        $this->extendObject($import, $row, $item, $object);
                    }
                    $this->doCallbacks($import, $this->useUniqueId($import) ? (string) $item->uniqueId : (string) $item->position, $persisted ? $object : null);
                }
                $current = $position;
            }
        } finally {
            $this->afterRun($import);
        }

        if (0 === $imported || !$interrupted) {
            $import->totalItems ??= $current;
            $import->finished = true;
            $this->removeFile($import);
        }
        $import->updatedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $current;
    }

    /** Import#add_callback @param list<mixed> $args */
    protected function addCallback(Import $import, string|int $key, string $name, array $args): void
    {
        $settings = $this->settingsOf($import);
        $callbacks = \is_array($settings['callbacks'] ?? null) ? $settings['callbacks'] : [];
        $callbacks[(string) $key][] = [$name, $args];
        $settings['callbacks'] = $callbacks;
        $import->settings = $settings;
        $this->em->flush();
    }

    private function doCallbacks(Import $import, string $key, ?object $object): void
    {
        $settings = $this->settingsOf($import);
        $callbacks = \is_array($settings['callbacks'] ?? null) ? $settings['callbacks'] : [];
        if (!isset($callbacks[$key])) {
            return;
        }
        $list = $callbacks[$key];
        unset($callbacks[$key]);
        $settings['callbacks'] = $callbacks;
        $import->settings = $settings;
        foreach ((array) $list as [$name, $args]) {
            try {
                $this->callback($import, (string) $name, $object, (array) $args);
            } catch (\Throwable $e) {
                $this->logger?->error('Import callback error: '.$e->getMessage(), ['exception' => $e]);
                if (!$this->em->isOpen()) {
                    throw $e;
                }
            }
        }
        $this->em->flush();
    }

    /** @return list<ImportItem> */
    public function unsavedItems(Import $import): array
    {
        return $this->em->createQuery('SELECT i FROM App\Entity\ImportItem i WHERE i.import = :imp AND i.objId IS NULL ORDER BY i.position')
            ->setParameter('imp', $import)->getResult();
    }

    /** @return list<int> */
    protected function savedObjectIds(Import $import): array
    {
        return array_map('intval', $this->em->createQuery('SELECT i.objId FROM App\Entity\ImportItem i WHERE i.import = :imp AND i.objId IS NOT NULL ORDER BY i.position')
            ->setParameter('imp', $import)->getSingleColumnResult());
    }

    public function savedItemsCount(Import $import): int
    {
        return \count($this->savedObjectIds($import));
    }

    protected function itemObjId(Import $import, string $column, string|int $value): ?int
    {
        $field = 'unique_id' === $column ? 'uniqueId' : 'position';
        $id = $this->em->createQuery("SELECT i.objId FROM App\\Entity\\ImportItem i WHERE i.import = :imp AND i.$field = :v ORDER BY i.id")
            ->setParameter('imp', $import)->setParameter('v', 'position' === $field ? (int) $value : (string) $value)
            ->setMaxResults(1)->getOneOrNullResult();

        return null === $id || null === $id['objId'] ? null : (int) $id['objId'];
    }

    // ------------------------------------------------------------------------------------------
    // Row helpers
    // ------------------------------------------------------------------------------------------

    /** Import#row_value: mapped cell value, null when blank @param list<string|null> $row */
    protected function rowValue(Import $import, array $row, string $key): ?string
    {
        $index = $this->mapping($import)[$key] ?? null;
        if (null === $index || '' === (string) $index || !is_numeric($index)) {
            return null;
        }
        $v = $row[(int) $index] ?? null;

        return null === $v || '' === trim($v) ? null : $v;
    }

    /**
     * Import#row_date: the date as Y-m-d, or the raw string when it does not match the date format.
     *
     * @param list<string|null> $row
     */
    protected function rowDate(Import $import, array $row, string $key): ?string
    {
        $s = $this->rowValue($import, $row, $key);
        if (null === $s) {
            return null;
        }
        $format = (string) ($this->settingsOf($import)['date_format'] ?? '');
        if (!\in_array($format, self::DATE_FORMATS, true)) {
            $format = self::DATE_FORMATS[0];
        }

        return self::parseDate(trim($s), $format) ?? $s;
    }

    public static function parseDate(string $s, string $strftime): ?string
    {
        $php = strtr($strftime, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd']);
        $d = \DateTimeImmutable::createFromFormat('!'.$php, $s);
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $d || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $d->format('Y-m-d');
    }

    protected static function isIsoDate(?string $s): bool
    {
        return null !== $s && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && null !== self::parseDate($s, '%Y-%m-%d');
    }

    /** Import#yes? */
    protected function yes(Import $import, ?string $value): bool
    {
        return null !== $value && ($value === $this->i18n->l('general_text_yes', [], $this->userLocale($import->user)) || '1' === $value);
    }

    protected function useUniqueId(Import $import): bool
    {
        $v = $this->mapping($import)['unique_id'] ?? null;

        return null !== $v && '' !== (string) $v;
    }

    /** "Field cannot be blank" style message */
    protected function error(string $fieldKey, string $message, array $params = []): string
    {
        return $this->i18n->l($fieldKey).' '.$this->i18n->l('activerecord.errors.messages.'.$message, $params);
    }

    /**
     * Principal.detect_by_keyword
     *
     * @param iterable<Principal> $principals
     */
    public static function detectByKeyword(iterable $principals, ?string $keyword): ?Principal
    {
        $keyword = trim((string) $keyword);
        if ('' === $keyword) {
            return null;
        }
        $list = \is_array($principals) ? $principals : iterator_to_array($principals, false);
        foreach ($list as $p) {
            if (0 === strcasecmp($keyword, $p->login)) {
                return $p;
            }
        }
        foreach ($list as $p) {
            if ($p instanceof User && null !== $p->getMail() && 0 === strcasecmp($keyword, $p->getMail())) {
                return $p;
            }
        }
        if (str_contains($keyword, ' ')) {
            $parts = preg_split('/\s+/', $keyword) ?: [];
            [$first, $last] = [$parts[0] ?? '', $parts[1] ?? ''];
            foreach ($list as $p) {
                if ($p instanceof User && 0 === strcasecmp($first, $p->firstname) && 0 === strcasecmp($last, $p->lastname)) {
                    return $p;
                }
            }
        }
        foreach ($list as $p) {
            if (0 === strcasecmp($keyword, $p->getName())) {
                return $p;
            }
        }

        return null;
    }

    /**
     * CustomField#value_from_keyword: maps a CSV value to the stored value (list labels, enumeration
     * names, user logins/names, version names, yes/no), splitting multiple values on commas.
     *
     * @return string|list<string>|null
     */
    protected function valueFromKeyword(CustomField $field, string $keyword, ?Project $project): string|array|null
    {
        $format = $field->fieldFormat;
        if (!\in_array($format, ['list', 'bool', 'enumeration', 'user', 'version'], true)) {
            return $keyword;
        }
        $resolver = match ($format) {
            'enumeration' => function (string $k) use ($field): ?string {
                foreach ($field->enumerations as $e) {
                    if (0 === strcasecmp($e->name, $k)) {
                        return (string) $e->id;
                    }
                }

                return null;
            },
            'user' => function (string $k) use ($field, $project): ?string {
                $ids = array_map(static fn ($o) => (int) $o[0], $project ? $this->customFields->possibleValuesOptions($field, $project) : []);
                $principals = [] === $ids ? [] : $this->em->getRepository(Principal::class)->findBy(['id' => $ids]);

                return ($p = self::detectByKeyword($principals, $k)) ? (string) $p->id : null;
            },
            default => function (string $k) use ($field, $project): ?string {
                $options = $this->customFields->possibleValuesOptions($field, $project);
                if ([] === $options) {
                    return 'list' === $field->fieldFormat ? null : $k;
                }
                foreach ($options as [$value, $label]) {
                    if (0 === strcasecmp((string) $label, $k)) {
                        return (string) $value;
                    }
                }

                return null;
            },
        };
        if (!$field->isMultiple()) {
            return $resolver(trim($keyword));
        }
        // parse_keyword: longest comma separated prefixes first (labels may contain commas)
        $values = [];
        $keyword = (string) $keyword;
        while ('' !== $keyword) {
            $k = $keyword;
            while (true) {
                if (null !== ($v = $resolver(trim($k)))) {
                    $values[] = $v;
                    break;
                }
                $pos = strrpos($k, ',');
                if (false === $pos) {
                    break;
                }
                $k = substr($k, 0, $pos);
            }
            $keyword = substr($keyword, \strlen($k));
            if (str_starts_with($keyword, ',')) {
                $keyword = substr($keyword, 1);
            }
        }

        return $values;
    }

    /**
     * Custom field values of a row (attributes['custom_field_values'] in Redmine importers).
     *
     * @param list<CustomField>  $fields
     * @param list<string|null> $row
     *
     * @return array<string, mixed>
     */
    protected function customFieldValues(Import $import, array $row, array $fields, ?Project $project): array
    {
        $out = [];
        foreach ($fields as $cf) {
            $key = 'cf_'.$cf->id;
            $value = 'date' === $cf->fieldFormat ? $this->rowDate($import, $row, $key) : $this->rowValue($import, $row, $key);
            if (null !== $value) {
                $out[(string) $cf->id] = $this->valueFromKeyword($cf, $value, $project);
            }
        }

        return $out;
    }

    /** project_tree_options_for_select: [id, label with tree indentation] @param list<Project> $projects @return list<array{0: int, 1: string}> */
    public static function projectTreeOptions(array $projects): array
    {
        usort($projects, static fn (Project $a, Project $b) => ($a->lft ?? 0) <=> ($b->lft ?? 0));
        $ids = array_map(static fn (Project $p) => $p->id, $projects);
        $out = [];
        foreach ($projects as $p) {
            $level = 0;
            $a = $p->parent;
            while (null !== $a) {
                if (\in_array($a->id, $ids, true)) {
                    ++$level;
                }
                $a = $a->parent;
            }
            $out[] = [(int) $p->id, ($level > 0 ? str_repeat("\u{00A0}\u{00A0}", $level).'» ' : '').$p->name];
        }

        return $out;
    }
}
