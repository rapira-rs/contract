<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

/**
 * The two kinds of data message RFC 6455 defines (§5.6): what a {@see Message} arrived as, and what
 * {@see Connection::send()} sends. A PHP string is bytes either way, so the kind travels beside it.
 */
enum MessageType
{
    /** UTF-8 text — the host rejects an inbound one that is not, and so does {@see Connection::send()}. */
    case Text;

    /** Any bytes. */
    case Binary;
}
