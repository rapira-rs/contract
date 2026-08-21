<?php

declare(strict_types=1);

namespace Rapira;

/**
 * The mode Rapira runs a process in — the `[pool] mode` of `rapira.toml`, read back at runtime from
 * {@see get_mode()}.
 *
 * A property of how the host launched the process, not a choice the script makes: the same code is
 * loaded under every mode, and the entry point reads this to run the loop the mode calls for.
 */
enum Mode
{
    /**
     * One process per request: started for it, torn down after it. Nothing survives between requests,
     * so there is no state to reset and no loop — the script runs once and exits, the classic PHP-SAPI
     * lifecycle. {@see get_dispatcher()} refuses this mode, since nothing feeds this process units
     * of work.
     */
    case Classic;

    /**
     * A long-lived process serving requests one at a time. The request arrives through the SAPI
     * superglobals, as in {@see self::Classic}, but the process outlives it and serves many, so each
     * request resets the state it touched before the next one sees it. Requests are not units of
     * work here, so {@see get_dispatcher()} refuses this mode as it does {@see self::Classic}.
     */
    case Worker;

    /**
     * A long-lived process taking units of work from {@see get_dispatcher()} and answering through
     * them — an {@see \Rapira\Http\Exchange} for HTTP — instead of the SAPI superglobals. How many
     * run at once is the script's choice: one at a time in a plain loop, or several concurrently,
     * each on its own fiber.
     */
    case Dispatcher;
}
