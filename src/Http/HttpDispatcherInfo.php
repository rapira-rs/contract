<?php

declare(strict_types=1);

namespace Rapira\Http;

use Rapira\DispatcherInfo;

/**
 * The HTTP plugin's counters, from {@see HttpDispatcher::getInfo()}.
 *
 * Nothing beyond the shared counters yet: this is the narrowing point where HTTP-specific ones
 * land without touching the base contract.
 */
interface HttpDispatcherInfo extends DispatcherInfo {}
