<?php

declare(strict_types=1);

namespace Be\Framework\SemanticVariable;

use Be\Framework\Types;
use Override;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Null object pattern implementation for semantic validation
 *
 * Performs no validation and always returns no errors.
 * Used when semantic validation is not needed or disabled.
 *
 * @psalm-import-type ParameterAttributes from Types
 */
final class NullValidator implements SemanticValidatorInterface
{
    /**
     * Always returns no errors for method arguments
     */
    #[Override]
    public function validateArgs(ReflectionMethod $method, array $args): Errors
    {
        return new NullErrors();
    }

    /**
     * Always returns no errors for single parameter
     *
     * @codeCoverageIgnore
     */
    #[Override]
    public function validateArg(ReflectionParameter $parameter, mixed $value): Errors
    {
        return new NullErrors();
    }

    /**
     * Legacy method: Always returns no errors (for backward compatibility)
     */
    public function validate(string $variableName, mixed ...$args): Errors
    {
        return new NullErrors();
    }

    /**
     * Legacy method: Always returns no errors regardless of attributes
     *
     * @param ParameterAttributes $parameterAttributes
     * @phpstan-param array<string> $parameterAttributes
     */
    public function validateWithAttributes(string $variableName, array $parameterAttributes = [], mixed ...$args): Errors
    {
        return new NullErrors();
    }

    /**
     * Legacy method: Never throws exceptions - validation always passes
     */
    public function validateAndThrow(string $variableName, mixed ...$args): void
    {
        // No-op: null validator never throws
    }

    /**
     * Legacy method: Always returns no errors for object validation
     */
    public function validateObject(object $object): Errors
    {
        return new NullErrors();
    }

    /**
     * No-op: the null validator has no cache to activate.
     */
    #[Override]
    public function beginChain(): void
    {
        // No-op
    }

    /**
     * No-op: the null validator has no cache to discard.
     */
    #[Override]
    public function endChain(): void
    {
        // No-op
    }
}
