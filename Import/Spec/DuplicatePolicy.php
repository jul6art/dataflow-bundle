<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Spec;

/**
 * What an import does with a row that already exists.
 *
 * ⚠️ **`Update` used to be deliberately absent, and the reason it can exist now is a signature, not
 * a behaviour change.** `RowMapperInterface::map()` has always accepted `$existing`, unused, since
 * shipping the parameter before the case would have cost every implementation nothing and shipping
 * the case before the parameter would have meant an enum value that silently skipped — the worst
 * kind of half-feature, because the caller has no way to tell. The runner now passes the fetched
 * record through that parameter when this case is active; nothing else about the contract moved.
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

    /**
     * Apply the row onto the record a {@see \Jul6Art\DataflowBundle\Import\DuplicateResolverInterface}
     * found, instead of creating a new one or refusing it.
     *
     * ⚠️ **Requires a resolver.** Without one, the runner has no way to find what a row would
     * update, and `$existing` would always be `null` — which is indistinguishable from every row
     * being new. `ImportRunner::run()` refuses this combination rather than silently behaving like
     * {@see self::Skip} with nothing ever skipped: a policy nobody chose, worse than one that fails
     * loudly, is exactly the half-feature this enum's history exists to warn against.
     *
     * ⚠️ **The mapper MUST return the SAME object it was given, mutated — never a new one.** The
     * record `findExisting()` returned is already managed by Doctrine; the runner never calls
     * `persist()` on it; a fresh object handed back in its place would be silently DISCARDED, not
     * saved — Doctrine only flushes changes to what is managed or explicitly persisted, and this
     * runner does neither for a value it does not recognise as the one it fetched.
     */
    case Update = 'update';
}
