<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Port;

/**
 * The documented default: exports are not journalled.
 *
 * ⚠️ A null object rather than a nullable argument, so that no caller has to write
 * `$this->auditor?->record(...)`. Six call sites each remembering the `?` is six chances to forget
 * it — and the one that forgets fails only in the applications that bind no auditor, which are
 * exactly the ones nobody runs the tests of.
 */
final readonly class NullExportAuditor implements ExportAuditorInterface
{
    #[\Override]
    public function record(ExportRecord $record): void
    {
    }
}
