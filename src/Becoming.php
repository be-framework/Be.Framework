<?php

declare(strict_types=1);

namespace Be\Framework;

use Be\Framework\Exception\InputSemanticVariableException;
use Be\Framework\Exception\RuntimeSemanticVariableException;
use Be\Framework\Exception\SemanticVariableException;
use Be\Framework\SemanticLog\Context\BecomingCloseContext;
use Be\Framework\SemanticLog\Logger;
use Be\Framework\SemanticLog\LoggerInterface;
use Be\Framework\SemanticVariable\SemanticValidator;
use Koriym\SemanticLogger\SemanticLogger;
use Override;
use Ray\Di\Di\Named;
use Ray\Di\InjectorInterface;
use Throwable;

/**
 * The Be Framework - Metamorphic Programming Engine
 *
 * Objects undergo metamorphosis through constructor injection - a continuous process of becoming.
 */
final class Becoming implements BecomingInterface
{
    private Being $being;
    private LoggerInterface $logger;
    private BecomingArgumentsInterface $becomingArguments;

    public function __construct(
        InjectorInterface $injector,
        #[Named('semantic_namespace')]
        string $semanticNamespace = 'Be\App\Semantic',
        LoggerInterface|null $logger = null,
        BecomingArgumentsInterface|null $becomingArguments = null,
    ) {
        $becomingArguments ??= new BecomingArguments($injector, new SemanticValidator($semanticNamespace));
        $logger ??= new Logger(new SemanticLogger(), $becomingArguments);
        $this->logger = $logger;
        $this->becomingArguments = $becomingArguments;
        $this->being = new Being($logger, $becomingArguments, new BecomingType());
    }

    /**
     * Life as continuous becoming
     *
     * No man ever steps in the same river twice - everything flows, everything changes.
     * Each moment births what was always waiting to emerge.
     *
     * @param object $input The initial state of being
     *
     * @return object The final actualized form
     */
    #[Override]
    public function __invoke(object $input): object
    {
        $chainId = $this->logger->openChain($input);
        $current = $input;
        $isFirst = true;

        // Activate the semantic validator's per-chain cache; the finally below
        // guarantees it is discarded whether the chain succeeds or throws.
        $this->becomingArguments->beginChain();

        try {
            // Being reveals its becoming, then becomes it
            while ($nextForm = $this->being->willBe($current)) {
                $current = $this->being->metamorphose($current, $nextForm);
                $isFirst = false;
            }
        } catch (Throwable $e) {
            // Refine semantic validation failures by their position in the chain:
            // the first metamorphosis validates incoming input (input error),
            // any later one validates already-validated state (runtime error).
            // Callers receive the refined subtype, while the chain-close log keeps
            // the original error class (consistent with the inner span) and carries
            // the input/runtime distinction as `origin`.
            $loggedException = $e;
            $origin = null;
            if ($e instanceof SemanticVariableException) {
                $origin = $isFirst
                    ? BecomingCloseContext::ORIGIN_INPUT
                    : BecomingCloseContext::ORIGIN_RUNTIME;
                $e = $isFirst
                    ? new InputSemanticVariableException($e->getErrors(), $e)
                    : new RuntimeSemanticVariableException($e->getErrors(), $e);
            }

            try {
                $this->logger->closeChain(null, $chainId, $loggedException, $origin);
            } catch (Throwable) {
                // A failure inside error logging must not mask the original
                // metamorphosis exception. Swallow the logging error and
                // rethrow the real one.
            }

            throw $e;
        } finally {
            $this->becomingArguments->endChain();
        }

        // Success close is outside the try so a logging failure here is not
        // mis-reported as a metamorphosis failure.
        $this->logger->closeChain($current, $chainId);

        return $current;
    }
}
