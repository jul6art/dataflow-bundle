<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Jul6Art\DataflowBundle\Exception\ImportFailedException;
use Jul6Art\DataflowBundle\Import\Async\ImportMapperFactoryInterface;
use Jul6Art\DataflowBundle\Import\Async\ImportMessage;
use Jul6Art\DataflowBundle\Import\Async\ImportMessageHandler;
use Jul6Art\DataflowBundle\Import\Async\ImportProgressStoreInterface;
use Jul6Art\DataflowBundle\Import\DuplicateResolverInterface;
use Jul6Art\DataflowBundle\Import\ImportReport;
use Jul6Art\DataflowBundle\Import\ImportRunner;
use Jul6Art\DataflowBundle\Import\RowMapperInterface;
use Jul6Art\DataflowBundle\Io\Reader\CsvReader;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Customer;
use Jul6Art\DataflowBundle\Tests\Fixtures\Import\CustomerRowMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The handler against a real entity manager — the same reasoning as {@see ImportRunnerTest}: what
 * matters here is real Doctrine behaviour (a batch actually flushing), not that a mock's methods
 * were called with the right arguments.
 */
#[CoversClass(ImportMessageHandler::class)]
final class ImportMessageHandlerTest extends AbstractFunctionalTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    /** @var list<string> */
    private array $files = [];

    #[\Override]
    protected function tearDown(): void
    {
        $this->entityManager = null;

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];

        parent::tearDown();
    }

    public function testAMessageIsTurnedIntoARealImportAndReportedAsFinished(): void
    {
        $this->schema();

        $progress = $this->progressStore();
        $handler = $this->handler(new MapperFactory(), $progress);

        $message = new ImportMessage(
            importId: 'import-1',
            filePath: $this->file("name,email\nAda,ada@example.test\n"),
            mapping: [0 => 'name', 1 => 'email'],
            mapperId: 'customer',
        );

        $handler($message);

        self::assertSame(['starting:import-1'], \array_slice($progress->calls, 0, 1));
        self::assertStringStartsWith('finished:import-1:', $progress->calls[1]);
        self::assertSame(['Ada'], $this->storedNames());
    }

    /**
     * ⚠️ The property `ImportMapperFactoryInterface` exists for: the message crosses a queue as
     * plain scalars, and this factory is what turns them back into a real, correctly-scoped mapper —
     * proven here by actually reading `context` to build one.
     */
    public function testTheContextReachesTheMapperFactory(): void
    {
        $this->schema();

        $factory = new MapperFactory();
        $handler = $this->handler($factory, $this->progressStore());

        $message = new ImportMessage(
            importId: 'import-2',
            filePath: $this->file("name,email\nBob,bob@example.test\n"),
            mapping: [0 => 'name', 1 => 'email'],
            mapperId: 'customer',
            context: ['tag' => 'from-worker'],
        );

        $handler($message);

        self::assertSame(['from-worker'], $factory->seenContexts);
    }

    /**
     * ⚠️ Not defaulted to a no-op, on purpose — see the handler's own docblock. This is what proves
     * the failure is loud at the moment a message is actually handled, not silent.
     */
    public function testWithNoMapperFactoryBoundHandlingRefusesLoudly(): void
    {
        $this->schema();

        $handler = $this->handler(null, $this->progressStore());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/customer/');

        $handler(new ImportMessage(
            importId: 'import-3',
            filePath: $this->file("name,email\nAda,ada@example.test\n"),
            mapping: [0 => 'name', 1 => 'email'],
            mapperId: 'customer',
        ));
    }

    /**
     * ⚠️ Recorded AND rethrown: {@see ImportProgressStoreInterface} is how a screen finds out,
     * rethrowing is how Messenger's own retry policy still applies. Swallowing here would silently
     * disable whatever the application configured for this bus.
     */
    public function testAFailureIsRecordedAndRethrown(): void
    {
        // Deliberately no schema(): the table does not exist, so the flush inside ImportRunner
        // fails and ImportFailedException reaches the handler exactly as it would in production.
        $progress = $this->progressStore();
        $handler = $this->handler(new MapperFactory(), $progress);

        $message = new ImportMessage(
            importId: 'import-4',
            filePath: $this->file("name,email\nAda,ada@example.test\n"),
            mapping: [0 => 'name', 1 => 'email'],
            mapperId: 'customer',
        );

        try {
            $handler($message);
            self::fail('The flush against a missing table must fail.');
        } catch (ImportFailedException) {
            // expected
        }

        self::assertSame(['starting:import-4'], \array_slice($progress->calls, 0, 1));
        self::assertStringStartsWith('failed:import-4:', $progress->calls[1]);
    }

    private function handler(?ImportMapperFactoryInterface $mappers, ImportProgressStoreInterface $progress): ImportMessageHandler
    {
        $validator = $this->container()->get('validator');
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        return new ImportMessageHandler(
            new ImportRunner($this->manager(), $validator),
            $mappers,
            $progress,
            [new CsvReader()],
        );
    }

    private function progressStore(): RecordingProgressStore
    {
        return new RecordingProgressStore();
    }

    private function schema(): void
    {
        $manager = $this->manager();
        new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
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

    private function manager(): EntityManagerInterface
    {
        if ($this->entityManager instanceof EntityManagerInterface) {
            return $this->entityManager;
        }

        $manager = $this->container()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $this->entityManager = $manager;
    }

    private function container(): ContainerInterface
    {
        return $this->boot(withOrm: true);
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-async-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }
}

/**
 * @internal a mapper factory that actually builds a working mapper — the only way to prove the
 *           handler's happy path lands a row, not just that it called the right method
 */
final class MapperFactory implements ImportMapperFactoryInterface
{
    /** @var list<mixed> */
    public array $seenContexts = [];

    #[\Override]
    public function mapper(string $mapperId, array $context): RowMapperInterface
    {
        $this->seenContexts[] = $context['tag'] ?? null;

        return new CustomerRowMapper();
    }

    #[\Override]
    public function resolver(?string $resolverId, array $context): ?DuplicateResolverInterface
    {
        return null;
    }
}

/**
 * @internal
 */
final class RecordingProgressStore implements ImportProgressStoreInterface
{
    /** @var list<string> */
    public array $calls = [];

    #[\Override]
    public function starting(string $importId): void
    {
        $this->calls[] = 'starting:'.$importId;
    }

    #[\Override]
    public function finished(string $importId, ImportReport $report): void
    {
        $this->calls[] = 'finished:'.$importId.':'.$report->imported();
    }

    #[\Override]
    public function failed(string $importId, \Throwable $failure): void
    {
        $this->calls[] = 'failed:'.$importId.':'.$failure::class;
    }
}
