<?php

declare(strict_types=1);

namespace MyVendor\MyApp\SemanticVariables;

use Be\Framework\Attribute\Validate;

/**
 * Pure semantic variable (no #[Inject]) used to observe per-chain caching:
 * $count reveals how many times validation actually ran.
 */
final class Counted
{
    public static int $count = 0;

    #[Validate]
    public function validateCounted(int $counted): void
    {
        self::$count++;
    }
}
