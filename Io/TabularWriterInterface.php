<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io;

/**
 * Writes a header and a stream of rows in one tabular format.
 *
 * ## The contract, and the one thing it is strict about
 *
 * ⚠️ **`$rows` is an `iterable` and implementations MUST consume it lazily.** The signature existed
 * before the extraction and was honoured by every implementation and violated by every caller: one
 * runner materialised the whole result set into an array, then handed that array to a writer whose
 * docblock promised streaming. With a 50 000-row ceiling and ten columns, the process held two
 * complete copies before emitting its first byte.
 *
 * A caller therefore passes a `Generator` — and asserting the content is what let the defect
 * survive, since every row was correct either way.
 *
 * ⚠️ **Asserting the TYPE does not prove it either**, which this file used to recommend. A body
 * that builds the whole array and then `yield from`s it is still a generator function, so
 * `assertInstanceOf(\Generator::class)` stays green through exactly the change it was meant to
 * catch. What discriminates is peak memory, measured: on 6 000 rows the streaming path costs
 * nothing measurable and the materialising one costs 4 MB.
 *
 * ⚠️ **`$emit` receives chunks, not a document.** A CSV writer calls it per row. A spreadsheet
 * writer cannot — a workbook is a ZIP archive and its central directory is written last — so it
 * buffers to a temporary file and then emits that file **in chunks**, never as one string: reading
 * a finished 40 MB workbook into a single PHP string to hand it over defeats the point of having
 * streamed it.
 *
 * @phpstan-type Row array<array-key, scalar|null>
 */
interface TabularWriterInterface
{
    /**
     * The format's short code, as an application names it in a URL or a form (`csv`, `xlsx`).
     */
    public function code(): string;

    public function contentType(): string;

    public function fileExtension(): string;

    /**
     * @param list<string>                          $header
     * @param iterable<array<array-key, scalar|null>> $rows
     * @param callable(string $chunk): void         $emit
     */
    public function write(array $header, iterable $rows, callable $emit): void;
}
