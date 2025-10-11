<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Complaint;

class ComplaintStatusUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public $complaint;
    public $oldStatus;
    public $newStatus;
    public $notes;

    /**
     * Create a new message instance.
     */
    public function __construct(Complaint $complaint, $oldStatus, $newStatus, $notes = null)
    {
        $this->complaint = $complaint;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->notes = $notes;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $statusLabels = [
            'open' => 'Open',
            'in_progress' => 'In Progress',
            'resolved' => 'Resolved',
            'closed' => 'Closed'
        ];

        return new Envelope(
            subject: 'Complaint Status Update - ' . $this->complaint->ticket_number . ' (' . $statusLabels[$this->newStatus] . ')',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.complaint-status-update',
            with: [
                'complaint' => $this->complaint,
                'oldStatus' => $this->oldStatus,
                'newStatus' => $this->newStatus,
                'notes' => $this->notes,
                'trackingUrl' => $this->complaint->isGuestComplaint()
                    ? route('guest.complaint.show', $this->complaint->tracking_token)
                    : route('complaints.show', $this->complaint),
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
