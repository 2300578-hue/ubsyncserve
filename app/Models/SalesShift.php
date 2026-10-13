<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesShift extends Model
{
    protected $fillable = [
        'started_by',
        'ended_by',
        'business_date',
        'cutoff_time',
        'started_at',
        'ended_at',
        'opening_float',
        'status',
        'order_count',
        'items_sold',
        'total_sales',
        'gross_sales',
        'discount_total',
        'net_sales',
        'vat_total',
        'cash_sales',
        'gcash_sales',
        'z_read',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'order_count' => 'integer',
            'items_sold' => 'integer',
            'total_sales' => 'decimal:2',
            'gross_sales' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'net_sales' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'cash_sales' => 'decimal:2',
            'gcash_sales' => 'decimal:2',
            'z_read' => 'array',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
