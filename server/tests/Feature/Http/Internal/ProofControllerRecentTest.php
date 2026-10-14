<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ProofController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\File;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;

/**
 * The real query over an in-memory database; only the signed file URL is stood in,
 * since File::url resolves a storage disk the harness does not boot.
 */
class FleetOpsRecentProofController extends ProofController
{
    protected function photoUrl(File $file): ?string
    {
        return 'https://cdn.test/' . $file->path;
    }
}

function fleetOpsRecentProofDatabase(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Dispatcher());
    EloquentModel::clearBootedModels();

    $config = new Repository([
        'activitylog' => ['enabled' => false, 'default_auth_driver' => null, 'default_log_name' => 'default'],
        'api'         => ['cache' => ['enabled' => false]],
        'filesystems' => ['default' => 'local'],
    ]);
    app()->instance('config', $config);
    app()->instance(Illuminate\Contracts\Config\Repository::class, $config);

    $common = ['uuid', 'public_id', '_key', 'company_uuid', 'meta', 'slug', 'internal_id'];
    $tables = [
        'proofs'   => ['file_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'remarks', 'raw_data', 'data'],
        'files'    => ['uploader_uuid', 'subject_uuid', 'subject_type', 'path', 'disk', 'bucket', 'type', 'content_type', 'original_filename'],
        'orders'   => ['customer_uuid', 'customer_type', 'status', 'type'],
        'contacts' => ['name', 'type'],
        'vendors'  => ['name', 'type'],
    ];

    $schema = $connection->getSchemaBuilder();
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($common, $columns) {
            $blueprint->increments('id');
            foreach (array_unique(array_merge($common, $columns)) as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    $connection->table('contacts')->insert(['uuid' => 'contact-ada', 'public_id' => 'contact_ada', 'company_uuid' => 'company-a', 'name' => 'Ada Lovelace', 'type' => 'customer']);
    $connection->table('vendors')->insert(['uuid' => 'vendor-acme', 'public_id' => 'vendor_acme', 'company_uuid' => 'company-a', 'name' => 'Acme Retail']);
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'company-a', 'customer_uuid' => 'contact-ada', 'customer_type' => Contact::class],
        ['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'company-a', 'customer_uuid' => 'vendor-acme', 'customer_type' => Vendor::class],
        ['uuid' => 'order-3', 'public_id' => 'order_3', 'company_uuid' => 'company-a', 'customer_uuid' => null, 'customer_type' => null],
        ['uuid' => 'order-x', 'public_id' => 'order_x', 'company_uuid' => 'company-b', 'customer_uuid' => null, 'customer_type' => null],
    ]);

    session(['company' => 'company-a']);

    return $connection;
}

function fleetOpsRecentProof(SQLiteConnection $connection, string $key, string $createdAt, array $proof = [], ?string $fileType = 'photo'): void
{
    $proof += ['company_uuid' => 'company-a', 'order_uuid' => 'order-1', 'remarks' => 'Verified by Photo'];

    if ($fileType) {
        $connection->table('files')->insert(['uuid' => "file-{$key}", 'public_id' => "file_{$key}", 'company_uuid' => $proof['company_uuid'], 'path' => "photos/{$key}.png", 'type' => $fileType]);
    }

    $connection->table('proofs')->insert($proof + [
        'uuid'       => "proof-{$key}",
        'public_id'  => "proof_{$key}",
        'file_uuid'  => $fileType ? "file-{$key}" : null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function fleetOpsRecentProofs(array $query = []): array
{
    return (new FleetOpsRecentProofController())->recent(Request::create('/int/v1/fleet-ops/proofs/recent', 'GET', $query))->getData(true)['proofs'];
}

test('recent proofs are the company photo proofs on orders, newest first, with their order and customer', function () {
    $db = fleetOpsRecentProofDatabase();

    fleetOpsRecentProof($db, 'old', '2026-10-01 08:00:00');
    fleetOpsRecentProof($db, 'new', '2026-10-03 08:00:00', ['order_uuid' => 'order-2', 'remarks' => 'Left at the door']);
    fleetOpsRecentProof($db, 'mid', '2026-10-02 08:00:00', ['order_uuid' => 'order-3']);
    // Not photos: a signature, a QR scan with no file, a photo on no order.
    fleetOpsRecentProof($db, 'signature', '2026-10-04 08:00:00', [], 'signature');
    fleetOpsRecentProof($db, 'scan', '2026-10-04 09:00:00', [], null);
    fleetOpsRecentProof($db, 'orderless', '2026-10-04 10:00:00', ['order_uuid' => null]);
    // Another company's photo.
    fleetOpsRecentProof($db, 'foreign', '2026-10-05 08:00:00', ['company_uuid' => 'company-b', 'order_uuid' => 'order-x']);

    $proofs = fleetOpsRecentProofs();

    expect(array_column($proofs, 'id'))->toBe(['proof_new', 'proof_mid', 'proof_old'])
        ->and($proofs[0])->toBe([
            'id'            => 'proof_new',
            'order_id'      => 'order_2',
            'customer_name' => 'Acme Retail',
            'remarks'       => 'Left at the door',
            'url'           => 'https://cdn.test/photos/new.png',
            'captured_at'   => '2026-10-03T08:00:00+00:00',
        ])
        ->and($proofs[1]['customer_name'])->toBeNull()
        ->and($proofs[2]['customer_name'])->toBe('Ada Lovelace')
        ->and($proofs[2]['order_id'])->toBe('order_1');
});

test('recent proofs default to 12 and cap the limit between 1 and 24', function () {
    $db = fleetOpsRecentProofDatabase();

    foreach (range(1, 30) as $index) {
        fleetOpsRecentProof($db, "p{$index}", sprintf('2026-10-01 08:%02d:00', $index));
    }

    expect(fleetOpsRecentProofs())->toHaveCount(12)
        ->and(fleetOpsRecentProofs(['limit' => 5]))->toHaveCount(5)
        ->and(fleetOpsRecentProofs(['limit' => 500]))->toHaveCount(ProofController::RECENT_LIMIT_MAX)
        ->and(fleetOpsRecentProofs(['limit' => 0]))->toHaveCount(1)
        ->and(fleetOpsRecentProofs(['limit' => 'abc']))->toHaveCount(1)
        ->and(fleetOpsRecentProofs(['limit' => 5])[0]['id'])->toBe('proof_p30');
});

test('recent proofs are empty when the session has no company', function () {
    $db = fleetOpsRecentProofDatabase();
    fleetOpsRecentProof($db, 'one', '2026-10-01 08:00:00');
    session(['company' => null]);

    expect(fleetOpsRecentProofs())->toBe([]);
});

test('the photo url is the file url, signed for private buckets', function () {
    $file = new class extends File {
        public function getUrlAttribute()
        {
            return 'https://signed.test/photo.png';
        }
    };

    $method = new ReflectionMethod(ProofController::class, 'photoUrl');
    $method->setAccessible(true);

    expect($method->invoke(new ProofController(), $file))->toBe('https://signed.test/photo.png');
});

test('the recent proofs route is registered with the proof routes', function () {
    $routes = file_get_contents(dirname(__DIR__, 4) . '/src/routes.php');

    expect($routes)->toContain("\$router->get('recent', \$controller('recent'));");
});
