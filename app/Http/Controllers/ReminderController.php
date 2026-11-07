<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Support\Paginates;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Member reminders; sent by email + in-app 1 day before and on the due date (reminders:send). */
class ReminderController extends Controller
{
    use Paginates;

    public function index(Request $request)
    {
        $query = Reminder::query()->where('user_id', $request->user()->id)->orderBy('due_at');
        if ($request->boolean('upcoming')) {
            $query->where('due_at', '>=', now()->startOfDay());
        }

        return response()->json($this->paginate($request, $query, fn (Reminder $r) => $r->present()));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        if (Reminder::query()->where('user_id', $request->user()->id)->where('due_at', '>=', now())->count() >= 200) {
            return response()->json(['message' => 'You have too many upcoming reminders.'], 422);
        }
        $reminder = Reminder::query()->create([
            'user_id' => $request->user()->id,
            'title' => trim($data['title']),
            'due_at' => Carbon::parse($data['due_at']),
            'notes' => $data['notes'] ?? null,
            'conversation_id' => $data['conversation_id'] ?? null,
        ]);

        return response()->json(['data' => $reminder->present()], 201);
    }

    public function update(Request $request, Reminder $reminder)
    {
        if ((int) $reminder->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'This reminder was not found.'], 404);
        }
        $data = $request->validate($this->rules(false));
        if (array_key_exists('due_at', $data)) {
            $data['due_at'] = Carbon::parse($data['due_at']);
            if (! $data['due_at']->equalTo($reminder->due_at)) {
                // A new date gets new notices.
                $reminder->forceFill(['notified_day_before_at' => null, 'notified_due_at' => null]);
            }
        }
        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }
        $reminder->fill($data)->save();

        return response()->json(['data' => $reminder->fresh()->present()]);
    }

    public function destroy(Request $request, Reminder $reminder)
    {
        if ((int) $reminder->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'This reminder was not found.'], 404);
        }
        $reminder->delete();

        return response()->noContent();
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:1', 'max:160'],
            'due_at' => [$required, 'date', $creating ? 'after:now' : 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'conversation_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
