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
     * lifecycle. This is the mode {@see get_dispatcher()} refuses, since nothing feeds this process
     * units of work.
     */
    case Classic;

    /**
     * A long-lived process serving requests one at a time. The request arrives through the SAPI
     * superglobals, as in {@see self::Classic}, but the process outlives it and serves many, so each
     * request resets the state it touched before the next one sees it.
     */
    case Worker;

    /**
     * A long-lived process serving several requests at once, each on its own fiber. A fiber takes a
     * unit of work from {@see get_dispatcher()} and answers it through that unit — an
     * {@see \Rapira\Http\Exchange} for HTTP — while its siblings run on in the same process.
     */
    case Dispatcher;
}
