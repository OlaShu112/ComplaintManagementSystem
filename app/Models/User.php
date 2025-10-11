<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'organization_id',
        'consumer_number',
        'phone',
        'address',
        'is_active'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function complaints()
    {
        return $this->hasMany(Complaint::class, 'consumer_id');
    }

    public function assignedComplaints()
    {
        return $this->hasMany(Complaint::class, 'assigned_agent_id');
    }

    public function supportComplaints()
    {
        return $this->hasMany(Complaint::class, 'assigned_support_id');
    }

    // Role checking methods
    public function isConsumer()
    {
        return $this->role === 'consumer';
    }

    public function isHelpDeskAgent()
    {
        return $this->role === 'helpdesk_agent';
    }

    public function isSupportPerson()
    {
        return $this->role === 'support_person';
    }

    public function isHelpDeskManager()
    {
        return $this->role === 'helpdesk_manager';
    }

    public function isOrganizationAdmin()
    {
        return $this->role === 'organization_admin';
    }

    public function isSystemAdmin()
    {
        return $this->role === 'system_admin';
    }

    public function isStaff()
    {
        return in_array($this->role, ['helpdesk_agent', 'support_person', 'helpdesk_manager', 'organization_admin', 'system_admin']);
    }

    public function isAdmin()
    {
        return in_array($this->role, ['organization_admin', 'system_admin']);
    }

    public function canUpdateComplaint($complaint)
    {
        // Organization admin can update any complaint in their organization
        if ($this->isOrganizationAdmin()) {
            return $complaint->getOrganizationId() === $this->organization_id;
        }

        // Managers can update any complaint in their organization
        if ($this->isHelpDeskManager()) {
            return $complaint->getOrganizationId() === $this->organization_id;
        }

        // System admin can update any complaint
        if ($this->isSystemAdmin()) {
            return true;
        }

        // Agents can update complaints assigned to them
        if ($this->isHelpDeskAgent()) {
            return $complaint->assigned_agent_id === $this->id;
        }

        // Support persons can update complaints assigned to them
        if ($this->isSupportPerson()) {
            return $complaint->assigned_support_id === $this->id;
        }

        return false;
    }
}
