<?php

/**
 * One function of an ABI, as something a caller can read.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Ethereumico\EthRead;

use InvalidArgumentException;
use Ethereumico\EthAbi\Abi\Ethabi;

/**
 * A `function` item of an ABI.
 *
 * The value this object adds is the **canonical signature**: ABI JSON writes a
 * struct argument as `tuple` with `components`, while the selector is computed
 * from the expanded form `(uint256,address)`. Getting that wrong produces a
 * selector that matches nothing, and the node answers "function not found" with no
 * hint about why - so the expansion is here, once.
 *
 * @since 0.2.0
 */
final class FunctionAbi
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
            throw new InvalidArgumentException('abi.invalid: function without a name');
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
     * The ABI item as it was given, for a caller that needs the raw shape.
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
     * `balanceOf(address)`, with struct arguments expanded.
     *
     * @since 0.2.0
     */
    public function signature(): string
    {
        return $this->name() . '(' . implode(',', self::types($this->inputs())) . ')';
    }

    /**
     * The four-byte selector, `0x`-prefixed.
     *
     * @since 0.2.0
     */
    public function selector(): string
    {
        return (string) (new Ethabi())->encodeFunctionSignature($this->signature());
    }

    /**
     * The input arguments, in ABI order.
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
     * The output arguments, in ABI order.
     *
     * @since 0.2.0
     *
     * @return array<int,array>
     */
    public function outputs(): array
    {
        return array_values((array) ($this->item['outputs'] ?? []));
    }

    /**
     * Types of the input arguments, as the encoder wants them.
     *
     * @since 0.2.0
     *
     * @return string[]
     */
    public function inputTypes(): array
    {
        return self::types($this->inputs());
    }

    /**
     * Types of the output arguments, as the decoder wants them.
     *
     * @since 0.2.0
     *
     * @return string[]
     */
    public function outputTypes(): array
    {
        return self::types($this->outputs());
    }

    /**
     * Whether the function promises not to change state.
     *
     * `view` and `pure` today; the older `constant: true` still appears in ABI JSON
     * exported from old tooling, and a call to a constant function is a call.
     *
     * @since 0.2.0
     */
    public function isReadOnly(): bool
    {
        $mutability = (string) ($this->item['stateMutability'] ?? '');

        if ('' !== $mutability) {
            return in_array($mutability, ['view', 'pure'], true);
        }

        return true === ($this->item['constant'] ?? false);
    }

    /**
     * @since 0.2.0
     *
     * @param array<int,array> $arguments Argument items.
     *
     * @return string[]
     */
    private static function types(array $arguments): array
    {
        return array_map([self::class, 'canonicalType'], $arguments);
    }

    /**
     * One argument type, with `tuple` expanded into its components.
     *
     * `tuple` becomes `(uint256,address)`; `tuple[]` becomes `(uint256,address)[]`.
     *
     * @since 0.2.0
     *
     * @param array $argument Argument item.
     */
    private static function canonicalType(array $argument): string
    {
        $type = (string) ($argument['type'] ?? '');

        if (!str_starts_with($type, 'tuple')) {
            return $type;
        }

        $components = array_map([self::class, 'canonicalType'], array_values((array) ($argument['components'] ?? [])));

        return '(' . implode(',', $components) . ')' . substr($type, strlen('tuple'));
    }
}
