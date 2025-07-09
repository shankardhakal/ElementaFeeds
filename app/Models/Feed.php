<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Backpack\CRUD\app\Models\Traits\CrudTrait;

class Feed extends Model
{
    use HasFactory;
    use CrudTrait;

    protected $fillable = [
        'network_id',
        'name',
        'feed_url',
        'language',
        'is_active',
        'delimiter',
        'enclosure',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * from the form into a real tab character for the database.
     */
    public function setDelimiterAttribute($value)
    {
        $delimiters = [
            'comma'     => ',',
            'tab'       => "\t",
            'pipe'      => '|',
            'semicolon' => ';',
        ];
        
        // Set the actual character, defaulting to comma if not found.
        $this->attributes['delimiter'] = $delimiters[$value] ?? ',';
    }

    public function network()
    {
        return $this->belongsTo(Network::class);
    }

    public function websites()
    {
        return $this->belongsToMany(Website::class)->withPivot(
            'id',
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
            'last_run_at'
        )->withTimestamps();
    }
}