<?php

declare(strict_types=1);

namespace Be\Framework;

/**
 * Interface for resolving constructor arguments for metamorphosis transformations
 *
 * Resolves arguments needed for the destination class constructor from the current object,
 * following Be Framework's metamorphic programming paradigm.
 *
 * @psalm-import-type ConstructorArguments from Types
 * @psalm-import-type QualifiedClassName from Types
 */
interface BecomingArgumentsInterface
{
    /**
     * Resolve constructor arguments for the becoming (destination) class
     *
     * @param object $current  The current object being transformed
     * @param string $becoming The fully qualified class name of the destination class
     *
     * @return ConstructorArguments Associative array of constructor arguments [paramName => value]
     * @phpstan-return array<string, mixed>
     */
    public function be(object $current, string $becoming): array;

    /**
     * Begin a metamorphosis chain (activates the semantic validator's per-chain cache).
     */
    public function beginChain(): void;

    /**
     * End a metamorphosis chain (discards the per-chain cache; must run even on failure).
     */
    public function endChain(): void;
}
