<?php

declare(strict_types=1);

namespace MyVendor\MyApp\SemanticVariables;

use Be\Framework\Attribute\Validate;

/**
 * Array-typed semantic variable: array values are excluded from the per-chain
 * cache, so validation must run on every call. $count reveals whether it did.
 */
final class Tags
{
    public static int $count = 0;

    /** @param array<mixed> $tags */
    #[Validate]
    public function validateTags(array $tags): void
    {
        self::$count++;
    }
}
