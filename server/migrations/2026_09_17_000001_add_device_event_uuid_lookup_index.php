<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const INDEX = 'device_events_uuid_lookup_index';

    public function up(): void
    {
        if (!Schema::hasTable('device_events') || !Schema::hasColumn('device_events', 'uuid')) {
            return;
        }

        $indexes = $this->indexes();
        foreach ($indexes as $index) {
            // A primary, unique, or composite index with uuid first already
            // supports Eloquent's uuid lookup, regardless of the index name.
            if ($index['usable'] && ($index['columns'][0] ?? null) === 'uuid') {
                return;
            }
        }

        if (isset($indexes[self::INDEX])) {
            throw new RuntimeException('The device event UUID lookup index name is already used by another index.');
        }

        // The legacy table uses an integer primary key, while DeviceEvent uses
        // uuid. In particular, activity logging refreshes every saved event by
        // uuid. Keep this non-unique to preserve legacy nullable/duplicate data.
        Schema::table('device_events', fn (Blueprint $table) => $table->index('uuid', self::INDEX));
    }

    public function down(): void
    {
        if (!Schema::hasTable('device_events')) {
            return;
        }

        $index = $this->indexes()[self::INDEX] ?? null;
        if ($index && $index['columns'] === ['uuid']) {
            Schema::table('device_events', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }

    /**
     * Inspect indexes without relying on Schema::getIndexes, which is absent
     * in Laravel 9 and early Laravel 10. Fleet-Ops uses MySQL in deployments
     * and SQLite for its migration tests.
     *
     * @return array<string, array{columns: array, usable: bool}>
     */
    private function indexes(): array
    {
        $connection = Schema::getConnection();
        $table      = $connection->getTablePrefix() . 'device_events';
        $indexes    = [];

        if ($connection->getDriverName() === 'sqlite') {
            $table = str_replace('"', '""', $table);
            foreach ($connection->select('PRAGMA index_list("' . $table . '")') as $index) {
                $name    = str_replace('"', '""', $index->name);
                $columns = $connection->select('PRAGMA index_info("' . $name . '")');
                usort($columns, fn ($a, $b) => $a->seqno <=> $b->seqno);
                $indexes[$index->name] = ['columns' => array_column($columns, 'name'), 'usable' => !($index->partial ?? false)];
            }

            return $indexes;
        }

        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Device event UUID index inspection requires MySQL or SQLite.');
        }

        $table = str_replace('`', '``', $table);
        foreach ($connection->select('SHOW INDEX FROM `' . $table . '`') as $index) {
            $indexes[$index->Key_name]['columns'][(int) $index->Seq_in_index - 1] = $index->Column_name;
            $indexes[$index->Key_name]['usable']                                  = in_array(strtoupper($index->Index_type), ['BTREE', 'HASH'], true)
                && strtoupper($index->Visible ?? 'YES') !== 'NO'
                && strtoupper($index->Ignored ?? 'NO') !== 'YES';
        }
        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }

        return $indexes;
    }
};
