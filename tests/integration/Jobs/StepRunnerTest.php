<?php
namespace Dosya\Backup\Tests\integration\Jobs;

use Dosya\Backup\Db\MysqliDb;
use Dosya\Backup\Jobs\JobStore;
use Dosya\Backup\Jobs\Steppable;
use Dosya\Backup\Jobs\StepRunner;
use Dosya\Backup\Support\Budget;
use Dosya\Backup\Tests\integration\DbTestCase;

final class StepRunnerTest extends DbTestCase
{
    private function counter(): Steppable
    {
        return new class implements Steppable {
            public function step(string $state, array $c, Budget $b): array
            {
                $c['n'] = ($c['n'] ?? 0) + 1;
                if ($c['n'] === 2 && !empty($c['explode'])) {
                    throw new \RuntimeException('boom');
                }
                return ['state' => $c['n'] >= 3 ? 'done' : 'running', 'cursor' => $c, 'result' => $c['n'] >= 3 ? ['ok' => true] : null];
            }
        };
    }

    public function testRunsStepsUntilDone(): void
    {
        $jobs = new JobStore($this->db);
        $jobs->install();
        $id = $jobs->create('backup', []);
        $runner = new StepRunner($this->db, $jobs, function (): Steppable { return $this->counter(); });
        $this->assertSame('running', $runner->step($id, Budget::unlimited())['state']);
        $this->assertSame('running', $runner->step($id, Budget::unlimited())['state']);
        $last = $runner->step($id, Budget::unlimited());
        $this->assertSame('done', $last['state']);
        $this->assertSame(['ok' => true], $last['result']);
        $this->assertSame('done', $runner->step($id, Budget::unlimited())['state']);
    }

    public function testExceptionsFailTheJobWithTheMessage(): void
    {
        $jobs = new JobStore($this->db);
        $jobs->install();
        $id = $jobs->create('backup', ['explode' => true]);
        $runner = new StepRunner($this->db, $jobs, function (): Steppable { return $this->counter(); });
        $runner->step($id, Budget::unlimited());
        $r = $runner->step($id, Budget::unlimited());
        $this->assertSame('failed', $r['state']);
        $this->assertSame('boom', $r['error']);
    }

    public function testAJobLockedByAnotherConnectionIsBusy(): void
    {
        $jobs = new JobStore($this->db);
        $jobs->install();
        $id = $jobs->create('backup', []);
        $other = new MysqliDb((string) getenv('DOSYA_TEST_DB_HOST'), (string) getenv('DOSYA_TEST_DB_USER'), (string) getenv('DOSYA_TEST_DB_PASS'), (string) getenv('DOSYA_TEST_DB_NAME'), $this->prefix);
        $other->value("SELECT GET_LOCK('dosya_job_$id', 0)");
        $runner = new StepRunner($this->db, $jobs, function (): Steppable { return $this->counter(); });
        $this->assertSame('busy', $runner->step($id, Budget::unlimited())['state']);
        $other->value("SELECT RELEASE_LOCK('dosya_job_$id')");
        $this->assertSame('running', $runner->step($id, Budget::unlimited())['state']);
    }

    public function testCancelStopsANonTerminalJob(): void
    {
        $jobs = new JobStore($this->db);
        $jobs->install();
        $id = $jobs->create('backup', []);
        $this->assertTrue($jobs->cancel($id));
        $runner = new StepRunner($this->db, $jobs, function (): Steppable { return $this->counter(); });
        $this->assertSame('cancelled', $runner->step($id, Budget::unlimited())['state']);
        $this->assertFalse($jobs->cancel($id));
    }

    public function testACancelDuringAStepIsNotOverwritten(): void
    {
        $jobs = new JobStore($this->db);
        $jobs->install();
        $id = $jobs->create('backup', []);
        $runner = new StepRunner($this->db, $jobs, function () use ($jobs, $id): Steppable {
            return new class($jobs, $id) implements Steppable {
                private JobStore $jobs;
                private string $id;
                public function __construct(JobStore $jobs, string $id)
                {
                    $this->jobs = $jobs;
                    $this->id = $id;
                }
                public function step(string $state, array $c, Budget $b): array
                {
                    $this->jobs->cancel($this->id);
                    return ['state' => 'running', 'cursor' => $c];
                }
            };
        });
        $this->assertSame('cancelled', $runner->step($id, Budget::unlimited())['state']);
        $this->assertSame('cancelled', $jobs->get($id)['state']);
    }

    public function testAnUnencodableCursorFailsTheJobInsteadOfWedgingIt(): void
    {
        $jobs = new JobStore($this->db);
        $jobs->install();
        $id = $jobs->create('backup', []);
        $bad = new class implements Steppable {
            public function step(string $state, array $c, Budget $b): array
            {
                return ['state' => 'running', 'cursor' => ['w' => "caf\xE9", 'progress' => ['phase' => 'archive']]];
            }
        };
        $runner = new StepRunner($this->db, $jobs, function () use ($bad): Steppable { return $bad; });
        $r = $runner->step($id, Budget::unlimited());
        $this->assertSame('failed', $r['state']);
        $this->assertSame(['phase' => 'archive'], $r['progress']);
        $this->assertNotSame('', (string) $r['error']);
    }

    public function testNonUtf8ExceptionTextIsStored(): void
    {
        $jobs = new JobStore($this->db);
        $jobs->install();
        $id = $jobs->create('backup', []);
        $bad = new class implements Steppable {
            public function step(string $state, array $c, Budget $b): array
            {
                throw new \RuntimeException("Cannot write caf\xE9.txt");
            }
        };
        $runner = new StepRunner($this->db, $jobs, function () use ($bad): Steppable { return $bad; });
        $r = $runner->step($id, Budget::unlimited());
        $this->assertSame('failed', $r['state']);
        $this->assertSame('Cannot write caf?.txt', $r['error']);
    }
}
