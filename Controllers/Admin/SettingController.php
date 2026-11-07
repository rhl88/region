<?php

namespace App\Apps\CmsproRegion\Controllers\Admin;

use App\Apps\CmsproRegion\Services\MapSettingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台 · 应用设置（地图配置）
 *
 * 密钥由使用者自行到各开放平台申请后填入，落库使用 Crypt 加密；
 * 设置页明文回显已配置值，清空文本框并保存即删除该密钥。
 */
class SettingController extends Controller
{
    public function __construct(protected MapSettingService $mapSettings)
    {
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

        return view('cmsproregion::Admin.setting', [
            'values'     => $values,
            'hasKeys'    => $hasKeys,
            // 配置组由框架在安装/升级应用时按 manifest 注册，缺失时页面顶部提示升级，点击保存由接口返回原因
            'groupReady' => $this->mapSettings->configGroupReady(),
            'mapsStatus' => $this->mapSettings->mapsAppStatus(),
            'effective'  => $this->mapSettings->resolveEffective(),
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
            'data'    => $this->mapSettings->resolveEffective(),
        ]);
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
