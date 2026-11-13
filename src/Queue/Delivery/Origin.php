<?php

declare(strict_types=1);

namespace Rapira\Queue\Delivery;

/**
 * A broker's own coordinates of a delivery, on {@see Context::$origin}: one final class per driver,
 * under the broker's namespace. Which one a pipeline's deliveries carry is fixed by its
 * {@see \Rapira\Queue\Driver} case.
 */
interface Origin {}
