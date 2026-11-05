# olegabr/eth-read

Reading an EVM chain over JSON-RPC: a transport the caller supplies, a bounded
list of read methods, and refusals as codes.

Part of the same stack as [`olegabr/eth-abi`](../eth-abi): the ABI layer is the
base, this is the network above it, and `olegabr/web3.php` extends both with
signing and sending.

**What it does:** one blocking JSON-RPC round trip per call, with a deadline, a
retry on the failures that mean "the node said nothing", and an answer that is
either a value or a `Problem`.

**What it never does:** sign, send, hold a key, or create an HTTP client.

## Why the transport is an interface

The library never builds an HTTP request by itself, because on WordPress that
would mean shipping Guzzle and bypassing `wp_remote_post` — with the site's proxy
settings, its CA bundle, `http_request_args` and `WP_HTTP_BLOCK_EXTERNAL` along
with it. A CLI has no `wp_remote_post` at all. Both cases are the caller's, and
this is the seam:

```php
interface Transport
{
    public function post(string $url, string $json, int $timeout_seconds): Response;
}
```

`$timeout_seconds` is required by the type: a call without a deadline is a call
that can hang a page render forever, and such a call should not be expressible.

## Install

```bash
composer require olegabr/eth-read
```

Requires PHP 8.1+ and `olegabr/eth-abi`.

## Reading a contract

```php
use Olegabr\EthRead\Abi;
use Olegabr\EthRead\Client;

$answer = Abi::fromJson($abi_json);        // ['abi' => Abi] or ['problem' => Problem]
$function = $answer['abi']->function('balanceOf')['function'];   // name or signature

$client = new Client('https://rpc.example', $transport, 10, 2);
$read = $client->read($function, '0xContractAddress', ['0xHolder']);
// ['value' => '1000000000000000000'] or ['problem' => Problem]
```

One request goes out — selector, then encoded arguments — and one answer comes
back, decoded against the ABI's outputs: a single output as a string, several as an
array keyed by name.

What the read path refuses, on purpose:

- **a writing function** — `abi.not_read_only`, before any request: a node would
  otherwise simulate a transfer on someone's behalf;
- **an empty answer** — `contract.empty_answer`, not `0`: a dashboard prints zero in
  large type, and "no code at that address" is not zero;
- **an ambiguous name** — `abi.ambiguous`: `balanceOf(address)` and
  `balanceOf(address,uint256)` can exist together, and answering "the first one" is
  how a form sends the wrong call.

A revert arrives as the reason rather than the word "reverted": `revert.reason` with
the string from `revert("…")`, or `revert.panic` with the assertion code.

`balance()` gives wei as a decimal string, `blockNumber()` and `chainId()` give
integers, `code()` gives the raw `eth_getCode` answer.

## Reading logs

```php
$event = $abi->event('Transfer')['event'];

$logs = $client->logs($event->filter($contract, $fromBlock, $toBlock));
// ['logs' => [Log, …]]

foreach ($logs['logs'] as $log) {
    $decoded = $event->decode($log);   // ['args' => ['from' => …, 'to' => …, 'value' => '1000']]
}
```

Indexed arguments come from `topics` in declaration order, everything else from
`data`. One refusal to know about: a **dynamic indexed** argument (`string`,
`bytes`, an array) is stored as `keccak256(value)`, so the value is not in the log.
The argument keeps the hash, the rest is decoded anyway, and the problem
`log.indexed_hashed` names what is missing — a caller showing "unknown" is honest,
a caller showing a hash as a value is not.

A log of another event is refused by `topics[0]` (`log.topic_mismatch`).

Refusals: `rpc.transport`, `rpc.http_status`, `rpc.invalid_json`, `rpc.error`,
`rpc.no_result`, `rpc.body_too_large`, `abi.*`, `revert.*`,
`contract.empty_answer`. A method outside `Client::READ_METHODS` throws before any
request is made — `eth_sendRawTransaction` is not in the list, so this class cannot
be used to move money even by mistake.

## Test

```bash
composer install
vendor/bin/phpunit
```

No test opens a socket: the transport is a class with canned answers.

## Local development

`composer.json` points at `../eth-abi` and `../php-keccak` with path repositories,
so the packages are developed side by side. Once published, they are replaced by
real repositories.

> **Path repositories are root-only.** A `repositories` block inside a dependency's
> `composer.json` is ignored by its consumers, so every project doing local
> development against `eth-abi` must declare the `../php-keccak` path itself —
> otherwise `kornrunner\Keccak` is simply missing at the first selector.
