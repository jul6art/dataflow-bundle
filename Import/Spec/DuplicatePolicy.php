<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Spec;

/**
 * What an import does with a row that already exists.
 *
 * ⚠️ `Update` is deliberately absent. Upsert needs the mapper to apply a row onto an existing
 * entity, and shipping the case before the behaviour would mean an enum value that silently skips
 * — the worst kind of half-feature, because the caller has no way to tell. Adding a case later is
 * not a breaking change; shipping one that lies is.
 */
enum DuplicatePolicy: string
{
    /** Count it, say so in the report, move on. The default, and what an operator expects. */
    case Skip = 'skip';

    /**
     * Record it as an error on that row.
     *
     * For a file that is supposed to contain only new records: a duplicate then means the operator
     * picked the wrong file, and silence would let them import half of it before noticing.
     */
    case Fail = 'fail';
}
