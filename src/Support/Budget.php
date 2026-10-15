<?php
namespace Dosya\Backup\Support;

final class Budget
{
    private float $deadline;
    private int $memCeiling;
    /** @var callable */
    private $now;

    public function __construct(float $seconds, int $memCeilingBytes, ?callable $now = null)
    {
        $this->now = $now ?? static function (): float { return microtime(true); };
        $this->deadline = ($this->now)() + $seconds;
        $this->memCeiling = $memCeilingBytes;
    }

    public static function forRequest(): self
    {
        $max = (int) ini_get('max_execution_time');
        $seconds = $max <= 0 ? 25.0 : (float) max(3, min(15, $max - 5));
        $limit = self::memoryLimitBytes();
        return new self($seconds, $limit > 0 ? (int) ($limit * 0.7) : 0);
    }

    public static function unlimited(): self
    {
        return new self(1.0e9, 0);
    }

    public function exhausted(): bool
    {
        if (($this->now)() >= $this->deadline) {
            return true;
        }
        return $this->memCeiling > 0 && memory_get_usage(true) >= $this->memCeiling;
    }

    public static function memoryLimitBytes(?string $ini = null): int
    {
        $v = trim($ini ?? (string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        switch (strtoupper(substr($v, -1))) {
            case 'G': return $n * 1024 * 1024 * 1024;
            case 'M': return $n * 1024 * 1024;
            case 'K': return $n * 1024;
        }
        return $n;
    }
}
