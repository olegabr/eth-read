<?php

/**
 * One event of an ABI: its signature and its topic.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Olegabr\EthRead;

use InvalidArgumentException;
use Olegabr\EthAbi\Abi\Ethabi;

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
}
