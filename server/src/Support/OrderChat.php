<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The chat between an order's customer and its driver: a core chat channel with just the two of
 * them. Its meta (`order_public_id`, `kind: group`) is how Navigator finds it as the order's
 * "Message customer" thread. The customer only ever writes as themselves.
 *
 * And the chat between an order's driver and dispatch (`kind: driver`, the same tag Storefront
 * gives its store-and-driver thread, so an order has one such thread whichever side opened it):
 * see `openDispatch()`.
 */
class OrderChat
{
    public const MESSAGE_LIMIT = 200;

    /** `meta.kind` of the driver-and-dispatch thread; Navigator reads it as "Message dispatch". */
    public const KIND_DISPATCH = 'driver';

    /** Holding either of these is what makes a console user "dispatch" for an order. */
    public const DISPATCH_PERMISSIONS = ['fleet-ops dispatch order', 'fleet-ops * order'];

    /** The most console users put into a new dispatch thread. */
    public const DISPATCH_STAFF_LIMIT = 20;

    /**
     * The order's thread between its driver and dispatch, if one was started.
     */
    public static function findDispatch(Order $order): ?ChatChannel
    {
        return ChatChannel::where('company_uuid', $order->company_uuid)
            ->where('meta->order_public_id', $order->public_id)
            ->where('meta->kind', self::KIND_DISPATCH)
            ->oldest()
            ->first();
    }

    /**
     * The order's thread between its driver and dispatch, started when needed. Asking twice
     * gives the same channel.
     *
     * The driver is always in it. The organisation's side is put in when the thread is made
     * (and again only if nobody from the organisation is left in it): see `dispatchStaff()`.
     * Anyone else from the console who writes in the thread later joins it then, as Storefront's
     * staff do. A driver the order was taken away from leaves the thread.
     */
    public static function openDispatch(Order $order, string $driverUser): ChatChannel
    {
        $open = fn () => static::asUser($driverUser, function () use ($order, $driverUser) {
            $channel = static::findDispatch($order);
            $created = false;
            if (!$channel) {
                $tracking = data_get($order, 'trackingNumber.tracking_number') ?: $order->public_id;
                // Creating the channel adds its creator, the driver, as a participant.
                $channel = ChatChannel::create([
                    'company_uuid'    => $order->company_uuid,
                    'created_by_uuid' => $driverUser,
                    'name'            => 'Order ' . $tracking . ' · Dispatch',
                    'meta'            => [
                        'order_public_id' => $order->public_id,
                        'order_uuid'      => $order->uuid,
                        'tracking_number' => $tracking,
                        'kind'            => self::KIND_DISPATCH,
                    ],
                ]);
                $created = true;
            }

            $participants = ChatParticipant::where('chat_channel_uuid', $channel->uuid)->get();

            // A reassigned order's thread moves to the new driver.
            foreach ($participants as $participant) {
                if ($participant->user_uuid !== $driverUser && static::isDriverUser($participant->user_uuid)) {
                    $participant->delete();
                }
            }

            $driver = static::participant($channel, $driverUser, 'driver');
            if (!data_get($driver->meta, 'role')) {
                // The participant the channel made for its creator carries no role.
                $driver->meta = array_merge((array) ($driver->meta ?? []), ['role' => 'driver']);
                $driver->saveQuietly();
            }

            $hasStaff = $participants->contains(fn ($participant) => $participant->user_uuid !== $driverUser && !static::isDriverUser($participant->user_uuid));
            if ($created || !$hasStaff) {
                foreach (static::dispatchStaff($order, $driverUser) as $staffUser) {
                    static::participant($channel, $staffUser, 'store');
                }
            }

            return $channel;
        });

        // Two taps at once must not make two threads.
        try {
            return Cache::lock('fleetops:order-dispatch-chat:' . $order->uuid, 15)->block(5, $open);
        } catch (LockTimeoutException $e) {
            return $open();
        }
    }

    /**
     * Who "dispatch" is for an order, as user uuids:
     *
     *   1. whoever created the order and whoever last changed it, when they are console users of
     *      the organisation;
     *   2. the organisation's console users whose role or policies let them dispatch orders
     *      (`fleet-ops dispatch order`, which the DispatchManager policy and the Operations
     *      Manager role carry);
     *   3. when that names nobody, the organisation's owner.
     *
     * Drivers, customers and contacts are never dispatch. At most DISPATCH_STAFF_LIMIT users.
     *
     * @return array<int, string>
     */
    public static function dispatchStaff(Order $order, ?string $exceptUser = null): array
    {
        $staff = static::consoleUsers($order->company_uuid, array_filter([$order->created_by_uuid, $order->updated_by_uuid]));

        try {
            $capable = CompanyUser::where('company_uuid', $order->company_uuid)
                ->whereHas('user', fn ($query) => $query->where(fn ($query) => $query->whereNull('type')->orWhereNotIn('type', User::MANAGED_TYPES)))
                ->limit(200)
                ->get()
                ->filter(fn (CompanyUser $companyUser) => $companyUser->getAllPermissions()->pluck('name')->intersect(self::DISPATCH_PERMISSIONS)->isNotEmpty())
                ->pluck('user_uuid')
                ->all();
            $staff = array_merge($staff, $capable);
        } catch (\Throwable $e) {
            // Roles could not be read: the people on the order, or the owner, still stand.
        }

        $staff = array_values(array_unique(array_filter($staff, fn ($userUuid) => $userUuid && $userUuid !== $exceptUser && !static::isDriverUser($userUuid))));
        if (!$staff) {
            $owner = Company::where('uuid', $order->company_uuid)->value('owner_uuid');
            $staff = $owner && $owner !== $exceptUser ? static::consoleUsers($order->company_uuid, [$owner]) : [];
        }

        return array_slice($staff, 0, self::DISPATCH_STAFF_LIMIT);
    }

    /**
     * Those of the given users who are console users of the organisation, in the order given.
     *
     * @param array<int, string> $userUuids
     *
     * @return array<int, string>
     */
    protected static function consoleUsers(string $companyUuid, array $userUuids): array
    {
        if (!$userUuids) {
            return [];
        }

        $found = User::whereIn('uuid', $userUuids)
            ->where(fn ($query) => $query->whereNull('type')->orWhereNotIn('type', User::MANAGED_TYPES))
            ->where(function ($query) use ($companyUuid) {
                $query->where('company_uuid', $companyUuid)->orWhereHas('companyUsers', fn ($query) => $query->where('company_uuid', $companyUuid));
            })
            ->pluck('uuid')
            ->all();

        return array_values(array_filter($userUuids, fn ($userUuid) => in_array($userUuid, $found, true)));
    }

    /**
     * Whether a user is a driver. A plain query: the model's scopes would hide drivers of other
     * organisations.
     */
    protected static function isDriverUser(?string $userUuid): bool
    {
        if (!$userUuid) {
            return false;
        }

        $driver = new \Fleetbase\FleetOps\Models\Driver();

        return DB::connection($driver->getConnectionName())->table($driver->getTable())->where('user_uuid', $userUuid)->whereNull('deleted_at')->exists();
    }

    /**
     * The order's channel, opened on first use, with the customer and the current driver in it.
     * Null until a driver is assigned, or when either has no user to chat as.
     */
    public static function open(Order $order): ?ChatChannel
    {
        $customerUser = data_get($order, 'customer.user_uuid');
        $driverUser   = data_get($order, 'driverAssigned.user_uuid');
        if (!$customerUser || !$driverUser) {
            return null;
        }

        return static::asUser($customerUser, function () use ($order, $customerUser, $driverUser) {
            $channel = static::find($order);
            if (!$channel) {
                // Creating the channel adds its creator, the customer, as a participant.
                $channel = ChatChannel::create([
                    'company_uuid'    => $order->company_uuid,
                    'created_by_uuid' => $customerUser,
                    'name'            => 'Order ' . $order->public_id,
                    'meta'            => ['order_public_id' => $order->public_id, 'kind' => 'group'],
                ]);
                $order->forceFill(['chat_channel_uuid' => $channel->uuid])->saveQuietly();
            }

            static::participant($channel, $customerUser, 'customer');
            static::participant($channel, $driverUser, 'driver');

            return $channel;
        });
    }

    /**
     * The order's channel if it was opened.
     */
    public static function find(Order $order): ?ChatChannel
    {
        return $order->chat_channel_uuid ? ChatChannel::where('uuid', $order->chat_channel_uuid)->first() : null;
    }

    /**
     * Stop new messages in the order's channel. Its history stays readable.
     */
    public static function close(Order $order): bool
    {
        $channel = static::find($order);
        if (!$channel) {
            return false;
        }

        $channel->meta = array_merge((array) ($channel->meta ?? []), ['closed_at' => now()->toISOString()]);

        return $channel->save();
    }

    /**
     * No one can write: the channel was closed, or the order has finished.
     */
    public static function isClosed(Order $order, ?ChatChannel $channel = null): bool
    {
        $channel ??= static::find($order);

        return (bool) data_get($channel, 'meta.closed_at') || (bool) $order->config()?->isTerminalActivityCode($order->status);
    }

    /**
     * Messages oldest first, each marked as the customer's or the driver's.
     */
    public static function messages(ChatChannel $channel, string $customerUser): Collection
    {
        return ChatMessage::where('chat_channel_uuid', $channel->uuid)
            ->with('sender')
            ->latest()
            ->limit(self::MESSAGE_LIMIT)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (ChatMessage $message) => [
                'id'      => $message->public_id ?? $message->uuid,
                'from'    => data_get($message, 'sender.user_uuid') === $customerUser ? 'customer' : 'driver',
                'text'    => (string) $message->content,
                'sent_at' => $message->created_at?->toISOString(),
            ]);
    }

    /**
     * Send a message as the customer, and tell the driver.
     */
    public static function send(ChatChannel $channel, string $customerUser, string $text): ChatMessage
    {
        return static::asUser($customerUser, function () use ($channel, $customerUser, $text) {
            $sender  = static::participant($channel, $customerUser, 'customer');
            $message = ChatMessage::create([
                'company_uuid'      => $channel->company_uuid,
                'chat_channel_uuid' => $channel->uuid,
                'sender_uuid'       => $sender->uuid,
                'content'           => $text,
            ]);
            $message->notifyParticipants();

            return $message;
        });
    }

    /**
     * Core's chat logs record who added a participant from the signed-in user, which on a
     * customer request is the key's owner rather than the customer, so the customer is signed in
     * for the change.
     */
    protected static function asUser(string $userUuid, callable $callback): mixed
    {
        $previous = session('user');
        session(['user' => $userUuid]);

        try {
            return $callback();
        } finally {
            session(['user' => $previous]);
        }
    }

    protected static function participant(ChatChannel $channel, string $userUuid, string $role): ChatParticipant
    {
        return ChatParticipant::firstOrCreate(
            ['chat_channel_uuid' => $channel->uuid, 'user_uuid' => $userUuid],
            ['company_uuid' => $channel->company_uuid, 'meta' => ['role' => $role]]
        );
    }
}
