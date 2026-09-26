<?php

declare(strict_types=1);

namespace MyVendor\MyApp\SemanticVariables;

use Be\Framework\Attribute\Validate;
use Ray\Di\Di\Inject;

/**
 * Semantic variable whose #[Validate] method also takes an #[Inject] parameter.
 *
 * Such a validator can consult mutable injected state and legitimately return a
 * different answer for the same value, so it must never be served from the
 * per-chain cache. $count reveals whether it was (wrongly) skipped.
 */
final class Tally
{
    public static int $count = 0;

    #[Validate]
    public function validateTally(int $tally, #[Inject]
    object|null $service = null,): void
    {
        self::$count++;
    }
}
