<?php

namespace Fleetbase\FleetOps\Support\Rides;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Illuminate\Support\Collection;

/**
 * The chat between a rider and the driver of their ride: a core chat channel with just the two of
 * them. Its meta (`order_public_id`, `kind: group`) is how Navigator finds it as the order's
 * "Message customer" thread. The rider only ever writes as themselves.
 */
class RideChat
{
    public const MESSAGE_LIMIT = 200;

    /**
     * The ride's channel, opened on first use, with the rider and the current driver in it.
     * Null until a driver has accepted, or when either has no user to chat as.
     */
    public static function channel(Order $order): ?ChatChannel
    {
        $riderUser  = data_get($order, 'customer.user_uuid');
        $driverUser = data_get($order, 'driverAssigned.user_uuid');
        if (!$riderUser || !$driverUser) {
            return null;
        }

        return static::asUser($riderUser, function () use ($order, $riderUser, $driverUser) {
            $channel = $order->chat_channel_uuid ? ChatChannel::where('uuid', $order->chat_channel_uuid)->first() : null;
            if (!$channel) {
                // Creating the channel adds its creator, the rider, as a participant.
                $channel = ChatChannel::create([
                    'company_uuid'    => $order->company_uuid,
                    'created_by_uuid' => $riderUser,
                    'name'            => 'Ride ' . $order->public_id,
                    'meta'            => ['order_public_id' => $order->public_id, 'kind' => 'group'],
                ]);
                $order->forceFill(['chat_channel_uuid' => $channel->uuid])->saveQuietly();
            }

            static::participant($channel, $riderUser, 'customer');
            static::participant($channel, $driverUser, 'driver');

            return $channel;
        });
    }

    /**
     * Messages oldest first, each marked as the rider's or the driver's.
     */
    public static function messages(ChatChannel $channel, string $riderUser): Collection
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
                'from'    => data_get($message, 'sender.user_uuid') === $riderUser ? 'rider' : 'driver',
                'text'    => (string) $message->content,
                'sent_at' => $message->created_at?->toISOString(),
            ]);
    }

    /**
     * Send a message as the rider, and tell the driver.
     */
    public static function send(ChatChannel $channel, string $riderUser, string $text): ChatMessage
    {
        return static::asUser($riderUser, function () use ($channel, $riderUser, $text) {
            $sender  = static::participant($channel, $riderUser, 'customer');
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
     * Core's chat logs record who added a participant from the signed-in user, which on a rider
     * request is the key's owner rather than the rider, so the rider is signed in for the change.
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
