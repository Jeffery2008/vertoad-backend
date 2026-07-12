<?php

declare(strict_types=1);

namespace {
    if (!class_exists('Redis')) {
        final class Redis
        {
            /** @var array<string, int> */
            public array $keys = [];
            /** @var array<string, string> */
            public array $values = [];
            /** @var array<string, int> */
            public array $counts = [];
            /** @var array<string, int> */
            public array $ttl = [];
            /** @var array<string, array<string, float>> */
            public array $zsets = [];
            /** @var list<array{script: string, args: list<mixed>, numKeys: int}> */
            public array $evalCalls = [];
            /** @var list<array{host: string, port: int, timeout: float}> */
            public array $connections = [];
            /** @var array<string, array{seconds: int, value: string}> */
            public array $setExCalls = [];
            public bool $failNextAtomicRecordAfterSet = false;
            public ?string $password = null;
            public ?int $database = null;

            public function connect(string $host, int $port, float $timeout): bool
            {
                $this->connections[] = ['host' => $host, 'port' => $port, 'timeout' => $timeout];

                return true;
            }

            public function auth(string $password): bool
            {
                $this->password = $password;

                return true;
            }

            public function select(int $database): bool
            {
                $this->database = $database;

                return true;
            }

            /** @param array<int|string, int|string> $options */
            public function set(string $key, string $value, array $options = []): bool
            {
                if (isset($options[0]) && strtolower((string) $options[0]) === 'nx' && isset($this->keys[$key])) {
                    return false;
                }

                $this->keys[$key] = (int) ($options['ex'] ?? 0);
                $this->values[$key] = $value;

                return true;
            }

            public function setex(string $key, int $seconds, string $value): bool
            {
                $this->setExCalls[$key] = ['seconds' => $seconds, 'value' => $value];
                $this->keys[$key] = $seconds;
                $this->values[$key] = $value;

                return $seconds > 0;
            }

            public function get(string $key): string|false
            {
                return $this->values[$key] ?? false;
            }

            public function exists(string $key): int
            {
                return isset($this->keys[$key]) ? 1 : 0;
            }

            public function del(string $key): int
            {
                if (!isset($this->keys[$key])
                    && !array_key_exists($key, $this->values)
                    && !array_key_exists($key, $this->counts)
                    && !array_key_exists($key, $this->zsets)) {
                    return 0;
                }

                unset($this->keys[$key], $this->values[$key], $this->counts[$key], $this->ttl[$key], $this->zsets[$key]);

                return 1;
            }

            public function incr(string $key): int
            {
                $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

                return $this->counts[$key];
            }

            public function expire(string $key, int $seconds): bool
            {
                $this->ttl[$key] = $seconds;

                return true;
            }

            public function zAdd(string $key, float $score, string $member): int
            {
                $exists = array_key_exists($member, $this->zsets[$key] ?? []);
                $this->zsets[$key][$member] = $score;

                return $exists ? 0 : 1;
            }

            public function zRem(string $key, string $member): int
            {
                if (!array_key_exists($member, $this->zsets[$key] ?? [])) {
                    return 0;
                }

                unset($this->zsets[$key][$member]);

                return 1;
            }

            /** @param array{limit?: array{0: int, 1: int}} $options */
            public function zRangeByScore(string $key, string $from, string $to, array $options = []): array
            {
                $fromScore = $from === '-inf' ? -INF : (float) $from;
                $toScore = $to === '+inf' ? INF : (float) $to;
                $members = [];
                foreach ($this->sortedZset($key) as $member => $score) {
                    if ($score >= $fromScore && $score <= $toScore) {
                        $members[] = $member;
                    }
                }

                return isset($options['limit'])
                    ? array_slice($members, $options['limit'][0], $options['limit'][1])
                    : $members;
            }

            public function zRange(string $key, int $start, int $end): array
            {
                $members = array_keys($this->sortedZset($key));
                $length = $end < 0 ? null : $end - $start + 1;

                return array_slice($members, $start, $length);
            }

            /** @param list<mixed> $args */
            public function eval(string $script, array $args, int $numKeys): array
            {
                $this->evalCalls[] = ['script' => $script, 'args' => $args, 'numKeys' => $numKeys];

                if (str_contains($script, "redis.call('GET', KEYS[1])") && $numKeys === 1) {
                    $key = (string) $args[0];
                    $expectedValue = (string) ($args[1] ?? '');
                    if (($this->values[$key] ?? null) === $expectedValue) {
                        $this->del($key);

                        return [1];
                    }

                    return [0];
                }

                if (str_contains($script, "local created = redis.call('SET', KEYS[1]")) {
                    return $this->atomicRecord($args, $numKeys);
                }

                if (str_contains($script, "redis.call('SETEX', KEYS[4]")) {
                    if ($numKeys !== 4) {
                        throw new \RuntimeException('The fake acknowledge script received an invalid key count.');
                    }

                    $this->zRem((string) $args[0], (string) $args[5]);
                    $this->zRem((string) $args[1], (string) $args[5]);
                    $this->del((string) $args[2]);
                    $this->keys[(string) $args[3]] = (int) $args[4];
                    $this->values[(string) $args[3]] = '1';

                    return ['acknowledged'];
                }

                $pending = (string) $args[0];
                $processing = (string) $args[1];
                $now = (float) $args[2];
                $limit = (int) $args[3];
                $deadline = (float) $args[4];

                foreach ($this->zRangeByScore($processing, '-inf', (string) $now) as $member) {
                    $this->zRem($processing, $member);
                    $this->zAdd($pending, $now, $member);
                }

                $claimed = [];
                foreach ($this->zRange($pending, 0, $limit - 1) as $member) {
                    if ($this->zRem($pending, $member) === 1) {
                        $this->zAdd($processing, $deadline, $member);
                        $claimed[] = $member;
                    }
                }

                return $claimed;
            }

            /** @param list<mixed> $args */
            private function atomicRecord(array $args, int $numKeys): array
            {
                if ($numKeys !== 5) {
                    throw new \RuntimeException('The fake record script received an invalid key count.');
                }

                $eventKey = (string) $args[0];
                $pending = (string) $args[1];
                $processing = (string) $args[2];
                $deadLetter = (string) $args[3];
                $acknowledged = (string) $args[4];
                $payload = (string) $args[5];
                $retention = (int) $args[6];
                $score = (float) $args[7];

                if (!isset($this->keys[$eventKey])) {
                    $this->keys[$eventKey] = $retention;
                    $this->values[$eventKey] = $payload;
                    if ($this->failNextAtomicRecordAfterSet) {
                        $this->failNextAtomicRecordAfterSet = false;
                        throw new \RuntimeException('Injected pending ZADD failure.');
                    }

                    $this->zAdd($pending, $score, $eventKey);

                    return ['created'];
                }

                if (isset($this->keys[$acknowledged])) {
                    return ['acknowledged'];
                }
                if ($this->zsetHas($pending, $eventKey)) {
                    return ['queued'];
                }
                if ($this->zsetHas($processing, $eventKey)) {
                    return ['processing'];
                }
                if ($this->zsetHas($deadLetter, $eventKey)) {
                    return ['dead-letter'];
                }

                $this->zAdd($pending, $score, $eventKey);

                return ['recovered'];
            }

            private function zsetHas(string $key, string $member): bool
            {
                return array_key_exists($member, $this->zsets[$key] ?? []);
            }

            /** @return array<string, float> */
            private function sortedZset(string $key): array
            {
                $members = $this->zsets[$key] ?? [];
                asort($members, SORT_NUMERIC);

                return $members;
            }
        }
    }
}
