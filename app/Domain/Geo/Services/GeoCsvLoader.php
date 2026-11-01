<?php

declare(strict_types=1);

namespace App\Domain\Geo\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GeoCsvLoader
{
    /**
     * Upserts the four CSV files by id, parents first. Safe to run again.
     */
    public function load(string $directory): void
    {
        DB::transaction(function () use ($directory): void {
            $this->upsert('geo_divisions', $directory.'/divisions.csv', ['id', 'name_en', 'name_bn']);
            $this->upsert('geo_districts', $directory.'/districts.csv', ['id', 'division_id', 'name_en', 'name_bn']);
            $this->upsert('geo_upazilas', $directory.'/upazilas.csv', ['id', 'district_id', 'name_en', 'name_bn']);
            $this->upsert('geo_unions', $directory.'/unions.csv', ['id', 'upazila_id', 'name_en', 'name_bn']);
        });
    }

    /**
     * @param  list<string>  $columns  the leading CSV columns, in file order
     */
    private function upsert(string $table, string $path, array $columns): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $batch = [];

        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($row === [null]) {
                    continue;
                }

                $record = [];

                foreach ($columns as $index => $column) {
                    $record[$column] = str_starts_with($column, 'name_') ? (string) $row[$index] : (int) $row[$index];
                }

                $batch[] = $record;

                if (count($batch) === 500) {
                    DB::table($table)->upsert($batch, ['id'], array_slice($columns, 1));
                    $batch = [];
                }
            }
        } finally {
            fclose($handle);
        }

        if ($batch !== []) {
            DB::table($table)->upsert($batch, ['id'], array_slice($columns, 1));
        }
    }
}
