<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'feed_website_id',
        'status',
        'processed_records',
        'created_records',
        'updated_records',
        'deleted_records',
        'log_messages',
    ];

    protected $casts = [
        'log_messages' => 'array',
    ];

    public function feedWebsite()
    {
        return $this->belongsTo(FeedWebsite::class);
    }
}