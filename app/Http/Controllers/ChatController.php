<?php
namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    // Polled every few seconds by the chat widget — "since" is the highest
    // message id the browser already has, so this only ever returns what's
    // new. This app runs on plain XAMPP/shared hosting, so polling was
    // chosen over WebSockets (Reverb/Pusher) — no extra background
    // processes or server config required, and a few seconds of delay is
    // completely fine for an internal staff chat.
    public function poll(Request $request)
    {
        $sinceId = (int) $request->query('since', 0);

        $messages = ChatMessage::with('user')
            ->where('id', '>', $sinceId)
            ->orderBy('id')
            ->limit(50)
            ->get();

        return response()->json([
            'messages' => $messages->map(fn ($m) => $this->format($m)),
        ]);
    }

    // Full recent history, loaded once when the chat panel first opens.
    public function history()
    {
        $messages = ChatMessage::with('user')->latest('id')->limit(50)->get()->reverse()->values();

        return response()->json([
            'messages' => $messages->map(fn ($m) => $this->format($m)),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $message = ChatMessage::create([
            'user_id' => Auth::id(),
            'message' => $validated['message'],
        ]);

        $message->load('user');

        return response()->json(['message' => $this->format($message)]);
    }

    public function react(Request $request, ChatMessage $chatMessage)
    {
        $validated = $request->validate([
            'emoji' => 'required|string|max:8',
        ]);

        $reactions = $chatMessage->reactions ?? [];
        $emoji = $validated['emoji'];
        $userId = Auth::id();

        $reactions[$emoji] = $reactions[$emoji] ?? [];

        if (in_array($userId, $reactions[$emoji])) {
            // Already reacted with this emoji — clicking again removes it.
            $reactions[$emoji] = array_values(array_diff($reactions[$emoji], [$userId]));
            if (empty($reactions[$emoji])) {
                unset($reactions[$emoji]);
            }
        } else {
            $reactions[$emoji][] = $userId;
        }

        $chatMessage->update(['reactions' => $reactions]);

        return response()->json(['reactions' => $reactions]);
    }

    private function format(ChatMessage $m): array
    {
        return [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'user_name' => $m->user->name ?? 'Unknown',
            'is_mine' => $m->user_id === Auth::id(),
            'message' => $m->message,
            'reactions' => $m->reactions ?? [],
            'time' => $m->created_at->format('g:i A'),
            'full_time' => $m->created_at->format('M j, g:i A'),
        ];
    }
}
