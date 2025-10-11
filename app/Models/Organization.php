<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'organization_number',
        'email',
        'phone',
        'address',
        'status',
        'support_email',
        'support_phone',
        'full_address',
        'city',
        'postal_code',
        'country'
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function complaints()
    {
        return $this->hasManyThrough(Complaint::class, User::class, 'organization_id', 'consumer_id');
    }
}
