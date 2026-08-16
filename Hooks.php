<?php

namespace App\Apps\CmsproRegion;

use App\Apps\CmsproRegion\Services\RegionService;
use App\Services\HookManager;

class Hooks
{
    public function register(HookManager $hooks): void
    {
        // 注册 Filter 钩子：获取级联选择器数据
        $hooks->registerFilter('region.cascader_data', [$this, 'getCascaderData'], 10, 'cmspro.region');

        // 注册 Filter 钩子：获取省份列表
        $hooks->registerFilter('region.provinces', [$this, 'getProvinces'], 10, 'cmspro.region');

        // 注册 Filter 钩子：获取子级区划
        $hooks->registerFilter('region.children', [$this, 'getChildren'], 10, 'cmspro.region');

        // 注册 Filter 钩子：获取完整路径
        $hooks->registerFilter('region.path', [$this, 'getPath'], 10, 'cmspro.region');
    }

    /**
     * 获取级联选择器数据
     */
    public function getCascaderData($data = null): array
    {
        $service = app(RegionService::class);
        return $service->getCascaderData(3);
    }

    /**
     * 获取省份列表
     */
    public function getProvinces($data = null)
    {
        $service = app(RegionService::class);
        return $service->getProvinces();
    }

    /**
     * 获取子级区划（需传入父级 code）
     */
    public function getChildren($parentCode = null)
    {
        if (!$parentCode) {
            return collect();
        }
        $service = app(RegionService::class);
        return $service->getChildren($parentCode);
    }

    /**
     * 获取完整路径（需传入区划 code）
     */
    public function getPath($code = null)
    {
        if (!$code) {
            return collect();
        }
        $service = app(RegionService::class);
        return $service->getPath($code);
    }
}
