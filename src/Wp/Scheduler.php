<?php
namespace Dosya\Backup\Wp;

use Dosya\Backup\Jobs\StepRunner;
use Dosya\Backup\Support\Budget;

final class Scheduler
{
    public const STEP = 'dosya_backup_step';
    public const SCHEDULED = 'dosya_backup_scheduled';
    public const GROUP = 'dosya-backup';

    public static function register(): void
    {
        add_action(self::STEP, [self::class, 'runStep']);
        add_action(self::SCHEDULED, [self::class, 'runScheduled']);
    }

    public static function kick(string $jobId, int $delay = 0): void
    {
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }
        if ($delay > 0) {
            as_schedule_single_action(time() + $delay, self::STEP, [$jobId], self::GROUP);
        } else {
            as_enqueue_async_action(self::STEP, [$jobId], self::GROUP);
        }
    }

    public static function runStep(string $jobId): void
    {
        try {
            $r = Plugin::runner()->step($jobId, Budget::forRequest());
        } catch (\InvalidArgumentException $e) {
            // unknown job, for example a queued row that travelled in a migrated database
            return;
        }
        if (StepRunner::isTerminal($r['state'])) {
            Mailer::afterStep($r);
        }
        if ($r['state'] === 'busy') {
            self::kick($jobId, 30);
        } elseif (!StepRunner::isTerminal($r['state'])) {
            self::kick($jobId);
        }
    }

    public static function runScheduled(): void
    {
        try {
            Plugin::startBackup('full', true, '', '');
        } catch (\RuntimeException $e) {
            // a job is already running: this slot is skipped, the next one runs
        }
    }

    public static function sync(array $settings): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }
        as_unschedule_all_actions(self::SCHEDULED, [], self::GROUP);
        if ($settings['schedule'] === 'off') {
            return;
        }
        $interval = $settings['schedule'] === 'weekly' ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
        $time = isset($settings['time']) && is_string($settings['time']) && preg_match(SettingsSchema::TIME_RE, $settings['time']) ? $settings['time'] : '03:00';
        $first = new \DateTimeImmutable('today ' . $time, wp_timezone());
        if ($first->getTimestamp() <= time()) {
            $first = $first->modify('+1 day');
        }
        as_schedule_recurring_action($first->getTimestamp(), $interval, self::SCHEDULED, [], self::GROUP);
    }

    public static function nextRun(): ?int
    {
        if (!function_exists('as_next_scheduled_action')) {
            return null;
        }
        $ts = as_next_scheduled_action(self::SCHEDULED, [], self::GROUP);
        return is_int($ts) ? $ts : null;
    }
}
