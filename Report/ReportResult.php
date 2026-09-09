<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report;

/**
 * What a report run hands back: its header, and its rows as a stream.
 *
 * ## Why the rows are a `Generator` and not an array
 *
 * ⚠️ The runner this replaces returned `['columns' => [...], 'rows' => [...]]` — both complete
 * arrays. It called `getArrayResult()` and then built a **second** full array to re-key the rows by
 * column path, so with a fifty-thousand-row ceiling and ten columns the process held two entire
 * copies before emitting its first byte. The writers' signature already said `iterable` and
 * promised streaming; the only caller broke the promise, and no content assertion could see it
 * because every row was correct.
 *
 * ⚠️ **A `Generator` is single-use, and that is a contract, not a defect.** Iterating twice throws
 * `Cannot traverse an already closed generator`. A caller that needs the rows twice — a preview
 * above an export, say — runs the report twice or buffers deliberately, which is the point: the
 * cost becomes a decision instead of a default.
 *
 * ⚠️ **And it cannot be counted without consuming it.** `count()` on a report is a second query,
 * not a property of this object. Offering a `count()` here would silently materialise everything.
 */
final readonly class ReportResult
{
    /**
     * @param list<string>                                       $header
     * @param \Closure(): \Generator<int, array<string, scalar|null>> $rows
     */
    public function __construct(
        private array $header,
        private \Closure $rows,
    ) {
    }

    /**
     * @return list<string>
     */
    public function header(): array
    {
        return $this->header;
    }

    /**
     * @return \Generator<int, array<string, scalar|null>>
     */
    public function rows(): \Generator
    {
        return ($this->rows)();
    }
}
