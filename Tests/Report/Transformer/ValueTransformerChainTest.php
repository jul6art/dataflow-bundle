<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Report\Transformer;

use Jul6Art\DataflowBundle\Report\Transformer\BoolValueTransformer;
use Jul6Art\DataflowBundle\Report\Transformer\DateTimeValueTransformer;
use Jul6Art\DataflowBundle\Report\Transformer\EnumValueTransformer;
use Jul6Art\DataflowBundle\Report\Transformer\ValueTransformerChain;
use Jul6Art\DataflowBundle\Report\Transformer\ValueTransformerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ChainTestStatus: string
{
    case Paid = 'paid';
}

/**
 * The transformer chain and its three transformers.
 *
 * ⚠️ The case worth understanding is {@see self::testAnUnhandledObjectBecomesAnEmptyCell()}. The
 * chain lets an unrecognised value through on purpose — scalars are most of a report and must not
 * need a transformer — so the narrowing happens in `transformRow()`. Without it an entity reaches
 * the writer and a customer's spreadsheet reads `Object(App\Entity\Foo)`.
 */
#[CoversClass(ValueTransformerChain::class)]
#[CoversClass(DateTimeValueTransformer::class)]
#[CoversClass(EnumValueTransformer::class)]
#[CoversClass(BoolValueTransformer::class)]
final class ValueTransformerChainTest extends TestCase
{
    public function testADateWithoutATimeRendersAsADate(): void
    {
        self::assertSame(
            '2026-05-27',
            $this->chain()->transform(new \DateTimeImmutable('2026-05-27 00:00:00')),
        );
    }

    public function testADateWithATimeRendersAsIso8601(): void
    {
        $out = $this->chain()->transform(new \DateTimeImmutable('2026-05-27 14:30:00'));

        self::assertIsString($out);
        self::assertStringContainsString('2026-05-27T14:30:00', $out);
    }

    public function testABackedEnumRendersAsItsBackingValue(): void
    {
        self::assertSame('paid', $this->chain()->transform(ChainTestStatus::Paid));
    }

    /**
     * ⚠️ The point of D-7: the words come from the catalogue, so the bundle does not impose one
     * language on every consumer.
     */
    public function testABooleanIsTranslatedRatherThanHardcoded(): void
    {
        self::assertSame('Oui', $this->chain()->transform(true));
        self::assertSame('Non', $this->chain()->transform(false));
    }

    public function testTheDomainIsTheBundlesOwnAndNotMessages(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(BoolValueTransformer::KEY_TRUE, [], 'dataflow')
            ->willReturn('Yes');

        self::assertSame('Yes', new BoolValueTransformer($translator)->transform(true));
    }

    public function testAScalarPassesThroughUntouched(): void
    {
        $chain = $this->chain();

        self::assertSame('Dupont', $chain->transform('Dupont'));
        self::assertSame(42, $chain->transform(42));
        self::assertNull($chain->transform(null));
    }

    /**
     * ⚠️ First match wins, in registration order. Stated as a contract because a consumer adding a
     * transformer for a type an earlier one already claims will never see it run, and nothing
     * reports that.
     */
    public function testTheFirstTransformerThatMatchesWins(): void
    {
        $shouted = new class implements ValueTransformerInterface {
            public function supports(mixed $value): bool
            {
                return \is_bool($value);
            }

            public function transform(mixed $value): string
            {
                return 'FIRST';
            }
        };

        $chain = new ValueTransformerChain([$shouted, new BoolValueTransformer($this->translator())]);

        self::assertSame('FIRST', $chain->transform(true));
    }

    /**
     * ⚠️ The narrowing that makes the chain safe: an object nothing handled must not reach a
     * writer. A blank cell is bad; `Object(App\Entity\Foo)` in a customer's spreadsheet is worse.
     */
    public function testAnUnhandledObjectBecomesAnEmptyCell(): void
    {
        $row = $this->chain()->transformRow(['a' => new \stdClass()]);

        self::assertSame(['a' => ''], $row);
    }

    public function testAStringableObjectRendersItsString(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'rendered';
            }
        };

        self::assertSame(['a' => 'rendered'], $this->chain()->transformRow(['a' => $stringable]));
    }

    /**
     * ⚠️ Keys survive, so a row indexed by column path stays indexed by column path.
     */
    public function testAWholeRowKeepsItsKeysAndOrder(): void
    {
        $row = $this->chain()->transformRow([
            'name' => 'Dupont',
            'paid' => true,
            'on' => new \DateTimeImmutable('2026-05-27 00:00:00'),
            'status' => ChainTestStatus::Paid,
            'nothing' => null,
        ]);

        self::assertSame(
            ['name' => 'Dupont', 'paid' => 'Oui', 'on' => '2026-05-27', 'status' => 'paid', 'nothing' => null],
            $row,
        );
    }

    private function chain(): ValueTransformerChain
    {
        return new ValueTransformerChain([
            new DateTimeValueTransformer(),
            new EnumValueTransformer(),
            new BoolValueTransformer($this->translator()),
        ]);
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => BoolValueTransformer::KEY_TRUE === $id ? 'Oui' : 'Non',
        );

        return $translator;
    }
}
