<?php

/**
 * Reading a chain over JSON-RPC with a transport the caller supplied.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Olegabr\EthRead;

use JsonException;
use Olegabr\EthAbi\Problem;
use Throwable;

/**
 * A client for the read half of JSON-RPC.
 *
 * Three properties matter here, and each of them is a rule rather than a taste:
 *
 *  * **the transport is injected** - WordPress uses `wp_remote_post`, a CLI uses
 *    curl or streams, a test uses an array of canned answers;
 *  * **every call has a deadline** - `$timeout` is required by the constructor
 *    and passed to the transport on every request; nothing here can wait
 *    forever (a page render must not);
 *  * **only read methods exist** - `{@see self::READ_METHODS}`. There is no
 *    `send`, no key, no signing: a method outside the list is refused before any
 *    request is made, so a caller cannot use this class to move money even by
 *    mistake.
 *
 * Answers come back as values: `{@see self::request()}` returns
 * `['result' => …]` or `['problem' => Problem]`, and never a sentence.
 *
 * @since 0.1.0
 */
final class Client
{
    /**
     * The methods this library will send. Anything else is a programming error.
     *
     * @var string[]
     */
    public const READ_METHODS = [
        'eth_call',
        'eth_getCode',
        'eth_getBalance',
        'eth_blockNumber',
        'eth_chainId',
        'eth_getLogs',
        'eth_estimateGas',
        'eth_getTransactionReceipt',
        'eth_getTransactionCount',
        'net_version',
    ];

    /**
     * @since 0.1.0
     *
     * @param string    $url             Endpoint.
     * @param Transport $transport       How a request gets to the endpoint.
     * @param int       $timeout_seconds Deadline for one round trip.
     * @param int       $attempts        How many times one request may be tried.
     * @param int       $max_body_bytes  Refuse an answer larger than this.
     */
    public function __construct(
        private readonly string $url,
        private readonly Transport $transport,
        private readonly int $timeout_seconds = 10,
        private readonly int $attempts = 2,
        private readonly int $max_body_bytes = 4_194_304
    ) {
        if ($this->timeout_seconds <= 0) {
            throw new \InvalidArgumentException('timeout_seconds must be positive');
        }
    }

    /**
     * One JSON-RPC call.
     *
     * @since 0.1.0
     *
     * @param string $method Read method name.
     * @param array  $params Positional parameters.
     *
     * @throws \InvalidArgumentException When the method is not a read method.
     *
     * @return array{result?:mixed,problem?:Problem} Exactly one key is present:
     *                                               `result` on success, `problem` otherwise.
     */
    public function request(string $method, array $params = []): array
    {
        if (!in_array($method, self::READ_METHODS, true)) {
            throw new \InvalidArgumentException('rpc.method_not_allowed: ' . $method);
        }

        $payload = $this->payload($method, $params);
        $last = null;

        for ($attempt = 1; $attempt <= max(1, $this->attempts); $attempt++) {
            try {
                $response = $this->transport->post($this->url, $payload, $this->timeout_seconds);
            } catch (TransportError $error) {
                // A transport failure is the only thing worth retrying without a
                // policy: the node said nothing, so nothing was decided.
                $last = new Problem('rpc.transport', [$method]);
                $this->sleep($attempt);

                continue;
            }

            if (strlen($response->body()) > $this->max_body_bytes) {
                return ['problem' => new Problem('rpc.body_too_large', [strlen($response->body())])];
            }

            if ($response->status() >= 500 || 429 === $response->status()) {
                $last = new Problem('rpc.http_status', [$response->status()]);
                $this->sleep($attempt);

                continue;
            }

            return $this->decode($response, $method);
        }

        return ['problem' => $last ?? new Problem('rpc.transport', [$method])];
    }

    /**
     * @param string $method Method name.
     * @param array  $params Parameters.
     *
     * @return string JSON body.
     */
    private function payload(string $method, array $params): string
    {
        try {
            return (string) json_encode(
                [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => $method,
                    'params' => array_values($params),
                ],
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            // Only reachable with a value json_encode cannot express (a resource,
            // recursion). Nothing to retry and nothing to ask the node about.
            throw new \InvalidArgumentException('rpc.params_not_encodable: ' . $method);
        }
    }

    /**
     * @param Response $response Answer.
     * @param string   $method   Method, for the code.
     *
     * @return array{result?:mixed,problem?:Problem}
     */
    private function decode(Response $response, string $method): array
    {
        try {
            $decoded = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['problem' => new Problem('rpc.invalid_json', [$method])];
        }

        if (!is_array($decoded)) {
            return ['problem' => new Problem('rpc.invalid_json', [$method])];
        }

        if (isset($decoded['error'])) {
            $code = is_array($decoded['error']) ? ($decoded['error']['code'] ?? 0) : 0;
            $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? '') : '';

            // `execution reverted` carries no reason here; the reason is in
            // `data`, decoded by the ABI layer, not by the transport.
            return ['problem' => new Problem('rpc.error', [(int) $code, $message, $method])];
        }

        if (!array_key_exists('result', $decoded)) {
            return ['problem' => new Problem('rpc.no_result', [$method])];
        }

        return ['result' => $decoded['result']];
    }

    /**
     * A short backoff between attempts.
     *
     * Deliberately tiny and fixed: a page render is waiting, and a caller with
     * stricter needs (a scanner catching up) schedules its own retries.
     *
     * @param int $attempt Attempt number, 1-based.
     */
    private function sleep(int $attempt): void
    {
        if ($attempt < 1) {
            return;
        }

        try {
            usleep(min(200_000, 25_000 * $attempt));
        } catch (Throwable) {
            // usleep does not throw; this keeps the signature honest for static
            // analysers that do not know that.
        }
    }
}
