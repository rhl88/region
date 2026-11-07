<?php

return [
    // 缓存时间（秒）
    'cache_ttl' => 86400,

    // 缓存键前缀
    'cache_prefix' => 'region',

    // 默认最大层级
    'default_max_level' => 3,

    // ========== 地图配置默认值（数据库 config_items 无记录时回退到此处） ==========

    // 配置来源：none=不启用厂商地图（演示页用 Leaflet + 高德瓦片）、own=本应用独立配置、maps=跟随「地图」应用
    'map_source' => 'none',

    // 独立配置时使用的地图厂商，仅 map_source=own 生效
    'map_provider' => 'baidu',

    // 三家厂商前端 JS SDK 密钥，须由使用者自行到开放平台申请，严禁内置真实密钥
    'tencent_js_key' => '',
    'amap_js_key' => '',
    'amap_security_code' => '',
    'baidu_js_ak' => '',
];
