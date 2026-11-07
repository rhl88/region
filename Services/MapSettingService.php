<?php

namespace App\Apps\CmsproRegion\Services;

use App\Apps\CmsproRegion\ServiceProvider;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * 地图配置服务
 *
 * 三种配置来源：
 *  - none：不启用厂商地图，数据演示页使用 Leaflet + 高德瓦片底图；
 *  - own ：使用本应用「应用设置」页独立配置的厂商与前端密钥；
 *  - maps：跟随「地图」应用（CmsproMaps）的厂商与前端密钥，该应用未安装或未配置时视为未配置。
 *
 * 敏感密钥按开发文档「敏感数据加密存储规范」使用 Crypt 加密存储，
 * 并按值特征 eyJpdiI6 兼容系统配置页写入的历史明文值。
 */
class MapSettingService
{
    /** 配置来源：不启用厂商地图。 */
    public const SOURCE_NONE = 'none';

    /** 配置来源：本应用独立配置。 */
    public const SOURCE_OWN = 'own';

    /** 配置来源：跟随「地图」应用。 */
    public const SOURCE_MAPS = 'maps';

    /** 合规地图厂商白名单（腾讯 / 高德 / 百度）。 */
    public const PROVIDERS = ['tencent', 'amap', 'baidu'];

    /** 设置接口允许写入的配置项白名单（与 manifest.json 的 map_settings 组逐项对应）。 */
    public const ALLOWED_KEYS = [
        'map_source', 'map_provider',
        'tencent_js_key', 'amap_js_key', 'amap_security_code', 'baidu_js_ak',
    ];

    /** 需要加密存储的配置项白名单（不含前缀的 name）。 */
    public const ENCRYPTED_CODES = [
        'tencent_js_key', 'amap_js_key', 'amap_security_code', 'baidu_js_ak',
    ];

    /** manifest.json 中声明的地图配置组 code（框架按 app_id 自动生成）。 */
    public const CONFIG_GROUP_CODE = ServiceProvider::CONFIG_CODE_PREFIX . 'map_settings';

    private const CACHE_PREFIX = 'cmspro_region:map_config:';
    private const CACHE_TTL = 3600;

    /** 读取配置值（敏感项自动解密，数据库无记录时回退到 Config/region.php）。 */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key, $default) {
            try {
                $value = ConfigItem::where('code', ServiceProvider::CONFIG_CODE_PREFIX . $key)->value('value');
                if ($value !== null && $value !== '') {
                    return $this->decryptIfNeeded($key, (string) $value);
                }
            } catch (\Throwable $e) {
                // 安装前 config_items 表可能不存在，降级到默认配置
            }

            return config(ServiceProvider::CONFIG_KEY . '.' . $key, $default);
        });
    }

    /**
     * 批量保存配置项。
     *
     * 只接受白名单内的键，避免写入任意 code；敏感项使用 Crypt::encryptString 加密。
     * 新建配置项必须归到 manifest 声明的分组，否则会脱离分组并在应用升级时产生重复 code，
     * 因此分组缺失时直接抛异常，由调用方提示先升级应用。
     *
     * @throws \RuntimeException 配置组尚未注册（应用未升级到 1.6.0 及以上）
     */
    public function set(array $values): void
    {
        $groupId = $this->configGroupId();
        if ($groupId === 0) {
            throw new \RuntimeException('地图配置组尚未注册，请先在「应用管理」中升级本应用后再保存设置');
        }

        $changed = [];

        foreach ($values as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            $code = ServiceProvider::CONFIG_CODE_PREFIX . $key;
            $plain = is_scalar($value) ? trim((string) $value) : '';
            $stored = in_array($key, self::ENCRYPTED_CODES, true) && $plain !== ''
                ? Crypt::encryptString($plain)
                : $plain;

            $existing = ConfigItem::where('code', $code)->first();
            if ($existing) {
                $existing->update(['value' => $stored]);
            } else {
                ConfigItem::create([
                    'code'     => $code,
                    'group_id' => $groupId,
                    'name'     => $key,
                    'type'     => in_array($key, self::ENCRYPTED_CODES, true) ? 'password' : 'text',
                    'value'    => $stored,
                    'tips'     => '',
                    'sort'     => 0,
                    'status'   => 1,
                ]);
            }

            $changed[] = $key;
        }

        $this->forget($changed);
    }

    /** 清除配置缓存，使保存后即时生效。 */
    public function forget(array $keys): void
    {
        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }

    /** 配置来源是否合法。 */
    public function isValidSource(string $source): bool
    {
        return in_array($source, [self::SOURCE_NONE, self::SOURCE_OWN, self::SOURCE_MAPS], true);
    }

    /** 厂商是否合规。 */
    public function isValidProvider(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true);
    }

    /** manifest 声明的地图配置组是否已注册（未注册时设置页提示升级，保存由接口返回原因）。 */
    public function configGroupReady(): bool
    {
        return $this->configGroupId() > 0;
    }

    /** 「地图」应用（CmsproMaps）是否可用。 */
    public function mapsAppAvailable(): bool
    {
        return function_exists('cmspro_maps_config');
    }

    /**
     * 「地图」应用当前状态，供设置页展示关联结果。
     *
     * @return array{installed:bool,provider:string,configured:bool}
     */
    public function mapsAppStatus(): array
    {
        if (! $this->mapsAppAvailable()) {
            return ['installed' => false, 'provider' => '', 'configured' => false];
        }

        $provider = (string) cmspro_maps_config('provider', '');
        $configured = $this->isValidProvider($provider) && $this->mapsFrontendKey($provider) !== '';

        return ['installed' => true, 'provider' => $provider, 'configured' => $configured];
    }

    /**
     * 解析当前生效的地图配置（只含前端可用字段，服务端密钥绝不输出）。
     *
     * @return array{configured:bool,source:string,provider:string,key:string,sdk_url:string,coordinate_system:string,security_config:array,message:string}
     */
    public function resolveEffective(): array
    {
        $fallback = [
            'configured'        => false,
            'source'            => self::SOURCE_NONE,
            'provider'          => '',
            'key'               => '',
            'sdk_url'           => '',
            'coordinate_system' => 'gcj02',
            'security_config'   => [],
            'message'           => '',
        ];

        $source = (string) $this->get('map_source', self::SOURCE_NONE);
        if (! $this->isValidSource($source) || $source === self::SOURCE_NONE) {
            $fallback['message'] = '未启用厂商地图，演示页使用 Leaflet + 高德瓦片底图';

            return $fallback;
        }

        if ($source === self::SOURCE_MAPS) {
            $status = $this->mapsAppStatus();
            if (! $status['installed']) {
                $fallback['source'] = $source;
                $fallback['message'] = '「地图」应用未安装或未启用，演示页回退到 Leaflet + 高德瓦片底图';

                return $fallback;
            }
            if (! $status['configured']) {
                $fallback['source'] = $source;
                $fallback['message'] = '「地图」应用尚未配置可用的前端密钥，演示页回退到 Leaflet + 高德瓦片底图';

                return $fallback;
            }

            $provider = $status['provider'];
            $key = $this->mapsFrontendKey($provider);
            $securityCode = $provider === 'amap' ? (string) cmspro_maps_config('amap_security_code', '') : '';
        } else {
            $provider = (string) $this->get('map_provider', 'baidu');
            if (! $this->isValidProvider($provider)) {
                $fallback['source'] = $source;
                $fallback['message'] = '独立配置的地图厂商不合法，演示页回退到 Leaflet + 高德瓦片底图';

                return $fallback;
            }

            $key = (string) $this->get($this->ownKeyField($provider), '');
            if ($key === '') {
                $fallback['source'] = $source;
                $fallback['provider'] = $provider;
                $fallback['message'] = '尚未填写该厂商的前端密钥，演示页回退到 Leaflet + 高德瓦片底图';

                return $fallback;
            }

            $securityCode = $provider === 'amap' ? (string) $this->get('amap_security_code', '') : '';
        }

        return [
            'configured'        => true,
            'source'            => $source,
            'provider'          => $provider,
            'key'               => $key,
            'sdk_url'           => $this->sdkUrl($provider, $key),
            'coordinate_system' => $this->coordinateSystem($provider),
            // 高德 JS API 2.0 强制要求 securityJsCode，须在 SDK 加载前注入
            'security_config'   => $provider === 'amap' && $securityCode !== ''
                ? ['_AMapSecurityConfig' => ['securityJsCode' => $securityCode]]
                : [],
            'message'           => '',
        ];
    }

    /** 厂商对应的前端官方 JS SDK 地址（固定官方域名，密钥由使用者申请后填入）。 */
    public function sdkUrl(string $provider, string $key): string
    {
        return match ($provider) {
            'tencent' => 'https://map.qq.com/api/gljs?' . http_build_query(['v' => '1.exp', 'key' => $key]),
            'amap'    => 'https://webapi.amap.com/maps?' . http_build_query(['v' => '2.0', 'key' => $key]),
            // 百度 GL v1.0 必须带 callback：带回调时官方引导脚本改用 appendChild 插入 SDK，
            // 不带回调则返回 document.write 引导脚本，动态加载会被浏览器忽略导致 BMapGL 不存在
            'baidu'   => 'https://api.map.baidu.com/api?' . http_build_query([
                'v' => '1.0', 'type' => 'webgl', 'ak' => $key,
                'callback' => '__cmsproRegionBaiduReady',
            ]),
            default   => '',
        };
    }

    /** 厂商所需坐标系：百度为 BD-09，腾讯 / 高德为 GCJ-02（本应用库内坐标统一 GCJ-02）。 */
    public function coordinateSystem(string $provider): string
    {
        return $provider === 'baidu' ? 'bd09' : 'gcj02';
    }

    /** 独立配置下厂商对应的前端密钥字段名。 */
    private function ownKeyField(string $provider): string
    {
        return match ($provider) {
            'tencent' => 'tencent_js_key',
            'amap'    => 'amap_js_key',
            'baidu'   => 'baidu_js_ak',
            default   => '',
        };
    }

    /** 「地图」应用中厂商对应的前端密钥字段名（与本应用同名）。 */
    private function mapsFrontendKey(string $provider): string
    {
        $field = $this->ownKeyField($provider);

        return $field === '' ? '' : (string) cmspro_maps_config($field, '');
    }

    /** 取 manifest 声明的地图配置组 ID，未注册时返回 0。 */
    private function configGroupId(): int
    {
        try {
            return (int) (ConfigGroup::where('code', self::CONFIG_GROUP_CODE)->value('id') ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** 按白名单与密文特征解密，非白名单或明文原样返回。 */
    private function decryptIfNeeded(string $key, string $value): string
    {
        if (! in_array($key, self::ENCRYPTED_CODES, true) || ! str_starts_with($value, 'eyJpdiI6')) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // APP_KEY 变更或旧数据时避免抛异常，原样返回
            return $value;
        }
    }
}
