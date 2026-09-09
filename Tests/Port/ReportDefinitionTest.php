<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Port;

use Jul6Art\DataflowBundle\Port\ReportDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportDefinition::class)]
final class ReportDefinitionTest extends TestCase
{
    /**
     * ⚠️ The payload is kept VERBATIM, including a key the interpreter would drop today. That is
     * the point: a saved report has to survive a column being renamed and a permission being
     * narrowed, and re-interpretation on load is what makes the authorisation decision current
     * rather than frozen at save time.
     */
    public function testThePayloadIsStoredExactlyAsSubmitted(): void
    {
        $payload = [
            'entity' => 'App\\Entity\\Invoice',
            'columns' => [['path' => 'number'], ['path' => 'customer.email']],
            'a key the interpreter will drop' => 'kept anyway',
        ];

        self::assertSame($payload, new ReportDefinition('Monthly', $payload)->payload);
    }

    public function testANewDefinitionHasNoIdentifier(): void
    {
        $definition = new ReportDefinition('Monthly', []);

        self::assertNull($definition->id);
        self::assertNull($definition->ownerId);
        self::assertFalse($definition->shared, 'Private is the safe default.');
    }

    public function testStoringOneReturnsACopyCarryingItsIdentifier(): void
    {
        $definition = new ReportDefinition('Monthly', ['entity' => 'X'], ownerId: 7, shared: true);

        $stored = $definition->withId('abc');

        self::assertSame('abc', $stored->id);
        self::assertSame('Monthly', $stored->name);
        self::assertSame(['entity' => 'X'], $stored->payload);
        self::assertSame(7, $stored->ownerId);
        self::assertTrue($stored->shared);
        self::assertNull($definition->id, 'The original is untouched.');
    }
}
