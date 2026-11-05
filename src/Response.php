<?php

/**
 * What came back: a status and a body, nothing else.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Olegabr\EthRead;

/**
 * An HTTP answer, reduced to the two things JSON-RPC needs.
 *
 * No headers, no streams, no promises: a `eth_call` on a page render is one
 * blocking round trip, and a transport that wanted to be asynchronous would be
 * a different interface. The asynchronous face of the stack lives above this
 * library, not inside it.
 *
 * @since 0.1.0
 */
final class Response
{
    /**
     * @since 0.1.0
     *
     * @param int    $status HTTP status code.
     * @param string $body   Raw body.
     */
    public function __construct(
        private readonly int $status,
        private readonly string $body
    ) {
    }

    /**
     * @since 0.1.0
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * @since 0.1.0
     */
    public function body(): string
    {
        return $this->body;
    }
}
