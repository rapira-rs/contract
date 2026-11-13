<?php

declare(strict_types=1);

namespace Rapira\Queue;

use Rapira\DispatcherInfo;
use Rapira\Queue\QueueDispatcherInfo\PipelineCounters;

/**
 * The queue plugin's counters, from {@see QueueDispatcher::getInfo()}.
 */
interface QueueDispatcherInfo extends DispatcherInfo
{
    /**
     * The counters split by pipeline. Taken with the rest of the snapshot: the $pending fields sum to
     * {@see self::pendingCount()}, the $active fields to {@see self::activeCount()}.
     *
     * @return array<non-empty-string, PipelineCounters> By pipeline name, every pipeline of
     *         {@see QueueDispatcher::getPipelines()} present.
     */
    public function byPipeline(): array;
}
