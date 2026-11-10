<?php

namespace App\Apps\CmsproRegion\Controllers\Admin;

use App\Apps\CmsproRegion\Services\MapSettingService;
use App\Apps\CmsproRegion\Services\RegionLevelService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台 · 应用设置（区划级别 + 地图配置）
 *
 * 密钥由使用者自行到各开放平台申请后填入，落库使用 Crypt 加密；
 * 设置页明文回显已配置值，清空文本框并保存即删除该密钥。
 */
class SettingController extends Controller
{
    public function __construct(
        protected MapSettingService $mapSettings,
        protected RegionLevelService $regionLevels
    ) {
    }

    /**
     * 应用设置页面
     */
    public function index()
    {
        if (! $this->hasPermission('cmspro.region.setting.manage')) {
            return response('无权查看应用设置', 403);
        }

        $values = [];
        $hasKeys = [];
        foreach (MapSettingService::ALLOWED_KEYS as $key) {
            // get() 自动解密敏感项，在文本框直接明文回显
            $value = (string) $this->mapSettings->get($key, '');
            $values[$key] = $value;
            if (in_array($key, MapSettingService::ENCRYPTED_CODES, true)) {
                $hasKeys[$key] = $value !== '';
            }
        }

        $regionMaxLevel = $this->regionLevels->maxLevel();

        return view('cmsproregion::Admin.setting', [
            'values'     => $values,
            'hasKeys'    => $hasKeys,
            // 配置组由框架在安装/升级应用时按 manifest 注册，缺失时页面顶部提示升级，点击保存由接口返回原因
            'groupReady' => $this->mapSettings->configGroupReady(),
            'mapsStatus' => $this->mapSettings->mapsAppStatus(),
            'effective'  => $this->mapSettings->resolveEffective(),
            // 区划级别设置：控制数据演示页「区划选择器」的显示深度
            'regionMaxLevel' => $regionMaxLevel,
            'levelOptions'   => RegionLevelService::LEVEL_NAMES,
            'levelLabel'     => $this->regionLevels->levelLabel($regionMaxLevel),
        ]);
    }

    /**
     * 保存应用设置
     */
    public function update(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.region.setting.manage')) {
            return response()->json([
                'code'    => 1,
                'message' => '无权修改应用设置',
                'data'    => null,
            ]);
        }

        // 仅接收白名单内的配置项，禁止 $request->all() 带入任意字段
        // 文本框明文回显，提交空值即表示删除该密钥
        $data = $request->only(MapSettingService::ALLOWED_KEYS);

        if (isset($data['map_source']) && ! $this->mapSettings->isValidSource((string) $data['map_source'])) {
            return response()->json([
                'code'    => 1,
                'message' => '不支持的地图配置来源',
                'data'    => null,
            ]);
        }

        if (isset($data['map_provider']) && ! $this->mapSettings->isValidProvider((string) $data['map_provider'])) {
            return response()->json([
                'code'    => 1,
                'message' => '不支持的地图厂商',
                'data'    => null,
            ]);
        }

        try {
            $this->mapSettings->set($data);
            $this->saveRegionLevel($request);
        } catch (\RuntimeException $e) {
            return response()->json([
                'code'    => 1,
                'message' => $e->getMessage(),
                'data'    => null,
            ]);
        }

        return response()->json([
            'code'    => 0,
            'message' => '保存成功',
            'data'    => array_merge($this->mapSettings->resolveEffective(), [
                'region_max_level' => $this->regionLevels->maxLevel(),
            ]),
        ]);
    }

    /**
     * 保存区划显示级别（未提交该字段时跳过，兼容仅保存地图配置的调用）
     *
     * @throws \RuntimeException 级别非法或配置写入失败
     */
    private function saveRegionLevel(Request $request): void
    {
        if (! $request->has('region_max_level')) {
            return;
        }

        $this->regionLevels->setMaxLevel((int) $request->input('region_max_level'));
    }

    /**
     * 权限校验：超级管理员直通，其余按权限码判断
     */
    private function hasPermission(string $code): bool
    {
        $user = auth('admin')->user();
        if ($user === null) {
            return false;
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return method_exists($user, 'hasPermission') && (bool) $user->hasPermission($code);
    }
}
