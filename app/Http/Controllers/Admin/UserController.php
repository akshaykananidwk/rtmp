<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        return view('admin.users.index', ['users' => User::forTenant(auth()->user()->tenant_id)->with('roles')->orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.form', ['user' => new User(['is_active' => true]), 'roles' => $this->assignableRoles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);
        $data = $this->validated($request);
        $this->authorize('assignRole', [User::class, $data['role']]);

        $user = User::create(['tenant_id' => $request->user()->tenant_id, 'name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password'], 'phone' => $data['phone'] ?? null, 'timezone' => $data['timezone'], 'is_active' => (bool) ($data['is_active'] ?? true), 'email_verified_at' => now()]);
        $user->syncRole($data['role']);
        $this->audit->log('user.created', $user, ['email' => $user->email, 'role' => $data['role']]);

        return redirect()->route('admin.users.index')->with('status', 'User created.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.form', ['user' => $user, 'roles' => $this->assignableRoles()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $data = $this->validated($request, $user);
        $this->authorize('assignRole', [User::class, $data['role']]);

        $user->fill(['name' => $data['name'], 'email' => strtolower($data['email']), 'phone' => $data['phone'] ?? null, 'timezone' => $data['timezone'], 'is_active' => (bool) ($data['is_active'] ?? true)]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        if ($user->id === $request->user()->id) {
            $user->is_active = true; // never lock yourself out
        }
        $user->save();
        if ($user->id !== $request->user()->id || $request->user()->isSuperAdmin()) {
            $user->syncRole($data['role']);
        }
        $this->audit->log('user.updated', $user, ['role' => $data['role']]);

        return redirect()->route('admin.users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        $user->tokens()->delete();
        $user->delete();
        $this->audit->log('user.deleted', $user, ['email' => $user->email]);

        return redirect()->route('admin.users.index')->with('status', 'User deleted.');
    }

    private function assignableRoles()
    {
        $level = auth()->user()->highestRoleLevel();

        return Role::where('level', '<=', $level)->orderByDesc('level')->get();
    }

    private function validated(Request $request, ?User $existing = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($existing?->id)],
            'password' => [$existing ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s\-]+$/'],
            'timezone' => ['required', 'timezone:all'],
            'role' => ['required', Rule::in(Role::pluck('name')->all())],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
