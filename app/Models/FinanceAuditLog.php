<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinanceAuditLog extends Model
{
    use HasFactory;

    protected $table = 'finance_audit_logs';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before_data',
        'after_data',
        'notes',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
    ];
}
