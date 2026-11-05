<?php

/**
 * Why a call failed, when the contract said so.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Olegabr\EthRead;

use Olegabr\EthAbi\Abi\Ethabi;
use Olegabr\EthAbi\Problem;
use Throwable;

/**
 * Revert data, decoded.
 *
 * A node answers a failed call with `execution reverted` and, if the contract
 * helped, a payload: `Error(string)` for `revert("…")`, `Panic(uint256)` for an
 * assertion. Passing "execution reverted" up to a page tells the visitor nothing;
 * the reason is in those bytes, and decoding them costs one `eth_call`-sized
 * decode that already exists.
 *
 * Nothing here guesses: data this class does not recognise is returned as null and
 * the caller keeps the generic message.
 *
 * @since 0.2.0
 */
final class Revert
{
    /** `Error(string)` */
    private const ERROR_SELECTOR = '08c379a0';

    /** `Panic(uint256)` */
    private const PANIC_SELECTOR = '4e487b71';

    /**
     * A revert payload as a problem, or null when it is not one.
     *
     * @since 0.2.0
     *
     * @param string $data Hex, `0x`-prefixed, as the node returned it.
     *
     * @return Problem|null
     */
    public static function decode(string $data): ?Problem
    {
        $hex = str_starts_with($data, '0x') ? substr($data, 2) : $data;

        if (strlen($hex) < 8 + 64) {
            return null;
        }

        $selector = substr($hex, 0, 8);
        $payload = '0x' . substr($hex, 8);

        try {
            $abi = new Ethabi();

            if (self::ERROR_SELECTOR === $selector) {
                $decoded = $abi->decodeParameter('string', $payload);

                return new Problem('revert.reason', [is_string($decoded) ? $decoded : '']);
            }

            if (self::PANIC_SELECTOR === $selector) {
                $code = $abi->decodeParameter('uint256', $payload);

                return new Problem('revert.panic', [(string) $code]);
            }
        } catch (Throwable) {
            // Malformed payload: the generic message is better than a crash on the
            // way to reporting a failure.
            return null;
        }

        return null;
    }
}
