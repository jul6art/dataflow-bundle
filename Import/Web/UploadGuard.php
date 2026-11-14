<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

use Jul6Art\DataflowBundle\Io\Guard\SpreadsheetSignature;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What an uploaded file must be before it is stored: there, not too large, not a binary spreadsheet, of a text type.
 */
final readonly class UploadGuard
{
    /**
     * ⚠️ **A WIDE list, on purpose.** A CSV has no reliable type: the same file is guessed `text/csv`, `text/plain`,
     * `application/csv` or `application/vnd.ms-excel` — the last one is what an export from Excel gets. What the list
     * keeps out is an executable or an archive on the server's disk; `application/octet-stream` is not in it for
     * exactly that reason.
     *
     * @var list<string>
     */
    public const array MIME_TYPES = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];

    public function __construct(
        private int $maxBytes,
    ) {
    }

    /**
     * The refusal's translation key (domain `dataflow`), or `null` when the file may be stored.
     *
     * @param int|null $maxBytes the definition's ceiling, `null` for the configured one
     */
    public function refusalOf(mixed $file, ?int $maxBytes = null): ?string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return 'dataflow.import.error.upload_failed';
        }

        if ($file->getSize() > ($maxBytes ?? $this->maxBytes)) {
            return 'dataflow.import.error.file_too_large';
        }

        // ⚠️ BEFORE the type. A binary `.xls` is guessed `application/vnd.ms-excel`, which must stay allowed (it is
        // also what a browser sends for a CSV produced by Excel): that workbook would pass the type check, the CSV
        // reader would fail on every line of binary, and the report would say "imported: 0" without saying why.
        $path = $file->getRealPath();

        if (false !== $path && SpreadsheetSignature::matchesFile($path)) {
            return 'dataflow.import.error.binary_spreadsheet';
        }

        if (!\in_array($file->getMimeType(), self::MIME_TYPES, true)) {
            return 'dataflow.import.error.invalid_mime';
        }

        return null;
    }
}
