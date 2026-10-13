<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesSetting extends Model
{
    protected $fillable = [
        'id',
        'business_day_cutoff',
    ];

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], [
            'business_day_cutoff' => '00:00:00',
        ]);
    }
}
