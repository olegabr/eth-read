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

## Use

```php
use Olegabr\EthRead\Client;
use Olegabr\EthRead\Response;
use Olegabr\EthRead\Transport;

$client = new Client(
    'https://rpc.example',
    $transport,        // your implementation, e.g. wp_remote_post
    10,                // seconds, per round trip
    2                  // attempts
);

$answer = $client->request('eth_blockNumber');
// ['result' => '0x10'] or ['problem' => Problem(code: 'rpc.error', …)]
```

Refusals: `rpc.transport`, `rpc.http_status`, `rpc.invalid_json`, `rpc.error`,
`rpc.no_result`, `rpc.body_too_large`. A method outside `Client::READ_METHODS`
throws before any request is made — `eth_sendRawTransaction` is not in the list,
so this class cannot be used to move money even by mistake.

## Test

```bash
composer install
vendor/bin/phpunit
```

No test opens a socket: the transport is a class with canned answers.

## Local development

`composer.json` points at `../eth-abi` with a path repository, so both packages
are developed side by side. Once published, the path repository is replaced by a
VCS repository.
