<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Community;
use App\Models\WhatsappSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommunityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $communities = Community::with(['groups:id,community_id,chat_name,is_pinned,last_message_at,unread_count,whatsapp_session_id'])
            ->where('is_archived', false)
            ->orderByDesc('id')
            ->get(['id', 'whatsapp_session_id', 'name', 'description', 'avatar_path', 'created_at']);

        return response()->json([
            'communities' => $communities,
        ]);
    }

    public function show(Request $request, Community $community): JsonResponse
    {
        $community->load([
            'groups:id,community_id,chat_name,is_pinned,last_message_at,last_message_text,unread_count,whatsapp_session_id',
            'whatsappSession:id,session_name,display_name',
        ]);

        return response()->json([
            'community' => $community,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:2048',
            'whatsapp_session_id' => 'required|integer|exists:whatsapp_sessions,id',
        ]);

        $session = WhatsappSession::findOrFail($data['whatsapp_session_id']);
        $user = $request->user();

        $community = Community::create([
            'whatsapp_session_id' => $session->id,
            'created_by' => $user->id,
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'community' => $community->load('groups'),
        ], 201);
    }

    public function update(Request $request, Community $community): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:2048',
        ]);

        $community->update([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'community' => $community->fresh('groups'),
        ]);
    }

    public function destroy(Community $community): JsonResponse
    {
        // Отвязываем группы от сообщества, затем удаляем само сообщество.
        Chat::where('community_id', $community->id)->update(['community_id' => null]);
        $community->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Привязать уже существующую группу к сообществу.
     */
    public function linkGroup(Request $request, Community $community): JsonResponse
    {
        $data = $request->validate([
            'chat_id' => 'required|integer|exists:chats,id',
        ]);

        $chat = Chat::findOrFail($data['chat_id']);

        if (! $chat->is_group) {
            return response()->json([
                'success' => false,
                'error' => 'Можно добавить только групповой чат.',
            ], 422);
        }

        if ($chat->whatsapp_session_id !== $community->whatsapp_session_id) {
            return response()->json([
                'success' => false,
                'error' => 'Группа принадлежит другому WhatsApp-номеру.',
            ], 422);
        }

        $chat->update(['community_id' => $community->id]);

        return response()->json([
            'success' => true,
            'chat_id' => $chat->id,
        ]);
    }

    /**
     * Отвязать группу от сообщества.
     */
    public function unlinkGroup(Request $request, Community $community, Chat $chat): JsonResponse
    {
        if ($chat->community_id !== $community->id) {
            return response()->json([
                'success' => false,
                'error' => 'Группа не входит в это сообщество.',
            ], 422);
        }

        $chat->update(['community_id' => null]);

        return response()->json(['success' => true]);
    }

    /**
     * Список групп, доступных для добавления в сообщество
     * (групповые чаты той же WhatsApp-сессии, ещё не привязанные к этому сообществу).
     */
    public function availableGroups(Request $request, Community $community): JsonResponse
    {
        $groups = Chat::where('is_group', true)
            ->where('whatsapp_session_id', $community->whatsapp_session_id)
            ->where(function ($q) use ($community) {
                $q->whereNull('community_id')
                    ->orWhere('community_id', '!=', $community->id);
            })
            ->orderBy('chat_name')
            ->get(['id', 'chat_name', 'community_id', 'last_message_text']);

        return response()->json(['groups' => $groups]);
    }
}
