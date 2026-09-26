<?php

declare(strict_types=1);

namespace Be\Framework;

use Be\Framework\Exception\ConflictingParameterAttributes;
use Be\Framework\Exception\MissingParameterAttribute;
use Be\Framework\Exception\SemanticVariableException;
use Be\Framework\SemanticVariable\SemanticValidatorInterface;
use Override;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Named;
use Ray\Di\Exception\Unbound;
use Ray\Di\InjectorInterface;
use Ray\InputQuery\Attribute\Input;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

use function array_key_exists;
use function get_object_vars;

/**
 * Resolves constructor arguments
 *
 * for metamorphosis transformations
 *
 * @psalm-import-type ConstructorArguments from Types
 * @psalm-import-type ObjectProperties from Types
 */
final class BecomingArguments implements BecomingArgumentsInterface
{
    public function __construct(
        private InjectorInterface $injector,
        private SemanticValidatorInterface $semanticValidator,
    ) {
    }

    /**
     * @return ConstructorArguments
     * @phpstan-return array<string, mixed>
     */
    #[Override]
    public function be(object $current, string $becoming): array
    {
        $properties = get_object_vars($current);
        /** @var class-string $becoming */
        $targetClass = new ReflectionClass($becoming);
        $constructor = $targetClass->getConstructor();

        if ($constructor === null) {
            return [];
        }

        /** @var ConstructorArguments $args */
        /** @phpstan-var array<string, mixed> $args */
        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $paramName = $param->getName();
            $isInput = $this->isInputParameter($param);
            if ($isInput) {
                /** @var mixed $value */
                $value = array_key_exists($paramName, $properties) ? $properties[$paramName] : null;
                /** @psalm-suppress MixedAssignment */
                $args[$paramName] = $value;      // #[Input]
                continue;
            }

            /** @var mixed $value */
            $value = $this->getInjectParameter($param);
            /** @psalm-suppress MixedAssignment */
            $args[$paramName] = $value; // #[Inject]
        }

        $errors = $this->semanticValidator->validateArgs($constructor, $args);

        if ($errors->hasErrors()) {
            throw new SemanticVariableException($errors);
        }

        return $args;
    }

    #[Override]
    public function beginChain(): void
    {
        $this->semanticValidator->beginChain();
    }

    #[Override]
    public function endChain(): void
    {
        $this->semanticValidator->endChain();
    }

    /**
     * Resolves #[Inject] parameters from DI container
     *
     * Resolution rules:
     * - Class/Interface types: Resolved by type name from container
     * - Scalar types (int, string, etc.): Require #[Named('binding_name')] attribute
     * - Union/Intersection types: Not supported, require #[Named] attribute
     *
     * @throws Unbound When binding cannot be resolved.
     */
    private function getInjectParameter(ReflectionParameter $param): mixed
    {
        $namedAttributes = $param->getAttributes(Named::class);
        $named = ! empty($namedAttributes) ? $namedAttributes[0]->newInstance()->value : '';

        $type = $param->getType();
        $interface = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : '';

        /** @var class-string $interface */
        return $this->injector->getInstance($interface, $named);
    }

    /**
     * Returns if parameter is #[Input] (validates attributes as side effect)
     *
     * Returns true for #[Input], false for #[Inject]
     * Enforces Be Framework's philosophy: "Describe Yourself (Well)"
     * All dependencies must be explicitly declared for clarity and safety.
     *
     * @psalm-mutation-free
     */
    private function isInputParameter(ReflectionParameter $param): bool
    {
        $inputAttributes = $param->getAttributes(Input::class);
        $injectAttributes = $param->getAttributes(Inject::class);

        $hasInput = ! empty($inputAttributes);
        $hasInject = ! empty($injectAttributes);

        if ($hasInput && $hasInject) {
            throw ConflictingParameterAttributes::create($param);
        }

        if (! $hasInput && ! $hasInject) {
            throw MissingParameterAttribute::create($param);
        }

        return $hasInput;
    }
}
