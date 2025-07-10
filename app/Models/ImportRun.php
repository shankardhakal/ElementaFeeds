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
        'failed_records',
        'skipped_records',
        'draft_records',
        'reconciled_records',
        'log_messages',
        'error_records',
        'finished_at',
        'reconciled_at'
    ];

    protected $casts = [
        'log_messages' => 'array',
        'error_records' => 'array',
        'finished_at' => 'datetime',
        'reconciled_at' => 'datetime'
    ];

    public function feedWebsite()
    {
        return $this->belongsTo(FeedWebsite::class);
    }
}