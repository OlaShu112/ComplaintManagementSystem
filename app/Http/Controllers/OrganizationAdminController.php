<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Organization;
use App\Models\Complaint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class OrganizationAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:organization_admin');
    }

    // Dashboard for Organization Admin
    public function dashboard()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;

        // Organization Statistics
        $stats = [
            'total_users' => User::where('organization_id', $organizationId)->count(),
            'active_users' => User::where('organization_id', $organizationId)->where('is_active', true)->count(),
            'consumers' => User::where('organization_id', $organizationId)->where('role', 'consumer')->count(),
            'agents' => User::where('organization_id', $organizationId)->where('role', 'helpdesk_agent')->count(),
            'support_persons' => User::where('organization_id', $organizationId)->where('role', 'support_person')->count(),
            'managers' => User::where('organization_id', $organizationId)->where('role', 'helpdesk_manager')->count(),
            'total_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->count(),
            'open_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->where('status', 'open')->count(),
            'in_progress' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->where('status', 'in_progress')->count(),
            'resolved' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->where('status', 'resolved')->count(),
        ];

        // Recent complaints
        $recentComplaints = Complaint::whereHas('consumer', function($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->with(['consumer', 'assignedAgent', 'assignedSupport'])
        ->latest()
        ->limit(5)
        ->get();

        // Get user role statistics
        $consumers = User::where('organization_id', $organizationId)->where('role', 'consumer')->get();
        $agents = User::where('organization_id', $organizationId)->where('role', 'helpdesk_agent')->get();
        $supportPersons = User::where('organization_id', $organizationId)->where('role', 'support_person')->get();
        $managers = User::where('organization_id', $organizationId)->where('role', 'helpdesk_manager')->get();

        // Monthly trends data (last 12 months)
        $monthlyTrends = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthlyTrends[] = [
                'month' => $date->format('M Y'),
                'complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->count(),
                'resolved' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->whereIn('status', ['resolved', 'closed'])
                ->count()
            ];
        }

        // Role distribution
        $roleStats = [
            'consumers' => $consumers->count(),
            'helpdesk_agents' => $agents->count(),
            'support_persons' => $supportPersons->count(),
            'helpdesk_managers' => $managers->count(),
        ];

        // Chart data
        $chartData = [
            'status' => [
                'open' => $stats['open_complaints'],
                'in_progress' => $stats['in_progress'],
                'resolved' => $stats['resolved'],
                'closed' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('status', 'closed')->count()
            ],
            'categories' => [
                'technical' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('category', 'technical')->count(),
                'billing' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('category', 'billing')->count(),
                'service' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('category', 'service')->count(),
                'product' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('category', 'product')->count(),
                'delivery' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('category', 'delivery')->count(),
                'other' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('category', 'other')->count()
            ],
            'priorities' => [
                'low' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('priority', 'low')->count(),
                'medium' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('priority', 'medium')->count(),
                'high' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('priority', 'high')->count(),
                'urgent' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('priority', 'urgent')->count()
            ]
        ];

        return view('organization-admin.dashboard', compact('stats', 'recentComplaints', 'consumers', 'agents', 'supportPersons', 'managers', 'monthlyTrends', 'roleStats', 'chartData'));
    }

    // User Management for Organization Admin
    public function users()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;

        // Only show users from the organization admin's organization
        $users = User::where('organization_id', $organizationId)
            ->with('organization')
            ->latest()
            ->paginate(15);

        return view('organization-admin.users', compact('users'));
    }

    public function createUser()
    {
        $user = Auth::user();
        // Only allow creating users for the organization admin's organization
        $organization = Organization::where('id', $user->organization_id)
            ->where('status', 'active')
            ->first();

        if (!$organization) {
            abort(403, 'Your organization is not active.');
        }

        return view('organization-admin.create-user', compact('organization'));
    }

    public function storeUser(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:consumer,helpdesk_agent,support_person,helpdesk_manager',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
        ]);

        // Ensure user is created only for organization admin's organization
        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'organization_id' => $user->organization_id, // Force organization admin's organization
            'phone' => $request->phone,
            'address' => $request->address,
            'is_active' => true,
        ]);

        return redirect()->route('organization-admin.users')
            ->with('success', 'User created successfully!');
    }

    public function editUser(User $user)
    {
        $authUser = Auth::user();

        // Ensure organization admin can only edit users from their organization
        if ($user->organization_id !== $authUser->organization_id) {
            abort(403, 'You can only edit users from your organization.');
        }

        // Prevent editing system admins and other organization admins
        if (in_array($user->role, ['system_admin', 'organization_admin'])) {
            abort(403, 'You cannot edit this user.');
        }

        $organization = Organization::where('id', $authUser->organization_id)->first();

        return view('organization-admin.edit-user', compact('user', 'organization'));
    }

    public function updateUser(Request $request, User $user)
    {
        $authUser = Auth::user();

        // Ensure organization admin can only update users from their organization
        if ($user->organization_id !== $authUser->organization_id) {
            abort(403, 'You can only update users from your organization.');
        }

        // Prevent updating system admins and other organization admins
        if (in_array($user->role, ['system_admin', 'organization_admin'])) {
            abort(403, 'You cannot update this user.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role' => 'required|in:consumer,helpdesk_agent,support_person,helpdesk_manager',
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

        return redirect()->route('organization-admin.users')
            ->with('success', 'User updated successfully!');
    }

    public function toggleUserStatus(User $user)
    {
        $authUser = Auth::user();

        // Ensure organization admin can only toggle users from their organization
        if ($user->organization_id !== $authUser->organization_id) {
            abort(403, 'You can only manage users from your organization.');
        }

        // Prevent toggling system admins and other organization admins
        if (in_array($user->role, ['system_admin', 'organization_admin'])) {
            abort(403, 'You cannot manage this user.');
        }

        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';
        return redirect()->route('organization-admin.users')
            ->with('success', "User {$status} successfully!");
    }

    public function deleteUser(User $user)
    {
        $authUser = Auth::user();

        // Ensure organization admin can only delete users from their organization
        if ($user->organization_id !== $authUser->organization_id) {
            abort(403, 'You can only delete users from your organization.');
        }

        // Prevent deleting system admins, organization admins, and self
        if (in_array($user->role, ['system_admin', 'organization_admin']) || $user->id === $authUser->id) {
            abort(403, 'You cannot delete this user.');
        }

        $user->delete();
        return redirect()->route('organization-admin.users')
            ->with('success', 'User deleted successfully!');
    }

    // Complaints Management for Organization Admin
    public function complaints()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;

        $complaints = Complaint::whereHas('consumer', function($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->with(['consumer', 'assignedAgent', 'assignedSupport'])
        ->latest()
        ->paginate(15);

        return view('organization-admin.complaints', compact('complaints'));
    }

    // Reports for Organization Admin
    public function reports()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;

        // Organization-specific reports
        $stats = [
            'total_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->count(),
            'open_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->where('status', 'open')->count(),
            'in_progress' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->where('status', 'in_progress')->count(),
            'resolved' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->where('status', 'resolved')->count(),
            'closed' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->where('status', 'closed')->count(),
        ];

        // Category breakdown for organization
        $categoryStats = Complaint::whereHas('consumer', function($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->selectRaw('category, count(*) as count')
        ->groupBy('category')
        ->get();

        // Priority breakdown for organization
        $priorityStats = Complaint::whereHas('consumer', function($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->selectRaw('priority, count(*) as count')
        ->groupBy('priority')
        ->get();

        return view('organization-admin.reports', compact('stats', 'categoryStats', 'priorityStats'));
    }
        // Organization Statistics
        public function statistics()
        {
            $user = Auth::user();
            $organizationId = $user->organization_id;

            // Organization-specific statistics
            $stats = [
                'total_users' => User::where('organization_id', $organizationId)->count(),
                'active_users' => User::where('organization_id', $organizationId)->where('is_active', true)->count(),
                'total_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->count(),
                'open_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('status', 'open')->count(),
                'in_progress_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('status', 'in_progress')->count(),
                'resolved_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('status', 'resolved')->count(),
                'closed_complaints' => Complaint::whereHas('consumer', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })->where('status', 'closed')->count(),
            ];

            // Calculate resolution rate
            $stats['resolution_rate'] = $stats['total_complaints'] > 0 ?
                round((($stats['resolved_complaints'] + $stats['closed_complaints']) / $stats['total_complaints']) * 100, 1) : 0;

            // Role distribution in organization
            $roleStats = [
                'consumers' => User::where('organization_id', $organizationId)->where('role', 'consumer')->count(),
                'helpdesk_agents' => User::where('organization_id', $organizationId)->where('role', 'helpdesk_agent')->count(),
                'support_persons' => User::where('organization_id', $organizationId)->where('role', 'support_person')->count(),
                'helpdesk_managers' => User::where('organization_id', $organizationId)->where('role', 'helpdesk_manager')->count(),
            ];

            // Recent complaints
            $recentComplaints = Complaint::whereHas('consumer', function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->with(['consumer', 'assignedAgent', 'assignedSupport'])
            ->latest()
            ->limit(10)
            ->get();

            return view('organization-admin.statistics', compact('stats', 'roleStats', 'recentComplaints'));
        }

        // Organization Settings
        public function settings()
        {
            return view('organization-admin.settings');
        }

        public function updateSettings(Request $request)
        {
            $request->validate([
                'org_name' => 'required|string|max:255',
                'org_email' => 'required|email',
                'org_phone' => 'required|string',
                'org_address' => 'required|string',
            ]);

            $user = Auth::user();
            $organization = $user->organization;

            if ($organization) {
                $organization->update([
                    'name' => $request->org_name,
                    'email' => $request->org_email,
                    'phone' => $request->org_phone,
                    'address' => $request->org_address,
                    'description' => $request->org_description,
                ]);
            }

            return redirect()->route('organization-admin.settings')
                ->with('success', 'Organization settings updated successfully!');
        }
}
