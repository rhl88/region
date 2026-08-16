<?php

namespace App\Apps\CmsproRegion\Models;

use Illuminate\Database\Eloquent\Model;

class RegionClosure extends Model
{
    protected $table = 'app_cmspro_region_closure';

    public $timestamps = false;

    protected $fillable = [
        'ancestor',
        'descendant',
        'depth',
    ];

    protected $casts = [
        'ancestor' => 'integer',
        'descendant' => 'integer',
        'depth' => 'integer',
    ];
}
