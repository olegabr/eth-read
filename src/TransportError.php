<?php

/**
 * No HTTP response at all - DNS, connect, timeout, TLS.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Olegabr\EthRead;

use RuntimeException;

/**
 * Thrown by a {@see Transport} when the round trip did not produce a response.
 *
 * This is an exception rather than a {@see \Olegabr\EthAbi\Problem} because it is
 * not about the value or the protocol: the request never happened. Everything the
 * node *did* answer - an HTTP error, a JSON-RPC error, a revert - is a value.
 *
 * @since 0.1.0
 */
final class TransportError extends RuntimeException
{
}
