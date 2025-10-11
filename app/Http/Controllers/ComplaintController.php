<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Complaint;
use App\Models\ComplaintStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\ComplaintStatusUpdateMail;

class ComplaintController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = Auth::user();
        $query = Complaint::with(['consumer', 'assignedAgent', 'assignedSupport']);

        if ($user->isConsumer()) {
            $query->where('consumer_id', $user->id);
        } elseif ($user->isHelpDeskAgent()) {
            $query->where(function($q) use ($user) {
                $q->where('assigned_agent_id', $user->id)
                  ->orWhere('status', 'open');
            });
        } elseif ($user->isSupportPerson()) {
            $query->where('assigned_support_id', $user->id);
        }

        $complaints = $query->latest()->paginate(15);

        return view('complaints.index', compact('complaints'));
    }

    public function create()
    {
        return view('complaints.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|string',
            'subcategory' => 'nullable|string',
            'priority' => 'required|in:low,medium,high,urgent',
        ]);

        $complaint = Complaint::create([
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category,
            'subcategory' => $request->subcategory,
            'priority' => $request->priority,
            'consumer_id' => Auth::id(),
        ]);

        // Log status change
        ComplaintStatusHistory::create([
            'complaint_id' => $complaint->id,
            'new_status' => 'open',
            'notes' => 'Complaint created',
            'changed_by' => Auth::id(),
        ]);

        return redirect()->route('complaints.show', $complaint)
            ->with('success', 'Complaint submitted successfully! Ticket Number: ' . $complaint->ticket_number);
    }

    public function show(Complaint $complaint)
    {
        $user = Auth::user();

        // Check permissions
        if ($user->isConsumer() && $complaint->consumer_id !== $user->id) {
            abort(403);
        }

        $complaint->load(['consumer', 'assignedAgent', 'assignedSupport', 'statusHistory.changedBy']);

        return view('complaints.show', compact('complaint'));
    }

    public function edit(Complaint $complaint)
    {
        $user = Auth::user();

        if ($user->isConsumer() && $complaint->consumer_id !== $user->id) {
            abort(403);
        }

        if ($user->isConsumer() && $complaint->status !== 'open') {
            return redirect()->route('complaints.show', $complaint)
                ->with('error', 'Cannot edit complaint that is not in open status');
        }

        return view('complaints.edit', compact('complaint'));
    }

    public function update(Request $request, Complaint $complaint)
    {
        $user = Auth::user();

        if ($user->isConsumer() && $complaint->consumer_id !== $user->id) {
            abort(403);
        }

        if ($user->isConsumer()) {
            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'category' => 'required|string',
                'subcategory' => 'nullable|string',
                'priority' => 'required|in:low,medium,high,urgent',
            ]);

            $complaint->update($request->only(['title', 'description', 'category', 'subcategory', 'priority']));
        } else {
            // Staff can update status, assign agents, add resolution notes
            $request->validate([
                'status' => 'sometimes|in:open,in_progress,resolved,closed',
                'assigned_agent_id' => 'sometimes|nullable|exists:users,id',
                'assigned_support_id' => 'sometimes|nullable|exists:users,id',
                'resolution_notes' => 'sometimes|nullable|string',
            ]);

            $oldStatus = $complaint->status;
            $complaint->update($request->all());

            // Log status change if status changed
            if ($request->has('status') && $oldStatus !== $request->status) {
                ComplaintStatusHistory::create([
                    'complaint_id' => $complaint->id,
                    'old_status' => $oldStatus,
                    'new_status' => $request->status,
                    'notes' => $request->status_notes ?? 'Status updated',
                    'changed_by' => Auth::id(),
                ]);

                // Send email notification
                try {
                    Mail::to($complaint->getNotificationEmail())->send(
                        new ComplaintStatusUpdateMail($complaint, $oldStatus, $request->status, $request->status_notes)
                    );
                } catch (\Exception $e) {
                    // Log error but don't fail the update
                    \Log::error('Failed to send status update email: ' . $e->getMessage());
                }
            }
        }

        return redirect()->route('complaints.show', $complaint)
            ->with('success', 'Complaint updated successfully!');
    }

    public function destroy(Complaint $complaint)
    {
        $user = Auth::user();

        if ($user->isConsumer() && $complaint->consumer_id !== $user->id) {
            abort(403);
        }

        if ($user->isConsumer() && $complaint->status !== 'open') {
            return redirect()->route('complaints.show', $complaint)
                ->with('error', 'Cannot delete complaint that is not in open status');
        }

        $complaint->delete();

        return redirect()->route('complaints.index')
            ->with('success', 'Complaint deleted successfully!');
    }

    public function assign(Request $request, Complaint $complaint)
    {
        $user = Auth::user();

        if (!$user->isHelpDeskAgent() && !$user->isHelpDeskManager()) {
            abort(403);
        }

        $request->validate([
            'assigned_agent_id' => 'nullable|exists:users,id',
            'assigned_support_id' => 'nullable|exists:users,id',
        ]);

        $complaint->update($request->all());

        return redirect()->route('complaints.show', $complaint)
            ->with('success', 'Complaint assigned successfully!');
    }

    public function provideFeedback(Request $request, Complaint $complaint)
    {
        $user = Auth::user();

        if ($user->isConsumer() && $complaint->consumer_id !== $user->id) {
            abort(403);
        }

        $request->validate([
            'consumer_feedback' => 'required|string',
            'satisfaction_rating' => 'required|integer|min:1|max:5',
        ]);

        $complaint->update([
            'consumer_feedback' => $request->consumer_feedback,
            'satisfaction_rating' => $request->satisfaction_rating,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        // Log status change
        ComplaintStatusHistory::create([
            'complaint_id' => $complaint->id,
            'old_status' => $complaint->status,
            'new_status' => 'closed',
            'notes' => 'Closed with consumer feedback',
            'changed_by' => Auth::id(),
        ]);

        return redirect()->route('complaints.show', $complaint)
            ->with('success', 'Feedback submitted successfully!');
    }
}
