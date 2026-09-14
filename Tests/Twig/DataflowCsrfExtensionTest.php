<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Twig;

use Jul6Art\DataflowBundle\Twig\DataflowCsrfExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\TwigFunction;

/**
 * ⚠️ `DataflowCsrfExtension extends AbstractExtension` is what makes it a real
 * `Twig\Extension\ExtensionInterface` — PHPStan enforces that at the type level on every run, which
 * is a stronger, permanent guarantee than a test asserting it once at runtime would be. What a
 * static check cannot see is BEHAVIOUR, which is what the tests below cover instead.
 */
#[CoversClass(DataflowCsrfExtension::class)]
final class DataflowCsrfExtensionTest extends TestCase
{
    public function testItRegistersExactlyOneFunctionNamedDataflowCsrfToken(): void
    {
        $functions = new DataflowCsrfExtension(null)->getFunctions();

        self::assertCount(1, $functions);
        self::assertInstanceOf(TwigFunction::class, $functions[0]);
        self::assertSame('dataflow_csrf_token', $functions[0]->getName());
    }

    /**
     * ⚠️ Without `symfony/security-csrf` CONFIGURED, the shipped partial must still render — an
     * empty value is what the Stimulus controller already treats as "nothing to send".
     */
    public function testWithNoManagerBoundTheTokenIsEmpty(): void
    {
        self::assertSame('', new DataflowCsrfExtension(null)->csrfToken());
    }

    public function testWithAManagerBoundTheTokenIsMinted(): void
    {
        $manager = new class implements CsrfTokenManagerInterface {
            /** @var list<string> */
            public array $requestedIds = [];

            #[\Override]
            public function getToken(string $tokenId): CsrfToken
            {
                $this->requestedIds[] = $tokenId;

                return new CsrfToken($tokenId, 'the-real-value');
            }

            #[\Override]
            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            #[\Override]
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            #[\Override]
            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };

        $extension = new DataflowCsrfExtension($manager, 'my_token_id');

        self::assertSame('the-real-value', $extension->csrfToken());
        self::assertSame(['my_token_id'], $manager->requestedIds);
    }
}
