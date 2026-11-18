<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Makes every Fleet-Ops column that a foreign key points at unique.
 *
 * MySQL 8.4 turns on `restrict_fk_on_non_standard_key` by default. With it on, MySQL refuses to
 * create a foreign key that points at a non-unique key, and refuses any ALTER of a table that such
 * a key points at ("1553 Cannot drop index '<unknown key name>'"). These columns only had an
 * ordinary index. Once they are unique, those tables can be altered with the setting left on.
 *
 * Each column gets a unique index named `<table>_<column>_unique`, and its old single-column index
 * is dropped, so the column isn't indexed twice. A column that a unique or primary key already
 * covers is left alone, and so is a table that holds duplicate values, which is reported instead.
 * Running it again changes nothing.
 */
return new class extends Migration {
    /**
     * Messages for the columns that were skipped, for the operator and for tests.
     *
     * @var array<int, string>
     */
    public array $skipped = [];

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public function targets(): array
    {
        return [
            ['devices', 'uuid'],
            ['inspection_forms', 'uuid'],
            ['inspection_submissions', 'uuid'],
            ['order_configs', 'uuid'],
            ['telematics', 'uuid'],
            ['warranties', 'uuid'],
            ['work_orders', 'uuid'],
        ];
    }

    public function up(): void
    {
        $this->withoutNonStandardKeyRestriction(function () {
            foreach ($this->targets() as [$table, $column]) {
                $this->makeUnique($table, $column);
            }
        });
    }

    public function down(): void
    {
        // Going back to a non-unique key is itself refused on MySQL 8.4 while the setting is on.
        $this->withoutNonStandardKeyRestriction(function () {
            foreach (array_reverse($this->targets()) as [$table, $column]) {
                $this->makeNonUnique($table, $column);
            }
        });
    }

    protected function makeUnique(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        $indexes = $this->singleColumnIndexes($table, $column);
        foreach ($indexes as $index) {
            if ($index['unique'] || $index['primary']) {
                return;
            }
        }

        if ($this->hasDuplicates($table, $column)) {
            $this->skip("Skipped {$table}.{$column}: it holds duplicate values, so it can't be made unique. Remove the duplicates, then run this migration again with `php artisan migrate:refresh --force {$this->pathOption()}` (add `--database=sandbox` for the sandbox).");

            return;
        }

        $unique = $this->uniqueName($table, $column);
        Schema::table($table, function (Blueprint $blueprint) use ($column, $unique, $indexes) {
            $blueprint->unique([$column], $unique);
            foreach ($indexes as $index) {
                $blueprint->dropIndex($index['name']);
            }
        });
    }

    protected function makeNonUnique(string $table, string $column): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        $unique = $this->uniqueName($table, $column);
        $names  = array_column($this->singleColumnIndexes($table, $column), 'name');
        if (!in_array($unique, $names, true)) {
            return;
        }

        // Add the plain index first, so the foreign keys always have an index to use.
        $plain = $table . '_' . $column . '_index';
        Schema::table($table, function (Blueprint $blueprint) use ($column, $unique, $plain, $names) {
            if (!in_array($plain, $names, true)) {
                $blueprint->index([$column], $plain);
            }
            $blueprint->dropUnique($unique);
        });
    }

    /**
     * @return array<int, array{name: string, unique: bool, primary: bool}>
     */
    protected function singleColumnIndexes(string $table, string $column): array
    {
        $matches = [];
        foreach (Schema::getIndexes($table) as $index) {
            if (array_map('strtolower', $index['columns']) === [strtolower($column)]) {
                $matches[] = ['name' => $index['name'], 'unique' => (bool) $index['unique'], 'primary' => (bool) $index['primary']];
            }
        }

        return $matches;
    }

    protected function hasDuplicates(string $table, string $column): bool
    {
        return Schema::getConnection()->table($table)
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('count(*) > 1')
            ->limit(1)
            ->exists();
    }

    /**
     * The `migrate --path` option for this file: relative to the app root when it is installed
     * under `vendor/`, otherwise its absolute path.
     */
    protected function pathOption(): string
    {
        $position = strpos(__FILE__, 'vendor' . DIRECTORY_SEPARATOR);

        return $position === false ? '--realpath --path=' . __FILE__ : '--path=' . substr(__FILE__, $position);
    }

    protected function uniqueName(string $table, string $column): string
    {
        return strtolower($table . '_' . $column . '_unique');
    }

    protected function skip(string $message): void
    {
        $this->skipped[] = $message;

        if (defined('STDERR') && PHP_SAPI === 'cli') {
            fwrite(STDERR, '  ' . $message . PHP_EOL);
        }

        try {
            Log::warning($message);
        } catch (Throwable $e) {
            // No logger bound; the console line above is enough.
        }
    }

    /**
     * Run `$callback` with `restrict_fk_on_non_standard_key` off for this session, then restore it.
     * Where the variable doesn't exist (MySQL before 8.4, MariaDB, SQLite), it just runs.
     */
    protected function withoutNonStandardKeyRestriction(callable $callback): void
    {
        $connection = Schema::getConnection();
        $setting    = $connection->getDriverName() === 'mysql'
            ? $connection->selectOne("show session variables like 'restrict_fk_on_non_standard_key'")
            : null;

        if (!$setting) {
            $callback();

            return;
        }

        $previous = strtoupper((string) ($setting->Value ?? $setting->value ?? 'ON')) === 'OFF' ? 'OFF' : 'ON';
        $connection->statement('set session restrict_fk_on_non_standard_key = OFF');

        try {
            $callback();
        } finally {
            $connection->statement("set session restrict_fk_on_non_standard_key = {$previous}");
        }
    }
};
