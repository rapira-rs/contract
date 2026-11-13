<?php

declare(strict_types=1);

namespace Rapira\Queue\Delivery;

/**
 * A broker's own coordinates of a delivery, on {@see Context::$origin}: one final class per driver,
 * under the broker's namespace. The type for code that handles any of them; the closed set is the
 * union {@see Context::$origin} declares.
 */
interface Origin {}
