<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Backpack\CRUD\app\Models\Traits\CrudTrait;

class FeedWebsite extends Pivot
{
    use CrudTrait;

    public $incrementing = true;
    protected $table = 'feed_website';

    protected $fillable = [
        'feed_id',
        'website_id',
        'name',
        'is_active',
        'filtering_rules',
        'category_mappings',
        'attribute_mappings',
        'field_mappings',
        'category_source_field',
        'category_delimiter',
        'update_settings',
        'schedule',
        'last_run_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'filtering_rules' => 'array',
        'category_mappings' => 'array',
        'attribute_mappings' => 'array',
        'field_mappings' => 'array',
        'update_settings' => 'array',
        'last_run_at' => 'datetime',
    ];

    public function feed()
    {
        return $this->belongsTo(Feed::class);
    }

    public function website()
    {
        return $this->belongsTo(Website::class);
    }

    public function syndicatedProducts()
    {
        return $this->hasMany(SyndicatedProduct::class, 'feed_website_id', 'id');
    }

    public function importRuns()
    {
        return $this->hasMany(ImportRun::class, 'feed_website_id', 'id');
    }

    /**
     * Get the latest import run for this connection.
     * Using a more explicit approach to avoid column ambiguity with latestOfMany().
     */
    public function latestImportRun()
    {
        return $this->hasOne(ImportRun::class, 'feed_website_id', 'id')
                    ->select('import_runs.id', 'import_runs.feed_website_id', 'import_runs.status', 'import_runs.created_at')
                    ->orderByDesc('import_runs.created_at');
    }
}