<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyndicatedProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'feed_website_id',
        'source_product_identifier',
        'destination_product_id',
        'last_updated_hash',
    ];

    public function feedWebsite()
    {
        return $this->belongsTo(FeedWebsite::class);
    }
}