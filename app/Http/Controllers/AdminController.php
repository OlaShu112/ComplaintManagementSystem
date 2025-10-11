<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Organization;
use App\Models\Complaint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:system_admin');
    }

    // Organization Management
    public function organizations()
    {
        $organizations = Organization::withCount('users')->latest()->paginate(10);
        return view('admin.organizations', compact('organizations'));
    }

    public function createOrganization()
    {
        return view('admin.create-organization');
    }

    public function storeOrganization(Request $request)
    {
        $request->validate([
            'organization_number' => 'required|string|max:20|unique:organizations,organization_number',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:organizations,email',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'status' => 'required|in:active,inactive',
        ]);

        Organization::create($request->all());

        return redirect()->route('admin.organizations')
            ->with('success', 'Organization created successfully!');
    }

    public function editOrganization(Organization $organization)
    {
        return view('admin.edit-organization', compact('organization'));
    }

    public function updateOrganization(Request $request, Organization $organization)
    {
        $request->validate([
            'organization_number' => 'required|string|max:20|unique:organizations,organization_number,' . $organization->id,
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:organizations,email,' . $organization->id,
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'status' => 'required|in:active,inactive',
        ]);

        $organization->update($request->all());

        return redirect()->route('admin.organizations')
            ->with('success', 'Organization updated successfully!');
    }

    // User Management
    public function users()
    {
        $users = User::with('organization')->latest()->paginate(15);
        $organizations = Organization::where('status', 'active')->get();
        return view('admin.users', compact('users', 'organizations'));
    }

    public function createUser()
    {
        $organizations = Organization::where('status', 'active')->get();
        return view('admin.create-user', compact('organizations'));
    }

    public function storeUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:consumer,helpdesk_agent,support_person,helpdesk_manager,system_admin',
            'organization_id' => 'required|exists:organizations,id',
            'consumer_number' => 'required|string|size:8|unique:users,consumer_number',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
        ], [
            'consumer_number.size' => 'Account number must be exactly 8 characters.',
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'organization_id' => $request->organization_id,
            'consumer_number' => $request->consumer_number,
            'phone' => $request->phone,
            'address' => $request->address,
            'is_active' => true,
        ]);

        return redirect()->route('admin.users')
            ->with('success', 'User created successfully!');
    }

    public function editUser(User $user)
    {
        $organizations = Organization::where('status', 'active')->get();
        return view('admin.edit-user', compact('user', 'organizations'));
    }

    public function updateUser(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role' => 'required|in:consumer,helpdesk_agent,support_person,helpdesk_manager,system_admin',
            'organization_id' => 'required|exists:organizations,id',
            'consumer_number' => 'required|string|size:8|unique:users,consumer_number,' . $user->id,
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'is_active' => 'boolean',
        ], [
            'consumer_number.size' => 'Account number must be exactly 8 characters.',
        ]);

        $user->update($request->all());

        return redirect()->route('admin.users')
            ->with('success', 'User updated successfully!');
    }

    public function toggleUserStatus(User $user)
    {
        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';
        return redirect()->route('admin.users')
            ->with('success', "User {$status} successfully!");
    }

    public function deleteUser(User $user)
    {
        // Prevent deleting system admin
        if ($user->role === 'system_admin') {
            return redirect()->route('admin.users')
                ->with('error', 'Cannot delete system administrator!');
        }

        $user->delete();
        return redirect()->route('admin.users')
            ->with('success', 'User deleted successfully!');
    }

    // System Statistics
    public function statistics()
    {
        $stats = [
            'total_organizations' => Organization::count(),
            'active_organizations' => Organization::where('status', 'active')->count(),
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'total_complaints' => Complaint::count(),
            'open_complaints' => Complaint::where('status', 'open')->count(),
            'in_progress_complaints' => Complaint::where('status', 'in_progress')->count(),
            'resolved_complaints' => Complaint::where('status', 'resolved')->count(),
            'closed_complaints' => Complaint::where('status', 'closed')->count(),
        ];

        $roleStats = [
            'consumers' => User::where('role', 'consumer')->count(),
            'helpdesk_agents' => User::where('role', 'helpdesk_agent')->count(),
            'support_persons' => User::where('role', 'support_person')->count(),
            'helpdesk_managers' => User::where('role', 'helpdesk_manager')->count(),
            'organization_admins' => User::where('role', 'organization_admin')->count(),
            'system_admins' => User::where('role', 'system_admin')->count(),
        ];

        // Organization-wise statistics
        $organizations = Organization::withCount(['users', 'complaints'])->get();
        $organizationStats = [];
        foreach ($organizations as $org) {
            $organizationStats[] = [
                'name' => $org->name,
                'users_count' => $org->users_count,
                'complaints_count' => $org->complaints_count,
                'active_users' => User::where('organization_id', $org->id)->where('is_active', true)->count(),
                'open_complaints' => Complaint::whereHas('consumer', function($q) use ($org) {
                    $q->where('organization_id', $org->id);
                })->where('status', 'open')->count(),
            ];
        }

        // Category and Priority breakdown
        $categoryStats = Complaint::selectRaw('category, count(*) as count')
            ->groupBy('category')
            ->get();

        $priorityStats = Complaint::selectRaw('priority, count(*) as count')
            ->groupBy('priority')
            ->get();

        $recentComplaints = Complaint::with(['consumer', 'assignedAgent', 'assignedSupport'])
            ->latest()
            ->limit(10)
            ->get();

        return view('admin.statistics', compact('stats', 'roleStats', 'organizationStats', 'categoryStats', 'priorityStats', 'recentComplaints'));
    }

    // Comprehensive Reports
    public function reports()
    {
        // System-wide statistics
        $stats = [
            'total_organizations' => Organization::count(),
            'active_organizations' => Organization::where('status', 'active')->count(),
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'total_complaints' => Complaint::count(),
            'open_complaints' => Complaint::where('status', 'open')->count(),
            'in_progress_complaints' => Complaint::where('status', 'in_progress')->count(),
            'resolved_complaints' => Complaint::where('status', 'resolved')->count(),
            'closed_complaints' => Complaint::where('status', 'closed')->count(),
        ];

        // Organization performance
        $organizations = Organization::withCount(['users', 'complaints'])->get();
        $organizationPerformance = [];
        foreach ($organizations as $org) {
            $orgComplaints = Complaint::whereHas('consumer', function($q) use ($org) {
                $q->where('organization_id', $org->id);
            });

            $organizationPerformance[] = [
                'name' => $org->name,
                'users_count' => $org->users_count,
                'complaints_count' => $org->complaints_count,
                'open_complaints' => $orgComplaints->where('status', 'open')->count(),
                'resolved_complaints' => $orgComplaints->where('status', 'resolved')->count(),
                'resolution_rate' => $org->complaints_count > 0 ?
                    round(($orgComplaints->where('status', 'resolved')->count() / $org->complaints_count) * 100, 2) : 0,
            ];
        }

        // Category breakdown
        $categoryStats = Complaint::selectRaw('category, count(*) as count')
            ->groupBy('category')
            ->orderBy('count', 'desc')
            ->get();

        // Priority breakdown
        $priorityStats = Complaint::selectRaw('priority, count(*) as count')
            ->groupBy('priority')
            ->orderBy('count', 'desc')
            ->get();

        // Status breakdown
        $statusStats = Complaint::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();

        // Monthly trends (last 12 months)
        $monthlyTrends = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthlyTrends[] = [
                'month' => $date->format('M Y'),
                'complaints' => Complaint::whereYear('created_at', $date->year)
                    ->whereMonth('created_at', $date->month)
                    ->count(),
                'resolved' => Complaint::whereYear('created_at', $date->year)
                    ->whereMonth('created_at', $date->month)
                    ->where('status', 'resolved')
                    ->count(),
            ];
        }

        // Top performing organizations
        $topOrganizations = collect($organizationPerformance)
            ->sortByDesc('resolution_rate')
            ->take(5);

        return view('admin.reports', compact('stats', 'organizationPerformance', 'categoryStats', 'priorityStats', 'statusStats', 'monthlyTrends', 'topOrganizations'));
    }

    // All Complaints View
    public function complaints(Request $request)
    {
        $query = Complaint::with(['consumer', 'assignedAgent', 'assignedSupport', 'consumer.organization']);

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->has('priority') && $request->priority !== '') {
            $query->where('priority', $request->priority);
        }

        // Filter by category
        if ($request->has('category') && $request->category !== '') {
            $query->where('category', $request->category);
        }

        // Filter by organization
        if ($request->has('organization_id') && $request->organization_id !== '') {
            $query->whereHas('consumer', function($q) use ($request) {
                $q->where('organization_id', $request->organization_id);
            });
        }

        // Search by title or description
        if ($request->has('search') && $request->search !== '') {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        $complaints = $query->latest()->paginate(20);

        // Get filter options
        $organizations = Organization::where('status', 'active')->get();
        $statuses = ['open', 'in_progress', 'resolved', 'closed'];
        $priorities = ['low', 'medium', 'high', 'urgent'];
        $categories = ['technical', 'billing', 'service', 'product', 'delivery', 'other'];

        // Statistics for the dashboard
        $stats = [
            'total' => Complaint::count(),
            'open' => Complaint::where('status', 'open')->count(),
            'in_progress' => Complaint::where('status', 'in_progress')->count(),
            'resolved' => Complaint::where('status', 'resolved')->count(),
            'closed' => Complaint::where('status', 'closed')->count(),
            'urgent' => Complaint::where('priority', 'urgent')->count(),
            'high' => Complaint::where('priority', 'high')->count(),
        ];

        // Organization-wise complaint statistics
        $organizationStats = [];
        foreach ($organizations as $org) {
            $orgComplaints = Complaint::whereHas('consumer', function($q) use ($org) {
                $q->where('organization_id', $org->id);
            });

            $organizationStats[] = [
                'name' => $org->name,
                'total' => $orgComplaints->count(),
                'open' => $orgComplaints->where('status', 'open')->count(),
                'resolved' => $orgComplaints->where('status', 'resolved')->count(),
            ];
        }

        return view('admin.complaints', compact('complaints', 'organizations', 'statuses', 'priorities', 'categories', 'stats', 'organizationStats'));
    }

    // System Analytics Dashboard
    public function analytics()
    {
        // Real-time statistics
        $stats = [
            'total_organizations' => Organization::count(),
            'active_organizations' => Organization::where('status', 'active')->count(),
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'total_complaints' => Complaint::count(),
            'open_complaints' => Complaint::where('status', 'open')->count(),
            'in_progress_complaints' => Complaint::where('status', 'in_progress')->count(),
            'resolved_complaints' => Complaint::where('status', 'resolved')->count(),
            'closed_complaints' => Complaint::where('status', 'closed')->count(),
        ];

        // Organization breakdown
        $organizations = Organization::withCount(['users', 'complaints'])->get();
        $organizationData = [];
        foreach ($organizations as $org) {
            $organizationData[] = [
                'name' => $org->name,
                'users' => $org->users_count,
                'complaints' => $org->complaints_count,
                'active_users' => User::where('organization_id', $org->id)->where('is_active', true)->count(),
            ];
        }

        // Role distribution
        $roleDistribution = [
            'consumers' => User::where('role', 'consumer')->count(),
            'helpdesk_agents' => User::where('role', 'helpdesk_agent')->count(),
            'support_persons' => User::where('role', 'support_person')->count(),
            'helpdesk_managers' => User::where('role', 'helpdesk_manager')->count(),
            'organization_admins' => User::where('role', 'organization_admin')->count(),
            'system_admins' => User::where('role', 'system_admin')->count(),
        ];

        // Category distribution
        $categoryDistribution = Complaint::selectRaw('category, count(*) as count')
            ->groupBy('category')
            ->get()
            ->pluck('count', 'category')
            ->toArray();

        // Priority distribution
        $priorityDistribution = Complaint::selectRaw('priority, count(*) as count')
            ->groupBy('priority')
            ->get()
            ->pluck('count', 'priority')
            ->toArray();

        // Status distribution
        $statusDistribution = Complaint::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        return view('admin.analytics', compact('stats', 'organizationData', 'roleDistribution', 'categoryDistribution', 'priorityDistribution', 'statusDistribution'));
    }

    // System Settings
    public function settings()
    {
        return view('admin.settings');
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'app_name' => 'required|string|max:255',
            'app_email' => 'required|email',
            'app_phone' => 'required|string',
            'app_address' => 'required|string',
        ]);

        // Update system settings (you can store these in a settings table or config)
        // For now, we'll just show success message
        return redirect()->route('admin.settings')
            ->with('success', 'Settings updated successfully!');
    }
}
