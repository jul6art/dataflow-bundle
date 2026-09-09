<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import;

/**
 * Turns one mapped row into one entity. The business half of an import, and the half that stays in
 * the application.
 *
 * ## Why this is a contract and not configuration
 *
 * Everything an import does that is *generic* — reading, mapping columns, batching, validating,
 * counting, reporting — lives in {@see ImportRunner}. What remains is "what does a row of this file
 * mean for this entity", and that is business: a tenant to attach, a default status, a referential
 * to look up, a name to split. It cannot be declared in YAML without inventing a language, and the
 * attempts to do so are how import engines become frameworks.
 *
 * ## Three rules the runner relies on
 *
 * ⚠️ **Throw `\DomainException` with a TRANSLATION KEY as the message.** The runner catches it and
 * puts it in the report next to the record number. A sentence would reach the user untranslated;
 * an uncaught exception would end the import on one bad row.
 *
 * ⚠️ **You may hold an entity the CALLER supplied** — a tenant, a referential — and that is exactly
 * why the runner detaches what it persisted instead of clearing the unit of work. Clearing would
 * detach your held object too, and the next flush would raise "A new entity was found through the
 * relationship".
 *
 * ⚠️ **What you must not hold is an entity a previous `map()` created.** The runner detached it
 * after its batch was flushed, so reusing it as a relation target makes the next flush treat an
 * already-stored record as new. If a row needs to point at an earlier row of the same file, look it
 * up — or take `EntityManagerInterface::getReference()`, which costs no query and returns a fresh
 * proxy each time.
 *
 * ⚠️ **`$existing` is always null today.** It is in the signature now because upsert is a planned
 * lot, and adding a parameter to a published interface is a breaking change for every
 * implementation — whereas accepting one that is not yet passed costs an unused argument.
 */
interface RowMapperInterface
{
    /**
     * The field keys this mapper understands, in the order a mapping screen or a template file
     * should offer them.
     *
     * @return list<string>
     */
    public function fields(): array;

    /**
     * @param array<string, string> $row field key → cell value, trimmed; a column left blank in the
     *                                   file is ABSENT from this array rather than an empty string,
     *                                   so `??` gives the field's default in one place
     *
     * @throws \DomainException when the row cannot become an entity, message being a translation key
     */
    public function map(array $row, ?object $existing = null): object;
}
