<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use LogsActivity;

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'image_path',
        'publish_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'publish_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
