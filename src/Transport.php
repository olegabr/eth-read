<?php

/**
 * The one method this library needs from the outside world.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Olegabr\EthRead;

/**
 * An HTTP POST, supplied by the caller.
 *
 * The library never creates an HTTP client. Under WordPress that would mean
 * shipping Guzzle and bypassing `wp_remote_post` - with the site's proxy
 * settings, its CA bundle, `http_request_args` and `WP_HTTP_BLOCK_EXTERNAL`
 * along with it. A CLI has no `wp_remote_post` at all. Both are the caller's
 * problem, expressed as this interface.
 *
 * `$timeout` is not optional on purpose: a call without a deadline is a call
 * that can hang a page render forever, and the type system should not let one
 * exist.
 *
 * @since 0.1.0
 */
interface Transport
{
    /**
     * @since 0.1.0
     *
     * @param string $url             Endpoint.
     * @param string $json            Request body, already encoded.
     * @param int    $timeout_seconds Deadline for the whole round trip.
     *
     * @throws TransportError When no HTTP response was obtained at all.
     *
     * @return Response
     */
    public function post(string $url, string $json, int $timeout_seconds): Response;
}
