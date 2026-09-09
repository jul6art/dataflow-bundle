<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\DependencyInjection;

use Jul6Art\DataflowBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Symfony\Component\Config\Definition\Processor;

/**
 * The configuration tree is public API: an application writes against it and a rename breaks
 * someone's deployment. Assert the **whole** processed shape rather than one key at a time — that
 * is what makes an accidental addition or a changed default visible in a diff.
 */
#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    /**
     * The whole processed shape, so an accidental addition or a moved default shows up in a diff.
     *
     * ⚠️ Every ceiling here is the reference application's CURRENT value, and that is what makes
     * adopting the bundle a deletion rather than a behaviour change. Moving one of these numbers is
     * a decision, not a tidy-up — this test is where it has to be argued.
     *
     * @var array<string, mixed>
     */
    private const array DEFAULTS = [
        'enabled' => true,
        'translation_domain' => 'dataflow',
        'stimulus_identifier' => 'dataflow--report-builder',
        'limits' => [
            'report_rows' => 1000,
            'export_rows' => 50000,
            'exports_per_hour' => 30,
            'export_rows_per_hour' => 10000,
            'import_rows' => 10000,
            'imports_per_hour' => 5,
            'field_max_depth' => 2,
        ],
    ];

    public function testItsRootNodeIsTheBundleAlias(): void
    {
        self::assertSame('dataflow', new Configuration()->getConfigTreeBuilder()->buildTree()->getName());
    }

    public function testItAppliesItsDefaults(): void
    {
        self::assertSame(self::DEFAULTS, $this->process([]));
    }

    public function testLaterConfigsOverrideEarlierOnes(): void
    {
        self::assertSame(self::DEFAULTS, $this->process([['enabled' => false], ['enabled' => true]]));
    }

    /**
     * ⚠️ One configured ceiling must not reset the others. `addDefaultsIfNotSet()` is what makes
     * that true, and omitting it is a silent, total change of behaviour: every unmentioned limit
     * would arrive as null and the extension would refuse to compile.
     */
    public function testConfiguringOneCeilingLeavesTheOthersAtTheirDefault(): void
    {
        $limits = $this->limits([['limits' => ['export_rows' => 250]]]);

        self::assertSame(1000, $limits['report_rows'] ?? null);
        self::assertSame(250, $limits['export_rows'] ?? null);
    }

    /**
     * ⚠️ Zero is allowed for the depth and refused for a ceiling. A depth of zero offers the root
     * entity only, which is a real choice; a row cap of zero is a report engine that returns
     * nothing, which nobody configures on purpose.
     */
    public function testACeilingOfZeroIsRefusedAndADepthOfZeroIsNot(): void
    {
        self::assertSame(0, $this->limits([['limits' => ['field_max_depth' => 0]]])['field_max_depth'] ?? null);

        $this->expectException(InvalidConfigurationException::class);

        $this->process([['limits' => ['export_rows' => 0]]]);
    }

    /**
     * A `booleanNode` refuses anything but a boolean, which is what you want — and the reason an
     * env var cannot gate service registration.
     */
    #[DataProvider('nonBooleanValues')]
    public function testItRejectsNonBooleanValues(mixed $value): void
    {
        $this->expectException(InvalidTypeException::class);

        $this->process([['enabled' => $value]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonBooleanValues(): iterable
    {
        yield 'string' => ['yes'];
        yield 'int' => [0];
        yield 'array' => [[]];
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     *
     * @return array<array-key, mixed>
     */
    private function limits(array $configs): array
    {
        $limits = $this->process($configs)['limits'] ?? null;
        self::assertIsArray($limits);

        return $limits;
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     *
     * @return array<array-key, mixed>
     */
    private function process(array $configs): array
    {
        return new Processor()->processConfiguration(new Configuration(), $configs);
    }
}
