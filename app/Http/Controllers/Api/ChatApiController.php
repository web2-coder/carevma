<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChatApiController extends Controller
{
    private function normalizedRole(?string $role): string
    {
        return $role === 'doctor' ? 'client' : (string) $role;
    }

    private function canMessage(User $actor, User $target): bool
    {
        if ((int) $actor->id === (int) $target->id) {
            return false;
        }

        $actorRole = $this->normalizedRole($actor->role);
        $targetRole = $this->normalizedRole($target->role);

        // Admins can start a conversation with every CareVMA user.
        if (in_array($actorRole, ['admin', 'superadmin'], true)) {
            return true;
        }

        if ($actorRole === 'hr') {
            return in_array($targetRole, ['admin', 'superadmin', 'hr', 'employee', 'client'], true);
        }

        // Keep the existing employee/doctor privacy boundary: they can always
        // reach operations (Admin/HR). This can be widened later without a DB change.
        if (in_array($actorRole, ['employee', 'client'], true)) {
            return in_array($targetRole, ['admin', 'superadmin', 'hr'], true);
        }

        return false;
    }

    private function chatSchemaReady(): bool
    {
        foreach (['chat_conversations', 'chat_participants', 'chat_messages'] as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }

        $required = [
            'chat_participants' => ['conversation_id', 'user_id', 'last_read_at', 'created_at', 'updated_at'],
            'chat_messages' => ['conversation_id', 'sender_id', 'body', 'created_at', 'updated_at'],
        ];

        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function userPayload(User $user): array
    {
        $picture = $user->employee?->profile_picture
            ?: $user->client?->profile_picture
            ?: $user->getAttribute('profile_picture');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $this->normalizedRole($user->role),
            'profile_picture' => $picture,
            'online' => method_exists($user, 'isOnline') ? (bool) $user->isOnline() : false,
        ];
    }

    public function users(Request $request)
    {
        $me = $request->user();
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with(['employee', 'client'])
            ->where('id', '!=', $me->id)
            ->when($q, function ($query) use ($q) {
                $query->where(function ($search) use ($q) {
                    $search->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('role', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->filter(fn (User $user) => $this->canMessage($me, $user))
            ->values()
            ->map(fn (User $user) => $this->userPayload($user));

        return response()->json(['data' => $users]);
    }

    public function index(Request $request)
    {
        if (!$this->chatSchemaReady()) {
            return response()->json([
                'data' => [],
                'setup_required' => true,
                'message' => 'Chat database setup is incomplete. Run php artisan migrate once, then refresh Chat.',
            ]);
        }

        $me = $request->user();
        $rows = ChatConversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $me->id))
            ->with([
                'participants' => fn ($query) => $query->with(['employee', 'client']),
                'messages' => fn ($query) => $query->latest()->limit(1),
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (ChatConversation $conversation) use ($me) {
                $other = $conversation->participants->firstWhere('id', '!=', $me->id);
                $mine = $conversation->participants->firstWhere('id', $me->id);
                $lastReadAt = $mine?->pivot?->last_read_at;

                $unread = $conversation->messages()
                    ->where('sender_id', '!=', $me->id)
                    ->when($lastReadAt, fn ($query, $value) => $query->where('created_at', '>', $value))
                    ->count();

                return [
                    'id' => $conversation->id,
                    'user' => $other ? $this->userPayload($other) : null,
                    'last_message' => optional($conversation->messages->first())->body,
                    'updated_at' => $conversation->updated_at,
                    'unread' => $unread,
                ];
            })
            ->values();

        return response()->json(['data' => $rows, 'setup_required' => false]);
    }

    public function start(Request $request)
    {
        if (!$this->chatSchemaReady()) {
            return response()->json([
                'message' => 'Chat database setup is incomplete. Run php artisan migrate once, then refresh Chat.',
                'code' => 'CHAT_SCHEMA_NOT_READY',
            ], 503);
        }

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $me = $request->user();
        $target = User::findOrFail($data['user_id']);

        abort_unless($this->canMessage($me, $target), 403, 'You are not allowed to message this user.');

        $existing = ChatConversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $me->id))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $target->id))
            ->withCount('participants')
            ->get()
            ->firstWhere('participants_count', 2);

        if ($existing) {
            return response()->json(['id' => $existing->id]);
        }

        $conversation = DB::transaction(function () use ($me, $target) {
            $conversation = ChatConversation::create();
            $conversation->participants()->attach([$me->id, $target->id]);
            return $conversation;
        });

        return response()->json(['id' => $conversation->id], 201);
    }

    private function authorizeConversation(Request $request, ChatConversation $conversation): void
    {
        abort_unless(
            $conversation->participants()->where('users.id', $request->user()->id)->exists(),
            403
        );
    }

    public function messages(Request $request, ChatConversation $conversation)
    {
        if (!$this->chatSchemaReady()) {
            return response()->json([
                'data' => [],
                'setup_required' => true,
                'message' => 'Chat database setup is incomplete. Run php artisan migrate once, then refresh Chat.',
            ]);
        }

        $this->authorizeConversation($request, $conversation);

        DB::table('chat_participants')
            ->where([
                'conversation_id' => $conversation->id,
                'user_id' => $request->user()->id,
            ])
            ->update(['last_read_at' => now(), 'updated_at' => now()]);

        $rows = $conversation->messages()
            ->with('sender:id,name,role')
            ->latest()
            ->limit(200)
            ->get()
            ->reverse()
            ->values();

        return response()->json(['data' => $rows, 'setup_required' => false]);
    }

    public function send(Request $request, ChatConversation $conversation)
    {
        if (!$this->chatSchemaReady()) {
            return response()->json([
                'message' => 'Chat database setup is incomplete. Run php artisan migrate once, then refresh Chat.',
                'code' => 'CHAT_SCHEMA_NOT_READY',
            ], 503);
        }

        $this->authorizeConversation($request, $conversation);
        $data = $request->validate(['body' => 'required|string|max:5000']);

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => trim($data['body']),
        ]);
        $conversation->touch();

        return response()->json([
            'data' => $message->load('sender:id,name,role'),
        ], 201);
    }
}
