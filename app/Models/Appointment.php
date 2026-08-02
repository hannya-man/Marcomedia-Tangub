<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'client_name', 'contact_number', 'email', 'service', 'appointment_date',
        'appointment_time', 'location', 'notes', 'status',
        'archived_at', 'archived_reason',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'archived_at' => 'datetime',
    ];
}
