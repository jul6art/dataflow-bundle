<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import;

use Doctrine\ORM\EntityManagerInterface;
use Jul6Art\DataflowBundle\Exception\ImportFailedException;
use Jul6Art\DataflowBundle\Import\Spec\DuplicatePolicy;
use Jul6Art\DataflowBundle\Import\Spec\ImportSpec;
use Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reads a file, maps each row, validates it, writes it in batches, and reports.
 *
 * ## Four things it does that the implementation it replaces did not
 *
 * ⚠️ **It detaches what it persisted, instead of letting the unit of work grow.** After each flush
 * every entity this run created is detached, so peak memory is one batch rather than the file. The
 * obvious alternative — `EntityManager::clear()` — is what Doctrine's own batch documentation
 * suggests and is wrong here: it detaches the CALLER's objects too, so the tenant the controller
 * passed in becomes detached and the next flush raises "A new entity was found through the
 * relationship". `detach()` names exactly the objects this class created and touches nothing else.
 *
 * ⚠️ **It resolves duplicates one batch at a time.** The contract takes a list and is expected to
 * answer with one query; the version this replaces asked per row, so a 5 000-row file issued 5 000
 * `SELECT`s.
 *
 * ⚠️ **It catches duplicates WITHIN the file.** Two identical rows are both absent from the
 * database when the batch is queried, so both would be persisted and the flush would die on the
 * unique index — closing the entity manager and taking the rest of the run with it. It also made
 * the dry run lie: two rows previewed, one imported.
 *
 * ⚠️ **It wraps the run in a transaction unless told otherwise.** A batched import is not atomic by
 * nature; a failure on batch 40 used to leave batches 1 to 39 committed with no record of which.
 *
 * ## Upsert reuses the same duplicate lookup, on purpose
 *
 * ⚠️ **`DuplicatePolicy::Update` still asks the SAME resolver, in the SAME one-query-per-batch
 * call.** What changes is what happens once a match is found: the row goes to the mapper WITH the
 * match instead of being counted and dropped, and the runner does not `persist()` what comes back
 * — the record `findExisting()` returned is already managed, and `flush()` sees changes to a
 * managed object on its own. A row with no match is still created exactly as under `Skip` or
 * `Fail`: upsert is duplicate handling with one more branch, not a second import engine.
 *
 * ## What it deliberately does not do
 *
 * No rate limiting, no audit entry, no permission check, no tenant scoping. Each of those is a
 * decision an application makes with objects this bundle does not know about, and the controller is
 * where they already live.
 */
final readonly class ImportRunner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @throws ImportFailedException             when a batch cannot be written
     * @throws \Jul6Art\DataflowBundle\Exception\UnreadableFileException
     */
    public function run(
        ImportSpec $spec,
        RowMapperInterface $mapper,
        TabularReaderInterface $reader,
        ?DuplicateResolverInterface $duplicates = null,
        ?ErrorSinkInterface $errorSink = null,
    ): ImportReport {
        // ⚠️ Refused here, loudly, rather than run: with no resolver `$existing` is always `null`,
        // which is indistinguishable from every row being new — an `Update` policy that updates
        // nothing is exactly the half-feature this enum's own history warns against shipping.
        if (DuplicatePolicy::Update === $spec->onDuplicate && null === $duplicates) {
            throw new \InvalidArgumentException('DuplicatePolicy::Update requires a duplicate resolver; none was given.');
        }

        $report = new ImportReport($spec->dryRun, $errorSink);

        // A dry run writes nothing, so there is nothing to wrap and nothing to roll back.
        $transactional = $spec->atomic && !$spec->dryRun;
        $connection = $this->entityManager->getConnection();

        if ($transactional) {
            $connection->beginTransaction();
        }

        try {
            $this->consume($spec, $mapper, $reader, $duplicates, $report);
        } catch (ImportFailedException $failure) {
            if (!$transactional) {
                throw $failure;
            }

            // The ORM's entity manager is closed at this point; the DBAL connection is not, so the
            // rollback still reaches the database.
            $connection->rollBack();

            throw new ImportFailedException(
                $failure->report(),
                $failure->record(),
                rolledBack: true,
                previous: $failure->getPrevious() ?? $failure,
            );
        }

        if ($transactional) {
            $connection->commit();
        }

        return $report;
    }

    private function consume(
        ImportSpec $spec,
        RowMapperInterface $mapper,
        TabularReaderInterface $reader,
        ?DuplicateResolverInterface $duplicates,
        ImportReport $report,
    ): void {
        /** @var list<array{record: int, row: array<string, string>}> $batch */
        $batch = [];
        /** @var array<string, true> $seen */
        $seen = [];
        $dataRows = 0;
        $isHeader = true;

        foreach ($reader->read($spec->filePath) as $record => $cells) {
            if ($isHeader) {
                $isHeader = false;

                continue;
            }

            if (++$dataRows > $spec->maxRows) {
                $report->recordError($record, 'dataflow.import.error.row_cap_reached');

                break;
            }

            $row = $this->extract($cells, $spec->mapping);

            // Every mapped column blank. Not an error — a trailing separator or a spacer line — but
            // it is counted, so the totals still add up to the number of records read.
            if ([] === $row) {
                $report->recordSkipped();

                continue;
            }

            $key = $duplicates?->keyOf($row);

            if (null !== $key && isset($seen[$key])) {
                $this->countDuplicate($spec, $report, $record);

                continue;
            }

            if (null !== $key) {
                $seen[$key] = true;
            }

            $batch[] = ['record' => $record, 'row' => $row];

            if (\count($batch) >= $spec->batchSize) {
                $this->processBatch($spec, $mapper, $duplicates, $report, $batch, $record);
                $batch = [];
            }
        }

        if ([] !== $batch) {
            $this->processBatch($spec, $mapper, $duplicates, $report, $batch, $batch[\count($batch) - 1]['record']);
        }
    }

    /**
     * @param non-empty-list<array{record: int, row: array<string, string>}> $batch
     * @param int                                                            $lastRecord the record
     *                                                                                   the batch
     *                                                                                   ends on,
     *                                                                                   reported if
     *                                                                                   the flush
     *                                                                                   fails
     */
    private function processBatch(
        ImportSpec $spec,
        RowMapperInterface $mapper,
        ?DuplicateResolverInterface $duplicates,
        ImportReport $report,
        array $batch,
        int $lastRecord,
    ): void {
        $rows = \array_map(static fn (array $entry): array => $entry['row'], $batch);
        $existing = $duplicates?->findExisting($rows) ?? [];

        /** @var list<object> $persisted */
        $persisted = [];

        foreach ($batch as $index => $entry) {
            $match = $existing[$index] ?? null;

            if (null !== $match && DuplicatePolicy::Update !== $spec->onDuplicate) {
                $this->countDuplicate($spec, $report, $entry['record']);

                continue;
            }

            try {
                $entity = $mapper->map($entry['row'], $match);
            } catch (\DomainException $refusal) {
                $report->recordError($entry['record'], $refusal->getMessage());

                continue;
            }

            $violation = $this->firstViolation($entity);

            if (null !== $violation) {
                $report->recordError($entry['record'], $violation);

                continue;
            }

            null !== $match ? $report->recordUpdated() : $report->recordImported();

            if ($spec->dryRun) {
                continue;
            }

            // ⚠️ Never `persist()` a match: it is already managed, and doing so anyway would be
            // harmless on its own but would blur the one thing that tells a reader whether this
            // branch created a row or mutated one — the very distinction `recordUpdated()` exists
            // to keep visible in the report.
            if (null === $match) {
                $this->entityManager->persist($entity);
            }

            $persisted[] = $entity;
        }

        if ([] === $persisted) {
            return;
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable $failure) {
            throw new ImportFailedException($report, $lastRecord, rolledBack: false, previous: $failure);
        }

        // ⚠️ `detach`, not `clear`. See the class docblock: clearing would also detach the objects
        // the caller still holds, and the next flush would treat the tenant as a new entity.
        foreach ($persisted as $entity) {
            $this->entityManager->detach($entity);
        }
    }

    /**
     * @param list<string>       $cells
     * @param array<int, string> $mapping
     *
     * @return array<string, string> field key → value; a blank cell is ABSENT rather than an empty
     *                               string, so a mapper's `??` gives the field's default in one place
     */
    private function extract(array $cells, array $mapping): array
    {
        $row = [];

        foreach ($mapping as $index => $field) {
            // A record shorter than the mapping is normal: a writer that omits trailing empty
            // columns produces one on every row that ends blank.
            $value = \trim($cells[$index] ?? '');

            if ('' !== $value) {
                $row[$field] = $value;
            }
        }

        return $row;
    }

    /**
     * @return string|null the first violation, rendered, or null when the entity is valid
     */
    private function firstViolation(object $entity): ?string
    {
        $violations = $this->validator->validate($entity);

        if (0 === \count($violations)) {
            return null;
        }

        $first = $violations->get(0);

        return \sprintf('%s: %s', $first->getPropertyPath(), (string) $first->getMessage());
    }

    private function countDuplicate(ImportSpec $spec, ImportReport $report, int $record): void
    {
        if (DuplicatePolicy::Fail === $spec->onDuplicate) {
            $report->recordError($record, 'dataflow.import.error.duplicate');

            return;
        }

        $report->recordSkipped();
    }
}
