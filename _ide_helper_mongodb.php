<?php
/**
 * MongoDB extension stubs for IDE support (Intelephense).
 *
 * These classes are defined in the C extension and have no PHP source files.
 * This file is never executed — it exists solely for static analysis.
 *
 * @noinspection ALL
 */

namespace MongoDB\BSON {

    class UTCDateTime implements \JsonSerializable, \Stringable
    {
        public function __construct(int|string|\DateTimeInterface|null $milliseconds = null) {}
        public function toDateTime(): \DateTime { return new \DateTime(); }
        public function __toString(): string { return ''; }
        public function jsonSerialize(): mixed { return null; }
    }

    class ObjectId implements \JsonSerializable, \Stringable
    {
        public function __construct(?string $id = null) {}
        public function getTimestamp(): int { return 0; }
        public function __toString(): string { return ''; }
        public function jsonSerialize(): mixed { return null; }
    }

    class Regex implements \JsonSerializable, \Stringable
    {
        public function __construct(string $pattern, string $flags = '') {}
        public function getPattern(): string { return ''; }
        public function getFlags(): string { return ''; }
        public function __toString(): string { return ''; }
        public function jsonSerialize(): mixed { return null; }
    }

    class Binary implements \JsonSerializable, \Stringable
    {
        public function __construct(string $data, int $type = 0) {}
        public function getData(): string { return ''; }
        public function getType(): int { return 0; }
        public function __toString(): string { return ''; }
        public function jsonSerialize(): mixed { return null; }
    }

    class Timestamp implements \JsonSerializable, \Stringable
    {
        public function __construct(int $increment, int $timestamp) {}
        public function getIncrement(): int { return 0; }
        public function getTimestamp(): int { return 0; }
        public function __toString(): string { return ''; }
        public function jsonSerialize(): mixed { return null; }
    }
}

namespace MongoDB\Driver {

    /**
     * @template-implements \Traversable<int, object>
     */
    interface CursorInterface extends \Traversable
    {
        /** @return array<int, object> */
        public function toArray(): array;
        public function getId(): CursorId;
        public function getServer(): Server;
        public function isDead(): bool;
    }

    class CursorId {}
    class Server {}
}
