<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PositionController;
use Fleetbase\Models\User;
use Fleetbase\Support\SocketCluster\ChannelAuthorizer;
use Fleetbase\Support\SocketCluster\ChannelDecision;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Fleetbase\TestSupport\DispatchRecorder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the positions/replay channel check: with socket authentication on, a replay may only
 * target a channel the requesting user could subscribe to; with it off the endpoint is unchanged.
 */
class PositionReplayChannelAuthorizerFake
{
    public array $checked = [];

    public function __construct(public array $allowed = [])
    {
    }

    public function authorize(?SocketPrincipal $principal, string $channel): ChannelDecision
    {
        $this->checked[] = [$principal, $channel];

        return in_array($channel, $this->allowed, true) ? ChannelDecision::allowed('resolver') : ChannelDecision::denied('forbidden');
    }
}

function positionReplayChannelBoot(bool $enabled): PositionReplayChannelAuthorizerFake
{
    config(['broadcasting.connections.socketcluster.auth_key' => $enabled ? 'fleetops-position-replay-test-key-0123456789' : null]);

    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    $schema = $connection->getSchemaBuilder();
    foreach (['positions' => ['uuid', 'company_uuid', 'subject_uuid', 'coordinates', 'speed'], 'companies' => ['uuid', 'public_id']] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_one']);
    $connection->table('positions')->insert(['uuid' => 'pos-1', 'company_uuid' => 'company-1', 'subject_uuid' => 'vehicle-1', 'created_at' => '2026-10-06 08:00:00']);

    session(['company' => 'company-1']);
    DispatchRecorder::$dispatched = [];

    $authorizer = new PositionReplayChannelAuthorizerFake(['vehicle.vehicle-1']);
    app()->instance(ChannelAuthorizer::class, $authorizer);

    return $authorizer;
}

function positionReplayChannelRequest(mixed $channel, ?User $user = null): Request
{
    $request = Request::create('/int/v1/positions/replay', 'POST', ['channel_id' => $channel, 'position_ids' => ['pos-1']]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function positionReplayChannelUser(): User
{
    $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
    $user->setRawAttributes(['uuid' => 'user-1', 'public_id' => 'user_one', 'company_uuid' => 'company-1', 'type' => 'user'], true);

    return $user;
}

afterEach(function () {
    config(['broadcasting.connections.socketcluster.auth_key' => null]);
});

test('a replay to a channel the user may subscribe to is started', function () {
    $authorizer = positionReplayChannelBoot(true);

    $response = (new PositionController())->replay(positionReplayChannelRequest('vehicle.vehicle-1', positionReplayChannelUser()));

    [$principal, $channel] = $authorizer->checked[0];
    expect($response->getData(true)['status'])->toBe('ok')
        ->and(DispatchRecorder::$dispatched)->toHaveCount(1)
        ->and($channel)->toBe('vehicle.vehicle-1')
        ->and($principal->kind)->toBe('user')
        ->and($principal->sub)->toBe('user-1')
        ->and($principal->cid)->toBe('company-1')
        ->and($principal->adm)->toBeFalse();
});

test('a replay to any other channel is refused before anything is queued', function () {
    $authorizer = positionReplayChannelBoot(true);
    $controller = new PositionController();
    $user       = positionReplayChannelUser();

    foreach ([
        positionReplayChannelRequest('chat_channel.someone-elses', $user),
        positionReplayChannelRequest('vehicle.vehicle-1', null),
        positionReplayChannelRequest('vehicle. vehicle-1', $user),
        positionReplayChannelRequest(str_repeat('x', 256), $user),
        positionReplayChannelRequest(['vehicle.vehicle-1'], $user),
    ] as $request) {
        $response = $controller->replay($request);
        expect($response->getStatusCode())->toBe(403)
            ->and($response->getData(true)['error'])->toContain('not allowed');
    }

    expect(DispatchRecorder::$dispatched)->toBe([])
        ->and(array_column($authorizer->checked, 1))->toBe(['chat_channel.someone-elses']);
});

test('without socket authentication the replay endpoint does not check the channel', function () {
    $authorizer = positionReplayChannelBoot(false);

    $response = (new PositionController())->replay(positionReplayChannelRequest('anything.at-all'));

    expect($response->getData(true)['status'])->toBe('ok')
        ->and($authorizer->checked)->toBe([]);
});
