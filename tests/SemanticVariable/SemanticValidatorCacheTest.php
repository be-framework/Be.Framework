<?php

declare(strict_types=1);

namespace Be\Framework\SemanticVariable;

use MyVendor\MyApp\SemanticVariables\Counted;
use MyVendor\MyApp\SemanticVariables\Tags;
use MyVendor\MyApp\SemanticVariables\Tally;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins the per-chain value-validation cache (issue #81) and the three
 * correctness traps that gate it.
 */
final class SemanticValidatorCacheTest extends TestCase
{
    private SemanticValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SemanticValidator('MyVendor\\MyApp\\SemanticVariables');
        Counted::$count = 0;
        Tally::$count = 0;
        Tags::$count = 0;
    }

    public function testUnchangedValueIsValidatedOncePerChain(): void
    {
        $this->validator->beginChain();
        $this->validator->validateWithAttributes('counted', [], 5);
        $this->validator->validateWithAttributes('counted', [], 5);
        $this->validator->endChain();

        $this->assertSame(1, Counted::$count, 'Same value in one chain must validate once');
    }

    public function testAttributeSetIsPartOfCacheKey(): void
    {
        // Trap #2: an earlier plain `int $age` check must not let a later
        // `#[Teen] int $age` with the same value skip validation. age=25 passes
        // validateAge but fails validateTeen (>19), so a wrong cache hit would
        // hide the Teen error.
        $this->validator->beginChain();

        $plain = $this->validator->validateWithAttributes('age', [], 25);
        $this->assertFalse($plain->hasErrors(), 'Plain age=25 is valid');

        $teen = $this->validator->validateWithAttributes('age', ['Teen'], 25);
        $this->validator->endChain();

        $this->assertTrue($teen->hasErrors(), 'Teen validation must run despite same value/name');
    }

    public function testInjectConsumingValidatorIsNeverCached(): void
    {
        // Trap #1: a #[Validate] method with an #[Inject] parameter may depend on
        // mutable injected state and must re-run for the same value.
        $this->validator->beginChain();
        $this->validator->validateWithAttributes('tally', [], 7);
        $this->validator->validateWithAttributes('tally', [], 7);
        $this->validator->endChain();

        $this->assertSame(2, Tally::$count, 'Inject-consuming validator must not be cached');
    }

    public function testDirectCallsOutsideChainNeverAccumulate(): void
    {
        // Trap #3: calling the validator directly (no active chain) must leave the
        // cache inactive so entries never accumulate or serve stale results
        // across unrelated calls.
        $cache = new ReflectionProperty(SemanticValidator::class, 'chainCache');

        $this->assertNull($cache->getValue($this->validator), 'Cache starts inactive');

        $this->validator->validate('counted', 5);
        $this->validator->validate('counted', 5);
        $this->validator->validateWithAttributes('age', [], 25);

        $this->assertNull($cache->getValue($this->validator), 'Direct calls must not activate the cache');
        $this->assertSame(2, Counted::$count, 'Without an active chain every call re-validates');
    }

    public function testEndChainDiscardsCache(): void
    {
        $cache = new ReflectionProperty(SemanticValidator::class, 'chainCache');

        $this->validator->beginChain();
        $this->validator->validateWithAttributes('counted', [], 5);
        $this->assertIsArray($cache->getValue($this->validator));

        $this->validator->endChain();
        $this->assertNull($cache->getValue($this->validator), 'endChain() must discard the cache');
    }

    public function testArrayValuesAreNeverCached(): void
    {
        // Array values are excluded from the cache (normalizing costs more than
        // re-validating), so each call must re-run even in an active chain.
        $this->validator->beginChain();
        $this->validator->validateWithAttributes('tags', [], ['a', 'b']);
        $this->validator->validateWithAttributes('tags', [], ['a', 'b']);
        $this->validator->endChain();

        $this->assertSame(2, Tags::$count, 'Array values must never be cached');
    }

    public function testMultiArgCallsAreNeverCached(): void
    {
        // The cache only serves single-value validations; multi-arg (cross-field)
        // calls must always re-validate so a changed sibling arg is never masked.
        $this->validator->beginChain();
        $this->validator->validateWithAttributes('counted', [], 5, 99);
        $this->validator->validateWithAttributes('counted', [], 5, 99);
        $this->validator->endChain();

        $this->assertSame(2, Counted::$count, 'Multi-arg calls must never be cached');
    }
}
