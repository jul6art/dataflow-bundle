<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Writer;

use Jul6Art\DataflowBundle\Io\TabularWriterInterface;

/**
 * Writes a JSON document, row by row.
 *
 * ## The document is assembled by hand, the values are not
 *
 * ⚠️ **The braces and commas are written here on purpose**, because serialising a complete object
 * is precisely what has to be avoided: `json_encode($everything)` builds the whole document in
 * memory before emitting its first byte, which is the defect this layer exists to remove. A three
 * year old technician's export is tens of thousands of rows.
 *
 * ⚠️ **Every value still goes through `json_encode()`**, one row at a time. Escaping a string is
 * exactly the part nobody should write by hand — a quote, a newline, a lone surrogate or an
 * invalid UTF-8 byte each change the answer. The split is deliberate: structure by hand, escaping
 * by the extension.
 *
 * ## No formula guard here, and that is not an omission
 *
 * ⚠️ A spreadsheet never opens this file, so prefixing a cell with an apostrophe would corrupt the
 * payload of the one consumer that exists — a program reading JSON. The guard belongs to the
 * formats a human double-clicks.
 */
final readonly class JsonWriter implements TabularWriterInterface
{
    #[\Override]
    public function code(): string
    {
        return 'json';
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/json; charset=UTF-8';
    }

    #[\Override]
    public function fileExtension(): string
    {
        return 'json';
    }

    #[\Override]
    public function write(array $header, iterable $rows, callable $emit): void
    {
        $emit('{"columns":'.$this->encode($header).',"rows":[');

        $first = true;

        foreach ($rows as $row) {
            $emit(($first ? '' : ',').$this->encode($row));
            $first = false;
        }

        $emit(']}');
    }

    /**
     * ⚠️ `JSON_THROW_ON_ERROR` rather than a silent `false`. A row that cannot be encoded — invalid
     * UTF-8 out of a legacy column is the realistic case — would otherwise emit the empty string
     * and produce a syntactically valid document silently missing a row. Failing is the only honest
     * answer, and the caller's `StreamedResponse` surfaces it.
     *
     * @param array<array-key, scalar|null> $values
     */
    private function encode(array $values): string
    {
        return \json_encode($values, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
