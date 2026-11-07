<?php

/**
 * An ABI: the functions and events of a contract, by name and by signature.
 *
 * @package olegabr/eth-read
 */

declare(strict_types=1);

namespace Ethereumico\EthRead;

use JsonException;
use Ethereumico\EthAbi\Problem;

/**
 * A parsed ABI, with the lookups a block or a form actually performs.
 *
 * Two rules are worth more than the class itself:
 *
 *  * **overloads are addressed by signature.** `balanceOf(address)` and
 *    `balanceOf(address,uint256)` can exist at once; answering "the first one" to
 *    a name lookup is how a form sends the wrong call. A name that matches more
 *    than one function is refused with `abi.ambiguous`.
 *  * **a malformed ABI is an error, not a shrug.** An item without `name` or with
 *    a `type` this class does not know is skipped *and* recorded, so the caller can
 *    say why a function it expected is not there.
 *
 * @since 0.2.0
 */
final class Abi
{
    /** @var array<string,FunctionAbi> By signature. */
    private array $functions = [];

    /** @var array<string,EventAbi> By signature. */
    private array $events = [];

    /** @var string[] Signatures of the items that were skipped. */
    private array $skipped = [];

    /**
     * @since 0.2.0
     *
     * @param array $items ABI items.
     */
    private function __construct(array $items)
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            try {
                match ((string) ($item['type'] ?? '')) {
                    'function' => $this->addFunction(new FunctionAbi($item)),
                    'event' => $this->addEvent(new EventAbi($item)),
                    // constructor, fallback, receive, error: nothing to read.
                    default => null,
                };
            } catch (\InvalidArgumentException) {
                $this->skipped[] = (string) ($item['name'] ?? '?');
            }
        }
    }

    /**
     * @since 0.2.0
     *
     * @param string $json ABI JSON, as an explorer returns it.
     *
     * @return array{abi?:self,problem?:Problem}
     */
    public static function fromJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['problem' => new Problem('abi.invalid', ['json'])];
        }

        if (!is_array($decoded)) {
            return ['problem' => new Problem('abi.invalid', ['json'])];
        }

        return ['abi' => new self($decoded)];
    }

    /**
     * @since 0.2.0
     *
     * @param array $items ABI items, already decoded.
     *
     * @return self
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    /**
     * @since 0.2.0
     *
     * @return array<string,FunctionAbi> Keyed by signature.
     */
    public function functions(): array
    {
        return $this->functions;
    }

    /**
     * @since 0.2.0
     *
     * @return array<string,EventAbi> Keyed by signature.
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Names that could not be read out of the ABI.
     *
     * @since 0.2.0
     *
     * @return string[]
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * A function by signature, or by name when the name is unambiguous.
     *
     * @since 0.2.0
     *
     * @param string $nameOrSignature `balanceOf` or `balanceOf(address)`.
     *
     * @return array{function?:FunctionAbi,problem?:Problem}
     */
    public function function(string $nameOrSignature): array
    {
        $needle = trim($nameOrSignature);

        if (isset($this->functions[$needle])) {
            return ['function' => $this->functions[$needle]];
        }

        $matches = array_values(array_filter(
            $this->functions,
            static fn (FunctionAbi $function): bool => $function->name() === $needle
        ));

        if (1 === count($matches)) {
            return ['function' => $matches[0]];
        }

        if (count($matches) > 1) {
            // Sending "the first one" is how a form writes to the wrong overload.
            return ['problem' => new Problem('abi.ambiguous', [$needle, count($matches)])];
        }

        return ['problem' => new Problem('abi.function_missing', [$needle])];
    }

    /**
     * An event by name or signature.
     *
     * @since 0.2.0
     *
     * @param string $nameOrSignature Event name or signature.
     *
     * @return array{event?:EventAbi,problem?:Problem}
     */
    public function event(string $nameOrSignature): array
    {
        $needle = trim($nameOrSignature);

        if (isset($this->events[$needle])) {
            return ['event' => $this->events[$needle]];
        }

        $matches = array_values(array_filter(
            $this->events,
            static fn (EventAbi $event): bool => $event->name() === $needle
        ));

        if (1 === count($matches)) {
            return ['event' => $matches[0]];
        }

        if (count($matches) > 1) {
            return ['problem' => new Problem('abi.ambiguous', [$needle, count($matches)])];
        }

        return ['problem' => new Problem('abi.event_missing', [$needle])];
    }

    private function addFunction(FunctionAbi $function): void
    {
        $this->functions[$function->signature()] = $function;
    }

    private function addEvent(EventAbi $event): void
    {
        $this->events[$event->signature()] = $event;
    }
}
