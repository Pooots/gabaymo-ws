<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\Request;

class AdminPartnerController extends Controller
{
    public function index()
    {
        $openTickets = SupportTicket::query()->where('status', 'open')->count();
        $partners = User::query()
            ->partners()
            ->latest()
            ->get()
            ->map(fn (User $user) => $this->present($user, $openTickets));

        return response()->json(['partners' => $partners]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'mobile' => ['required', 'string', 'regex:/^(?:09\d{9}|\+639\d{9}|639\d{9})$/'],
            'specialty' => ['required', 'string', 'max:160'],
            'password' => ['required', 'string', 'min:8', 'max:120'],
            'response_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'partner_status' => ['required', 'in:active,inactive'],
        ], [
            'mobile.regex' => 'Enter a Philippine mobile number, such as 09171234567.',
        ]);

        $first = trim($data['first_name']);
        $last = trim($data['last_name']);
        $user = User::query()->create([
            'name' => trim($first.' '.$last),
            'first_name' => $first,
            'last_name' => $last,
            'email' => $data['email'],
            'mobile' => $this->mobile($data['mobile']),
            'specialty' => trim($data['specialty']),
            'password' => $data['password'],
            'account_type' => User::PARTNER,
            'response_hours' => $data['response_hours'],
            'partner_status' => $data['partner_status'],
            'email_verified_at' => now(),
        ]);

        AdminOverviewController::record(
            $request,
            'partner.create',
            $user->name.' ('.$user->specialty.') was added as a partner',
        );

        return response()->json([
            'partner' => $this->present($user, SupportTicket::query()->where('status', 'open')->count()),
        ], 201);
    }

    private function present(User $user, int $openTickets = 0): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'specialty' => $user->specialty,
            'open_tickets' => $openTickets,
            'response_hours' => $user->response_hours,
            'partner_status' => $user->partner_status,
        ];
    }

    private function mobile(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '63')) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }
}
