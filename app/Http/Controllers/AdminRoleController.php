<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminRoleController extends Controller
{
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

        if (User::query()->where('role', $staffRole->slug)->exists()) {
            return response()->json([
                'message' => 'Move the accounts off this role before deleting it.',
            ], 422);
        }

        $name = $staffRole->name;
        $staffRole->delete();
        AdminOverviewController::record($request, 'role.delete', "Removed the {$name} role");

        return response()->json(['ok' => true]);
    }

    public function storeAccount(Request $request, Role $staffRole)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $staffRole->slug,
            'email_verified_at' => now(),
        ]);

        AdminOverviewController::record(
            $request,
            'role.account',
            "Gave {$user->email} the {$staffRole->name} role",
        );

        return response()->json(['role' => $this->present($staffRole)], 201);
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
        if ($base === '' || $base === 'member') {
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
        $accounts = User::query()
            ->where('role', $role->slug)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'permissions' => $role->granted(),
            'accounts' => $accounts->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->all(),
        ];
    }
}
