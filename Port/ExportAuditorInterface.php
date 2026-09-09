<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Port;

/**
 * Records that an export happened.
 *
 * Optional: with nothing bound, the bundle uses {@see NullExportAuditor} and exports are not
 * journalled. An application with `audit-bundle` installed binds this to a fifteen-line adapter.
 *
 * ⚠️ **An implementation must not throw.** An audit trail that can fail the thing it observes turns
 * a full log table into an outage of the export feature. Swallow, log, and let the export finish —
 * and if losing an entry is unacceptable in your domain, that is a reason to make the write
 * transactional in your adapter, not a reason to propagate.
 */
interface ExportAuditorInterface
{
    public function record(ExportRecord $record): void;
}
