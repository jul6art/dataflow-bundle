<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Writer;

use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use Jul6Art\DataflowBundle\Io\Guard\FormulaInjectionGuard;
use Jul6Art\DataflowBundle\Io\TabularWriterInterface;

/**
 * Writes CSV, one row at a time, in the dialect it is given.
 *
 * ## Why it formats through `fputcsv` and a memory stream
 *
 * Quoting a CSV field correctly is a longer job than it looks — a delimiter, a quote, a newline or
 * a leading space inside a value each change the answer — and PHP already does it. Re-implementing
 * it to avoid a stream handle would be the wrong trade: the handle is opened once per writer call,
 * not per row.
 *
 * ⚠️ `fputcsv` always terminates with `\n`. A dialect asking for CRLF therefore needs the trailing
 * byte swapped, which is done here rather than by post-processing the whole document — the point of
 * this class is that no complete document ever exists in memory.
 *
 * ## Every cell goes through the guard, header included
 *
 * ⚠️ The header is **not** trusted input. In a report builder a column label is typed by the user,
 * so the header row is an injection vector exactly like the data. Guarding only the body is the
 * mistake that looks harmless.
 */
final readonly class CsvWriter implements TabularWriterInterface
{
    public function __construct(
        private CsvDialect $dialect = new CsvDialect(),
    ) {
    }

    #[\Override]
    public function code(): string
    {
        return 'csv';
    }

    #[\Override]
    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    #[\Override]
    public function fileExtension(): string
    {
        return 'csv';
    }

    #[\Override]
    public function write(array $header, iterable $rows, callable $emit): void
    {
        if ($this->dialect->bom) {
            $emit("\xEF\xBB\xBF");
        }

        $handle = \fopen('php://memory', 'r+');
        if (false === $handle) {
            return;
        }

        try {
            if ([] !== $header) {
                $emit($this->encode($handle, $header));
            }

            foreach ($rows as $row) {
                $emit($this->encode($handle, $row));
            }
        } finally {
            \fclose($handle);
        }
    }

    /**
     * Formats one row, reusing the same handle across the whole document.
     *
     * @param resource                       $handle
     * @param array<array-key, scalar|null>  $cells
     */
    private function encode($handle, array $cells): string
    {
        \rewind($handle);
        \ftruncate($handle, 0);

        \fputcsv(
            $handle,
            FormulaInjectionGuard::neutralizeRow($cells),
            $this->dialect->delimiter,
            $this->dialect->enclosure,
            $this->dialect->escape,
        );

        \rewind($handle);
        $line = \stream_get_contents($handle);

        if (false === $line) {
            return '';
        }

        return \substr($line, 0, -1).$this->dialect->lineEnding;
    }
}
