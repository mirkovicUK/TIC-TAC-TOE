<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Games\SweepExpiredGames;
use Illuminate\Console\Command;

/**
 * `php artisan games:sweep` — the command Requirement 13.3 asks for, and the
 * production means of deleting Games that are Eligible_For_Expiry (Req 12.12,
 * ADR-007).
 *
 * The whole of the work is `SweepExpiredGames`; this class exists to be the entry
 * point the host crontab invokes and to print the counts. No schedule is declared
 * here: the cadence lives in the crontab on the instance so the deliverable runs no
 * scheduler process (ADR-007).
 *
 * The class is discovered by `ApplicationBuilder::withCommands()`, which
 * `Application::configure()` calls with no arguments and which therefore scans
 * `app/Console/Commands` — so there is nothing to register in `bootstrap/app.php`
 * or `routes/console.php`. It is the framework default; this project had no
 * class-based command before it.
 *
 * Nothing is caught. A failed sweep must leave the transaction rolled back and the
 * process exiting non-zero, so the exception is left to the framework's handler,
 * which prints it and returns 1. A `try`/`catch` returning `SUCCESS`, or reporting a
 * partial count, would hide the one failure mode the `ON DELETE RESTRICT` on
 * `games.rematch_of_game_id` was chosen to make loud.
 */
final class SweepGamesCommand extends Command
{
    /** @var string */
    protected $signature = 'games:sweep';

    /** @var string */
    protected $description = 'Delete Games that are eligible for expiry, retaining an expiry record for each';

    /**
     * A run that finds nothing eligible is a success with three zeroes, not a
     * failure: the thresholds are lower bounds on retention (Req 13.5), so an empty
     * sweep is the ordinary case on a quiet day.
     */
    public function handle(SweepExpiredGames $sweep): int
    {
        $report = $sweep->handle();

        $this->line(sprintf('Games deleted: %d', $report->gamesDeleted));

        // Reported because it is the one outcome an operator cannot see afterwards:
        // a deferred Game is eligible, still present, and carries no record of why.
        $this->line(sprintf('Games deferred (a rematch survives): %d', $report->gamesDeferred));

        $this->line(sprintf('Expiry records purged: %d', $report->recordsPurged));

        return self::SUCCESS;
    }
}
