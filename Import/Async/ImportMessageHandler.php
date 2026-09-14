<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Async;

use Jul6Art\DataflowBundle\Exception\ImportFailedException;
use Jul6Art\DataflowBundle\Exception\UnreadableFileException;
use Jul6Art\DataflowBundle\Import\ImportRunner;
use Jul6Art\DataflowBundle\Import\Spec\ImportSpec;
use Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface;

/**
 * Turns an {@see ImportMessage} into a real {@see ImportRunner} call, off the request thread.
 *
 * ⚠️ **No `#[AsMessageHandler]` attribute — the `messenger.message_handler` tag is written by hand
 * in `Resources/config/services.yaml` instead.** That attribute is processed by Messenger's OWN
 * autoconfiguration pass, and `services.yaml`'s `_defaults` turn autoconfiguration off for every
 * service this bundle registers itself, on purpose (see that file). An attribute here would compile
 * cleanly, resolve cleanly, and never once be invoked — found by a wiring test that dispatched a
 * real message through a real bus instead of only asserting the service exists.
 *
 * ⚠️ **Removed by {@see \Jul6Art\DataflowBundle\DependencyInjection\Compiler\AsyncImportPass} when
 * `symfony/messenger` is not configured**, and by
 * {@see \Jul6Art\DataflowBundle\DependencyInjection\Compiler\OptionalContractPass} when there is no
 * `EntityManagerInterface` — `ImportRunner` needs one, and a dangling reference to a removed service
 * is a compile-time failure, not a degraded feature.
 *
 * ⚠️ **A failure is recorded AND rethrown.** {@see ImportProgressStoreInterface} is how a screen
 * finds out; rethrowing is how Messenger's own retry and failure-transport machinery still applies
 * exactly as it does for any other message. Swallowing the exception here would silently disable
 * whatever retry policy the application configured for this bus.
 *
 * ⚠️ **`$mappers` is nullable, and unlike `ImportProgressStoreInterface` it is not defaulted to a
 * null object.** This service is registered — and autowired — for EVERY application that has
 * `symfony/messenger` configured, whether or not it uses this bundle's async import: a hard,
 * unbindable-by-default argument would fail THEIR container over a port they never meant to need,
 * the same hazard `OptionalContractPass` documents. A missing binding therefore fails at the moment
 * a message is actually handled, not at boot — loud for the application that dispatches
 * {@see ImportMessage} without wiring {@see ImportMapperFactoryInterface}, silent for every other.
 */
final readonly class ImportMessageHandler
{
    /**
     * @param iterable<TabularReaderInterface> $readers
     */
    public function __construct(
        private ImportRunner $runner,
        private ?ImportMapperFactoryInterface $mappers,
        private ImportProgressStoreInterface $progress,
        private iterable $readers,
    ) {
    }

    public function __invoke(ImportMessage $message): void
    {
        if (null === $this->mappers) {
            throw new \LogicException(\sprintf(
                'Cannot handle %s: no %s is bound. Async import needs one to turn "%s" back into a real mapper.',
                ImportMessage::class,
                ImportMapperFactoryInterface::class,
                $message->mapperId,
            ));
        }

        $reader = $this->resolveReader($message->filePath);
        $mapper = $this->mappers->mapper($message->mapperId, $message->context);
        $resolver = null !== $message->resolverId
            ? $this->mappers->resolver($message->resolverId, $message->context)
            : null;

        $spec = new ImportSpec(
            $message->filePath,
            $message->mapping,
            batchSize: $message->batchSize,
            maxRows: $message->maxRows,
            onDuplicate: $message->onDuplicate,
            atomic: $message->atomic,
        );

        $this->progress->starting($message->importId);

        try {
            $report = $this->runner->run($spec, $mapper, $reader, $resolver);
        } catch (ImportFailedException $failure) {
            $this->progress->failed($message->importId, $failure);

            throw $failure;
        }

        $this->progress->finished($message->importId, $report);
    }

    /**
     * The same by-content resolution a controller uses (lot 2.1) — never the extension, and never
     * named by the message, so a message and its file cannot quietly disagree about what it is.
     */
    private function resolveReader(string $filePath): TabularReaderInterface
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($filePath)) {
                return $reader;
            }
        }

        throw UnreadableFileException::cannotOpen($filePath);
    }
}
