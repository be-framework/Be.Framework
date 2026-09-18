<?php

declare(strict_types=1);

namespace Be\Framework\SemanticLog;

use ReflectionClass;
use SensitiveParameter;

use function array_walk;
use function get_object_vars;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;

/**
 * Safely extracts public object properties for semantic log payloads.
 *
 * Handles both declared and dynamic properties, with special handling for:
 * - Uninitialized properties (returns null)
 * - Been instances (excluded from output)
 * - Non-storable values like resources (excluded)
 * - Properties named after a constructor parameter marked #[\SensitiveParameter]
 *   (value replaced with {@see self::FILTERED})
 *
 * The last rule reuses the attribute an application already applies for PHP stack-trace
 * redaction: a credential-shaped public property (a login's password, a reset token) would
 * otherwise reach the semantic log verbatim, since #[\SensitiveParameter] itself only
 * affects traces. Matching by name, not by promotion, also covers a Final that assigns a
 * marked constructor argument to a same-named declared property.
 */
final class ObjectPropertyExtractor
{
    /**
     * The value recorded in place of a sensitive property.
     *
     * Same literal bear/event-sourcing's SensitiveParamsFilter records for a redacted request
     * param, so both layers of one observation tree read the same way.
     */
    public const string FILTERED = '[FILTERED]';

    /**
     * Extract all public properties from an object for logging
     *
     * @param object $result The object to extract properties from
     *
     * @return ObjectProperties Key-value pairs of property names and their values.
     *                          Uninitialized properties are returned as null.
     *                          Been instances and non-JSON-serializable values are excluded.
     * @phpstan-return array<string, mixed>
     */
    public function extract(object $result): array
    {
        $sensitive = self::sensitivePropertyNames($result);
        $properties = $this->collectVisibleProperties($result, $sensitive);
        $this->mergeDeclaredProperties($properties, $result, $sensitive);

        return $properties;
    }

    /**
     * @param array<string, true> $sensitive
     *
     * @return array<string, mixed>
     */
    private function collectVisibleProperties(object $result, array $sensitive): array
    {
        $properties = [];
        $dynamicProperties = get_object_vars($result);
        array_walk(
            $dynamicProperties,
            static function (mixed $value, string $name) use (&$properties, $sensitive): void {
                if ($value instanceof Been || ! self::isStorableValue($value)) {
                    return;
                }

                self::storeProperty($properties, $name, $value, $sensitive);
            },
        );

        return $properties;
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, true>  $sensitive
     */
    private function mergeDeclaredProperties(array &$properties, object $result, array $sensitive): void
    {
        foreach ((new ReflectionClass($result))->getProperties() as $property) {
            if (! $property->isPublic() || $property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            if (! $property->isInitialized($result)) {
                $properties[$name] = null;

                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $value = $property->getValue($result);
            if ($value instanceof Been) {
                unset($properties[$name]);

                continue;
            }

            if (! self::isStorableValue($value)) {
                continue;
            }

            self::storeProperty($properties, $name, $value, $sensitive);
        }
    }

    /**
     * Names of constructor parameters marked #[\SensitiveParameter], keyed for lookup.
     *
     * @return array<string, true>
     */
    private static function sensitivePropertyNames(object $result): array
    {
        $constructor = (new ReflectionClass($result))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $names = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                $names[$parameter->getName()] = true;
            }
        }

        return $names;
    }

    /** @psalm-assert-if-true array<array-key, mixed>|bool|float|int|object|string|null $value */
    private static function isStorableValue(mixed $value): bool
    {
        return $value === null
            || is_array($value)
            || is_bool($value)
            || is_float($value)
            || is_int($value)
            || is_object($value)
            || is_string($value);
    }

    /**
     * @param array<string, mixed>                                      $properties
     * @param array<array-key, mixed>|bool|float|int|object|string|null $value
     * @param array<string, true>                                       $sensitive
     */
    private static function storeProperty(
        array &$properties,
        string $name,
        array|bool|float|int|object|string|null $value,
        array $sensitive,
    ): void {
        $properties[$name] = isset($sensitive[$name]) ? self::FILTERED : $value;
    }
}
