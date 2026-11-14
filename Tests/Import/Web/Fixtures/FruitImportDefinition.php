<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import\Web\Fixtures;

use Jul6Art\DataflowBundle\Exception\ImportFailedException;
use Jul6Art\DataflowBundle\Import\DuplicateResolverInterface;
use Jul6Art\DataflowBundle\Import\ImportReport;
use Jul6Art\DataflowBundle\Import\Web\AbstractImportDefinition;
use Jul6Art\DataflowBundle\Import\Web\ImportAccess;
use Jul6Art\DataflowBundle\Import\Web\ImportContext;
use Jul6Art\DataflowBundle\Import\Web\ImportLink;
use Jul6Art\DataflowBundle\Import\Web\ImportResult;
use Jul6Art\DataflowBundle\Import\Web\ImportRoutes;
use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use Jul6Art\DataflowBundle\Io\Reader\CsvReader;
use Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface;
use Jul6Art\DataflowBundle\Io\TabularWriterInterface;
use Jul6Art\DataflowBundle\Io\Writer\CsvWriter;

/**
 * An import of fruits, without a database: the rows it receives are kept, and a scenario can make the next run
 * fail or be refused.
 */
final class FruitImportDefinition extends AbstractImportDefinition
{
    /** @var list<array<string, string>> */
    public array $imported = [];

    /** @var list<ImportContext> */
    public array $contexts = [];

    public bool $failNext = false;

    public ?string $refuseNext = null;

    /**
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly string $key = 'fruit',
        private readonly bool $preview = false,
        private readonly ?int $maxBytes = null,
        private readonly ?ImportRoutes $routes = null,
        private readonly string $prefix = 'fruit.import',
        private readonly array $templates = [],
    ) {
    }

    #[\Override]
    public function fields(): array
    {
        return ['name', 'colour', 'origin'];
    }

    #[\Override]
    public function requiredFields(): array
    {
        return ['name'];
    }

    #[\Override]
    public function reader(): TabularReaderInterface
    {
        return new CsvReader(CsvDialect::excelFr());
    }

    #[\Override]
    public function writer(): TabularWriterInterface
    {
        return new CsvWriter(CsvDialect::excelFr());
    }

    #[\Override]
    public function import(string $filePath, array $mapping, ImportContext $context): ImportResult
    {
        $this->contexts[] = $context;

        if (null !== $this->refuseNext) {
            throw new \DomainException($this->refuseNext);
        }

        $report = new ImportReport();
        $first = true;

        foreach ($this->reader()->read($filePath) as $cells) {
            if ($first) {
                $first = false;

                continue;
            }

            $row = [];

            foreach ($mapping as $position => $field) {
                $row[$field] = (string) ($cells[$position] ?? '');
            }

            $this->imported[] = $row;
            $report->recordImported();
        }

        if ($this->failNext) {
            throw new ImportFailedException($report, 2, true, new \RuntimeException('the batch'));
        }

        return new ImportResult($report, ['fruit_note' => 'ripe']);
    }

    #[\Override]
    public function access(ImportContext $context): array
    {
        return [new ImportAccess('ROLE_IMPORTER')];
    }

    #[\Override]
    public function translationDomain(): string
    {
        return 'fruit';
    }

    #[\Override]
    public function translationPrefix(): string
    {
        return $this->prefix;
    }

    #[\Override]
    public function templates(): array
    {
        return $this->templates;
    }

    #[\Override]
    public function routes(): ImportRoutes
    {
        return $this->routes ?? new ImportRoutes('fruit_upload', 'fruit_mapping', 'fruit_run', 'fruit_template');
    }

    #[\Override]
    public function returnTo(ImportContext $context): ImportLink
    {
        return new ImportLink('fruit_list');
    }

    #[\Override]
    public function preview(): bool
    {
        return $this->preview;
    }

    #[\Override]
    public function duplicateResolver(ImportContext $context): DuplicateResolverInterface
    {
        return new class implements DuplicateResolverInterface {
            #[\Override]
            public function keyOf(array $row): ?string
            {
                return $row['name'] ?? null;
            }

            #[\Override]
            public function findExisting(array $rows): array
            {
                return array_map(static fn (): object => new \stdClass(), array_filter($rows, static fn (array $row): bool => 'apple' === ($row['name'] ?? null)));
            }
        };
    }

    #[\Override]
    public function maxBytes(): ?int
    {
        return $this->maxBytes;
    }

    public function key(): string
    {
        return $this->key;
    }
}
