<?php

namespace App\Apps\CmsproRegion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $table = 'app_cmspro_region_region';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    protected $fillable = [
        'code',
        'name',
        'level',
        'parent_id',
        'status',
        'lon',
        'lat',
    ];

    protected $casts = [
        'level' => 'integer',
        'parent_id' => 'integer',
        'status' => 'integer',
        'lon' => 'float',
        'lat' => 'float',
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 层级常量
     */
    public const LEVEL_PROVINCE = 1;
    public const LEVEL_CITY = 2;
    public const LEVEL_DISTRICT = 3;
    public const LEVEL_STREET = 4;

    /**
     * 层级名称映射
     */
    public static array $levelNames = [
        self::LEVEL_PROVINCE => '省/直辖市',
        self::LEVEL_CITY => '市',
        self::LEVEL_DISTRICT => '区/县',
        self::LEVEL_STREET => '街道/乡镇',
    ];

    /**
     * 父级关联
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * 子级关联
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * 获取层级名称
     */
    public function getLevelNameAttribute(): string
    {
        return self::$levelNames[$this->level] ?? '未知';
    }
}
