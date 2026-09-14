<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Sink;

use Jul6Art\DataflowBundle\Import\ErrorSinkInterface;
use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use Jul6Art\DataflowBundle\Io\Guard\FormulaInjectionGuard;

/**
 * Writes every import error to a file handle, one `fputcsv` call at a time — the complete list
 * {@see \Jul6Art\DataflowBundle\Import\ImportReport} deliberately does not keep.
 *
 * ```php
 * $handle = fopen('php://temp', 'w+');
 * $sink = new CsvErrorSink($handle);
 *
 * $report = $runner->run($spec, $mapper, $reader, errorSink: $sink);
 *
 * if ($report->errorCount() > 0) {
 *     rewind($handle);
 *     // stream $handle back as a download — the complete list, not the report's 100-row sample
 * }
 * fclose($handle);
 * ```
 *
 * ⚠️ **The handle is the caller's, opened and closed by the caller.** A sink that opened its own
 * temporary file would need to expose it anyway for the download that follows, and owning a
 * resource it does not hand back is a leak waiting for whoever forgets `fclose()`.
 *
 * ⚠️ **The header is written once, on construction, not lazily on the first row.** A run with zero
 * errors still produces a file with a header and nothing else — the correct, unsurprising shape of
 * "there was nothing to report," rather than an empty file a consumer has to special-case.
 *
 * ⚠️ **Every cell goes through {@see FormulaInjectionGuard}, header included.** A message can be a
 * validator's rendering of a user-typed value; the same guard a `TabularWriterInterface` applies to
 * export data applies here, on the same terms.
 *
 * ⚠️ **A non-default line ending needs a SEEKABLE handle.** `fputcsv` always terminates a row with
 * `\n`; a dialect asking for CRLF is honoured by seeking back one byte and overwriting it, exactly
 * as {@see \Jul6Art\DataflowBundle\Io\Writer\CsvWriter} does on its own throwaway buffer. `php://temp`
 * and a real file both support this; `php://output` does not — write to a seekable handle first and
 * hand the finished file to a response afterward, rather than streaming straight to the client.
 */
final class CsvErrorSink implements ErrorSinkInterface
{
    /**
     * @param resource              $handle
     * @param array{string, string} $header
     */
    public function __construct(
        private $handle,
        private readonly CsvDialect $dialect = new CsvDialect(),
        array $header = ['record', 'message'],
    ) {
        if ($this->dialect->bom) {
            \fwrite($this->handle, "\xEF\xBB\xBF");
        }

        $this->writeRow($header);
    }

    #[\Override]
    public function record(int $record, string $message): void
    {
        $this->writeRow([$record, $message]);
    }

    /**
     * @param array{int|string, int|string} $cells
     */
    private function writeRow(array $cells): void
    {
        \fputcsv(
            $this->handle,
            FormulaInjectionGuard::neutralizeRow($cells),
            $this->dialect->delimiter,
            $this->dialect->enclosure,
            $this->dialect->escape,
        );

        if ("\n" !== $this->dialect->lineEnding) {
            \fseek($this->handle, -1, \SEEK_CUR);
            \fwrite($this->handle, $this->dialect->lineEnding);
        }
    }
}
