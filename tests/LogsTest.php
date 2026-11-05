<?php

/**
 * Logs: what the node returns, what the ABI makes of it, and what it cannot.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Olegabr\EthRead\Tests;

use Olegabr\EthAbi\Abi\Ethabi;
use Olegabr\EthRead\Abi;
use Olegabr\EthRead\Client;
use Olegabr\EthRead\Log;
use Olegabr\EthRead\Response;
use Olegabr\EthRead\Transport;
use PHPUnit\Framework\TestCase;

final class LogsTest extends TestCase
{
    private const ABI = <<<'JSON'
[
  {"type":"event","name":"Transfer","anonymous":false,"inputs":[
     {"name":"from","type":"address","indexed":true},
     {"name":"to","type":"address","indexed":true},
     {"name":"value","type":"uint256","indexed":false}]},
  {"type":"event","name":"Noted","anonymous":false,"inputs":[
     {"name":"who","type":"address","indexed":true},
     {"name":"tag","type":"string","indexed":true},
     {"name":"note","type":"string","indexed":false}]}
]
JSON;

    private function event(string $name): \Olegabr\EthRead\EventAbi
    {
        $abi = Abi::fromJson(self::ABI)['abi'];

        return $abi->event($name)['event'];
    }

    /**
     * A filter names the event by `topics[0]` - the node compares that word and
     * never needs the ABI - and the block range is a hex quantity, because some
     * clients reject a decimal number and others ignore it.
     */
    public function test_a_filter_asks_for_one_event(): void
    {
        $filter = $this->event('Transfer')->filter('0x1111111111111111111111111111111111111111', 100, 200);

        $this->assertSame('0x1111111111111111111111111111111111111111', $filter['address']);
        $this->assertSame(
            ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef'],
            $filter['topics']
        );
        $this->assertSame('0x64', $filter['fromBlock']);
        $this->assertSame('0xc8', $filter['toBlock']);
    }

    /**
     * Indexed arguments come from `topics`, the rest from `data` - the split the
     * ABI declares, not the order they appear in the log.
     */
    public function test_an_indexed_and_a_plain_argument_are_found_where_the_abi_says(): void
    {
        $abi = new Ethabi();
        $data = $abi->encodeParameters(['uint256'], ['1000']);

        $log = [
            'address' => '0x1111111111111111111111111111111111111111',
            'topics' => [
                $this->event('Transfer')->topic(),
                '0x' . str_repeat('0', 24) . str_repeat('aa', 20),
                '0x' . str_repeat('0', 24) . str_repeat('bb', 20),
            ],
            'data' => $data,
            'blockNumber' => '0x10',
            'transactionHash' => '0x' . str_repeat('cc', 32),
            'logIndex' => '0x2',
            'removed' => false,
        ];

        $answer = $this->event('Transfer')->decode($log);

        $this->assertArrayHasKey('args', $answer);
        $this->assertArrayNotHasKey('problem', $answer);
        $this->assertSame('0x' . str_repeat('aa', 20), $answer['args']['from']);
        $this->assertSame('0x' . str_repeat('bb', 20), $answer['args']['to']);
        $this->assertSame('1000', (string) $answer['args']['value']);
    }

    /**
     * A dynamic indexed argument is only present as `keccak256(value)`: the value
     * cannot be recovered, and showing the hash as if it were the value is a lie.
     * The other arguments are still decoded, and the problem names what is missing.
     */
    public function test_a_dynamic_indexed_argument_is_reported_not_invented(): void
    {
        $abi = new Ethabi();
        $data = $abi->encodeParameters(['string'], ['hello']);

        $log = [
            'address' => '0x1111111111111111111111111111111111111111',
            'topics' => [
                $this->event('Noted')->topic(),
                '0x' . str_repeat('0', 24) . str_repeat('aa', 20),
                '0x' . str_repeat('ee', 32),
            ],
            'data' => $data,
        ];

        $answer = $this->event('Noted')->decode($log);

        $this->assertSame('log.indexed_hashed', $answer['problem']->code());
        $this->assertSame([['tag']], $answer['problem']->params());
        $this->assertSame('0x' . str_repeat('aa', 20), $answer['args']['who']);
        $this->assertSame('hello', $answer['args']['note']);
    }

    /**
     * A log of another event is refused by its `topics[0]`, so a feed cannot show
     * one event's arguments under another event's name.
     */
    public function test_a_foreign_log_is_refused(): void
    {
        $log = [
            'address' => '0x1111111111111111111111111111111111111111',
            'topics' => ['0x' . str_repeat('11', 32)],
            'data' => '0x',
        ];

        $answer = $this->event('Transfer')->decode($log);

        $this->assertSame('log.topic_mismatch', $answer['problem']->code());
        $this->assertArrayNotHasKey('args', $answer);
    }

    /**
     * `eth_getLogs` end to end: the filter goes out, `Log` objects come back with
     * the numbers as integers and the reorg flag readable.
     */
    public function test_logs_come_back_as_objects(): void
    {
        $transport = $this->transport([[
            [
                'address' => '0x1111111111111111111111111111111111111111',
                'topics' => ['0x' . str_repeat('22', 32)],
                'data' => '0x',
                'blockNumber' => '0x10',
                'blockHash' => '0x' . str_repeat('33', 32),
                'transactionHash' => '0x' . str_repeat('44', 32),
                'logIndex' => '0x1',
                'removed' => true,
            ],
        ]]);
        $client = new Client('http://127.0.0.1:8545', $transport);

        $answer = $client->logs($this->event('Transfer')->filter('0x1111111111111111111111111111111111111111', 1));

        $this->assertArrayHasKey('logs', $answer);
        $this->assertCount(1, $answer['logs']);
        $this->assertInstanceOf(Log::class, $answer['logs'][0]);
        $this->assertSame(16, $answer['logs'][0]->blockNumber());
        $this->assertSame(1, $answer['logs'][0]->logIndex());
        $this->assertTrue($answer['logs'][0]->removed());
        $this->assertSame('0x' . str_repeat('44', 32), $answer['logs'][0]->transactionHash());

        $payload = json_decode($transport->calls[0]['json'], true);
        $this->assertSame('eth_getLogs', $payload['method']);
        $this->assertArrayNotHasKey('toBlock', $payload['params'][0]);
    }

    /**
     * A log that is missing a field is a problem with the field's name, not a
     * notice in the middle of a page.
     */
    public function test_a_malformed_log_names_the_field(): void
    {
        $answer = Log::fromRaw(['address' => '0x1', 'topics' => []]);

        $this->assertSame('log.invalid', $answer['problem']->code());
        $this->assertSame(['data'], $answer['problem']->params());
    }

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

                return new Response(200, (string) json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => array_shift($this->answers) ?? [],
                ]));
            }
        };
    }
}
