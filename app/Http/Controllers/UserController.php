<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->string('search')->toString(), function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('username', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')->toString()))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => UserRole::assignable(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', [
            'roles' => UserRole::assignable(),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        User::create($data);

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user): View
    {
        return view('users.edit', [
            'user' => $user,
            'roles' => UserRole::assignable(),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $roleChanging = $user->role !== UserRole::from($data['role']);
        $deactivating = $user->is_active && ! $data['is_active'];

        if ($roleChanging && $user->isLastActiveAdmin() && $data['role'] !== UserRole::Admin->value) {
            return back()->withInput()->withErrors([
                'role' => 'Cannot demote the only active administrator. Promote another user first.',
            ]);
        }

        if ($deactivating && $user->isLastActiveAdmin()) {
            return back()->withInput()->withErrors([
                'is_active' => 'Cannot deactivate the only active administrator.',
            ]);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        $user->fill($data);
        $user->save();

        if ($roleChanging || $deactivating) {
            $user->logOutEverywhere();
        }

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account from this screen.');
        }

        if ($user->isLastActiveAdmin()) {
            return back()->with('error', 'Cannot delete the only active administrator.');
        }

        $user->logOutEverywhere();
        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($user->isLastActiveAdmin()) {
            return back()->with('error', 'Cannot deactivate the only active administrator.');
        }

        $user->is_active = false;
        $user->save();
        $user->logOutEverywhere();

        return back()->with('success', "{$user->name} deactivated.");
    }

    public function reactivate(User $user): RedirectResponse
    {
        $user->is_active = true;
        $user->save();

        return back()->with('success', "{$user->name} reactivated.");
    }
}
