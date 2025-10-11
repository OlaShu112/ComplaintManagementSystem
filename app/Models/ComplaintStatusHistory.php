<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComplaintStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'complaint_status_history';

    protected $fillable = [
        'complaint_id',
        'old_status',
        'new_status',
        'notes',
        'changed_by'
    ];

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
