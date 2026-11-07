<?php

namespace App\Http\Controllers;

use App\Models\AccountInvitation;
use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Accounts\AccountInvitations;
use App\Support\AdminPermissions;
use App\Support\SpaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminRoleController extends Controller
{
    public function __construct(private AccountInvitations $invitations) {}

    public function index()
    {
        return response()->json([
            'modules' => AdminPermissions::MODULES,
            'roles' => Role::query()->orderByDesc('is_system')->orderBy('name')->get()
                ->map(fn (Role $role) => $this->present($role))
                ->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $role = Role::query()->create([
            ...$data,
            'slug' => $this->uniqueSlug($data['name']),
            'is_system' => false,
        ]);

        AdminOverviewController::record(
            $request,
            'role.create',
            "Created the {$role->name} role",
        );

        return response()->json(['role' => $this->present($role)], 201);
    }

    public function update(Request $request, Role $staffRole)
    {
        $data = $this->validated($request);
        if ($staffRole->is_system) {
            $data['permissions'] = AdminPermissions::keys();
            $data['name'] = $staffRole->name;
        }
        $staffRole->update($data);

        AdminOverviewController::record(
            $request,
            'role.update',
            "Updated the {$staffRole->name} role",
        );

        return response()->json(['role' => $this->present($staffRole->fresh())]);
    }

    public function destroy(Request $request, Role $staffRole)
    {
        if ($staffRole->is_system) {
            return response()->json([
                'message' => 'The administrator role cannot be removed.',
            ], 422);
        }

        if ($staffRole->accounts()->exists()) {
            return response()->json([
                'message' => 'Move the accounts off this role before deleting it.',
            ], 422);
        }

        $name = $staffRole->name;
        $staffRole->delete();
        AdminOverviewController::record($request, 'role.delete', "Removed the {$name} role");

        return response()->json(['ok' => true]);
    }

    /**
     * Creates the staff account as "invited" (no password, cannot sign in)
     * and emails a set-up link.
     */
    public function storeAccount(Request $request, Role $staffRole)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:admin_users,email'],
        ]);

        $user = new AdminUser(['name' => trim($data['name']), 'email' => $data['email'], 'role_id' => $staffRole->id]);
        $user->forceFill(['account_status' => AccountInvitation::STATUS_INVITED])->save();
        $user->setRelation('role', $staffRole);
        $issued = $this->invitations->issue($user, $request->user());

        AdminOverviewController::record(
            $request,
            'role.account',
            "Invited {$user->email} with the {$staffRole->name} role",
        );

        return response()->json([
            'role' => $this->present($staffRole),
            'account' => [
                ...$this->presentAccount($user),
                'invitation_expires_at' => $issued['invitation']->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    /** New set-up link for a staff member who has not accepted yet; the old link stops working. */
    public function resendInvite(Request $request, Role $staffRole, AdminUser $admin)
    {
        if ((int) $admin->role_id !== (int) $staffRole->id) {
            return response()->json(['message' => 'This account does not have this role.'], 404);
        }
        if (! $admin->isInvited()) {
            return response()->json(['message' => 'This account is already set up. Use reset password instead.'], 422);
        }
        $admin->setRelation('role', $staffRole);
        $this->invitations->issue($admin, $request->user());
        AdminOverviewController::record($request, 'role.account_resend_invite', "Sent a new set-up link to {$admin->email}");

        return response()->json(['account' => $this->presentAccount($admin->fresh('pendingInvitation'))], 202);
    }

    /** Emails a link to choose a new password (the current one keeps working until then). */
    public function resetPassword(Request $request, Role $staffRole, AdminUser $admin)
    {
        if ((int) $admin->role_id !== (int) $staffRole->id) {
            return response()->json(['message' => 'This account does not have this role.'], 404);
        }
        if ((int) $admin->id === (int) $request->user()->id) {
            return response()->json(['message' => 'Use Change password for your own account.'], 422);
        }
        $admin->setRelation('role', $staffRole);
        $issued = $this->invitations->issue($admin, $request->user(), AccountInvitation::RESET);
        AdminOverviewController::record($request, 'role.account_reset_password', "Sent a password link to {$admin->email}");

        return response()->json([
            'message' => 'We emailed '.$admin->email.' a link to set a new password.',
            'expires_at' => $issued['invitation']->expires_at->toIso8601String(),
        ], 202);
    }

    /** Blocks sign-in and ends every session of the account. */
    public function suspend(Request $request, Role $staffRole, AdminUser $admin)
    {
        if ((int) $admin->role_id !== (int) $staffRole->id) {
            return response()->json(['message' => 'This account does not have this role.'], 404);
        }
        if ((int) $admin->id === (int) $request->user()->id) {
            return response()->json(['message' => 'You cannot suspend your own account.', 'error' => 'cannot_suspend_self'], 422);
        }
        if ($admin->isSuspended()) {
            return response()->json(['message' => 'This account is already suspended.'], 422);
        }
        if ($staffRole->is_system && ! $admin->isInvited() && $this->activeSuperAdmins() <= 1) {
            return response()->json(['message' => 'You cannot suspend the last active administrator.', 'error' => 'last_super_admin'], 422);
        }

        $admin->forceFill(['suspended_at' => now()])->save();
        SpaSession::revokeAll($request, 'admin', $admin);
        AdminOverviewController::record($request, 'role.account_suspend', "Suspended the staff account {$admin->email}");

        return response()->json(['account' => $this->presentAccount($admin->fresh('pendingInvitation'))]);
    }

    public function unsuspend(Request $request, Role $staffRole, AdminUser $admin)
    {
        if ((int) $admin->role_id !== (int) $staffRole->id) {
            return response()->json(['message' => 'This account does not have this role.'], 404);
        }
        if (! $admin->isSuspended()) {
            return response()->json(['message' => 'This account is not suspended.'], 422);
        }

        $admin->forceFill(['suspended_at' => null])->save();
        AdminOverviewController::record($request, 'role.account_unsuspend', "Lifted the suspension of the staff account {$admin->email}");

        return response()->json(['account' => $this->presentAccount($admin->fresh('pendingInvitation'))]);
    }

    /** Set-up, not suspended accounts on a system (administrator) role. */
    private function activeSuperAdmins(): int
    {
        return AdminUser::query()
            ->whereHas('role', fn ($q) => $q->where('is_system', true))
            ->where('account_status', '!=', AccountInvitation::STATUS_INVITED)
            ->whereNull('suspended_at')
            ->count();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::in(AdminPermissions::keys())],
        ]);
        $data['description'] = trim((string) ($data['description'] ?? '')) ?: null;
        $data['permissions'] = array_values(array_unique($data['permissions']));

        return $data;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'role';
        }

        $slug = $base;
        $n = 2;
        while (Role::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }

    private function present(Role $role): array
    {
        $accounts = $role->accounts()
            ->with('pendingInvitation')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'account_status', 'suspended_at']);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'permissions' => $role->granted(),
            'accounts' => $accounts->map(fn (AdminUser $user) => $this->presentAccount($user))->all(),
        ];
    }

    private function presentAccount(AdminUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            // invited | active | suspended
            'status' => $user->isInvited()
                ? AccountInvitation::STATUS_INVITED
                : ($user->isSuspended() ? 'suspended' : AccountInvitation::STATUS_ACTIVE),
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'invitation_expires_at' => $user->pendingInvitation?->expires_at?->toIso8601String(),
        ];
    }
}
