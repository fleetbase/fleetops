<?php

if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request { public function resolveFilesFromIds() { return \Fleetbase\Models\File::whereIn(\'uuid\', (array) $this->input(\'files\'))->get(); } }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\IssueController;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the internal IssueController export and import endpoints: excel
 * download delegation with formatted file names, import iteration over
 * resolved files with counts, and the invalid-file error response.
 */
function fleetopsIssueExportBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    app()->instance('excel', new class {
        public array $downloads    = [];
        public array $imports      = [];
        public bool $throwOnImport = false;

        public function download($export, $fileName, $writerType = null, array $headers = [])
        {
            $this->downloads[] = $fileName;

            return response()->json(['download' => $fileName]);
        }

        public function import($import, $file, $disk = null, $readerType = null)
        {
            if ($this->throwOnImport) {
                throw new RuntimeException('bad spreadsheet');
            }

            $this->imports[] = $file;

            return $import;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    $GLOBALS['fleetopsIssueExcelFake'] = app('excel');
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');

    $schema = $connection->getSchemaBuilder();
    $schema->create('files', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'type', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);

    return $connection;
}

test('issue export streams a spreadsheet download', function () {
    fleetopsIssueExportBoot();

    $response = (new IssueController())->export(ExportRequest::create('/int/v1/issues/export', 'GET', ['format' => 'csv', 'selections' => ['issue-1']]));

    expect($response->getData(true)['download'])->toContain('.csv')
        ->and($GLOBALS['fleetopsIssueExcelFake']->downloads)->toHaveCount(1);
});

test('issue imports count processed files and reject invalid spreadsheets', function () {
    $connection = fleetopsIssueExportBoot();
    $connection->table('files')->insert(['uuid' => 'file-imp-1', 'company_uuid' => 'company-1', 'original_filename' => 'issues.csv', 'path' => 'imports/issues.csv', 'disk' => 'local']);

    $request = ImportRequest::create('/int/v1/issues/import', 'POST', ['files' => ['file-imp-1']]);

    $imported = (new IssueController())->import($request);
    expect($imported->getData(true)['status'] ?? '')->toBe('ok')
        ->and($GLOBALS['fleetopsIssueExcelFake']->imports)->toHaveCount(1);

    $GLOBALS['fleetopsIssueExcelFake']->throwOnImport = true;
    $failed                                           = (new IssueController())->import(ImportRequest::create('/int/v1/issues/import', 'POST', ['files' => ['file-imp-1']]));
    expect($failed->getData(true)['error'] ?? '')->toContain('Invalid file');
});
