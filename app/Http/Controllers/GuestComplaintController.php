<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Complaint;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\ComplaintSubmittedMail;
use App\Mail\ComplaintStatusUpdateMail;

class GuestComplaintController extends Controller
{
    /**
     * Show the guest complaint submission form
     */
    public function create()
    {
        $organizations = Organization::where('status', 'active')->orderBy('name')->get();
        return view('guest.complaint-form', compact('organizations'));
    }

    /**
     * Store a new guest complaint
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|min:10',
            'category' => 'required|string',
            'subcategory' => 'nullable|string',
            'priority' => 'required|in:low,medium,high,urgent',
            'guest_name' => 'required|string|max:255',
            'guest_email' => 'required|email|max:255',
            'guest_phone' => 'nullable|string|max:20',
            'account_number' => 'required|string|size:8',
            'organization_id' => 'required|exists:organizations,id',
        ], [
            'account_number.size' => 'Account number must be exactly 8 characters.',
            'organization_id.required' => 'Please select your organization.',
            'organization_id.exists' => 'The selected organization is invalid.',
        ]);

        // Find registered user by email, phone (optional), AND account number (consumer_number) and organization
        $user = User::where('email', $request->guest_email)
            ->where('consumer_number', $request->account_number)
            ->where('organization_id', $request->organization_id)
            ->where('role', 'consumer')
            ->where('is_active', true)
            ->first();

        // If phone is provided, also verify it matches
        if (!$user && $request->guest_phone) {
            $user = User::where('email', $request->guest_email)
                ->where('consumer_number', $request->account_number)
                ->where('phone', $request->guest_phone)
                ->where('organization_id', $request->organization_id)
                ->where('role', 'consumer')
                ->where('is_active', true)
                ->first();
        }

        if (!$user) {
            return back()->withErrors([
                'account_number' => 'Record not found. Your email, account number, and organization do not match our records. Please contact your organization administrator to register.'
            ])->withInput();
        }

        // Verify user has an organization
        if (!$user->organization) {
            return back()->withErrors([
                'account_number' => 'Your account is not associated with any organization. Please contact the administrator.'
            ])->withInput();
        }

        // Create the complaint linked to the registered user
        $complaint = Complaint::create([
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category,
            'subcategory' => $request->subcategory,
            'priority' => $request->priority,
            'consumer_id' => $user->id, // Link to registered user
            'guest_name' => $request->guest_name,
            'guest_email' => $request->guest_email,
            'guest_phone' => $request->guest_phone,
            'guest_organization' => $user->organization->name,
            'status' => 'open',
        ]);

        // Send confirmation email
        try {
            Mail::to($complaint->guest_email)->send(new ComplaintSubmittedMail($complaint));
        } catch (\Exception $e) {
            // Log error but don't fail the complaint creation
            Log::error('Failed to send complaint confirmation email: ' . $e->getMessage());
        }

        return redirect()->route('guest.complaint.success', ['token' => $complaint->tracking_token])
            ->with('success', 'Your complaint has been submitted successfully!');
    }

    /**
     * Show success page with tracking information
     */
    public function success(Request $request)
    {
        $token = $request->get('token');
        $complaint = Complaint::where('tracking_token', $token)->first();

        if (!$complaint) {
            return redirect()->route('guest.complaint.create')
                ->with('error', 'Invalid tracking token.');
        }

        return view('guest.complaint-success', compact('complaint'));
    }

    /**
     * Show complaint tracking page
     */
    public function track(Request $request)
    {
        $token = $request->get('token');
        $complaint = null;

        if ($token) {
            // Try to find by tracking token first, then by ticket number
            $complaint = Complaint::with(['statusHistory.changedBy', 'assignedAgent', 'assignedSupport'])
                ->where(function($query) use ($token) {
                    $query->where('tracking_token', $token)
                          ->orWhere('ticket_number', $token);
                })
                ->first();
        }

        return view('guest.complaint-track', compact('complaint', 'token'));
    }

    /**
     * Show complaint details for tracking
     */
    public function show($token)
    {
        $complaint = Complaint::with(['statusHistory.changedBy', 'assignedAgent', 'assignedSupport'])
            ->where(function($query) use ($token) {
                $query->where('tracking_token', $token)
                      ->orWhere('ticket_number', $token);
            })
            ->first();

        if (!$complaint) {
            return redirect()->route('guest.complaint.track')
                ->with('error', 'Complaint not found. Please check your ticket number or tracking token.');
        }

        return view('guest.complaint-details', compact('complaint'));
    }

    /**
     * Provide feedback for a guest complaint
     */
    public function feedback(Request $request, $token)
    {
        $complaint = Complaint::where(function($query) use ($token) {
            $query->where('tracking_token', $token)
                  ->orWhere('ticket_number', $token);
        })->first();

        if (!$complaint) {
            return redirect()->route('guest.complaint.track')
                ->with('error', 'Complaint not found. Please check your ticket number or tracking token.');
        }

        if ($complaint->status !== 'resolved') {
            return redirect()->route('guest.complaint.show', $token)
                ->with('error', 'Feedback can only be provided for resolved complaints.');
        }

        $request->validate([
            'satisfaction_rating' => 'required|integer|min:1|max:5',
            'consumer_feedback' => 'nullable|string|max:1000',
        ]);

        $complaint->update([
            'satisfaction_rating' => $request->satisfaction_rating,
            'consumer_feedback' => $request->consumer_feedback,
        ]);

        return redirect()->route('guest.complaint.show', $token)
            ->with('success', 'Thank you for your feedback!');
    }
}
