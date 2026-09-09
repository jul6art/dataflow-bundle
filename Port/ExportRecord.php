<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Port;

/**
 * What was exported, by whom, and how much of it.
 *
 * ⚠️ **`$rows` is filled in AFTER the response has been streamed**, which is the only moment the
 * number exists: a streamed export does not know its own size in advance, and that is the point of
 * streaming it. An auditor called before the first byte would record every export as zero rows —
 * and a row count is the single most useful thing in an export audit trail, because it is what
 * distinguishes a normal export from an exfiltration.
 */
final readonly class ExportRecord
{
    /**
     * @param string          $subject  what was exported, as the application names it — a report
     *                                  name, an entity, a screen
     * @param string          $format   the writer's short code (`csv`, `xlsx`, `json`)
     * @param string|int|null $actorId  null for a run with no user: a command, a scheduled job
     * @param string|int|null $tenantId null on a single-tenant application
     */
    public function __construct(
        public string $subject,
        public string $format,
        public int $rows,
        public string|int|null $actorId = null,
        public string|int|null $tenantId = null,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
    ) {
    }
}
