<?php

declare(strict_types=1);

namespace Rapira;

/**
 * Severity of a log message. Values match PSR-3 log levels, so a PSR wrapper
 * maps onto them one to one.
 */
enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';
}
