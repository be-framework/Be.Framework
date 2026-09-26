<?php

declare(strict_types=1);

namespace Be\Framework;

use Be\Framework\Attribute\Be;
use Be\Framework\Attribute\Validate;
use Be\Framework\Exception\BeMatchException;
use Be\Framework\Exception\ConflictingParameterAttributes;
use Be\Framework\Exception\InputSemanticVariableException;
use Be\Framework\Exception\MissingParameterAttribute;
use Be\Framework\Exception\RuntimeSemanticVariableException;
use Be\Framework\Exception\SemanticVariableException;
use Be\Framework\Exception\UnbecomingException;
use Be\Framework\SemanticLog\Context\BecomingCloseContext;
use Be\Framework\SemanticLog\Logger;
use Be\Framework\SemanticVariable\Errors;
use Be\Framework\SemanticVariable\SemanticValidator;
use InvalidArgumentException;
use Koriym\SemanticLogger\Exception\NoLogSessionException;
use Koriym\SemanticLogger\SemanticLogger;
use MyVendor\MyApp\SemanticVariables\Counted;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Named;
use Ray\Di\Injector;
use Ray\InputQuery\Attribute\Input;
use RuntimeException;

use function assert;
use function filter_var;
use function is_array;

use const FILTER_VALIDATE_EMAIL;

final class BecomingTest extends TestCase
{
    private Becoming $becoming;

    protected function setUp(): void
    {
        $injector = new Injector(new BecomingTestModule());

        // BecomingArgumentsには必ずSemanticValidatorを渡す
        $semanticValidator = new SemanticValidator('MyVendor\\MyApp\\SemanticVariables');
        $becomingArguments = new BecomingArguments($injector, $semanticValidator);

        $this->becoming = new Becoming($injector, 'MyVendor\\MyApp', null, $becomingArguments);
    }

    public function testSingleTransformation(): void
    {
        $input = new BecomingTestInput('test data');
        $result = ($this->becoming)($input);

        $this->assertInstanceOf(BecomingTestProcessed::class, $result);
        $this->assertEquals('PROCESSED: test data', $result->processedData);
    }

    public function testChainedTransformation(): void
    {
        $input = new BecomingTestChainStart('hello');
        $result = ($this->becoming)($input);

        // Should go through: ChainStart -> ChainMiddle -> ChainEnd
        $this->assertInstanceOf(BecomingTestChainEnd::class, $result);
        $this->assertEquals('hello -> middle -> end', $result->finalValue);
    }

    public function testNoTransformation(): void
    {
        $input = new BecomingTestFinal('final value');
        $result = ($this->becoming)($input);

        // Should return the same object as it has no #[Be] attribute
        $this->assertSame($input, $result);
        $this->assertEquals('final value', $result->value);
    }

    public function testArrayTransformationWithTypeMatching(): void
    {
        // Test successful path
        $successInput = new BecomingTestBranching('success', 100);
        $result = ($this->becoming)($successInput);

        $this->assertInstanceOf(BecomingTestSuccessPath::class, $result);
        $this->assertEquals('success', $result->status);
        $this->assertEquals(100, $result->value);

        // Test failure path
        $failureInput = new BecomingTestBranching('failure', 50);
        $result = ($this->becoming)($failureInput);

        $this->assertInstanceOf(BecomingTestFailurePath::class, $result);
        $this->assertEquals('failure', $result->status);
        $this->assertEquals(50, $result->value);
    }

    public function testArrayTransformationWithNoMatch(): void
    {
        $this->expectException(BeMatchException::class);
        $this->expectExceptionMessage('No matching class for becoming in [Be\Framework\BecomingTestImpossible1, Be\Framework\BecomingTestImpossible2]');

        $input = new BecomingTestNoMatch('test');
        ($this->becoming)($input);
    }

    public function testTransformationWithEmptyConstructor(): void
    {
        $input = new BecomingTestWithEmpty('data');
        $result = ($this->becoming)($input);

        $this->assertInstanceOf(BecomingTestEmpty::class, $result);
    }

    public function testSingleTransformationException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Always throws exception');

        $input = new BecomingTestExceptionThrows('test');
        ($this->becoming)($input);
    }

    public function testMissingAttributeValidation(): void
    {
        $this->expectException(MissingParameterAttribute::class);
        $this->expectExceptionMessage('Parameter "data" in Be\Framework\BecomingTestMissingAttribute::__construct must have either #[Input] or #[Inject] attribute');

        $input = new BecomingTestMissingAttributeInput('test');
        ($this->becoming)($input);
    }

    public function testBothAttributesValidation(): void
    {
        $this->expectException(ConflictingParameterAttributes::class);
        $this->expectExceptionMessage('Parameter "data" in Be\Framework\BecomingTestBothAttributes::__construct cannot have both #[Input] and #[Inject] attributes simultaneously');

        $input = new BecomingTestBothAttributesInput('test');
        ($this->becoming)($input);
    }

    public function testInputParameterWithDefaultValue(): void
    {
        $input = new BecomingTestDefaultInput('test');
        $result = ($this->becoming)($input);

        $this->assertInstanceOf(BecomingTestWithDefault::class, $result);
        $this->assertEquals('test', $result->data);
        $this->assertEquals('default-value', $result->defaultParam);
    }

    public function testUnionTypeWithNamedBinding(): void
    {
        $input = new BecomingTestUnionInput('test');
        $result = ($this->becoming)($input);

        $this->assertInstanceOf(BecomingTestWithUnion::class, $result);
        $this->assertEquals('test', $result->data);
        $this->assertEquals('test-union-value', $result->unionParam);
    }

    public function testArrayTransformationWithTypeMatchAndConstructorFailure(): void
    {
        // Test the case where type matching succeeds but constructor fails
        // This should hit lines 106-107 in Being.php (candidateErrors recording)

        $input = new BecomingTestFailureInput('test');
        $result = ($this->becoming)($input);

        // Should successfully transform using the fallback target class
        $this->assertInstanceOf(BecomingTestSuccessfulFallback::class, $result);
        $this->assertEquals('test-processed', $result->processedValue);
    }

    public function testSemanticVariableExceptionIsNotRetried(): void
    {
        // Test line 113 in Being.php - SemanticVariableException should be re-thrown immediately
        $this->expectException(SemanticVariableException::class);

        $input = new BecomingTestSemanticFailureInput('invalid-email');
        ($this->becoming)($input);
    }

    public function testSemanticValidationFailure(): void
    {
        $this->expectException(SemanticVariableException::class);

        $input = new BecomingTestSemanticInvalid('invalid-email');
        ($this->becoming)($input);
    }

    public function testFirstMetamorphosisFailsWithInputException(): void
    {
        // Validation failing on the first metamorphosis is an input error
        $input = new BecomingTestSemanticInvalid('invalid-email');

        try {
            ($this->becoming)($input);
            $this->fail('Expected InputSemanticVariableException');
        } catch (InputSemanticVariableException $e) {
            // The refined input subtype is still a SemanticVariableException
            $this->assertInstanceOf(SemanticVariableException::class, $e);
            $this->assertTrue($e->getErrors()->hasErrors());

            // The original base exception is preserved as the cause so the real
            // validation site stays reachable. Pin it on the Becoming rewrap path
            // (not just in the direct-construction unit tests).
            $previous = $e->getPrevious();
            $this->assertInstanceOf(SemanticVariableException::class, $previous);
            $this->assertNotInstanceOf(InputSemanticVariableException::class, $previous);
            $this->assertSame($e->getErrors(), $previous->getErrors());
        }
    }

    public function testLaterMetamorphosisFailsWithRuntimeException(): void
    {
        // The first transformation succeeds; the second fails validation,
        // which is a runtime error rather than an input error.
        $input = new BecomingTestRuntimeStart('seed');

        try {
            ($this->becoming)($input);
            $this->fail('Expected RuntimeSemanticVariableException');
        } catch (RuntimeSemanticVariableException $e) {
            $this->assertInstanceOf(SemanticVariableException::class, $e);
            $this->assertNotInstanceOf(InputSemanticVariableException::class, $e);
            $this->assertTrue($e->getErrors()->hasErrors());

            // Cause chain preserved through the rewrap, same Errors carried over.
            $previous = $e->getPrevious();
            $this->assertInstanceOf(SemanticVariableException::class, $previous);
            $this->assertNotInstanceOf(RuntimeSemanticVariableException::class, $previous);
            $this->assertSame($e->getErrors(), $previous->getErrors());
        }
    }

    public function testBranchingFirstStepFailsWithInputException(): void
    {
        // The first metamorphosis through the array/branching path (performTypeMatching)
        // must classify a validation failure as an input error, just like the linear path.
        $input = new BecomingTestSemanticFailureInput('invalid-email');

        try {
            ($this->becoming)($input);
            $this->fail('Expected InputSemanticVariableException');
        } catch (InputSemanticVariableException $e) {
            $this->assertNotInstanceOf(RuntimeSemanticVariableException::class, $e);
            $this->assertTrue($e->getErrors()->hasErrors());
        }
    }

    public function testChainCloseLogRecordsBaseErrorAndInputOrigin(): void
    {
        // The chain-close log records the ORIGINAL base exception class (consistent
        // with the inner being_error_close span) and carries the input/runtime
        // distinction via `origin` — not by swapping the class name. Use a real
        // SemanticLogger so the emitted becoming_close payload can be inspected.
        $semanticLogger = new SemanticLogger();
        $becoming = $this->becomingWithLogger($semanticLogger);

        try {
            $becoming(new BecomingTestSemanticInvalid('invalid-email'));
            $this->fail('Expected InputSemanticVariableException');
        } catch (InputSemanticVariableException) {
            // expected
        }

        $context = $this->becomingCloseContext($semanticLogger);
        $this->assertSame('error', $context['exit']);
        $this->assertSame(SemanticVariableException::class, $context['error']);
        $this->assertSame(BecomingCloseContext::ORIGIN_INPUT, $context['origin']);
    }

    public function testChainCloseLogRecordsRuntimeOrigin(): void
    {
        // A validation failure on a later metamorphosis is logged with origin=runtime.
        $semanticLogger = new SemanticLogger();
        $becoming = $this->becomingWithLogger($semanticLogger);

        try {
            $becoming(new BecomingTestRuntimeStart('seed'));
            $this->fail('Expected RuntimeSemanticVariableException');
        } catch (RuntimeSemanticVariableException) {
            // expected
        }

        $context = $this->becomingCloseContext($semanticLogger);
        $this->assertSame('error', $context['exit']);
        $this->assertSame(SemanticVariableException::class, $context['error']);
        $this->assertSame(BecomingCloseContext::ORIGIN_RUNTIME, $context['origin']);
    }

    public function testFlushReturnsAccumulatedSessionAndResetsState(): void
    {
        // BeModule binds SemanticLoggerInterface as a singleton so one instance
        // can accumulate several chains before the caller reads it out (see
        // docs/semantic-log-architecture.md#log-lifecycle-flush-ownership).
        // flush() must return everything accumulated so far AND reset the
        // logger's internal state so a later read finds no session.
        $semanticLogger = new SemanticLogger();
        $becoming = $this->becomingWithLogger($semanticLogger);

        $becoming(new BecomingTestInput('first'));
        $becoming(new BecomingTestInput('second'));

        $session = $semanticLogger->flush()->toArray();
        assert(is_array($session['open']));
        $this->assertCount(2, $session['open'], 'flush() must return both accumulated chains');

        $this->expectException(NoLogSessionException::class);
        $semanticLogger->toArray();
    }

    private function becomingWithLogger(SemanticLogger $semanticLogger): Becoming
    {
        $injector = new Injector(new BecomingTestModule());
        $semanticValidator = new SemanticValidator('MyVendor\\MyApp\\SemanticVariables');
        $becomingArguments = new BecomingArguments($injector, $semanticValidator);
        $logger = new Logger($semanticLogger, $becomingArguments);

        return new Becoming($injector, 'MyVendor\\MyApp', $logger, $becomingArguments);
    }

    /** @return array<string, mixed> */
    private function becomingCloseContext(SemanticLogger $semanticLogger): array
    {
        $logData = $semanticLogger->toArray();
        assert(is_array($logData['open']) && is_array($logData['open'][0]) && is_array($logData['open'][0]['close']));
        $closeData = $logData['open'][0]['close'];
        assert(is_array($closeData) && is_array($closeData['context']));

        // A real assertion (not assert()) so the shape check still holds when
        // zend.assertions is disabled.
        $this->assertSame('becoming_close', $closeData['type']);

        return $closeData['context'];
    }

    public function testTypeMatchingFailureWithFallback(): void
    {
        // This test covers lines 106-107 in Being.php performTypeMatching
        // First candidate will fail type matching, second will succeed
        $input = new BecomingTestTypeMismatchInput('test-string', 42);
        $result = ($this->becoming)($input);

        // Should succeed with the compatible target class
        $this->assertInstanceOf(BecomingTestTypeCompatible::class, $result);
        $this->assertEquals('test-string', $result->stringValue);
        $this->assertEquals(42, $result->intValue);
    }

    public function testUnbecomingExceptionTriesNextCandidate(): void
    {
        // When constructor throws UnbecomingException, framework should try next candidate
        $input = new BecomingTestRejectionInput('fallback-value');
        $result = ($this->becoming)($input);

        // Should use the fallback class after rejection
        $this->assertInstanceOf(BecomingTestRejectionFallback::class, $result);
        $this->assertEquals('fallback-value-processed', $result->processedValue);
    }

    public function testInfrastructureExceptionPropagatesImmediately(): void
    {
        // Infrastructure errors should propagate immediately, not be masked as type mismatches
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection failed');

        $input = new BecomingTestInfrastructureErrorInput('test');
        ($this->becoming)($input);
    }

    public function testUnchangedValueValidatedOncePerChainThenReValidatedNextChain(): void
    {
        // Issue #81: a value carried unchanged through a chain's hops is
        // validated once, but each new Becoming::__invoke() starts fresh.
        Counted::$count = 0;

        $result = ($this->becoming)(new BecomingTestCacheStart(5));
        $this->assertSame(5, $result->counted);
        $this->assertSame(1, Counted::$count, 'Unchanged value validated once within one chain');

        ($this->becoming)(new BecomingTestCacheStart(5));
        $this->assertSame(2, Counted::$count, 'A new chain re-validates (cache is per-chain)');
    }
}

// Cache-per-chain fixtures (issue #81): `counted` carried unchanged through hops.
#[Be(BecomingTestCacheMid::class)]
final class BecomingTestCacheStart
{
    public function __construct(
        #[Input]
        public readonly int $counted,
    ) {
    }
}

#[Be(BecomingTestCacheEnd::class)]
final class BecomingTestCacheMid
{
    public function __construct(
        #[Input]
        public readonly int $counted,
    ) {
    }
}

final class BecomingTestCacheEnd
{
    public function __construct(
        #[Input]
        public readonly int $counted,
    ) {
    }
}

// Test fixtures for coverage testing

// Classes for testing failure scenarios
#[Be([BecomingTestFailingTarget::class, BecomingTestSuccessfulFallback::class])]
final class BecomingTestFailureInput
{
    public function __construct(
        public readonly string $value,
    ) {
    }
}

// This class will fail during construction when value = 'test'
final class BecomingTestFailingTarget
{
    public function __construct(
        #[Input]
        string $value,
    ) {
        if ($value === 'test') {
            throw new UnbecomingException('I am not this');
        }

        $this->processedValue = $value;
    }

    public readonly string $processedValue;
}

// This class should succeed as fallback
final class BecomingTestSuccessfulFallback
{
    public function __construct(
        #[Input]
        string $value,
    ) {
        $this->processedValue = $value . '-processed';
    }

    public readonly string $processedValue;
}

// Class for testing SemanticVariableException re-throw
#[Be([BecomingTestSemanticFailingTarget::class])]
final class BecomingTestSemanticFailureInput
{
    public function __construct(
        public readonly string $email,
    ) {
    }
}

final class BecomingTestSemanticFailingTarget
{
    public function __construct(
        #[Input]
        string $email,
    ) {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors = new Errors([
                'Invalid email format',
            ]);

            throw new SemanticVariableException($errors);
        }

        $this->processedEmail = $email;
    }

    public readonly string $processedEmail;
}

// Type mismatch test fixtures - for covering lines 106-107 in Being.php
#[Be([BecomingTestTypeIncompatible::class, BecomingTestTypeCompatible::class])]
final class BecomingTestTypeMismatchInput
{
    public function __construct(
        public readonly string $stringValue,
        public readonly int $intValue,
    ) {
    }
}

// This class expects int for stringValue - will cause type mismatch
final class BecomingTestTypeIncompatible
{
    public function __construct(
        #[Input]
        int $stringValue,  // Type mismatch: expects int but gets string
        #[Input]
        int $intValue,
    ) {
        $this->processedValue = $stringValue + $intValue;
    }

    public readonly int $processedValue;
}

// This class has compatible types - will succeed after first fails
final class BecomingTestTypeCompatible
{
    public function __construct(
        #[Input]
        public readonly string $stringValue,  // Compatible: expects string, gets string
        #[Input]
        public readonly int $intValue,        // Compatible: expects int, gets int
    ) {
    }
}

// Original test fixtures

#[Be(BecomingTestProcessed::class)]
final class BecomingTestInput
{
    public function __construct(
        public readonly string $data,
    ) {
    }
}

final class BecomingTestProcessed
{
    public function __construct(
        #[Input]
        string $data,
    ) {
        $this->processedData = 'PROCESSED: ' . $data;
    }

    public readonly string $processedData;
}

// Chain transformation fixtures
#[Be(BecomingTestChainMiddle::class)]
final class BecomingTestChainStart
{
    public function __construct(
        public readonly string $value,
    ) {
    }
}

#[Be(BecomingTestChainEnd::class)]
final class BecomingTestChainMiddle
{
    public function __construct(
        #[Input]
        string $value,
    ) {
        $this->intermediateValue = $value . ' -> middle';
    }

    public readonly string $intermediateValue;
}

final class BecomingTestChainEnd
{
    public function __construct(
        #[Input]
        string $intermediateValue,
    ) {
        $this->finalValue = $intermediateValue . ' -> end';
    }

    public readonly string $finalValue;
}

// Final object (no transformation)
final class BecomingTestFinal
{
    public function __construct(
        public readonly string $value,
    ) {
    }
}

// Branching transformation fixtures
#[Be([BecomingTestSuccessPath::class, BecomingTestFailurePath::class])]
final class BecomingTestBranching
{
    public function __construct(
        public readonly string $type,
        public readonly int $value,
    ) {
    }
}

final class BecomingTestSuccessPath
{
    public function __construct(
        #[Input]
        string $type,
        #[Input]
        public readonly int $value,
    ) {
        if ($type !== 'success' || $value < 100) {
            throw new UnbecomingException('Invalid success conditions');
        }

        $this->status = 'success';
    }

    public readonly string $status;
}

final class BecomingTestFailurePath
{
    public function __construct(
        #[Input]
        string $type,
        #[Input]
        public readonly int $value,
    ) {
        // Accepts any input that doesn't match success criteria
        $this->status = 'failure';
    }

    public readonly string $status;
}

// No match fixtures
#[Be([BecomingTestImpossible1::class, BecomingTestImpossible2::class])]
final class BecomingTestNoMatch
{
    public function __construct(
        public readonly string $data,
    ) {
    }
}

final class BecomingTestImpossible1
{
    public function __construct(
        #[Input]
        string $data,
    ) {
        throw new UnbecomingException('Always rejects');
    }
}

final class BecomingTestImpossible2
{
    public function __construct(
        #[Input]
        string $data,
    ) {
        throw new UnbecomingException('Also always rejects');
    }
}

// Empty constructor fixtures
#[Be(BecomingTestEmpty::class)]
final class BecomingTestWithEmpty
{
    public function __construct(
        public readonly string $data,
    ) {
    }
}

final class BecomingTestEmpty
{
    // No constructor - should be handled correctly
}

// Exception throwing fixture
#[Be(BecomingTestNeverReached::class)]
final class BecomingTestExceptionThrows
{
    public function __construct(
        public readonly string $data,
    ) {
    }
}

final class BecomingTestNeverReached
{
    public function __construct(
        #[Input]
        string $data,
    ) {
        throw new InvalidArgumentException('Always throws exception');
    }
}

// Missing attribute validation fixture
#[Be(BecomingTestMissingAttribute::class)]
final class BecomingTestMissingAttributeInput
{
    public function __construct(
        public readonly string $data,
    ) {
    }
}

final class BecomingTestMissingAttribute
{
    public function __construct(
        public readonly string $data,  // Missing #[Input] or #[Inject] attribute intentionally
    ) {
    }
}

// Both attributes validation fixture
#[Be(BecomingTestBothAttributes::class)]
final class BecomingTestBothAttributesInput
{
    public function __construct(
        public readonly string $data,
    ) {
    }
}

final class BecomingTestBothAttributes
{
    public function __construct(
        #[Input]
        #[Inject]
        public readonly string $data,  // Both attributes - should fail
    ) {
    }
}

// Default parameter value fixture
#[Be(BecomingTestWithDefault::class)]
final class BecomingTestDefaultInput
{
    public function __construct(
        public readonly string $data,
        public readonly string $defaultParam = 'default-value',
    ) {
    }
}

final class BecomingTestWithDefault
{
    public function __construct(
        #[Input]
        public readonly string $data,
        #[Input]
        public readonly string $defaultParam = 'default-value',
    ) {
    }
}

// Union type fixture
#[Be(BecomingTestWithUnion::class)]
final class BecomingTestUnionInput
{
    public function __construct(
        public readonly string $data,
    ) {
    }
}

final class BecomingTestWithUnion
{
    public function __construct(
        #[Input]
        public readonly string $data,
        #[Inject]
        #[Named('unionValue')]
        public readonly string|int $unionParam,
    ) {
    }
}

final class BecomingTestModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind('')->annotatedWith('unionValue')->toInstance('test-union-value');
    }
}

// Semantic validation test fixtures
#[Be(BecomingTestSemanticTarget::class)]
final class BecomingTestSemanticInvalid
{
    public function __construct(
        public readonly string $email,
    ) {
    }
}

final class BecomingTestSemanticTarget
{
    public function __construct(
        #[Input]
        #[Validate('Email')]
        public readonly string $email,  // This will trigger semantic validation for email
    ) {
    }
}

// Runtime semantic validation test fixtures: a two-step chain where the first
// transformation passes validation and the second produces invalid data.
#[Be(BecomingTestRuntimeMiddle::class)]
final class BecomingTestRuntimeStart
{
    public function __construct(
        public readonly string $value,  // no Value semantic class - first step passes
    ) {
    }
}

#[Be(BecomingTestRuntimeFailingTarget::class)]
final class BecomingTestRuntimeMiddle
{
    public readonly string $email;

    public function __construct(
        #[Input]
        string $value,
    ) {
        // Carry the seed forward as the email for the next step. The seed ('seed')
        // is not a valid email, so the SECOND metamorphosis fails semantic validation.
        $this->email = $value;
    }
}

final class BecomingTestRuntimeFailingTarget
{
    public function __construct(
        #[Input]
        #[Validate('Email')]
        public readonly string $email,  // fails semantic validation on the second step
    ) {
    }
}

// UnbecomingException test fixtures
#[Be([BecomingTestRejectionTarget::class, BecomingTestRejectionFallback::class])]
final class BecomingTestRejectionInput
{
    public function __construct(
        public readonly string $value,
    ) {
    }
}

// This class intentionally rejects the transformation
final class BecomingTestRejectionTarget
{
    public function __construct(
        #[Input]
        string $value,
    ) {
        // Constructor logic determines this transformation should be rejected
        if ($value === 'fallback-value') {
            throw new UnbecomingException('This transformation path is not appropriate');
        }

        $this->processedValue = $value;
    }

    public readonly string $processedValue;
}

// Fallback class that accepts the input
final class BecomingTestRejectionFallback
{
    public function __construct(
        #[Input]
        string $value,
    ) {
        $this->processedValue = $value . '-processed';
    }

    public readonly string $processedValue;
}

// Infrastructure error test fixtures
#[Be([BecomingTestInfrastructureErrorTarget::class, BecomingTestInfrastructureErrorFallback::class])]
final class BecomingTestInfrastructureErrorInput
{
    public function __construct(
        public readonly string $value,
    ) {
    }
}

// This class throws infrastructure error that should propagate immediately
final class BecomingTestInfrastructureErrorTarget
{
    public function __construct(
        #[Input]
        string $value, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
    ) {
        // Simulate infrastructure error (DB, network, etc.)
        throw new RuntimeException('Database connection failed');
    }
}

// This fallback should never be reached when infrastructure error occurs
final class BecomingTestInfrastructureErrorFallback
{
    public function __construct(
        #[Input]
        string $value,
    ) {
        $this->processedValue = $value . '-fallback';
    }

    public readonly string $processedValue;
}
