<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Async;

use Jul6Art\DataflowBundle\Import\ImportReport;

/**
 * The default {@see ImportProgressStoreInterface} — records nothing, the same role
 * {@see \Jul6Art\DataflowBundle\Port\NullExportAuditor} plays for auditing.
 *
 * ⚠️ An import still runs correctly with this bound: nothing here affects whether a row lands, only
 * whether anyone can see that it did. A consumer that wants a screen to poll binds its own.
 */
final class NullImportProgressStore implements ImportProgressStoreInterface
{
    #[\Override]
    public function starting(string $importId): void
    {
    }

    #[\Override]
    public function finished(string $importId, ImportReport $report): void
    {
    }

    #[\Override]
    public function failed(string $importId, \Throwable $failure): void
    {
    }
}
