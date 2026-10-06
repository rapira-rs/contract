<?php

declare(strict_types=1);

namespace Rapira\Internal;

/**
 * What the stub functions use outside a Rapira process, replaceable by test doubles.
 *
 * Process-wide: a test that sets a double sets null afterwards, or the next test inherits it. With the
 * extension loaded the stubs are not declared, and whatever is set here is never consulted.
 *
 * @internal Set by `rapira/testing`; tests go through its doubles.
 */
final class Double
{
    private static ?Runtime $runtime = null;

    /**
     * @param Runtime|null $runtime Null restores the default {@see Runtime}.
     */
    public static function setRuntime(?Runtime $runtime): void
    {
        self::$runtime = $runtime;
    }

    public static function runtime(): Runtime
    {
        return self::$runtime ??= new Runtime();
    }
}
