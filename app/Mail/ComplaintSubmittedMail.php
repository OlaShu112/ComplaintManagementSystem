<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use App\Models\Complaint;

class ComplaintSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $complaint;

    /**
     * Create a new message instance.
     */
    public function __construct(Complaint $complaint)
    {
        $this->complaint = $complaint;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $organization = $this->complaint->getOrganization();
        $orgName = $organization ? $organization->name : 'Complaint Management System';

        return new Envelope(
            subject: 'Complaint Received – Ticket No. ' . $this->complaint->ticket_number,
            from: config('mail.from.address'),
            replyTo: $organization && $organization->support_email
                ? [$organization->support_email]
                : [config('mail.from.address')],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.complaint-submitted',
            text: 'emails.complaint-submitted-text',
            with: [
                'complaint' => $this->complaint,
                'trackingUrl' => route('guest.complaint.show', $this->complaint->tracking_token),
            ]
        );
    }

    /**
     * Get the message headers.
     */
    public function headers(): Headers
    {
        return new Headers(
            messageId: null,
            references: [],
            text: [
                'X-Priority' => '1',
                'X-MSMail-Priority' => 'High',
                'Importance' => 'High',
                'X-Mailer' => 'Complaint Management System',
            ],
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
