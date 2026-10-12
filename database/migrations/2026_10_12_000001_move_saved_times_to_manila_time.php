<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The app ran on UTC while the shop is in the Philippines (UTC+8), so every time saved so far is 8
// hours behind. It now runs on Manila time (config/app.php); this moves the times already saved
// forward 8 hours to match: sales, batches, alerts and the rest.
// A fresh install has nothing saved in UTC (it has no accounts yet while migrating), so it is skipped.
// Dates without a time (a batch's received date) stay as they are.
return new class extends Migration {
    private const HOURS = 8;
    private const SKIP = ['migrations', 'failed_jobs', 'password_resets', 'password_reset_tokens', 'personal_access_tokens',
        'sessions', 'jobs', 'job_batches', 'cache', 'cache_locks'];

    public function up(): void {
        $this->shift(self::HOURS);
    }

    public function down(): void {
        $this->shift(-self::HOURS);
    }

    private function shift(int $hours): void
    {
        if (DB::getDriverName() !== 'mysql' || ! $this->hasEarlierData()) {
            return;
        }

        $columns = collect(DB::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('timestamp', 'datetime')"))
            ->reject(fn ($col) => in_array($col->t, self::SKIP, true))
            ->groupBy('t');

        foreach ($columns as $table => $cols) {
            // One statement per table, every time column set in it: a column that changes itself on any
            // update (ON UPDATE CURRENT_TIMESTAMP) takes the value given instead.
            $set = $cols->map(fn ($col) => "`{$col->c}` = DATE_ADD(`{$col->c}`, INTERVAL {$hours} HOUR)")->implode(', ');
            DB::statement("UPDATE `{$table}` SET {$set}");
        }
    }

    // Accounts exist only in an install that has been used (no migration adds any; seeding comes
    // after migrating). A fresh install, or the tests, has saved nothing on UTC.
    private function hasEarlierData(): bool
    {
        return DB::table('users')->exists();
    }
};
