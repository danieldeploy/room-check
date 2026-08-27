<?php

declare(strict_types = 1);

namespace LanguageDetection;

/**
 * Class LanguageResult
 *
 * @copyright Patrick Schur
 * @license https://opensource.org/licenses/mit-license.html MIT
 * @author Patrick Schur <patrick_schur@outlook.de>
 * @package LanguageDetection
 */
class LanguageResult implements \JsonSerializable, \IteratorAggregate, \ArrayAccess
{
    const THRESHOLD = .025;
    private $result = [];

    public function __construct(array $result = [])
    {
        $this->result = $result;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->result[$offset]);
    }

    public function offsetGet($offset): ?float
    {
        return $this->result[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        if (null === $offset) {
            $this->result[] = $value;
        } else {
            $this->result[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->result[$offset]);
    }

    public function jsonSerialize(): array
    {
        return $this->result;
    }

    public function __toString(): string
    {
        return (string) \key($this->result);
    }

    public function whitelist(string ...$whitelist): LanguageResult
    {
        return new LanguageResult(\array_intersect_key($this->result, \array_flip($whitelist)));
    }

    public function blacklist(string ...$blacklist): LanguageResult
    {
        return new LanguageResult(\array_diff_key($this->result, \array_flip($blacklist)));
    }

    public function close(): array
    {
        return $this->result;
    }

    public function bestResults(): LanguageResult
    {
        if (!\count($this->result))
        {
            return new LanguageResult;
        }
        $first = \array_values($this->result)[0];
        return new LanguageResult(\array_filter($this->result, function ($value) use ($first) {
            return ($first - $value) <= self::THRESHOLD ? true : false;
        }));
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->result);
    }

    public function limit(int $offset, ?int $length = null): LanguageResult
    {
        return new LanguageResult(\array_slice($this->result, $offset, $length));
    }
}
