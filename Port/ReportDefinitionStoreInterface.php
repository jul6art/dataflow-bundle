<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Port;

use Jul6Art\AclBundle\Contract\AclUserInterface;

/**
 * Where saved reports live — which is in the application, always.
 *
 * ## Why the bundle ships no implementation at all
 *
 * A saved report is a row with an owner, a tenant, a visibility and a lifecycle; it belongs to the
 * application's schema and its migrations. A bundle that shipped an entity for it would force its
 * table on three applications with three different tenancy models, and the rule this ecosystem
 * arrived at is unambiguous: **a bundle interprets, a project persists**. With nothing bound,
 * reports are ephemeral — which is a complete, usable feature, and what the two greenfield
 * consumers will start with.
 *
 * ## Every method takes the actor, and that is not decoration
 *
 * ⚠️ **The store enforces ownership and tenancy; the bundle cannot.** It has no idea whether a
 * definition is visible to a colleague, whether "shared" means the team or the company, or how
 * tenants are separated. Passing the actor makes that the implementation's job explicitly, instead
 * of leaving a gap each consumer fills differently — and one of them not at all.
 *
 * ⚠️ **`find()` returns null for "not yours" as well as for "not there".** Distinguishing them
 * tells an attacker which identifiers exist, and a saved report's id is guessable.
 */
interface ReportDefinitionStoreInterface
{
    /**
     * The definitions this actor may run, in the order they should be offered.
     *
     * @return list<ReportDefinition>
     */
    public function listFor(AclUserInterface $actor): array;

    public function find(string|int $id, AclUserInterface $actor): ?ReportDefinition;

    /**
     * @return ReportDefinition the stored definition, carrying its id
     *
     * @throws \DomainException when this actor may not write it, message being a translation key
     */
    public function save(ReportDefinition $definition, AclUserInterface $actor): ReportDefinition;

    /**
     * ⚠️ Silent on an absent id, by design: deleting something that is already gone is the state
     * the caller asked for. It must still refuse an id belonging to someone else, and a refusal is
     * an exception rather than a silent no-op — the user pressed a button and deserves an answer.
     *
     * @throws \DomainException when this actor may not delete it, message being a translation key
     */
    public function delete(string|int $id, AclUserInterface $actor): void;
}
