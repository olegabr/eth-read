<?php

/**
 * The client asks the transport, the transport answers, nothing touches a socket.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Ethereumico\EthRead\Tests;

use InvalidArgumentException;
use Ethereumico\EthAbi\Problem;
use Ethereumico\EthAbi\Type;
use Ethereumico\EthRead\Client;
use Ethereumico\EthRead\Response;
use Ethereumico\EthRead\Transport;
use Ethereumico\EthRead\TransportError;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /**
     * A transport that replays canned answers and records what it was asked.
     */
    private function transport(array $answers): Transport
    {
        return new class ($answers) implements Transport {
            /** @var array<int,array{url:string,json:string,timeout:int}> */
            public array $calls = [];

            public function __construct(private array $answers)
            {
            }

            public function post(string $url, string $json, int $timeout_seconds): Response
            {
                $this->calls[] = ['url' => $url, 'json' => $json, 'timeout' => $timeout_seconds];

                $next = array_shift($this->answers);

                if ($next instanceof TransportError) {
                    throw $next;
                }

                return $next ?? new Response(200, '{"jsonrpc":"2.0","id":1,"result":"0x1"}');
            }
        };
    }

    public function test_a_result_comes_back_as_a_value(): void
    {
        $transport = $this->transport([new Response(200, '{"jsonrpc":"2.0","id":1,"result":"0x2a"}')]);
        $client = new Client('http://127.0.0.1:8545', $transport, 5);

        $this->assertSame(['result' => '0x2a'], $client->request('eth_blockNumber'));
        $this->assertSame('http://127.0.0.1:8545', $transport->calls[0]['url']);
        $this->assertSame(5, $transport->calls[0]['timeout']);
        $this->assertStringContainsString('"method":"eth_blockNumber"', $transport->calls[0]['json']);
    }

    public function test_a_json_rpc_error_is_a_problem_not_an_exception(): void
    {
        $transport = $this->transport([
            new Response(200, '{"jsonrpc":"2.0","id":1,"error":{"code":-32000,"message":"execution reverted"}}'),
        ]);
        $client = new Client('http://127.0.0.1:8545', $transport);

        $answer = $client->request('eth_call', [['to' => '0x0'], 'latest']);

        $this->assertArrayNotHasKey('result', $answer);
        $this->assertInstanceOf(Problem::class, $answer['problem']);
        $this->assertSame('rpc.error', $answer['problem']->code());
        $this->assertSame(-32000, $answer['problem']->params()[0]);
    }

    public function test_a_transport_failure_is_retried_and_then_reported(): void
    {
        $transport = $this->transport([
            new TransportError('connect: connection refused'),
            new TransportError('connect: connection refused'),
        ]);
        $client = new Client('http://127.0.0.1:8545', $transport, 2, 2);

        $answer = $client->request('eth_call');

        $this->assertSame('rpc.transport', $answer['problem']->code());
        $this->assertCount(2, $transport->calls);
    }

    public function test_a_server_error_is_retried_once(): void
    {
        $transport = $this->transport([
            new Response(503, 'unavailable'),
            new Response(200, '{"jsonrpc":"2.0","id":1,"result":"0x1"}'),
        ]);
        $client = new Client('http://127.0.0.1:8545', $transport, 2, 2);

        $this->assertSame(['result' => '0x1'], $client->request('eth_chainId'));
        $this->assertCount(2, $transport->calls);
    }

    public function test_an_oversized_answer_is_refused_before_decoding(): void
    {
        $transport = $this->transport([new Response(200, str_repeat('x', 100))]);
        $client = new Client('http://127.0.0.1:8545', $transport, 2, 1, 10);

        $this->assertSame('rpc.body_too_large', $client->request('eth_call')['problem']->code());
    }

    /**
     * The library cannot write, and the refusal happens before any request: no
     * key, no signature, no `eth_sendRawTransaction` in the list.
     */
    public function test_only_read_methods_can_be_sent(): void
    {
        $transport = $this->transport([]);
        $client = new Client('http://127.0.0.1:8545', $transport);

        try {
            $client->request('eth_sendRawTransaction', ['0xdead']);
            $this->fail('a write method was accepted');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('rpc.method_not_allowed', $exception->getMessage());
        }

        $this->assertCount(0, $transport->calls);
    }

    /**
     * The dependency on `olegabr/eth-abi` is real, not decorative: values are
     * validated with it before a request is built.
     */
    public function test_the_abi_layer_is_available_here(): void
    {
        $transport = $this->transport([]);
        $client = new Client('http://127.0.0.1:8545', $transport);

        $this->assertNull(Type::problem('address', '0x1111111111111111111111111111111111111111'));
        $this->assertSame('int.too_big', Type::problem('uint8', '256')?->code());
        $this->assertSame(['result' => '0x1'], $client->request('net_version'));
    }
}
