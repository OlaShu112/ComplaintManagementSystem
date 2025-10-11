<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Organization;
use App\Models\Complaint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class ManagerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:helpdesk_manager');
    }

    // User Management for Manager's Organization
    public function users()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;

        // Only show users from the manager's organization
        $users = User::where('organization_id', $organizationId)
            ->with('organization')
            ->latest()
            ->paginate(15);

        return view('manager.users', compact('users'));
    }

    public function createUser()
    {
        $user = Auth::user();
        // Only allow creating users for the manager's organization
        $organization = Organization::where('id', $user->organization_id)
            ->where('status', 'active')
            ->first();

        if (!$organization) {
            abort(403, 'Your organization is not active.');
        }

        return view('manager.create-user', compact('organization'));
    }

    public function storeUser(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:consumer,helpdesk_agent,support_person',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
        ]);

        // Ensure user is created only for manager's organization
        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'organization_id' => $user->organization_id, // Force manager's organization
            'phone' => $request->phone,
            'address' => $request->address,
            'is_active' => true,
        ]);

        return redirect()->route('manager.users')
            ->with('success', 'User created successfully!');
    }

    public function editUser(User $user)
    {
        $authUser = Auth::user();

        // Ensure manager can only edit users from their organization
        if ($user->organization_id !== $authUser->organization_id) {
            abort(403, 'You can only edit users from your organization.');
        }

        // Prevent editing system admins and other managers
        if (in_array($user->role, ['system_admin', 'helpdesk_manager'])) {
            abort(403, 'You cannot edit this user.');
        }

        $organization = Organization::where('id', $authUser->organization_id)->first();

        return view('manager.edit-user', compact('user', 'organization'));
    }

    public function updateUser(Request $request, User $user)
    {
        $authUser = Auth::user();

        // Ensure manager can only update users from their organization
        if ($user->organization_id !== $authUser->organization_id) {
            abort(403, 'You can only update users from your organization.');
        }

        // Prevent updating system admins and other managers
        if (in_array($user->role, ['system_admin', 'helpdesk_manager'])) {
            abort(403, 'You cannot update this user.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role' => 'required|in:consumer,helpdesk_agent,support_person',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'is_active' => 'boolean',
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'phone' => $request->phone,
            'address' => $request->address,
            'is_active' => $request->has('is_active'),
            // Keep organization_id unchanged
        ]);

        return redirect()->route('manager.users')
            ->with('success', 'User updated successfully!');
    }

    public function toggleUserStatus(User $user)
    {
        $authUser = Auth::user();

        // Ensure manager can only toggle users from their organization
        if ($user->organization_id !== $authUser->organization_id) {
            abort(403, 'You can only manage users from your organization.');
        }

        // Prevent toggling system admins and other managers
        if (in_array($user->role, ['system_admin', 'helpdesk_manager'])) {
            abort(403, 'You cannot manage this user.');
        }

        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';
        return redirect()->route('manager.users')
            ->with('success', "User {$status} successfully!");
    }

    public function deleteUser(User $user)
    {
        $authUser = Auth::user();

        // Ensure manager can only delete users from their organization
        if ($user->organization_id !== $authUser->organization_id) {
            abort(403, 'You can only delete users from your organization.');
        }

        // Prevent deleting system admins, managers, and self
        if (in_array($user->role, ['system_admin', 'helpdesk_manager']) || $user->id === $authUser->id) {
            abort(403, 'You cannot delete this user.');
        }

        $user->delete();
        return redirect()->route('manager.users')
            ->with('success', 'User deleted successfully!');
    }

    // Organization Statistics for Manager
    public function statistics()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;

        $stats = [
            'total_users' => User::where('organization_id', $organizationId)->count(),
            'active_users' => User::where('organization_id', $organizationId)->where('is_active', true)->count(),
            'consumers' => User::where('organization_id', $organizationId)->where('role', 'consumer')->count(),
            'agents' => User::where('organization_id', $organizationId)->where('role', 'helpdesk_agent')->count(),
            'support_persons' => User::where('organization_id', $organizationId)->where('role', 'support_person')->count(),
            'total_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->count(),
            'open_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->where('status', 'open')->count(),
            'resolved_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->where('status', 'resolved')->count(),
        ];

        return view('manager.statistics', compact('stats'));
    }
}
