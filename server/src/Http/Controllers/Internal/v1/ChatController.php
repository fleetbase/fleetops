<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\User;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\FleetOps\Http\Requests\CreateChatChannelRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateChatChannelRequest;
use Illuminate\Http\Request;



/**
 * Class ChatController.
 */
class ChatController extends Controller
{

    /**
     * Create a new chat channel.
     */
    public function create(CreateChatChannelRequest $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $chatChannel = new ChatChannel();
        $chatChannel->name = $request->input('name');
        
        $chatChannel->save();

        return response()->json([
            'success' => true,
            'chat_channel' => $chatChannel->toArray($request)
        ]);
    }

    /**
     * Update chat channel.
     */
    public function updateChatChannel(UpdateChatChannelRequest $request, $id)
    {
        $chatChannelRecord = ChatChannel::findOrFail($id);
        
        $chatChannelRecord->update($request->all());
        
        return response()->json([
            'success' => true,
            'chatChannel' => $chatChannelRecord
        ]);
    }

    /**
     * Delete chat channel.
     */
    public function deleteChatChannel($id)
    {
        $chatChannelRecord = ChatChannel::findOrFail($id);
        
        $chatChannelRecord->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Chat channel deleted successfully'
        ]);
    }

    /**
     * Add participant.
     */
    public function addParticipant(Request $request)
    {
    
        $chatChannelId = $request->input('chat_channel_uuid');
        $userId = $request->input('user_uuid');


        $chatChannelRecord = ChatChannel::find($chatChannelId);
        $userRecord = User::find($userId);

    
        $chatParticipant = new ChatParticipant();
        $chatParticipant->chat_channel_id = $chatChannelRecord->id;
        $chatParticipant->user_id = $userRecord->id;

        $chatParticipant->save();

        return response()->json([
            'success' => true,
            'chatParticipant' => $chatParticipant,
            'chatChannel' => $chatChannelRecord
        ], 201);
    }

    /**
     * Remove participant.
     */
    public function removeParticipant(Request $request, $chatChannelId, $userId)
    {

        $chatParticipant = ChatParticipant::where('chat_channel_id', $chatChannelId)
                                          ->where('user_id', $userId)
                                          ->firstOrFail();
        
        $chatParticipant->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Participant removed from chat channel successfully'
        ]);
    }

}