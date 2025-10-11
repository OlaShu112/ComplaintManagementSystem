<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Complaint;
use App\Models\User;
use App\Models\Organization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = Auth::user();

        if ($user->isSystemAdmin()) {
            return $this->adminReports();
        } elseif ($user->isHelpDeskManager()) {
            return $this->managerReports();
        }

        abort(403, 'You do not have permission to access reports.');
    }

    private function managerReports()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;

        // Overall Statistics
        $stats = [
            'total_complaints' => Complaint::forOrganization($organizationId)->count(),
            'open_complaints' => Complaint::forOrganization($organizationId)->where('status', 'open')->count(),
            'in_progress' => Complaint::forOrganization($organizationId)->where('status', 'in_progress')->count(),
            'resolved' => Complaint::forOrganization($organizationId)->where('status', 'resolved')->count(),
            'closed' => Complaint::forOrganization($organizationId)->where('status', 'closed')->count(),
        ];

        // Category Breakdown
        $categoryStats = Complaint::forOrganization($organizationId)->select('category', DB::raw('count(*) as total'))
        ->groupBy('category')
        ->get();

        // Priority Breakdown
        $priorityStats = Complaint::forOrganization($organizationId)->select('priority', DB::raw('count(*) as total'))
        ->groupBy('priority')
        ->get();

        // Agent Performance
        $agentPerformance = User::where('role', 'helpdesk_agent')
            ->where('organization_id', $organizationId)
            ->withCount([
                'assignedComplaints',
                'assignedComplaints as resolved_count' => function($q) {
                    $q->where('status', 'resolved');
                },
                'assignedComplaints as closed_count' => function($q) {
                    $q->where('status', 'closed');
                }
            ])
            ->get();

        // Support Performance
        $supportPerformance = User::where('role', 'support_person')
            ->where('organization_id', $organizationId)
            ->withCount([
                'supportComplaints',
                'supportComplaints as resolved_count' => function($q) {
                    $q->where('status', 'resolved');
                },
                'supportComplaints as closed_count' => function($q) {
                    $q->where('status', 'closed');
                }
            ])
            ->get();

        // Resolution Time Analysis
        $avgResolutionTime = Complaint::forOrganization($organizationId)->where('status', 'resolved')
        ->orWhere('status', 'closed')
        ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours')
        ->first();

        // Monthly Trend (Last 6 months)
        $monthlyTrend = Complaint::forOrganization($organizationId)->where('created_at', '>=', now()->subMonths(6))
        ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, count(*) as total')
        ->groupBy('month')
        ->orderBy('month')
        ->get();

        return view('reports.manager', compact(
            'stats',
            'categoryStats',
            'priorityStats',
            'agentPerformance',
            'supportPerformance',
            'avgResolutionTime',
            'monthlyTrend'
        ));
    }

    private function adminReports()
    {
        // System-wide Statistics
        $stats = [
            'total_organizations' => Organization::count(),
            'total_users' => User::count(),
            'total_complaints' => Complaint::count(),
            'active_users' => User::where('is_active', true)->count(),
            'open_complaints' => Complaint::where('status', 'open')->count(),
            'in_progress' => Complaint::where('status', 'in_progress')->count(),
            'resolved' => Complaint::where('status', 'resolved')->count(),
            'closed' => Complaint::where('status', 'closed')->count(),
        ];

        // Organization Performance
        $organizations = Organization::withCount([
            'users',
            'complaints',
        ])->get();

        $orgPerformance = $organizations->map(function($org) {
            $complaints = Complaint::whereHas('consumer', function($q) use ($org) {
                $q->where('organization_id', $org->id);
            });

            return [
                'name' => $org->name,
                'users' => $org->users_count,
                'total_complaints' => $complaints->count(),
                'open' => $complaints->clone()->where('status', 'open')->count(),
                'resolved' => $complaints->clone()->where('status', 'resolved')->count(),
                'closed' => $complaints->clone()->where('status', 'closed')->count(),
            ];
        });

        // Category Breakdown (System-wide)
        $categoryStats = Complaint::select('category', DB::raw('count(*) as total'))
            ->groupBy('category')
            ->get();

        // Priority Breakdown (System-wide)
        $priorityStats = Complaint::select('priority', DB::raw('count(*) as total'))
            ->groupBy('priority')
            ->get();

        // User Role Distribution
        $roleStats = User::select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->get();

        // Monthly Trend (Last 12 months)
        $monthlyTrend = Complaint::where('created_at', '>=', now()->subMonths(12))
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, count(*) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Resolution Time by Organization
        $avgResolutionByOrg = [];
        foreach ($organizations as $org) {
            $avgTime = Complaint::whereHas('consumer', function($q) use ($org) {
                $q->where('organization_id', $org->id);
            })->whereIn('status', ['resolved', 'closed'])
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours')
            ->first();

            $avgResolutionByOrg[$org->name] = $avgTime->avg_hours ?? 0;
        }

        return view('reports.admin', compact(
            'stats',
            'orgPerformance',
            'categoryStats',
            'priorityStats',
            'roleStats',
            'monthlyTrend',
            'avgResolutionByOrg'
        ));
    }

    public function export(Request $request)
    {
        $type = $request->input('type', 'all');
        $user = Auth::user();

        // Generate CSV export
        $filename = 'complaints_report_' . date('Y-m-d') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($user, $type) {
            $file = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($file, ['Ticket Number', 'Title', 'Category', 'Priority', 'Status', 'Consumer', 'Created At', 'Updated At']);

            // Get complaints based on user role
            $query = Complaint::with(['consumer', 'assignedAgent', 'assignedSupport']);

            if ($user->isHelpDeskManager()) {
                $query->whereHas('consumer', function($q) use ($user) {
                    $q->where('organization_id', $user->organization_id);
                });
            }

            if ($type !== 'all') {
                $query->where('status', $type);
            }

            $complaints = $query->get();

            foreach ($complaints as $complaint) {
                fputcsv($file, [
                    $complaint->ticket_number,
                    $complaint->title,
                    ucfirst($complaint->category),
                    ucfirst($complaint->priority),
                    ucfirst(str_replace('_', ' ', $complaint->status)),
                    $complaint->consumer->name,
                    $complaint->created_at->format('Y-m-d H:i:s'),
                    $complaint->updated_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
