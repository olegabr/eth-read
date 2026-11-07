<?php

/**
 * One event of an ABI: its signature and its topic.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Ethereumico\EthRead;

use InvalidArgumentException;
use Ethereumico\EthAbi\Abi\Ethabi;
use Ethereumico\EthAbi\Problem;
use Throwable;

/**
 * An `event` item of an ABI.
 *
 * `topics[0]` of a log is the full 32-byte hash of the signature - not a four-byte
 * selector - and the signature is expanded the same way a function's is, so the
 * expansion is shared rather than written twice.
 *
 * @since 0.2.0
 */
final class EventAbi
{
    /**
     * @since 0.2.0
     *
     * @param array $item The ABI item.
     *
     * @throws InvalidArgumentException When the item has no name.
     */
    public function __construct(private readonly array $item)
    {
        if ('' === $this->name()) {
            throw new InvalidArgumentException('abi.invalid: event without a name');
        }
    }

    /**
     * @since 0.2.0
     */
    public function name(): string
    {
        return (string) ($this->item['name'] ?? '');
    }

    /**
     * The ABI item as it was given.
     *
     * @since 0.2.0
     *
     * @return array
     */
    public function item(): array
    {
        return $this->item;
    }

    /**
     * `Transfer(address,address,uint256)`.
     *
     * @since 0.2.0
     */
    public function signature(): string
    {
        // An event and a function with the same signature share the shape, so the
        // function object computes it and this one just names itself.
        $item = $this->item;
        $item['type'] = 'function';

        return (new FunctionAbi($item))->signature();
    }

    /**
     * `topics[0]`: `0x` + 64 hex digits.
     *
     * @since 0.2.0
     */
    public function topic(): string
    {
        return (string) (new Ethabi())->encodeEventSignature($this->signature());
    }

    /**
     * The arguments, in ABI order, each with its `indexed` flag.
     *
     * @since 0.2.0
     *
     * @return array<int,array>
     */
    public function inputs(): array
    {
        return array_values((array) ($this->item['inputs'] ?? []));
    }

    /**
     * The argument types, as the decoder wants them.
     *
     * @since 0.2.0
     *
     * @return string[]
     */
    public function inputTypes(): array
    {
        return array_map(
            static fn (array $argument): string => (string) ($argument['type'] ?? ''),
            $this->inputs()
        );
    }

    /**
     * A filter for `eth_getLogs` that asks the node for this event only.
     *
     * `topics[0]` is the filter the node understands: it compares that word for
     * equality and does not need the ABI. `$fromBlock`/`$toBlock` are sent as hex
     * quantities because that is what the specification wants - the node rejects a
     * decimal block number on some clients and silently ignores it on others.
     *
     * @since 0.3.0
     *
     * @param string   $address   Contract address.
     * @param int      $fromBlock First block.
     * @param int|null $toBlock   Last block, or null for the latest.
     *
     * @return array
     */
    public function filter(string $address, int $fromBlock, ?int $toBlock = null): array
    {
        $filter = [
            'address' => $address,
            'topics' => [$this->topic()],
            'fromBlock' => '0x' . dechex(max(0, $fromBlock)),
        ];

        if (null !== $toBlock) {
            $filter['toBlock'] = '0x' . dechex(max($fromBlock, $toBlock));
        }

        return $filter;
    }

    /**
     * A log, decoded against this event.
     *
     * Two facts decide the shape of the answer, and both come from the ABI:
     *
     *  * **indexed arguments live in `topics`**, in declaration order, starting at
     *    `topics[1]`; everything else is concatenated in `data`;
     *  * **a dynamic indexed argument is hashed**, not stored: a `string`, `bytes` or
     *    array marked `indexed` is only present as `keccak256(value)`, so its value
     *    cannot be recovered and claiming one would be a lie. Those names are
     *    reported in the problem while every other argument is still decoded.
     *
     * @since 0.3.0
     *
     * @param Log|array $log The log, or its raw array.
     *
     * @return array{args?:array<string,mixed>,problem?:Problem}
     */
    public function decode(Log|array $log): array
    {
        if (is_array($log)) {
            $parsed = Log::fromRaw($log);

            if (isset($parsed['problem'])) {
                return $parsed;
            }

            $log = $parsed['log'];
        }

        $topics = $log->topics();

        if ([] === $topics) {
            return ['problem' => new Problem('log.no_topics', [$this->name()])];
        }

        if (strtolower($topics[0]) !== strtolower($this->topic())) {
            return ['problem' => new Problem('log.topic_mismatch', [$this->signature(), $topics[0]])];
        }

        $ethabi = new Ethabi();
        $args = [];
        $hashed = [];
        $topicIndex = 1;
        $plainTypes = [];
        $plainNames = [];

        foreach ($this->inputs() as $input) {
            $name = (string) ($input['name'] ?? '');
            $type = (string) ($input['type'] ?? '');
            $key = '' !== $name ? $name : (string) count($args);

            if (true === ($input['indexed'] ?? false)) {
                if (self::isHashedWhenIndexed($type)) {
                    $hashed[] = $key;
                    $args[$key] = $topics[$topicIndex] ?? null;
                    $topicIndex++;

                    continue;
                }

                $args[$key] = isset($topics[$topicIndex])
                    ? $ethabi->decodeParameter($type, $topics[$topicIndex])
                    : null;
                $topicIndex++;

                continue;
            }

            $plainTypes[] = $type;
            $plainNames[] = $key;
        }

        if ([] !== $plainTypes) {
            try {
                $values = $ethabi->decodeParameters($plainTypes, $log->data());
            } catch (Throwable $error) {
                return ['problem' => new Problem('log.decode_failed', [$this->name(), $error->getMessage()])];
            }

            foreach ($plainNames as $index => $name) {
                $args[$name] = $values[$index] ?? null;
            }
        }

        if ([] !== $hashed) {
            // The values are not in the log; the names are, and a caller that shows
            // "unknown" is honest where a caller that shows a hash as a value is not.
            return ['args' => $args, 'problem' => new Problem('log.indexed_hashed', [$hashed])];
        }

        return ['args' => $args];
    }

    /**
     * Whether an indexed argument of this type is stored as a hash.
     *
     * @since 0.3.0
     *
     * @param string $type ABI type.
     */
    private static function isHashedWhenIndexed(string $type): bool
    {
        if (in_array($type, ['string', 'bytes'], true)) {
            return true;
        }

        if (str_ends_with($type, ']')) {
            return true;
        }

        if (str_starts_with($type, 'tuple') || str_starts_with($type, '(')) {
            // A struct is hashed once any of its members is dynamic, and deciding
            // that needs the components; a struct with a static layout would decode,
            // so this errs on the side of "hash" and says so in the problem.
            return true;
        }

        return false;
    }
}
