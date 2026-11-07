<?php

/**
 * Reading a chain over JSON-RPC with a transport the caller supplied.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Ethereumico\EthRead;

use JsonException;
use Ethereumico\EthAbi\Abi\Ethabi;
use Ethereumico\EthAbi\Problem;
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
        private readonly int $max_body_bytes = 4_194_304,
        private readonly ?Ethabi $ethabi = null
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
     * Read a `view`/`pure` function and return its first output as a value.
     *
     * The whole path of one read is here: encode the call (selector + arguments),
     * make `eth_call`, decode the answer against the ABI's outputs. What comes back
     * is a value or a {@see Problem} - never an exception, never a sentence.
     *
     * @since 0.2.0
     *
     * @param FunctionAbi $function The function, from a parsed ABI.
     * @param string      $to       Contract address.
     * @param array       $args     Argument values, in ABI order.
     * @param string      $block    Block tag or number.
     *
     * @return array{value?:mixed,problem?:Problem} `value` is a string for a
     *                                              single output, an array keyed by
     *                                              name for several.
     */
    public function read(FunctionAbi $function, string $to, array $args = [], string $block = 'latest'): array
    {
        if (!$function->isReadOnly()) {
            // A state-changing function has no business in a read call: the node
            // would simulate it and answer as if it happened.
            return ['problem' => new Problem('abi.not_read_only', [$function->signature()])];
        }

        $ethabi = $this->ethabi ?? new Ethabi();
        $types = $function->inputTypes();

        if (count($types) !== count($args)) {
            return ['problem' => new Problem('abi.argument_count', [count($types), count($args)])];
        }

        try {
            $encoded = $ethabi->encodeParameters($types, $args);
        } catch (Throwable $error) {
            return ['problem' => new Problem('abi.encode_failed', [$function->signature(), $error->getMessage()])];
        }

        $data = $function->selector() . substr($encoded, 2);
        $answer = $this->request('eth_call', [['to' => $to, 'data' => $data], $block]);

        if (isset($answer['problem'])) {
            return $answer;
        }

        $result = $answer['result'];

        if (!is_string($result) || '' === $result || '0x' === $result) {
            // An empty answer is not "zero": the contract has no code, or the node
            // has no state at that block. Saying `0` would be a lie a dashboard
            // prints in large type.
            return ['problem' => new Problem('contract.empty_answer', [$to, $function->signature()])];
        }

        $outputs = $function->outputTypes();

        if ([] === $outputs) {
            return ['value' => ''];
        }

        try {
            $decoded = $ethabi->decodeParameters($outputs, $result);
        } catch (Throwable $error) {
            return ['problem' => new Problem('abi.decode_failed', [$function->signature(), $error->getMessage()])];
        }

        return ['value' => $this->name_outputs($function, $decoded)];
    }

    /**
     * `eth_getCode`: whether the address is a contract at all.
     *
     * @since 0.2.0
     *
     * @param string $address Address.
     *
     * @return array{result?:string,problem?:Problem}
     */
    public function code(string $address): array
    {
        return $this->request('eth_getCode', [$address, 'latest']);
    }

    /**
     * `eth_getBalance`, in wei, as a decimal string.
     *
     * @since 0.2.0
     *
     * @param string $address Address.
     * @param string $block   Block tag or number.
     *
     * @return array{value?:string,problem?:Problem}
     */
    public function balance(string $address, string $block = 'latest'): array
    {
        $answer = $this->request('eth_getBalance', [$address, $block]);

        if (!isset($answer['result']) || !is_string($answer['result'])) {
            return $answer;
        }

        return ['value' => (string) \Ethereumico\EthAbi\Support\Uint::fromHex($answer['result'])];
    }

    /**
     * `eth_blockNumber`, as an integer.
     *
     * @since 0.2.0
     *
     * @return array{value?:int,problem?:Problem}
     */
    public function blockNumber(): array
    {
        $answer = $this->request('eth_blockNumber', []);

        if (!isset($answer['result']) || !is_string($answer['result'])) {
            return $answer;
        }

        return ['value' => (int) hexdec(substr((string) $answer['result'], 2))];
    }

    /**
     * `eth_chainId`, as an integer.
     *
     * @since 0.2.0
     *
     * @return array{value?:int,problem?:Problem}
     */
    public function chainId(): array
    {
        $answer = $this->request('eth_chainId', []);

        if (!isset($answer['result']) || !is_string($answer['result'])) {
            return $answer;
        }

        return ['value' => (int) hexdec(substr((string) $answer['result'], 2))];
    }

    /**
     * `eth_getLogs`, with each entry as a {@see Log}.
     *
     * The filter is the caller's: `EventAbi::filter()` builds the one that asks for
     * a single event, and blocks or numbers are hex quantities by then. The window
     * is not policed here - a node's limit on a block range differs per provider -
     * but a caller that asks for a million blocks gets the node's answer, not a
     * silent truncation.
     *
     * @since 0.3.0
     *
     * @param array $filter The `eth_getLogs` filter object.
     *
     * @return array{logs?:array<int,Log>,problem?:Problem}
     */
    public function logs(array $filter): array
    {
        $answer = $this->request('eth_getLogs', [$filter]);

        if (isset($answer['problem'])) {
            return $answer;
        }

        if (!is_array($answer['result'])) {
            return ['problem' => new Problem('log.invalid_result', [get_debug_type($answer['result'])])];
        }

        $logs = [];

        foreach ($answer['result'] as $raw) {
            $parsed = Log::fromRaw($raw);

            if (isset($parsed['problem'])) {
                return $parsed;
            }

            $logs[] = $parsed['log'];
        }

        return ['logs' => $logs];
    }

    /**
     * @param string $method Method name.
     * @param array  $params Parameters.
     *
     * @return string JSON body.
     */
    private function payload(string $method, array $params): string    {
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

            // `execution reverted` carries no reason in the message; the reason is
            // in `data`, and a visitor deserves it instead of the word "reverted".
            $data = is_array($decoded['error']) ? ($decoded['error']['data'] ?? null) : null;

            if (is_string($data) && str_starts_with($data, '0x') && strlen($data) > 2) {
                $revert = Revert::decode($data);

                if (null !== $revert) {
                    return ['problem' => $revert];
                }
            }

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

    /**
     * Decoded outputs, named when the ABI names them.
     *
     * One unnamed output is the common case (`totalSupply()`), and a caller wants
     * the value, not `[0 => value]`; several outputs become name-keyed when the ABI
     * provides names, so a view can print them without counting positions.
     *
     * @param FunctionAbi $function Function.
     * @param mixed       $decoded  Decoder answer.
     *
     * @return mixed
     */
    private function name_outputs(FunctionAbi $function, mixed $decoded): mixed
    {
        if (!is_array($decoded)) {
            return $decoded;
        }

        $outputs = $function->outputs();

        if (1 === count($outputs)) {
            return $decoded[0] ?? null;
        }

        $named = [];

        foreach ($outputs as $index => $output) {
            $name = (string) ($output['name'] ?? '');

            $named['' !== $name ? $name : (string) $index] = $decoded[$index] ?? null;
        }

        return $named;
    }
}
