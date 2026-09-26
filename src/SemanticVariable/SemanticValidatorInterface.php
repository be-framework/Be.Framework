<?php

declare(strict_types=1);

namespace Be\Framework\SemanticVariable;

use Be\Framework\Types;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Interface for semantic variable validation
 *
 * Provides two distinct APIs:
 * - validateArgs: Framework usage for method-wide validation
 * - validateArg: Test usage for individual parameter validation
 *
 * @psalm-import-type ConstructorArguments from Types
 */
interface SemanticValidatorInterface
{
    /**
     * Validate all arguments for a method (primary API)
     *
     * @param ReflectionMethod     $method Method containing parameter definitions
     * @param ConstructorArguments $args   Values to validate (associative array: param_name => value)
     * @phpstan-param array<string, mixed> $args
     *
     * @return Errors Validation errors (empty if validation passes)
     */
    public function validateArgs(ReflectionMethod $method, array $args): Errors;

    /**
     * Validate single parameter (test convenience API)
     *
     * @param ReflectionParameter $parameter Parameter containing variable name and attributes
     * @param mixed               $value     Value to validate
     *
     * @return Errors Validation errors (empty if validation passes)
     */
    public function validateArg(ReflectionParameter $parameter, mixed $value): Errors;

    /**
     * Begin a metamorphosis chain: activate the per-chain value-validation cache.
     *
     * Between beginChain() and endChain(), a successfully validated
     * (parameterName, attributes, value) triple is remembered so an unchanged
     * value carried through several hops of one chain is validated once. Outside
     * this window the cache is inactive and every call re-validates.
     */
    public function beginChain(): void;

    /**
     * End a metamorphosis chain: discard the per-chain cache.
     *
     * Must be called (even on failure) so cache entries never outlive their
     * chain — the unbounded-growth failure mode documented for SemanticLogger.
     */
    public function endChain(): void;
}
