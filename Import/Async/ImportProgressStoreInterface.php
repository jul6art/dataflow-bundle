<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Async;

use Jul6Art\DataflowBundle\Import\ImportReport;

/**
 * Where an asynchronous import's progress goes, so a screen has something to poll.
 *
 * ## Why this exists
 *
 * ⚠️ A synchronous import returns its {@see ImportReport} to the caller directly — the HTTP response
 * IS the answer. An import running in {@see ImportMessageHandler}, off the request thread, has no
 * caller left to answer: the request that dispatched it has already returned. Something has to
 * persist what happened, or an operator who started a 50 000-row import has no way to know whether
 * it succeeded, failed, or is still running.
 *
 * ## Why a port, not a bundled table
 *
 * ⚠️ Same reasoning as `Port\ReportDefinitionStoreInterface`: a progress record is a row with an
 * owner, a tenant, and a place on a screen, and none of that is this bundle's to invent. `superp`,
 * `cegeta` and `cereezer` do not share a tenancy model; a bundled entity would fit one of the three
 * and force a migration on the other two.
 *
 * ⚠️ **Bound to {@see NullImportProgressStore} by default, unlike `ReportDefinitionStoreInterface`.**
 * That port has no default because an ephemeral report is still a complete feature; this one would
 * not be — an import with nowhere to report its progress is a black hole an operator cannot see
 * into. The trade is deliberate anyway: `ImportMessageHandler` is registered whenever
 * `symfony/messenger` is, for EVERY consumer that installs it, whether or not they use this
 * bundle's async import — a hard, defaultless argument would make THEIR container fail to compile
 * over a port they never meant to need, the exact hazard `OptionalContractPass` documents having
 * shipped twice already. Binding this port for real visibility is a decision worth making
 * deliberately; failing to boot over it is not.
 */
interface ImportProgressStoreInterface
{
    public function starting(string $importId): void;

    public function finished(string $importId, ImportReport $report): void;

    public function failed(string $importId, \Throwable $failure): void;
}
