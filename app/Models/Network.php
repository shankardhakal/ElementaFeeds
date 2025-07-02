<?php

namespace App\Models;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Network extends Model
{
    use CrudTrait;
    use HasFactory;

    protected $fillable = ['name'];

    public function feeds()
    {
        return $this->hasMany(Feed::class);
    }
}