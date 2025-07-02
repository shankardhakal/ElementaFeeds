<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Illuminate\Contracts\Encryption\DecryptException;

class Website extends Model
{
    use HasFactory;
    use CrudTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'url',
        'platform',
        'language',
        'woocommerce_credentials', 
        'wordpress_credentials', 
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'last_checked_at' => 'datetime',
    ];


    /**
     * Encrypt the WooCommerce credentials before saving to the database.
     */
    public function setWoocommerceCredentialsAttribute($value)
    {
        $this->attributes['woocommerce_credentials'] = !empty($value) ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt the WooCommerce credentials when retrieving them from the database.
     */
    public function getWoocommerceCredentialsAttribute($value)
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return null;
        }
    }

    /**
     * Encrypt the WordPress credentials before saving to the database.
     */
    public function setWordpressCredentialsAttribute($value)
    {
        $this->attributes['wordpress_credentials'] = !empty($value) ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt the WordPress credentials when retrieving them from the database.
     */
    public function getWordpressCredentialsAttribute($value)
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return null;
        }
    }

    /**
     * Defines the many-to-many relationship with Feeds through the 'feed_website' table.
     */
    public function feeds()
    {
        return $this->belongsToMany(Feed::class, 'feed_website')
            ->using(FeedWebsite::class) 
            ->withPivot(
                'id',
                'name',
                'is_active',
                'filtering_rules',
                'category_mappings',
                'attribute_mappings',
                'field_mappings',
                'update_settings',
                'schedule',
                'last_run_at'
            )->withTimestamps();
    }
}