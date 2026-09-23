<?php

namespace Fleetbase\FleetOps\Support\Database;

use Illuminate\Support\Facades\Schema;

/**
 * Inspect table indexes without Schema::getIndexes, which is absent in the
 * Laravel versions this package supports. Fleet-Ops deploys on MySQL and runs
 * its migration tests on SQLite.
 */
final class TableIndexes
{
    /**
     * @return array<string, string[]> index name => ordered column names
     */
    public static function for(string $table): array
    {
        $connection = Schema::getConnection();
        $prefixed   = $connection->getTablePrefix() . $table;
        $indexes    = [];

        if ($connection instanceof \Illuminate\Database\SQLiteConnection || $connection->getDriverName() === 'sqlite') {
            $quoted = str_replace('"', '""', $prefixed);
            foreach ($connection->select('PRAGMA index_list("' . $quoted . '")') as $index) {
                $name    = str_replace('"', '""', $index->name);
                $columns = $connection->select('PRAGMA index_info("' . $name . '")');
                usort($columns, fn ($a, $b) => $a->seqno <=> $b->seqno);
                $indexes[$index->name] = array_column($columns, 'name');
            }

            return $indexes;
        }

        // @codeCoverageIgnoreStart
        if ($connection->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Index inspection requires MySQL or SQLite.');
        }

        $quoted = str_replace('`', '``', $prefixed);
        foreach ($connection->select('SHOW INDEX FROM `' . $quoted . '`') as $index) {
            $indexes[$index->Key_name][(int) $index->Seq_in_index - 1] = $index->Column_name;
        }
        foreach ($indexes as &$columns) {
            ksort($columns);
            $columns = array_values($columns);
        }

        return $indexes;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Whether any index already leads with exactly these columns, in order.
     */
    public static function covers(string $table, array $columns): bool
    {
        foreach (self::for($table) as $indexed) {
            if (array_slice($indexed, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }
}
