<?php

/**
 * One log, as the node returned it.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Ethereumico\EthRead;

use Ethereumico\EthAbi\Problem;

/**
 * A log entry from `eth_getLogs`, with the fields a reader needs and no guessing.
 *
 * Everything is kept as the node wrote it: `topics` stay hex, `data` stays hex, and
 * the numbers are converted only where an integer is genuinely wanted. The decoding
 * happens on top, in {@see EventAbi::decode()}, because only the ABI knows what the
 * words mean.
 *
 * @since 0.3.0
 */
final class Log
{
    /**
     * @since 0.3.0
     *
     * @param array $raw The log object from JSON-RPC.
     */
    public function __construct(private readonly array $raw)
    {
    }

    /**
     * @since 0.3.0
     *
     * @param mixed $raw The log object from JSON-RPC.
     *
     * @return array{log?:self,problem?:Problem}
     */
    public static function fromRaw(mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['problem' => new Problem('log.invalid', [get_debug_type($raw)])];
        }

        foreach (['address', 'data'] as $field) {
            if (!isset($raw[$field]) || !is_string($raw[$field])) {
                return ['problem' => new Problem('log.invalid', [$field])];
            }
        }

        if (!isset($raw['topics']) || !is_array($raw['topics'])) {
            return ['problem' => new Problem('log.invalid', ['topics'])];
        }

        return ['log' => new self($raw)];
    }

    /**
     * @since 0.3.0
     */
    public function address(): string
    {
        return strtolower((string) $this->raw['address']);
    }

    /**
     * `topics[0]` and up, `0x`-prefixed, as the node returned them.
     *
     * @since 0.3.0
     *
     * @return string[]
     */
    public function topics(): array
    {
        return array_values(array_map('strval', (array) $this->raw['topics']));
    }

    /**
     * @since 0.3.0
     */
    public function data(): string
    {
        return (string) $this->raw['data'];
    }

    /**
     * @since 0.3.0
     */
    public function blockNumber(): int
    {
        return self::toInt($this->raw['blockNumber'] ?? '0x0');
    }

    /**
     * @since 0.3.0
     */
    public function blockHash(): string
    {
        return (string) ($this->raw['blockHash'] ?? '');
    }

    /**
     * @since 0.3.0
     */
    public function transactionHash(): string
    {
        return (string) ($this->raw['transactionHash'] ?? '');
    }

    /**
     * Position in the block: half of the identity of an event.
     *
     * @since 0.3.0
     */
    public function logIndex(): int
    {
        return self::toInt($this->raw['logIndex'] ?? '0x0');
    }

    /**
     * Whether the chain says this log is gone (a reorg).
     *
     * @since 0.3.0
     */
    public function removed(): bool
    {
        return true === ($this->raw['removed'] ?? false);
    }

    /**
     * The raw object, for a caller that stores it as it came.
     *
     * @since 0.3.0
     *
     * @return array
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @param mixed $value Hex quantity or an integer.
     */
    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || '' === $value) {
            return 0;
        }

        $hex = str_starts_with($value, '0x') ? substr($value, 2) : $value;

        return '' === $hex ? 0 : (int) hexdec($hex);
    }
}
