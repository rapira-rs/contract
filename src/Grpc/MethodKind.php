<?php

declare(strict_types=1);

namespace Rapira\Grpc;

/**
 * The streaming shape of a {@see MethodInfo}: the four names gRPC speaks, projected onto the two
 * axes the contract splits a call into by {@see self::isStreamingRequest()} and
 * {@see self::isStreamingResponse()} — an adapter asks per axis and never unpacks the four names
 * itself, and the case is what a log field or an exhaustive `match` wants.
 */
enum MethodKind: string
{
    case Unary = 'unary';
    case ServerStreaming = 'server-streaming';
    case ClientStreaming = 'client-streaming';
    case BidiStreaming = 'bidi-streaming';

    /**
     * Whether calls of this method carry {@see Call\StreamingRequest} — the messages still arriving —
     * rather than {@see Call\UnaryRequest} — one message in hand.
     */
    public function isStreamingRequest(): bool
    {
        return match ($this) {
            self::ClientStreaming, self::BidiStreaming => true,
            self::Unary, self::ServerStreaming => false,
        };
    }

    /**
     * Whether calls of this method finalize through {@see Responder\StreamingResponse} — a drained
     * generator — rather than {@see Responder\UnaryResponse} — one message.
     */
    public function isStreamingResponse(): bool
    {
        return match ($this) {
            self::ServerStreaming, self::BidiStreaming => true,
            self::Unary, self::ClientStreaming => false,
        };
    }
}
