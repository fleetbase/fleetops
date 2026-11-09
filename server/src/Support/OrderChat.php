<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Illuminate\Support\Collection;

/**
 * The chat between an order's customer and its driver: a core chat channel with just the two of
 * them. Its meta (`order_public_id`, `kind: group`) is how Navigator finds it as the order's
 * "Message customer" thread. The customer only ever writes as themselves.
 */
class OrderChat
{
    public const MESSAGE_LIMIT = 200;

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
