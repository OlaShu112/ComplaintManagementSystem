<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'title',
        'description',
        'status',
        'priority',
        'category',
        'subcategory',
        'consumer_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_organization',
        'tracking_token',
        'assigned_agent_id',
        'assigned_support_id',
        'resolution_notes',
        'consumer_feedback',
        'satisfaction_rating',
        'resolved_at',
        'closed_at'
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function consumer()
    {
        return $this->belongsTo(User::class, 'consumer_id');
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function assignedSupport()
    {
        return $this->belongsTo(User::class, 'assigned_support_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(ComplaintStatusHistory::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($complaint) {
            $complaint->ticket_number = 'TKT-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);

            // Generate tracking token for guest submissions
            if ($complaint->guest_email && !$complaint->tracking_token) {
                $complaint->tracking_token = \Illuminate\Support\Str::random(32);
            }
        });
    }

    // Check if this is a guest complaint
    public function isGuestComplaint()
    {
        return !is_null($this->guest_email) || is_null($this->consumer_id);
    }

    // Get the email for notifications (guest or consumer)
    public function getNotificationEmail()
    {
        if ($this->isGuestComplaint()) {
            return $this->guest_email;
        } else {
            return $this->consumer ? $this->consumer->email : null;
        }
    }

    // Get the name for notifications (guest or consumer)
    public function getNotificationName()
    {
        if ($this->isGuestComplaint()) {
            return $this->guest_name;
        } else {
            return $this->consumer ? $this->consumer->name : 'Unknown User';
        }
    }

    // Get the organization name for the complaint
    public function getOrganizationName()
    {
        if ($this->isGuestComplaint()) {
            return $this->guest_organization ?: 'Guest Organization';
        } else {
            // Check if consumer exists and has an organization
            if ($this->consumer && $this->consumer->organization) {
                return $this->consumer->organization->name;
            }
            return 'Unknown Organization';
        }
    }

    // Get the organization for the complaint
    public function getOrganization()
    {
        if ($this->isGuestComplaint()) {
            // For guest complaints, try to find organization by name
            if ($this->guest_organization) {
                return Organization::where('name', 'like', '%' . $this->guest_organization . '%')->first();
            }
            return null;
        } else {
            // Check if consumer exists and has an organization
            if ($this->consumer && $this->consumer->organization) {
                return $this->consumer->organization;
            }
            return null;
        }
    }

    // Get the organization ID for the complaint
    public function getOrganizationId()
    {
        if ($this->isGuestComplaint()) {
            // For guest complaints, try to find organization by name
            if ($this->guest_organization) {
                $org = Organization::where('name', 'like', '%' . $this->guest_organization . '%')->first();
                return $org ? $org->id : null;
            }
            return null;
        } else {
            // Check if consumer exists and has an organization
            if ($this->consumer && $this->consumer->organization) {
                return $this->consumer->organization->id;
            }
            return $this->consumer ? $this->consumer->organization_id : null;
        }
    }

    // Scope to filter complaints by organization
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where(function($q) use ($organizationId) {
            // For registered users
            $q->whereHas('consumer', function($subQ) use ($organizationId) {
                $subQ->where('organization_id', $organizationId);
            })
            // For guest complaints - match by organization name
            ->orWhere(function($guestQ) use ($organizationId) {
                $organization = Organization::find($organizationId);
                if ($organization) {
                    $guestQ->whereNotNull('guest_organization')
                           ->where('guest_organization', 'like', '%' . $organization->name . '%');
                }
            });
        });
    }
}
