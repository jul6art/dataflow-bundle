<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import;

/**
 * Decides which rows of a batch already exist.
 *
 * ## The signature IS the fix
 *
 * ⚠️ The implementation this replaces asked one question per row —
 * `findOneByOrganizationAndEmail()` inside the loop — so a five-thousand-row file issued five
 * thousand `SELECT`s. A per-row interface would have made that unavoidable no matter how the
 * implementation was written, so the contract takes the whole batch and is expected to answer with
 * **one** query, `WHERE email IN (…)`.
 *
 * ## And `keyOf` is not a convenience
 *
 * ⚠️ Without it, two identical rows *inside the same file* are both new: neither is in the database
 * when the batch is queried, so both are persisted, and the flush dies on the unique index — which
 * closes the entity manager and takes the rest of the import with it. Worse, a dry run would report
 * two rows imported where the real run imports one, so the preview would be a lie. The runner keeps
 * the keys it has seen and skips the second occurrence, which needs a key it can compare.
 *
 * Returning `null` means "this row has no business key", and such a row is never a duplicate.
 */
interface DuplicateResolverInterface
{
    /**
     * A stable, comparable identity for the row — an e-mail, a reference, a composite.
     *
     * @param array<string, string> $row field key → cell value
     */
    public function keyOf(array $row): ?string;

    /**
     * @param list<array<string, string>> $rows the batch's rows, in order
     *
     * @return array<int, object> index within `$rows` → the record already stored; absent index
     *                            means new. Implementations MUST resolve the batch in one query.
     */
    public function findExisting(array $rows): array;
}
