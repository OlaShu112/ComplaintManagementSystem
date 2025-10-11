<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Complaint;
use App\Models\ComplaintStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ComplaintAssignmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    // Show assignment interface for managers
    public function showAssignment(Complaint $complaint)
    {
        $user = Auth::user();

        // Only managers and agents can assign
        if (!$user->isHelpDeskManager() && !$user->isHelpDeskAgent()) {
            abort(403, 'You do not have permission to assign complaints.');
        }

        // Get available staff based on complaint category and priority
        $availableStaff = $this->getAvailableStaff($complaint);

        return view('complaints.assignment', compact('complaint', 'availableStaff'));
    }

    // Assign complaint to appropriate staff
    public function assign(Request $request, Complaint $complaint)
    {
        $user = Auth::user();

        // Only managers and agents can assign
        if (!$user->isHelpDeskManager() && !$user->isHelpDeskAgent()) {
            abort(403, 'You do not have permission to assign complaints.');
        }

        $request->validate([
            'assigned_agent_id' => 'nullable|exists:users,id',
            'assigned_support_id' => 'nullable|exists:users,id',
            'assignment_reason' => 'required|string',
            'priority' => 'sometimes|in:low,medium,high,urgent',
        ]);

        // Update complaint assignment
        $complaint->update([
            'assigned_agent_id' => $request->assigned_agent_id,
            'assigned_support_id' => $request->assigned_support_id,
            'priority' => $request->priority ?? $complaint->priority,
            'status' => $request->assigned_agent_id || $request->assigned_support_id ? 'in_progress' : $complaint->status,
        ]);

        // Log assignment
        ComplaintStatusHistory::create([
            'complaint_id' => $complaint->id,
            'old_status' => $complaint->status,
            'new_status' => $complaint->status,
            'notes' => 'Complaint assigned: ' . $request->assignment_reason,
            'changed_by' => Auth::id(),
        ]);

        return redirect()->route('complaints.show', $complaint)
            ->with('success', 'Complaint assigned successfully!');
    }

    // Auto-assign complaint based on rules
    public function autoAssign(Complaint $complaint)
    {
        $user = Auth::user();

        // Only managers can auto-assign
        if (!$user->isHelpDeskManager()) {
            abort(403, 'Only managers can auto-assign complaints.');
        }

        $assignment = $this->determineAssignment($complaint);

        if ($assignment) {
            $complaint->update([
                'assigned_agent_id' => $assignment['agent_id'] ?? null,
                'assigned_support_id' => $assignment['support_id'] ?? null,
                'status' => 'in_progress',
            ]);

            // Log auto-assignment
            ComplaintStatusHistory::create([
                'complaint_id' => $complaint->id,
                'old_status' => 'open',
                'new_status' => 'in_progress',
                'notes' => 'Auto-assigned based on category and priority: ' . $assignment['reason'],
                'changed_by' => Auth::id(),
            ]);

            return redirect()->route('complaints.show', $complaint)
                ->with('success', 'Complaint auto-assigned successfully!');
        }

        return redirect()->route('complaints.show', $complaint)
            ->with('info', 'No suitable staff found for auto-assignment. Please assign manually.');
    }

    // Get available staff based on complaint characteristics
    private function getAvailableStaff(Complaint $complaint)
    {
        $organizationId = Auth::user()->organization_id;

        // Get agents and support staff from the same organization
        $agents = User::where('role', 'helpdesk_agent')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();

        $supportStaff = User::where('role', 'support_person')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();

        // Filter based on complaint category and priority
        $filteredAgents = $this->filterStaffByCategory($agents, $complaint);
        $filteredSupport = $this->filterStaffByCategory($supportStaff, $complaint);

        return [
            'agents' => $filteredAgents,
            'support_staff' => $filteredSupport,
        ];
    }

    // Filter staff based on complaint category
    private function filterStaffByCategory($staff, Complaint $complaint)
    {
        return $staff->filter(function ($user) use ($complaint) {
            // Add logic to filter staff based on their expertise/category
            // For now, return all active staff
            return $user->is_active;
        });
    }

    // Determine automatic assignment based on rules
    private function determineAssignment(Complaint $complaint)
    {
        $organizationId = Auth::user()->organization_id;

        // Assignment rules based on category and priority
        $rules = [
            'technical' => [
                'high' => 'support_person',
                'urgent' => 'support_person',
                'default' => 'helpdesk_agent',
            ],
            'billing' => [
                'default' => 'helpdesk_agent',
            ],
            'service' => [
                'high' => 'support_person',
                'urgent' => 'support_person',
                'default' => 'helpdesk_agent',
            ],
            'product' => [
                'high' => 'support_person',
                'urgent' => 'support_person',
                'default' => 'helpdesk_agent',
            ],
            'delivery' => [
                'default' => 'helpdesk_agent',
            ],
            'other' => [
                'default' => 'helpdesk_agent',
            ],
        ];

        $category = $complaint->category;
        $priority = $complaint->priority;

        $assignmentRole = $rules[$category][$priority] ?? $rules[$category]['default'] ?? 'helpdesk_agent';

        // Find available staff for the determined role
        $availableStaff = User::where('role', $assignmentRole)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();

        if ($availableStaff->isEmpty()) {
            return null;
        }

        // Find staff with least current workload
        $staffWithWorkload = $availableStaff->map(function ($staff) {
            $workload = $staff->role === 'helpdesk_agent'
                ? $staff->assignedComplaints()->where('status', '!=', 'closed')->count()
                : $staff->supportComplaints()->where('status', '!=', 'closed')->count();

            return [
                'staff' => $staff,
                'workload' => $workload,
            ];
        });

        $selectedStaff = $staffWithWorkload->sortBy('workload')->first();

        if (!$selectedStaff) {
            return null;
        }

        $result = [
            'reason' => "Auto-assigned to {$selectedStaff['staff']->name} based on category '{$category}' and priority '{$priority}'",
        ];

        if ($assignmentRole === 'helpdesk_agent') {
            $result['agent_id'] = $selectedStaff['staff']->id;
        } else {
            $result['support_id'] = $selectedStaff['staff']->id;
        }

        return $result;
    }

    // Escalate complaint to manager
    public function escalate(Complaint $complaint)
    {
        $user = Auth::user();

        // Only agents and support staff can escalate
        if (!$user->isHelpDeskAgent() && !$user->isSupportPerson()) {
            abort(403, 'You do not have permission to escalate complaints.');
        }

        // Find manager in the same organization
        $manager = User::where('role', 'helpdesk_manager')
            ->where('organization_id', Auth::user()->organization_id)
            ->where('is_active', true)
            ->first();

        if (!$manager) {
            return redirect()->route('complaints.show', $complaint)
                ->with('error', 'No manager available for escalation.');
        }

        // Assign to manager
        $complaint->update([
            'assigned_agent_id' => $manager->id,
            'status' => 'in_progress',
        ]);

        // Log escalation
        ComplaintStatusHistory::create([
            'complaint_id' => $complaint->id,
            'old_status' => $complaint->status,
            'new_status' => 'in_progress',
            'notes' => "Complaint escalated to manager: {$manager->name}",
            'changed_by' => Auth::id(),
        ]);

        return redirect()->route('complaints.show', $complaint)
            ->with('success', 'Complaint escalated to manager successfully!');
    }

    // Reassign complaint
    public function reassign(Request $request, Complaint $complaint)
    {
        $user = Auth::user();

        // Only managers can reassign
        if (!$user->isHelpDeskManager()) {
            abort(403, 'Only managers can reassign complaints.');
        }

        $request->validate([
            'new_agent_id' => 'nullable|exists:users,id',
            'new_support_id' => 'nullable|exists:users,id',
            'reassignment_reason' => 'required|string',
        ]);

        $oldAgent = $complaint->assignedAgent;
        $oldSupport = $complaint->assignedSupport;

        $complaint->update([
            'assigned_agent_id' => $request->new_agent_id,
            'assigned_support_id' => $request->new_support_id,
        ]);

        // Log reassignment
        ComplaintStatusHistory::create([
            'complaint_id' => $complaint->id,
            'old_status' => $complaint->status,
            'new_status' => $complaint->status,
            'notes' => "Complaint reassigned: {$request->reassignment_reason}",
            'changed_by' => Auth::id(),
        ]);

        return redirect()->route('complaints.show', $complaint)
            ->with('success', 'Complaint reassigned successfully!');
    }
}
