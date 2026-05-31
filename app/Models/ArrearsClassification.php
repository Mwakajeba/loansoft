<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArrearsClassification extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'days_from',
        'days_to',
        'bucket_label',
        'status',
        'provision_percentage',
        'comments',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'days_from' => 'integer',
        'days_to' => 'integer',
        'provision_percentage' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeForCompany($query)
    {
        return $query->where('company_id', current_company_id());
    }
}

