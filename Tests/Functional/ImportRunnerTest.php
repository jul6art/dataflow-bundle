<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Jul6Art\DataflowBundle\Exception\ImportFailedException;
use Jul6Art\DataflowBundle\Import\ErrorSinkInterface;
use Jul6Art\DataflowBundle\Import\ImportReport;
use Jul6Art\DataflowBundle\Import\ImportRunner;
use Jul6Art\DataflowBundle\Import\RowMapperInterface;
use Jul6Art\DataflowBundle\Import\Sink\CsvErrorSink;
use Jul6Art\DataflowBundle\Import\Spec\DuplicatePolicy;
use Jul6Art\DataflowBundle\Import\Spec\ImportSpec;
use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use Jul6Art\DataflowBundle\Io\Reader\CsvReader;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Account;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Customer;
use Jul6Art\DataflowBundle\Tests\Fixtures\Import\CustomerRowMapper;
use Jul6Art\DataflowBundle\Tests\Fixtures\Import\EmailDuplicateResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The import engine against a real entity manager and a real SQLite database.
 *
 * ⚠️ Functional rather than unit, and not out of laziness: everything that made the previous
 * implementation wrong was Doctrine behaviour — the unit of work growing with the file, the entity
 * manager closing on a failed flush, a detached tenant being treated as a new entity. A mocked
 * `EntityManagerInterface` would confirm the calls were made and none of that.
 */
#[CoversClass(ImportRunner::class)]
final class ImportRunnerTest extends AbstractFunctionalTestCase
{
    private ?ContainerInterface $container = null;

    private ?EntityManagerInterface $entityManager = null;

    /** @var list<string> */
    private array $files = [];

    #[\Override]
    protected function tearDown(): void
    {
        $this->entityManager = null;
        $this->container = null;

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];

        parent::tearDown();
    }

    public function testAPlainFileLandsInTheDatabase(): void
    {
        $this->schema();

        $report = $this->import("name,email\nAda,ada@example.test\nBob,bob@example.test\n");

        self::assertSame(2, $report->imported());
        self::assertSame(0, $report->skipped());
        self::assertSame(0, $report->errorCount());
        self::assertSame(['Ada', 'Bob'], $this->storedNames());
    }

    /**
     * ⚠️ The whole point of a dry run: the same counts, and nothing written. A preview that reports
     * differently from the run it previews is worse than no preview.
     */
    public function testADryRunCountsEverythingAndWritesNothing(): void
    {
        $this->schema();
        $csv = "name,email\nAda,ada@example.test\nBob,not-an-email\n";

        $preview = $this->import($csv, dryRun: true);

        self::assertSame(1, $preview->imported());
        self::assertSame(1, $preview->errorCount());
        self::assertTrue($preview->isDryRun());
        self::assertSame([], $this->storedNames());

        $real = $this->import($csv);

        self::assertSame($preview->imported(), $real->imported());
        self::assertSame($preview->errorCount(), $real->errorCount());
        self::assertSame(['Ada'], $this->storedNames());
    }

    /**
     * ⚠️ D-15: one query for the batch, not one per row. Nothing about the RESULT could have caught
     * the version that asked per row — every answer was correct — so the count is the assertion.
     */
    public function testABatchIsResolvedInOneQuery(): void
    {
        $this->schema();
        $this->seedCustomer('ada@example.test');

        $rows = "name,email\n";

        for ($i = 1; $i <= 20; ++$i) {
            $rows .= 'Client '.$i.',c'.$i."@example.test\n";
        }

        $resolver = new EmailDuplicateResolver($this->manager());
        $report = $this->import("name,email\nAda,ada@example.test\n".substr($rows, \strlen("name,email\n")), batchSize: 50, resolver: $resolver);

        self::assertSame(1, $resolver->queries, 'Twenty-one rows, one batch, one SELECT.');
        self::assertSame(20, $report->imported());
        self::assertSame(1, $report->skipped(), 'Ada was already stored.');
    }

    /**
     * ⚠️ The trap the batch query cannot see: neither of two identical rows is in the database when
     * the batch is queried, so both would be persisted and the flush would die on the unique index
     * — closing the entity manager and taking the rest of the file with it.
     */
    public function testTwoIdenticalRowsInOneFileImportOnce(): void
    {
        $this->schema();

        $report = $this->import(
            "name,email\nAda,ada@example.test\nAda again,ADA@example.test\nBob,bob@example.test\n",
            resolver: new EmailDuplicateResolver($this->manager()),
        );

        self::assertSame(2, $report->imported());
        self::assertSame(1, $report->skipped());
        self::assertSame(['Ada', 'Bob'], $this->storedNames());
    }

    /**
     * ⚠️ And the dry run has to agree with it, or the preview lies about the one case an operator
     * runs a preview for.
     */
    public function testADryRunSeesTheInFileDuplicateToo(): void
    {
        $this->schema();

        $report = $this->import(
            "name,email\nAda,ada@example.test\nAda again,ada@example.test\n",
            dryRun: true,
            resolver: new EmailDuplicateResolver($this->manager()),
        );

        self::assertSame(1, $report->imported());
        self::assertSame(1, $report->skipped());
    }

    public function testTheFailPolicyTurnsADuplicateIntoAnError(): void
    {
        $this->schema();
        $this->seedCustomer('ada@example.test');

        $report = $this->import(
            "name,email\nAda,ada@example.test\n",
            onDuplicate: DuplicatePolicy::Fail,
            resolver: new EmailDuplicateResolver($this->manager()),
        );

        self::assertSame(0, $report->imported());
        self::assertSame(0, $report->skipped());
        self::assertSame([['record' => 2, 'message' => 'dataflow.import.error.duplicate']], $report->errors());
    }

    /**
     * ⚠️ **The row LANDS ON THE SAME record, not a second one.** The one query duplicate detection
     * already relies on is what tells the runner which record to hand the mapper — this test is
     * what proves the runner actually uses it instead of quietly falling back to creating a row,
     * which would leave the database with two customers sharing one email the moment the unique
     * constraint isn't there to stop it.
     */
    public function testTheUpdatePolicyAppliesTheRowOntoTheExistingRecord(): void
    {
        $this->schema();
        $this->seedCustomer('ada@example.test');

        $report = $this->import(
            "name,email\nAda Lovelace,ada@example.test\n",
            onDuplicate: DuplicatePolicy::Update,
            resolver: new EmailDuplicateResolver($this->manager()),
        );

        self::assertSame(0, $report->imported(), 'Nothing new was created.');
        self::assertSame(1, $report->updated());
        self::assertSame(0, $report->skipped());
        self::assertSame(['Ada Lovelace'], $this->freshManagerNames(), 'One row in the table, renamed — not two.');
    }

    /**
     * ⚠️ Upsert is duplicate handling with one more branch, not a second import engine: a row with
     * no match is still CREATED, exactly as it would be under `Skip` or `Fail`.
     */
    public function testANewRowUnderTheUpdatePolicyIsStillCreated(): void
    {
        $this->schema();

        $report = $this->import(
            "name,email\nBob,bob@example.test\n",
            onDuplicate: DuplicatePolicy::Update,
            resolver: new EmailDuplicateResolver($this->manager()),
        );

        self::assertSame(1, $report->imported());
        self::assertSame(0, $report->updated());
        self::assertSame(['Bob'], $this->storedNames());
    }

    /**
     * ⚠️ **Refused loudly, before a single row is read** — an `Update` policy with no resolver
     * would leave `$existing` `null` for every row, indistinguishable from every row being new. A
     * policy that silently behaves like `Skip` with nothing ever skipped is exactly the
     * half-feature `DuplicatePolicy`'s own history warns against shipping.
     */
    public function testTheUpdatePolicyRequiresAResolver(): void
    {
        $this->schema();

        $this->expectException(\InvalidArgumentException::class);

        $this->import("name,email\nAda,ada@example.test\n", onDuplicate: DuplicatePolicy::Update);
    }

    /**
     * ⚠️ A refused row is a report entry, not the end of the import. The record number is what the
     * operator uses to find the line, so it is asserted rather than merely present.
     */
    public function testARowTheMapperRefusesIsReportedAndTheRunContinues(): void
    {
        $this->schema();

        $report = $this->import("name,email\n,orphan@example.test\nBob,bob@example.test\n");

        self::assertSame(1, $report->imported());
        self::assertSame([['record' => 2, 'message' => 'test.import.error.missing_name']], $report->errors());
        self::assertSame(['Bob'], $this->storedNames());
    }

    /**
     * ⚠️ The integration proof for {@see ErrorSinkInterface}: the
     * runner threads it all the way from `run()`'s argument to `ImportReport::recordError()`, on a
     * REAL run against a real reader and a real mapper — not a unit test standing in for the wiring.
     */
    public function testAnErrorSinkReceivesEveryErrorAsTheRunHappens(): void
    {
        $this->schema();

        $handle = fopen('php://temp', 'w+');
        self::assertNotFalse($handle);
        $sink = new CsvErrorSink($handle, new CsvDialect(lineEnding: "\n"));

        $report = $this->import(
            "name,email\n,orphan@example.test\nBob,bob@example.test\n,another-orphan@example.test\n",
            errorSink: $sink,
        );

        rewind($handle);
        $written = stream_get_contents($handle);
        fclose($handle);

        self::assertSame(2, $report->errorCount());
        self::assertSame(
            "record,message\n"
            ."2,test.import.error.missing_name\n"
            ."4,test.import.error.missing_name\n",
            $written,
        );
    }

    /**
     * ⚠️ Validation before persist, so a bad value reaches the report instead of blowing up a
     * mid-batch flush — "validation by the validator, never by the database".
     */
    public function testAnInvalidValueIsReportedWithItsPropertyPath(): void
    {
        $this->schema();

        $report = $this->import("name,email\nAda,not-an-email\nBob,bob@example.test\n");

        self::assertSame(1, $report->imported());
        self::assertSame(2, $report->errors()[0]['record']);
        self::assertStringStartsWith('email: ', $report->errors()[0]['message']);
        self::assertSame(['Bob'], $this->storedNames());
    }

    public function testTheRowCapStopsTheRunAndSaysSo(): void
    {
        $this->schema();

        $report = $this->import("name,email\nA,a@example.test\nB,b@example.test\nC,c@example.test\n", maxRows: 2);

        self::assertSame(2, $report->imported());
        self::assertSame([['record' => 4, 'message' => 'dataflow.import.error.row_cap_reached']], $report->errors());
        self::assertSame(['A', 'B'], $this->storedNames());
    }

    public function testARowWhoseMappedColumnsAreAllBlankIsSkippedNotFailed(): void
    {
        $this->schema();

        $report = $this->import("name,email\nAda,ada@example.test\n,\n");

        self::assertSame(1, $report->imported());
        self::assertSame(1, $report->skipped());
        self::assertSame(0, $report->errorCount());
    }

    /**
     * The bounded-memory proof, and a MEASUREMENT rather than a call assertion: the size of the
     * unit of work after the run. Without the detach it grows with the file.
     */
    public function testTheUnitOfWorkStaysBoundedAcrossBatches(): void
    {
        $this->schema();

        $rows = "name,email\n";

        for ($i = 1; $i <= 200; ++$i) {
            $rows .= 'Client '.$i.',c'.$i."@example.test\n";
        }

        $report = $this->import($rows, batchSize: 25);

        self::assertSame(200, $report->imported());
        self::assertLessThan(
            25,
            $this->manager()->getUnitOfWork()->size(),
            'The runner is keeping every row it wrote in the identity map.',
        );
    }

    /**
     * ⚠️ Why `detach` and not `clear`: the account is the caller's object, held by the mapper across
     * every batch. `EntityManager::clear()` would detach it behind the mapper's back and the second
     * batch would fail with "A new entity was found through the relationship".
     */
    public function testTheCallersOwnEntitiesSurviveTheBatchBoundary(): void
    {
        $this->schema();

        $account = new Account();
        $account->label = 'Held by the caller';
        $this->manager()->persist($account);
        $this->manager()->flush();

        $report = $this->import(
            "name,email\nA,a@example.test\nB,b@example.test\nC,c@example.test\nD,d@example.test\n",
            batchSize: 2,
            mapper: new CustomerRowMapper($account),
        );

        self::assertSame(4, $report->imported());
        self::assertCount(4, $this->manager()->getRepository(Customer::class)->findAll());
    }

    /**
     * ⚠️ A batched import is not atomic by nature. With `atomic` on, a failure on the second batch
     * leaves the database as it was — and the exception carries the partial report, because once
     * `flush()` throws the entity manager is closed and there is nothing else to tell the operator.
     */
    public function testAFailingBatchRollsTheWholeRunBackAndReportsHowFarItGot(): void
    {
        $this->schema();

        try {
            $this->import(
                "name,email\nA,a@example.test\nB,b@example.test\nC,c@example.test\nD,d@example.test\n",
                batchSize: 2,
                mapper: $this->mapperFailingFromRecord(4),
            );

            self::fail('The second batch had to fail.');
        } catch (ImportFailedException $failure) {
            self::assertSame('dataflow.import.error.batch_failed', $failure->getMessage());
            self::assertTrue($failure->wasRolledBack());
            self::assertSame(5, $failure->record());
            self::assertSame(4, $failure->report()->imported(), 'Counted, and none of them survive.');
        }

        self::assertSame([], $this->freshManagerNames(), 'The first batch must be gone too.');
    }

    /**
     * The counterpart, so the flag is not merely accepted: with `atomic` off, the batches that made
     * it are still there. That is the trade the flag exists to expose.
     */
    public function testWithoutAtomicityTheCommittedBatchesRemain(): void
    {
        $this->schema();

        try {
            $this->import(
                "name,email\nA,a@example.test\nB,b@example.test\nC,c@example.test\nD,d@example.test\n",
                batchSize: 2,
                atomic: false,
                mapper: $this->mapperFailingFromRecord(4),
            );

            self::fail('The second batch had to fail.');
        } catch (ImportFailedException $failure) {
            self::assertFalse($failure->wasRolledBack());
        }

        self::assertSame(['A', 'B'], $this->freshManagerNames());
    }

    /**
     * A mapper that attaches a brand-new, un-persisted `Account` from a given record on. Doctrine
     * refuses it at flush time — "A new entity was found through the relationship" — which is a
     * realistic way for a batch to die: a relation the mapper resolved wrongly, discovered by the
     * database rather than by the validator.
     */
    private function mapperFailingFromRecord(int $fromRecord): RowMapperInterface
    {
        return new class($fromRecord) implements RowMapperInterface {
            private int $seen = 0;

            public function __construct(private readonly int $fromRecord)
            {
            }

            public function fields(): array
            {
                return ['name', 'email'];
            }

            public function map(array $row, ?object $existing = null): Customer
            {
                $customer = new Customer();
                $customer->name = $row['name'] ?? '';
                $customer->email = $row['email'] ?? null;

                if (++$this->seen >= $this->fromRecord - 1) {
                    $orphan = new Account();
                    $orphan->label = 'never persisted';
                    $customer->account = $orphan;
                }

                return $customer;
            }
        };
    }

    private function import(
        string $csv,
        bool $dryRun = false,
        int $batchSize = ImportSpec::DEFAULT_BATCH_SIZE,
        int $maxRows = ImportSpec::DEFAULT_MAX_ROWS,
        DuplicatePolicy $onDuplicate = DuplicatePolicy::Skip,
        bool $atomic = true,
        ?RowMapperInterface $mapper = null,
        ?EmailDuplicateResolver $resolver = null,
        ?ErrorSinkInterface $errorSink = null,
    ): ImportReport {
        $spec = new ImportSpec(
            $this->file($csv),
            [0 => 'name', 1 => 'email'],
            dryRun: $dryRun,
            batchSize: $batchSize,
            maxRows: $maxRows,
            onDuplicate: $onDuplicate,
            atomic: $atomic,
        );

        return $this->runner()->run($spec, $mapper ?? new CustomerRowMapper(), new CsvReader(), $resolver, $errorSink);
    }

    private function runner(): ImportRunner
    {
        $validator = $this->container()->get('validator');
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        return new ImportRunner($this->manager(), $validator);
    }

    private function schema(): void
    {
        $manager = $this->manager();
        new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
    }

    private function seedCustomer(string $email): void
    {
        $customer = new Customer();
        $customer->name = 'Ada';
        $customer->email = $email;

        $this->manager()->persist($customer);
        $this->manager()->flush();
    }

    /**
     * @return list<string>
     */
    private function storedNames(): array
    {
        $names = array_map(
            static fn (Customer $customer): string => $customer->name,
            $this->manager()->getRepository(Customer::class)->findAll(),
        );

        sort($names);

        return $names;
    }

    /**
     * Reads through a NEW entity manager: after a failed flush the previous one is closed, and its
     * identity map would answer from memory anyway — which is exactly what must not be trusted when
     * the question is "what is actually in the database".
     *
     * @return list<string>
     */
    private function freshManagerNames(): array
    {
        $connection = $this->manager()->getConnection();

        $names = [];

        foreach ($connection->fetchAllAssociative('SELECT name FROM Customer ORDER BY name') as $row) {
            $name = $row['name'] ?? null;
            // Asserted rather than cast: the column is NOT NULL text, so anything else here means
            // the query or the schema changed and the test should say so.
            self::assertIsString($name);

            $names[] = $name;
        }

        return $names;
    }

    private function manager(): EntityManagerInterface
    {
        if ($this->entityManager instanceof EntityManagerInterface) {
            return $this->entityManager;
        }

        $manager = $this->container()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $this->entityManager = $manager;
    }

    /**
     * ⚠️ An instance property, never a static one: `tearDown` shuts the kernel down, and a static
     * cache would hand the next test a container whose services are gone — a failure that reads
     * like a bug in the bundle.
     */
    private function container(): ContainerInterface
    {
        return $this->container ??= $this->boot(withOrm: true);
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-import-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }
}
