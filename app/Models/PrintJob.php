<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintJob extends Model
{
    protected $fillable = [
        'job_type',
        'dedupe_key',
        'payload',
        'status',
        'claim_token',
        'claimed_at',
        'printed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'claimed_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }
}
