<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Complaint;
use App\Models\ComplaintStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\ComplaintStatusUpdateMail;

class StaffComplaintController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (!Auth::user()->isStaff()) {
                abort(403, 'Access denied. Staff only.');
            }
            return $next($request);
        });
    }

    public function create()
    {
        // Get users from the same organization
        $users = User::where('organization_id', Auth::user()->organization_id)
            ->where('role', 'consumer')
            ->get();

        return view('staff.create-complaint', compact('users'));
    }

    public function findOrCreateUser(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            return response()->json([
                'exists' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ]
            ]);
        }

        return response()->json([
            'exists' => false,
            'message' => 'User not found. Please fill in details to create new user.'
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|string',
            'subcategory' => 'nullable|string',
            'priority' => 'required|in:low,medium,high,urgent',
            'consumer_id' => 'nullable|exists:users,id',
            'account_number' => 'required_without:consumer_id|nullable|string|size:8',
            'consumer_email' => 'required_without:consumer_id|nullable|email|max:255',
            'consumer_name' => 'required_without:consumer_id|nullable|string|max:255',
            'consumer_phone' => 'nullable|string|max:20',
            'consumer_address' => 'nullable|string|max:500',
        ], [
            'consumer_id.exists' => 'The selected user does not exist.',
            'account_number.required_without' => 'Please enter 8-digit account number or select an existing user.',
            'account_number.size' => 'Account number must be exactly 8 characters.',
            'consumer_email.required_without' => 'Please enter user email address or select an existing user.',
            'consumer_email.email' => 'Please enter a valid email address.',
            'consumer_name.required_without' => 'Please enter user name or select an existing user.',
            'title.required' => 'Please enter a complaint title.',
            'description.required' => 'Please provide a detailed description of the complaint.',
            'category.required' => 'Please select a complaint category.',
            'priority.required' => 'Please select a priority level.',
        ]);

        // Find or create consumer
        if ($request->consumer_id) {
            $consumer = User::findOrFail($request->consumer_id);
        } else {
            // First, try to find user by account number and email
            if ($request->account_number && $request->consumer_email) {
                $consumer = User::where('consumer_number', $request->account_number)
                    ->where('email', $request->consumer_email)
                    ->where('organization_id', Auth::user()->organization_id)
                    ->where('role', 'consumer')
                    ->first();

                if (!$consumer) {
                    return back()->withErrors([
                        'account_number' => 'Account number and email do not match any registered user in your organization. Please verify the details.'
                    ])->withInput();
                }

                // User found, update their information if provided
                $consumer->update([
                    'name' => $request->consumer_name ?? $consumer->name,
                    'phone' => $request->consumer_phone ?? $consumer->phone,
                    'address' => $request->consumer_address ?? $consumer->address,
                ]);
            } else {
                return back()->withErrors([
                    'account_number' => 'Please provide both account number and email address.'
                ])->withInput();
            }
        }

        $complaint = Complaint::create([
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category,
            'subcategory' => $request->subcategory,
            'priority' => $request->priority,
            'consumer_id' => $consumer->id,
            'assigned_agent_id' => Auth::user()->isHelpDeskAgent() ? Auth::id() : null,
            'assigned_support_id' => Auth::user()->isSupportPerson() ? Auth::id() : null,
        ]);

        // Log status change
        ComplaintStatusHistory::create([
            'complaint_id' => $complaint->id,
            'new_status' => 'open',
            'notes' => 'Complaint created by staff member: ' . Auth::user()->name,
            'changed_by' => Auth::id(),
        ]);

        $message = 'Complaint created successfully! Ticket Number: ' . $complaint->ticket_number;
        if (!$request->consumer_id && !User::where('email', $request->consumer_email)->whereNot('id', $consumer->id)->exists()) {
            $message .= ' (New user account created with default password: password123)';
        }

        return redirect()->route('complaints.show', $complaint)
            ->with('success', $message);
    }

    public function updateStatus(Request $request, Complaint $complaint)
    {
        // Check if user has permission to update this complaint
        if (!Auth::user()->canUpdateComplaint($complaint)) {
            abort(403, 'You do not have permission to update this complaint.');
        }

        $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
            'notes' => 'required|string',
            'resolution_notes' => 'nullable|string',
        ]);

        $oldStatus = $complaint->status;
        $complaint->update([
            'status' => $request->status,
            'resolution_notes' => $request->resolution_notes,
        ]);

        // Log status change
        ComplaintStatusHistory::create([
            'complaint_id' => $complaint->id,
            'old_status' => $oldStatus,
            'new_status' => $request->status,
            'notes' => $request->notes,
            'changed_by' => Auth::id(),
        ]);

        // Send email notification
        try {
            Mail::to($complaint->getNotificationEmail())->send(
                new ComplaintStatusUpdateMail($complaint, $oldStatus, $request->status, $request->notes)
            );
        } catch (\Exception $e) {
            // Log error but don't fail the update
            \Log::error('Failed to send status update email: ' . $e->getMessage());
        }

        return redirect()->route('complaints.show', $complaint)
            ->with('success', 'Complaint status updated successfully!');
    }

    public function assign(Request $request, Complaint $complaint)
    {
        // Only managers and agents can assign
        if (!Auth::user()->isHelpDeskManager() && !Auth::user()->isHelpDeskAgent()) {
            abort(403, 'You do not have permission to assign complaints.');
        }

        $request->validate([
            'assigned_agent_id' => 'nullable|exists:users,id',
            'assigned_support_id' => 'nullable|exists:users,id',
            'notes' => 'required|string',
        ]);

        $complaint->update([
            'assigned_agent_id' => $request->assigned_agent_id,
            'assigned_support_id' => $request->assigned_support_id,
        ]);

        // Log assignment
        ComplaintStatusHistory::create([
            'complaint_id' => $complaint->id,
            'old_status' => $complaint->status,
            'new_status' => $complaint->status,
            'notes' => 'Complaint assigned: ' . $request->notes,
            'changed_by' => Auth::id(),
        ]);

        return redirect()->route('complaints.show', $complaint)
            ->with('success', 'Complaint assigned successfully!');
    }
}
