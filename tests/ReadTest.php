<?php

/**
 * Reading a contract: ABI lookups, the call that follows, and what comes back.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Olegabr\EthRead\Tests;

use Olegabr\EthAbi\Abi\Ethabi;
use Olegabr\EthAbi\Problem;
use Olegabr\EthRead\Abi;
use Olegabr\EthRead\Client;
use Olegabr\EthRead\Response;
use Olegabr\EthRead\Transport;
use PHPUnit\Framework\TestCase;

final class ReadTest extends TestCase
{
    private const ABI = <<<'JSON'
[
  {"type":"function","name":"balanceOf","stateMutability":"view",
   "inputs":[{"name":"account","type":"address"}],
   "outputs":[{"name":"","type":"uint256"}]},
  {"type":"function","name":"totalSupply","stateMutability":"view",
   "inputs":[],"outputs":[{"name":"","type":"uint256"}]},
  {"type":"function","name":"transfer","stateMutability":"nonpayable",
   "inputs":[{"name":"to","type":"address"},{"name":"value","type":"uint256"}],
   "outputs":[{"name":"","type":"bool"}]},
  {"type":"function","name":"settings","stateMutability":"view",
   "inputs":[],"outputs":[
     {"name":"fee","type":"uint16"},{"name":"paused","type":"bool"}]},
  {"type":"function","name":"named","stateMutability":"view",
   "inputs":[{"name":"order","type":"tuple","components":[
     {"name":"id","type":"uint256"},{"name":"owner","type":"address"}]}],
   "outputs":[{"name":"","type":"uint256"}]},
  {"type":"function","name":"overloaded","stateMutability":"view",
   "inputs":[{"name":"a","type":"uint256"}],"outputs":[]},
  {"type":"function","name":"overloaded","stateMutability":"view",
   "inputs":[{"name":"a","type":"address"}],"outputs":[]},
  {"type":"event","name":"Transfer","anonymous":false,"inputs":[
     {"name":"from","type":"address","indexed":true},
     {"name":"to","type":"address","indexed":true},
     {"name":"value","type":"uint256","indexed":false}]},
  {"type":"constructor","inputs":[]},
  {"type":"error","name":"Nope","inputs":[]},
  {"type":"function","inputs":[]}
]
JSON;

    private function abi(): Abi
    {
        $answer = Abi::fromJson(self::ABI);

        $this->assertArrayHasKey('abi', $answer);

        return $answer['abi'];
    }

    /**
     * Functions are keyed by their canonical signature, so an overload is two
     * entries rather than one that swallowed the other.
     */
    public function test_functions_are_indexed_by_signature(): void
    {
        $abi = $this->abi();

        $this->assertSame(
            ['balanceOf(address)', 'totalSupply()', 'transfer(address,uint256)', 'settings()', 'named((uint256,address))', 'overloaded(uint256)', 'overloaded(address)'],
            array_keys($abi->functions())
        );
        $this->assertSame(['Transfer(address,address,uint256)'], array_keys($abi->events()));

        // The item without a name is skipped and reported, not silently dropped.
        $this->assertSame(['?'], $abi->skipped());
    }

    public function test_selector_and_event_topic(): void
    {
        $abi = $this->abi();

        $this->assertSame('0x70a08231', $abi->functions()['balanceOf(address)']->selector());
        $this->assertSame('0x18160ddd', $abi->functions()['totalSupply()']->selector());
        $this->assertSame(
            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
            $abi->events()['Transfer(address,address,uint256)']->topic()
        );
    }

    /**
     * A struct argument is written `tuple` with components and hashed expanded: the
     * selector of `named((uint256,address))` is not the selector of `named(tuple)`.
     */
    public function test_a_tuple_argument_is_expanded_for_the_selector(): void
    {
        $function = $this->abi()->functions()['named((uint256,address))'];

        $this->assertSame('named((uint256,address))', $function->signature());
        $this->assertSame(
            (new Ethabi())->encodeFunctionSignature('named((uint256,address))'),
            $function->selector()
        );
    }

    /**
     * A name alone is only good when it is unambiguous: two overloads must be
     * addressed by signature, or a form writes to the wrong one.
     */
    public function test_overloads_must_be_addressed_by_signature(): void
    {
        $abi = $this->abi();

        $this->assertArrayHasKey('function', $abi->function('balanceOf'));
        $this->assertArrayHasKey('function', $abi->function('balanceOf(address)'));

        $ambiguous = $abi->function('overloaded');
        $this->assertArrayNotHasKey('function', $ambiguous);
        $this->assertSame('abi.ambiguous', $ambiguous['problem']->code());
        $this->assertSame(['overloaded', 2], $ambiguous['problem']->params());

        $this->assertSame('abi.function_missing', $abi->function('nope')['problem']->code());
        $this->assertSame('abi.event_missing', $abi->event('Nope')['problem']->code());
    }

    public function test_a_broken_abi_is_a_problem_not_an_exception(): void
    {
        $this->assertSame('abi.invalid', Abi::fromJson('{not json')['problem']->code());
    }

    /**
     * The read path as a whole: selector + argument word go out, the answer comes
     * back decoded.
     */
    public function test_read_sends_selector_and_arguments_and_decodes_the_answer(): void
    {
        $transport = $this->transport(['0x' . str_repeat('0', 62) . '2a']);
        $client = new Client('http://127.0.0.1:8545', $transport);
        $function = $this->abi()->function('balanceOf')['function'];

        $answer = $client->read(
            $function,
            '0x1111111111111111111111111111111111111111',
            ['0x2222222222222222222222222222222222222222']
        );

        $this->assertSame('42', $answer['value']);

        $payload = json_decode($transport->calls[0]['json'], true);
        $this->assertSame('eth_call', $payload['method']);
        $this->assertSame('0x1111111111111111111111111111111111111111', $payload['params'][0]['to']);
        $this->assertSame(
            '0x70a08231' . str_repeat('0', 24) . str_repeat('22', 20),
            $payload['params'][0]['data']
        );
        $this->assertSame('latest', $payload['params'][1]);
    }

    /**
     * Several outputs come back named when the ABI names them, so a block prints
     * `fee` and `paused` instead of counting words.
     */
    public function test_several_outputs_come_back_named(): void
    {
        $transport = $this->transport([
            '0x' . str_repeat('0', 62) . 'fa' . str_repeat('0', 63) . '1',
        ]);
        $client = new Client('http://127.0.0.1:8545', $transport);

        $answer = $client->read($this->abi()->function('settings')['function'], '0x1111111111111111111111111111111111111111');

        $this->assertSame(['fee' => '250', 'paused' => true], $answer['value']);
    }

    /**
     * A function that writes has no place in a read: refused before any request is
     * made, so the node never simulates a transfer on someone's behalf.
     */
    public function test_a_writing_function_is_refused_without_a_request(): void
    {
        $transport = $this->transport([]);
        $client = new Client('http://127.0.0.1:8545', $transport);

        $answer = $client->read(
            $this->abi()->function('transfer')['function'],
            '0x1111111111111111111111111111111111111111',
            ['0x2222222222222222222222222222222222222222', '1']
        );

        $this->assertSame('abi.not_read_only', $answer['problem']->code());
        $this->assertCount(0, $transport->calls);
    }

    /**
     * An empty answer means "no code here" or "no state at that block" - never
     * zero, because a dashboard prints zero in large type.
     */
    public function test_an_empty_answer_is_not_zero(): void
    {
        $transport = $this->transport(['0x']);
        $client = new Client('http://127.0.0.1:8545', $transport);

        $answer = $client->read($this->abi()->function('totalSupply')['function'], '0x1111111111111111111111111111111111111111');

        $this->assertSame('contract.empty_answer', $answer['problem']->code());
        $this->assertArrayNotHasKey('value', $answer);
    }

    /**
     * `revert("…")` reaches the caller as the reason, not as "execution reverted".
     */
    public function test_a_revert_reason_is_decoded(): void
    {
        $reason = 'insufficient balance';
        $data = '0x08c379a0' . substr((new Ethabi())->encodeParameters(['string'], [$reason]), 2);

        $transport = $this->transport([
            ['error' => ['code' => -32000, 'message' => 'execution reverted', 'data' => $data]],
        ]);
        $client = new Client('http://127.0.0.1:8545', $transport);

        $answer = $client->read($this->abi()->function('totalSupply')['function'], '0x1111111111111111111111111111111111111111');

        $this->assertSame('revert.reason', $answer['problem']->code());
        $this->assertSame([$reason], $answer['problem']->params());
    }

    /**
     * `Panic(uint256)` is the other shaped revert: an assertion code, not a
     * sentence.
     */
    public function test_a_panic_code_is_decoded(): void
    {
        $data = '0x4e487b71' . str_repeat('0', 62) . '11';

        $transport = $this->transport([
            ['error' => ['code' => -32000, 'message' => 'execution reverted', 'data' => $data]],
        ]);
        $client = new Client('http://127.0.0.1:8545', $transport);

        $answer = $client->read($this->abi()->function('totalSupply')['function'], '0x1111111111111111111111111111111111111111');

        $this->assertSame('revert.panic', $answer['problem']->code());
        $this->assertSame(['17'], $answer['problem']->params());
    }

    /**
     * The chain helpers: wei as a decimal string (no float, ever) and hex numbers
     * as integers.
     */
    public function test_balance_block_number_and_chain_id(): void
    {
        $transport = $this->transport([
            '0xde0b6b3a7640000',
            '0x10',
            '0x89',
        ]);
        $client = new Client('http://127.0.0.1:8545', $transport);

        $this->assertSame('1000000000000000000', $client->balance('0x1111111111111111111111111111111111111111')['value']);
        $this->assertSame(16, $client->blockNumber()['value']);
        $this->assertSame(137, $client->chainId()['value']);
    }

    /**
     * A transport that replays answers: a string is a `result`, an array is a raw
     * JSON-RPC body, null means "not answered yet, use the next one".
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

                if (null === $next) {
                    return new Response(200, '{"jsonrpc":"2.0","id":1,"result":"0x"}');
                }

                if (is_string($next)) {
                    return new Response(200, (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $next]));
                }

                return new Response(200, (string) json_encode(['jsonrpc' => '2.0', 'id' => 1] + $next));
            }
        };
    }
}
