<?php

namespace App\Apps\CmsproRegion\Services;

use App\Apps\CmsproRegion\Models\Region;
use App\Apps\CmsproRegion\ServiceProvider;
use App\Enums\Status;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * 区划级别配置服务
 *
 * 管理两个配置项：
 *  - region_max_level：数据演示页「区划选择器」显示的最大级别（1-5），可在「应用设置」页调整；
 *  - data_sync_level ：最近一次在线同步落库的最大级别（系统自动维护），
 *    用于「本地版本已是最新、但只同步到三级」时判断能否继续补齐更深层级。
 *
 * 在线同步完成后二者联动：落库级别写入 data_sync_level，同时把 region_max_level
 * 调整为同一级别，使演示页选择器与库中实际数据深度保持一致。
 *
 * 仅影响演示页选择器渲染，不改变对外 API（children/tree/list）与 RegionService 的返回契约。
 */
class RegionLevelService
{
    /** 最小可配置级别（省）。 */
    public const MIN_LEVEL = 1;

    /** 最大可配置级别（村/社区）。 */
    public const MAX_LEVEL = 5;

    /** 级别中文名，供设置页下拉与提示文案复用。 */
    public const LEVEL_NAMES = [
        1 => '省',
        2 => '市',
        3 => '区县',
        4 => '街道',
        5 => '村/社区',
    ];

    /** 配置项 name（code 由框架加 app_cmspro_region_ 前缀）。 */
    private const KEY_MAX_LEVEL = 'region_max_level';

    private const KEY_SYNC_LEVEL = 'data_sync_level';

    /** 配置组 code，与 manifest.json 的 config_groups 声明一致。 */
    private const GROUP_REGION_SETTINGS = ServiceProvider::CONFIG_CODE_PREFIX . 'region_settings';

    private const GROUP_SYNC_SETTINGS = ServiceProvider::CONFIG_CODE_PREFIX . 'sync_settings';

    private const GROUP_TITLES = [
        self::GROUP_REGION_SETTINGS => '区划设置',
        self::GROUP_SYNC_SETTINGS => '数据同步设置',
    ];

    /** 缓存键前缀（写入成功后即时失效，避免设置页保存后读到旧级别）。 */
    private const CACHE_PREFIX = 'cmspro_region:level_config:';

    private const CACHE_TTL = 3600;

    /**
     * 演示页选择器显示的最大级别。
     *
     * 数据库无记录或值非法时回退到 MAX_LEVEL（显示全部级别，与旧行为一致）。
     */
    public function maxLevel(): int
    {
        $level = (int) $this->read(self::KEY_MAX_LEVEL, self::MAX_LEVEL);

        return $this->isValidLevel($level) ? $level : self::MAX_LEVEL;
    }

    /**
     * 保存演示页显示级别（供「应用设置」页调用）。
     *
     * @throws RuntimeException 级别非法或配置写入失败
     */
    public function setMaxLevel(int $level): void
    {
        if (! $this->isValidLevel($level)) {
            throw new RuntimeException('区划显示级别必须为 ' . self::MIN_LEVEL . '（省）至 ' . self::MAX_LEVEL . '（村/社区）之间的整数');
        }

        $this->write(self::KEY_MAX_LEVEL, self::GROUP_REGION_SETTINGS, '区划显示级别', (string) $level);
    }

    /**
     * 最近一次在线同步落库的最大级别。
     *
     * data_sync_level 为本功能（1.7.0）新增配置项，老站点升级后尚无记录，
     * 此时回退到库中实际最大层级，避免已同步五级的站点首次点击「在线同步」重复全量导入。
     *
     * @return int 0 表示从未同步或无法判定
     */
    public function syncedLevel(): int
    {
        $level = (int) $this->read(self::KEY_SYNC_LEVEL, 0);

        return $this->isValidLevel($level) ? $level : $this->levelFromData();
    }

    /**
     * 记录本次同步落库的级别，并联动演示页显示级别。
     *
     * 同步已完成落库，配置写入失败不应让整次同步判定为失败，故仅记日志。
     */
    public function saveSyncedLevel(int $level): void
    {
        if (! $this->isValidLevel($level)) {
            return;
        }

        try {
            $this->write(self::KEY_SYNC_LEVEL, self::GROUP_SYNC_SETTINGS, '已同步数据级别', (string) $level);
            $this->write(self::KEY_MAX_LEVEL, self::GROUP_REGION_SETTINGS, '区划显示级别', (string) $level);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[cmspro.region] 同步级别记录失败', [
                'level' => $level,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 级别是否落在合法区间。
     */
    public function isValidLevel(int $level): bool
    {
        return $level >= self::MIN_LEVEL && $level <= self::MAX_LEVEL;
    }

    /**
     * 级别中文名（未知级别原样返回数字）。
     */
    public function levelName(int $level): string
    {
        return self::LEVEL_NAMES[$level] ?? (string) $level;
    }

    /**
     * 级别中文名组合，如「省市区县」（供文案使用）。
     */
    public function levelLabel(int $level): string
    {
        if (! $this->isValidLevel($level)) {
            return (string) $level;
        }

        return $level === self::MAX_LEVEL
            ? '省/市/区县/街道/村社区（五级）'
            : implode('/', array_map(
                fn (int $i) => self::LEVEL_NAMES[$i],
                range(self::MIN_LEVEL, $level)
            )) . '（' . $level . '级）';
    }

    /**
     * 由库中实际最大层级推断已同步级别（表不存在或无数据时返回 0）。
     */
    private function levelFromData(): int
    {
        try {
            $level = (int) Region::max('level');
        } catch (Throwable $e) {
            return 0;
        }

        return $this->isValidLevel($level) ? $level : 0;
    }

    /**
     * 读配置项：带缓存，数据库无记录或表不存在时回退到 Config/region.php 默认值。
     */
    private function read(string $key, mixed $default): mixed
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key, $default) {
            try {
                $value = ConfigItem::where('code', ServiceProvider::CONFIG_CODE_PREFIX . $key)->value('value');
                if ($value !== null && $value !== '') {
                    return $value;
                }
            } catch (Throwable $e) {
                // 应用未安装时 config_items 表可能不存在，降级到默认配置
            }

            return config(ServiceProvider::CONFIG_KEY . '.' . $key, $default);
        });
    }

    /**
     * 写配置项：已存在则更新，缺失则在 manifest 声明的分组下自愈创建
     * （未升级到 1.7.0 的站点分组尚未注册，自愈建组保证配置一定能写入）。
     *
     * @throws RuntimeException 写入失败
     */
    private function write(string $key, string $groupCode, string $itemTitle, string $value): void
    {
        try {
            $code = ServiceProvider::CONFIG_CODE_PREFIX . $key;
            $item = ConfigItem::where('code', $code)->first();

            if ($item !== null) {
                $item->update(['value' => $value]);
            } else {
                ConfigItem::create([
                    'group_id' => $this->groupId($groupCode),
                    'name'     => $itemTitle,
                    'code'     => $code,
                    'value'    => $value,
                    'type'     => 'text',
                    'tips'     => '',
                    'sort'     => 0,
                    'status'   => Status::ENABLED,
                ]);
            }

            Cache::forget(self::CACHE_PREFIX . $key);
        } catch (Throwable $e) {
            throw new RuntimeException('区划级别配置写入失败：' . $e->getMessage());
        }
    }

    /**
     * 取配置组 ID，未注册时自愈创建。
     */
    private function groupId(string $groupCode): int
    {
        $group = ConfigGroup::firstOrCreate(
            ['code' => $groupCode],
            [
                'name'   => self::GROUP_TITLES[$groupCode] ?? $groupCode,
                'app_id' => ServiceProvider::APP_ID,
                'sort'   => 0,
                'status' => Status::ENABLED,
            ]
        );

        return (int) $group->id;
    }
}
