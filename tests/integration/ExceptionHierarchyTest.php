<?php

declare(strict_types=1);

namespace Sorocharge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sorocharge\InternalErrorException;
use Sorocharge\SorochargeException;

final class ExceptionHierarchyTest extends TestCase
{
    /**
     * Every sorocharge-core SorochargeError variant, paired with the exception
     * class it must surface as. Kept in lockstep with the Rust enum: a variant
     * added in core fails to compile in src/errors.rs until it is mapped, and
     * then belongs here too.
     *
     * @return array<string, array{string, class-string<SorochargeException>}>
     */
    public static function coreErrorVariants(): array
    {
        $variants = [
            'InvalidAddress', 'UnsupportedCredentialType', 'EmptyDelegateSigners',
            'DuplicateDelegateSigner', 'ExpiredEntry', 'UnexpectedInvocationShape',
            'AssetMismatch', 'PayerMismatch', 'AmountMismatch', 'RecipientMismatch',
            'InvalidSignature', 'NoMatchingCredentialNode', 'SigningFailed', 'XdrEncodingFailed',
        ];
        $cases = [];
        foreach ($variants as $variant) {
            $cases[$variant] = [$variant, 'Sorocharge\\' . $variant . 'Exception'];
        }
        return $cases;
    }

    #[DataProvider('coreErrorVariants')]
    public function testEachCoreErrorVariantThrowsItsOwnSubclass(string $variant, string $class): void
    {
        self::assertTrue(class_exists($class), "$class is not registered");
        self::assertTrue(is_subclass_of($class, SorochargeException::class));

        try {
            \Sorocharge\Internal\throw_core_error($variant);
            self::fail("$variant did not throw");
        } catch (SorochargeException $e) {
            self::assertSame($class, $e::class);
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testSorochargeExceptionIsCatchableAsException(): void
    {
        self::assertTrue(is_subclass_of(SorochargeException::class, \Exception::class));
    }

    public function testRustPanicSurfacesAsInternalErrorExceptionNotACrash(): void
    {
        try {
            \Sorocharge\Internal\trigger_panic();
            self::fail('trigger_panic returned normally');
        } catch (InternalErrorException $e) {
            self::assertInstanceOf(SorochargeException::class, $e);
            self::assertStringContainsString('Rust panic', $e->getMessage());
            self::assertStringContainsString('deliberate panic', $e->getMessage());
        }

        // The process survived the panic and the extension still works.
        $this->expectException(\Sorocharge\ExpiredEntryException::class);
        \Sorocharge\Internal\throw_core_error('ExpiredEntry');
    }

    public function testBindingLayerRejectionIsSplInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Sorocharge\Internal\throw_core_error('NotAVariant');
    }
}
