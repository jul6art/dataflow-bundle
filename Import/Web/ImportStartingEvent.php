<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once the mapping is accepted, before the run writes anything. A listener that sets a response replaces
 * the run: a plan whose quota the file exceeds sends the operator to its upgrade page instead.
 */
final class ImportStartingEvent extends Event
{
    private ?Response $response = null;

    /**
     * @param array<int, string> $mapping
     */
    public function __construct(
        public readonly string $key,
        public readonly ImportDefinitionInterface $definition,
        public readonly ImportContext $context,
        public readonly string $filePath,
        public readonly array $mapping,
    ) {
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
        $this->stopPropagation();
    }

    public function response(): ?Response
    {
        return $this->response;
    }
}
