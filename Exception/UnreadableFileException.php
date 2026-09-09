<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Exception;

/**
 * The file could not be opened, or its first record could not be read.
 *
 * ⚠️ Its message is a TRANSLATION KEY, not a sentence. Everything a reader refuses is shown to the
 * person who uploaded the file, and a bundle cannot know their language. The English catalogue
 * ships the wording; a consumer overrides it.
 */
final class UnreadableFileException extends \RuntimeException
{
    public static function cannotOpen(string $filePath): self
    {
        return new self('dataflow.import.error.file_unreadable', previous: new \RuntimeException($filePath));
    }

    public static function empty(): self
    {
        return new self('dataflow.import.error.empty_file');
    }

    /**
     * The bytes are a workbook, not text.
     *
     * ⚠️ Separate from `cannotOpen` on purpose: "unreadable" tells the user nothing they can act
     * on, whereas "this is a spreadsheet, save it as CSV" is a instruction they can follow. The
     * whole point of {@see \Jul6Art\DataflowBundle\Io\Guard\SpreadsheetSignature} is lost if both
     * cases produce the same message.
     */
    public static function binaryWorkbook(): self
    {
        return new self('dataflow.import.error.binary_spreadsheet');
    }
}
