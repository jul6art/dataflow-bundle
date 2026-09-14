<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Security;

use Jul6Art\DataflowBundle\Security\ReportBuilderCsrfChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\Exception\TokenNotFoundException;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

#[CoversClass(ReportBuilderCsrfChecker::class)]
final class ReportBuilderCsrfCheckerTest extends TestCase
{
    /**
     * ⚠️ Without `symfony/security-csrf` CONFIGURED — no manager bound — a save must still work:
     * the partial could not have minted a token to check against either, and refusing every save
     * would break the feature over a package the application deliberately does not have.
     */
    public function testWithNoManagerBoundEveryRequestIsValid(): void
    {
        $checker = new ReportBuilderCsrfChecker(null);

        self::assertTrue($checker->isValid(new Request()));
    }

    public function testARealTokenMintedForTheSameIdIsValid(): void
    {
        $manager = new CsrfTokenManager(new UriSafeTokenGenerator(), $this->storage());
        $checker = new ReportBuilderCsrfChecker($manager, 'my_token_id');

        $token = $manager->getToken('my_token_id')->getValue();
        $request = new Request(request: ['_dataflow_csrf_token' => $token]);

        self::assertTrue($checker->isValid($request));
    }

    public function testAWrongValueIsRefused(): void
    {
        $manager = new CsrfTokenManager(new UriSafeTokenGenerator(), $this->storage());
        $checker = new ReportBuilderCsrfChecker($manager, 'my_token_id');
        $manager->getToken('my_token_id');

        $request = new Request(request: ['_dataflow_csrf_token' => 'not-the-real-token']);

        self::assertFalse($checker->isValid($request));
    }

    public function testAMissingFieldIsRefused(): void
    {
        $manager = new CsrfTokenManager(new UriSafeTokenGenerator(), $this->storage());
        $checker = new ReportBuilderCsrfChecker($manager, 'my_token_id');
        $manager->getToken('my_token_id');

        self::assertFalse($checker->isValid(new Request()));
    }

    /**
     * ⚠️ A token minted for ANOTHER id — the report builder checking a value meant for some other
     * feature entirely — must not validate. Proves the id is actually threaded through, not just
     * accepted as an unused constructor argument.
     */
    public function testATokenMintedForADifferentIdIsRefused(): void
    {
        $manager = new CsrfTokenManager(new UriSafeTokenGenerator(), $this->storage());
        $checker = new ReportBuilderCsrfChecker($manager, 'my_token_id');

        $otherToken = $manager->getToken('some_other_feature')->getValue();
        $request = new Request(request: ['_dataflow_csrf_token' => $otherToken]);

        self::assertFalse($checker->isValid($request));
    }

    /**
     * A minimal, array-backed store — this bundle's own tests do not mock what a project would
     * never mock either, and `symfony/security-csrf` ships no in-memory implementation of its own.
     */
    private function storage(): TokenStorageInterface
    {
        return new class implements TokenStorageInterface {
            /** @var array<string, string> */
            private array $tokens = [];

            #[\Override]
            public function getToken(string $tokenId): string
            {
                if (!isset($this->tokens[$tokenId])) {
                    throw new TokenNotFoundException();
                }

                return $this->tokens[$tokenId];
            }

            #[\Override]
            public function setToken(string $tokenId, #[\SensitiveParameter] string $token): void
            {
                $this->tokens[$tokenId] = $token;
            }

            #[\Override]
            public function hasToken(string $tokenId): bool
            {
                return isset($this->tokens[$tokenId]);
            }

            #[\Override]
            public function removeToken(string $tokenId): ?string
            {
                $token = $this->tokens[$tokenId] ?? null;
                unset($this->tokens[$tokenId]);

                return $token;
            }
        };
    }
}
