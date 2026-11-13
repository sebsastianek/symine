<?php

declare(strict_types=1);

namespace App\Import;

/** Raised when the file to import cannot be read with the chosen settings (CSV::MalformedCSVError / EncodingError). */
final class ImportFileException extends \RuntimeException
{
    public const string MALFORMED = 'malformed';
    public const string ENCODING = 'encoding';
    public const string READ = 'read';

    public function __construct(public readonly string $kind = self::READ, string $message = '')
    {
        parent::__construct($message);
    }
}
