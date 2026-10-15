<?php
namespace Dosya\Backup\Jobs;

use Dosya\Backup\Db\Db;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Support\Text;

final class StepRunner
{
    public const TERMINAL = ['done', 'failed', 'skipped', 'cancelled'];

    private Db $db;
    private JobStore $jobs;
    /** @var callable */
    private $factory;

    public function __construct(Db $db, JobStore $jobs, callable $factory)
    {
        $this->db = $db;
        $this->jobs = $jobs;
        $this->factory = $factory;
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    public function step(string $jobId, Budget $budget): array
    {
        $job = $this->jobs->get($jobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Unknown job');
        }
        if (self::isTerminal($job['state'])) {
            return $this->status($job);
        }
        $lock = $this->db->quote('dosya_job_' . $jobId);
        if ((int) $this->db->value("SELECT GET_LOCK($lock, 0)") !== 1) {
            return ['id' => $jobId, 'state' => 'busy', 'progress' => $job['cursor']['progress'] ?? null, 'error' => null, 'result' => null];
        }
        try {
            $job = $this->jobs->get($jobId);
            if (self::isTerminal($job['state'])) {
                return $this->status($job);
            }
            try {
                $r = ($this->factory)($job)->step($job['state'], $job['cursor'], $budget);
            } catch (\Throwable $e) {
                $r = ['state' => 'failed', 'cursor' => $job['cursor'], 'error' => Text::utf8($e->getMessage())];
            }
            try {
                $this->jobs->save($jobId, $r['state'], $r['cursor'], $r['error'] ?? null, $r['result'] ?? null);
            } catch (\Throwable $e) {
                // an un-encodable cursor (for example invalid UTF-8) must not wedge the job: fail it with what can be stored
                $this->jobs->save($jobId, 'failed', ['progress' => $r['cursor']['progress'] ?? ($job['cursor']['progress'] ?? null)],
                    Text::utf8($e->getMessage()), null);
            }
            return $this->status($this->jobs->get($jobId));
        } finally {
            $this->db->value("SELECT RELEASE_LOCK($lock)");
        }
    }

    private function status(array $job): array
    {
        return ['id' => $job['id'], 'state' => $job['state'], 'progress' => $job['cursor']['progress'] ?? null,
            'error' => $job['error'], 'result' => $job['result']];
    }
}
