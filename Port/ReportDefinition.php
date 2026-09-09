<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Port;

/**
 * A saved report, as the application stores it.
 *
 * ## Why this holds a raw payload and not a `ReportSpec`
 *
 * ⚠️ **A `ReportSpec` is valid against TODAY's catalogue; a saved report has to survive tomorrow's.**
 * A column is renamed, an entity leaves the catalogue, a permission is narrowed — and a store that
 * had persisted a `ReportSpec` would hand back an object asserting facts that are no longer true,
 * or would fail to rehydrate at all and take the user's saved report with it. So the payload is
 * kept exactly as it was submitted and re-interpreted on every load, by the same
 * {@see \Jul6Art\DataflowBundle\Report\Spec\ReportSpecInterpreter} that handles a fresh screen: an
 * unknown column is dropped, a missing entity is refused, and the user is told which.
 *
 * That is the same reading the datatable preferences of this ecosystem already use, for the same
 * reason: the alternative costs the user their whole layout.
 *
 * ⚠️ **And re-interpretation is a SECURITY property, not only a robustness one.** A definition saved
 * when the author could read `customer.email` must not still expose it after the permission is
 * revoked. Storing a spec would have frozen the authorisation decision at save time.
 */
final readonly class ReportDefinition
{
    /**
     * @param array<array-key, mixed> $payload the submitted definition, verbatim: `entity`,
     *                                         `columns`, `filters`
     * @param string|int|null         $id      null for a definition that has never been stored
     * @param bool                    $shared  whether other members of the tenant may run it; the
     *                                         STORE enforces this, not this object
     */
    public function __construct(
        public string $name,
        public array $payload,
        public string|int|null $id = null,
        public string|int|null $ownerId = null,
        public bool $shared = false,
    ) {
    }

    public function withId(string|int $id): self
    {
        return new self($this->name, $this->payload, $id, $this->ownerId, $this->shared);
    }
}
