<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Complaint;
use App\Models\User;
use App\Models\Organization;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = Auth::user();

        if ($user->isConsumer()) {
            return $this->consumerDashboard();
        } elseif ($user->isHelpDeskAgent()) {
            return $this->agentDashboard();
        } elseif ($user->isSupportPerson()) {
            return $this->supportDashboard();
        } elseif ($user->isHelpDeskManager()) {
            return $this->managerDashboard();
        } elseif ($user->isOrganizationAdmin()) {
            return $this->organizationAdminDashboard();
        } elseif ($user->isSystemAdmin()) {
            return $this->adminDashboard();
        }

        return redirect('/login');
    }

    private function consumerDashboard()
    {
        $complaints = Complaint::where('consumer_id', Auth::id())
            ->latest()
            ->paginate(10);

        $stats = [
            'total' => Complaint::where('consumer_id', Auth::id())->count(),
            'open' => Complaint::where('consumer_id', Auth::id())->where('status', 'open')->count(),
            'in_progress' => Complaint::where('consumer_id', Auth::id())->where('status', 'in_progress')->count(),
            'resolved' => Complaint::where('consumer_id', Auth::id())->where('status', 'resolved')->count(),
        ];

        return view('consumer.dashboard', compact('complaints', 'stats'));
    }

    private function agentDashboard()
    {
        $user = Auth::user();

        // Only show complaints from agent's organization
        $complaints = Complaint::where(function($query) use ($user) {
            $query->where('assigned_agent_id', $user->id)
                  ->orWhere(function($q) use ($user) {
                      $q->where('status', 'open')
                        ->forOrganization($user->organization_id);
                  });
        })->latest()->paginate(10);

        $stats = [
            'assigned' => Complaint::where('assigned_agent_id', $user->id)->count(),
            'open' => Complaint::forOrganization($user->organization_id)->where('status', 'open')->count(),
            'in_progress' => Complaint::where('assigned_agent_id', $user->id)
                ->where('status', 'in_progress')->count(),
            'resolved' => Complaint::where('assigned_agent_id', $user->id)
                ->where('status', 'resolved')->count(),
        ];

        // Chart data - Category breakdown
        $chartData = [
            'technical' => Complaint::where('assigned_agent_id', $user->id)->where('category', 'technical')->count(),
            'billing' => Complaint::where('assigned_agent_id', $user->id)->where('category', 'billing')->count(),
            'service' => Complaint::where('assigned_agent_id', $user->id)->where('category', 'service')->count(),
            'product' => Complaint::where('assigned_agent_id', $user->id)->where('category', 'product')->count(),
            'delivery' => Complaint::where('assigned_agent_id', $user->id)->where('category', 'delivery')->count(),
            'other' => Complaint::where('assigned_agent_id', $user->id)->where('category', 'other')->count(),
        ];

        return view('agent.dashboard', compact('complaints', 'stats', 'chartData'));
    }

    private function supportDashboard()
    {
        $user = Auth::user();

        // Show only complaints assigned to this support person
        $complaints = Complaint::where('assigned_support_id', $user->id)
            ->latest()
            ->paginate(10);

        $stats = [
            'assigned' => Complaint::where('assigned_support_id', $user->id)->count(),
            'in_progress' => Complaint::where('assigned_support_id', $user->id)->where('status', 'in_progress')->count(),
            'resolved' => Complaint::where('assigned_support_id', $user->id)->where('status', 'resolved')->count(),
            'pending' => Complaint::where('assigned_support_id', $user->id)->where('status', 'open')->count(),
        ];

        // Chart data - Priority breakdown
        $chartData = [
            'low' => Complaint::where('assigned_support_id', $user->id)->where('priority', 'low')->count(),
            'medium' => Complaint::where('assigned_support_id', $user->id)->where('priority', 'medium')->count(),
            'high' => Complaint::where('assigned_support_id', $user->id)->where('priority', 'high')->count(),
            'urgent' => Complaint::where('assigned_support_id', $user->id)->where('priority', 'urgent')->count(),
        ];

        return view('support.dashboard', compact('complaints', 'stats', 'chartData'));
    }

    private function managerDashboard()
    {
        $user = Auth::user();

        // Show only complaints from manager's organization
        $complaints = Complaint::forOrganization($user->organization_id)->latest()->paginate(10);

        $stats = [
            'total' => Complaint::forOrganization($user->organization_id)->count(),
            'open' => Complaint::forOrganization($user->organization_id)->where('status', 'open')->count(),
            'in_progress' => Complaint::forOrganization($user->organization_id)->where('status', 'in_progress')->count(),
            'resolved' => Complaint::forOrganization($user->organization_id)->where('status', 'resolved')->count(),
            'closed' => Complaint::forOrganization($user->organization_id)->where('status', 'closed')->count(),
        ];

        $agents = User::where('role', 'helpdesk_agent')
            ->where('organization_id', $user->organization_id)
            ->get();
        $supportPersons = User::where('role', 'support_person')
            ->where('organization_id', $user->organization_id)
            ->get();

        // Chart data - Category and Priority breakdown
        $chartData = [
            'categories' => [
                'technical' => Complaint::forOrganization($user->organization_id)->where('category', 'technical')->count(),
                'billing' => Complaint::forOrganization($user->organization_id)->where('category', 'billing')->count(),
                'service' => Complaint::forOrganization($user->organization_id)->where('category', 'service')->count(),
                'product' => Complaint::forOrganization($user->organization_id)->where('category', 'product')->count(),
                'delivery' => Complaint::forOrganization($user->organization_id)->where('category', 'delivery')->count(),
                'other' => Complaint::forOrganization($user->organization_id)->where('category', 'other')->count(),
            ],
            'priorities' => [
                'low' => Complaint::forOrganization($user->organization_id)->where('priority', 'low')->count(),
                'medium' => Complaint::forOrganization($user->organization_id)->where('priority', 'medium')->count(),
                'high' => Complaint::forOrganization($user->organization_id)->where('priority', 'high')->count(),
                'urgent' => Complaint::forOrganization($user->organization_id)->where('priority', 'urgent')->count(),
            ]
        ];

        return view('manager.dashboard', compact('complaints', 'stats', 'agents', 'supportPersons', 'chartData'));
    }

    private function organizationAdminDashboard()
    {
        $user = Auth::user();

        // Show only complaints from organization admin's organization
        $complaints = Complaint::forOrganization($user->organization_id)->latest()->paginate(10);

        $stats = [
            'total' => Complaint::forOrganization($user->organization_id)->count(),
            'open' => Complaint::forOrganization($user->organization_id)->where('status', 'open')->count(),
            'in_progress' => Complaint::forOrganization($user->organization_id)->where('status', 'in_progress')->count(),
            'resolved' => Complaint::forOrganization($user->organization_id)->where('status', 'resolved')->count(),
            'closed' => Complaint::forOrganization($user->organization_id)->where('status', 'closed')->count(),
        ];

        // Organization users
        $users = User::where('organization_id', $user->organization_id)->get();
        $agents = $users->where('role', 'helpdesk_agent');
        $supportPersons = $users->where('role', 'support_person');
        $managers = $users->where('role', 'helpdesk_manager');
        $consumers = $users->where('role', 'consumer');

        // Monthly trends for this organization (last 12 months)
        $monthlyTrends = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthlyTrends[] = [
                'month' => $date->format('M Y'),
                'complaints' => Complaint::forOrganization($user->organization_id)->whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->count(),
                'resolved' => Complaint::forOrganization($user->organization_id)->whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->where('status', 'resolved')
                ->count(),
            ];
        }

        // Role distribution in this organization
        $roleStats = [
            'consumers' => User::where('organization_id', $user->organization_id)->where('role', 'consumer')->count(),
            'helpdesk_agents' => User::where('organization_id', $user->organization_id)->where('role', 'helpdesk_agent')->count(),
            'support_persons' => User::where('organization_id', $user->organization_id)->where('role', 'support_person')->count(),
            'helpdesk_managers' => User::where('organization_id', $user->organization_id)->where('role', 'helpdesk_manager')->count(),
        ];

        // Chart data - Category and Priority breakdown for organization
        $chartData = [
            'status' => [
                'open' => $stats['open'],
                'in_progress' => $stats['in_progress'],
                'resolved' => $stats['resolved'],
                'closed' => $stats['closed'],
            ],
            'categories' => [
                'technical' => Complaint::forOrganization($user->organization_id)->where('category', 'technical')->count(),
                'billing' => Complaint::forOrganization($user->organization_id)->where('category', 'billing')->count(),
                'service' => Complaint::forOrganization($user->organization_id)->where('category', 'service')->count(),
                'product' => Complaint::forOrganization($user->organization_id)->where('category', 'product')->count(),
                'delivery' => Complaint::forOrganization($user->organization_id)->where('category', 'delivery')->count(),
                'other' => Complaint::forOrganization($user->organization_id)->where('category', 'other')->count(),
            ],
            'priorities' => [
                'low' => Complaint::forOrganization($user->organization_id)->where('priority', 'low')->count(),
                'medium' => Complaint::forOrganization($user->organization_id)->where('priority', 'medium')->count(),
                'high' => Complaint::forOrganization($user->organization_id)->where('priority', 'high')->count(),
                'urgent' => Complaint::forOrganization($user->organization_id)->where('priority', 'urgent')->count(),
            ],
            'monthly_trends' => $monthlyTrends,
            'roles' => $roleStats,
        ];

        return view('organization-admin.dashboard', compact('complaints', 'stats', 'agents', 'supportPersons', 'managers', 'consumers', 'chartData', 'monthlyTrends', 'roleStats'));
    }

    private function adminDashboard()
    {
        $organizations = Organization::withCount(['users', 'complaints'])->get();
        $users = User::with('organization')->paginate(10);

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

        // Role distribution
        $roleStats = [
            'consumers' => User::where('role', 'consumer')->count(),
            'helpdesk_agents' => User::where('role', 'helpdesk_agent')->count(),
            'support_persons' => User::where('role', 'support_person')->count(),
            'helpdesk_managers' => User::where('role', 'helpdesk_manager')->count(),
            'organization_admins' => User::where('role', 'organization_admin')->count(),
            'system_admins' => User::where('role', 'system_admin')->count(),
        ];

        // Organization performance data
        $organizationPerformance = [];
        foreach ($organizations as $org) {
            $orgComplaints = Complaint::whereHas('consumer', function($q) use ($org) {
                $q->where('organization_id', $org->id);
            });

            $organizationPerformance[] = [
                'name' => $org->name,
                'users' => $org->users_count,
                'complaints' => $org->complaints_count,
                'open' => $orgComplaints->where('status', 'open')->count(),
                'resolved' => $orgComplaints->where('status', 'resolved')->count(),
                'resolution_rate' => $org->complaints_count > 0 ?
                    round(($orgComplaints->where('status', 'resolved')->count() / $org->complaints_count) * 100, 2) : 0,
            ];
        }

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

        // Chart data - System-wide statistics
        $chartData = [
            'status' => [
                'open' => Complaint::where('status', 'open')->count(),
                'in_progress' => Complaint::where('status', 'in_progress')->count(),
                'resolved' => Complaint::where('status', 'resolved')->count(),
                'closed' => Complaint::where('status', 'closed')->count(),
            ],
            'by_organization' => [],
            'categories' => [
                'technical' => Complaint::where('category', 'technical')->count(),
                'billing' => Complaint::where('category', 'billing')->count(),
                'service' => Complaint::where('category', 'service')->count(),
                'product' => Complaint::where('category', 'product')->count(),
                'delivery' => Complaint::where('category', 'delivery')->count(),
                'other' => Complaint::where('category', 'other')->count(),
            ],
            'priorities' => [
                'low' => Complaint::where('priority', 'low')->count(),
                'medium' => Complaint::where('priority', 'medium')->count(),
                'high' => Complaint::where('priority', 'high')->count(),
                'urgent' => Complaint::where('priority', 'urgent')->count(),
            ],
            'roles' => $roleStats,
            'monthly_trends' => $monthlyTrends,
            'organization_performance' => $organizationPerformance,
        ];

        // Get complaints by organization
        foreach ($organizations as $org) {
            $chartData['by_organization'][$org->name] = Complaint::whereHas('consumer', function($q) use ($org) {
                $q->where('organization_id', $org->id);
            })->count();
        }

        return view('admin.simple-dashboard', compact('organizations', 'users', 'stats', 'roleStats', 'chartData', 'organizationPerformance', 'monthlyTrends'));
    }
}
