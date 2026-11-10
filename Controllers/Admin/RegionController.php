<?php

namespace App\Apps\CmsproRegion\Controllers\Admin;

use App\Apps\CmsproRegion\Models\Region;
use App\Apps\CmsproRegion\Services\MapSettingService;
use App\Apps\CmsproRegion\Services\RegionDataSyncService;
use App\Apps\CmsproRegion\Services\RegionLevelService;
use App\Apps\CmsproRegion\Services\RegionService;
use App\Apps\CmsproRegion\Services\RegionSnapshotService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RegionController extends Controller
{
    protected RegionService $regionService;

    protected RegionSnapshotService $regionSnapshotService;

    protected RegionDataSyncService $regionDataSyncService;

    protected MapSettingService $mapSettingService;

    protected RegionLevelService $regionLevels;

    public function __construct(
        RegionService $regionService,
        RegionSnapshotService $regionSnapshotService,
        RegionDataSyncService $regionDataSyncService,
        MapSettingService $mapSettingService,
        RegionLevelService $regionLevels
    ) {
        $this->regionService = $regionService;
        $this->regionSnapshotService = $regionSnapshotService;
        $this->regionDataSyncService = $regionDataSyncService;
        $this->mapSettingService = $mapSettingService;
        $this->regionLevels = $regionLevels;
    }

    /**
     * 数据管理页面
     */
    public function index()
    {
        $stats = $this->regionService->getStats();

        // 开发模式（APP_DEV_MODE=true）下才提供「更新坐标」入口，AK 优先取本应用生效配置
        $devMode = (bool) config('app.dev_mode');
        $baiduAk = $devMode ? $this->devBaiduAk() : '';
        // AK 缺失时给出具体原因，前端点击「更新坐标」时引导到「应用设置」页
        $coordMapTip = ($devMode && $baiduAk === '') ? $this->coordMapTip() : '';

        return view('cmsproregion::Admin.index', compact('stats', 'devMode', 'baiduAk', 'coordMapTip'));
    }

    /**
     * 数据演示页面
     *
     * 同时服务于后台（/admin/cmspro/region/demo）与前台公开访问（/cmspro/region/demo）：
     * 前台模式只读，接口切换到公开只读 API，并隐藏后台导入指引。
     */
    public function demo(Request $request)
    {
        $publicMode = $request->route('demoMode') === 'public';
        $apiBase = $publicMode ? '/api/cmspro/region' : '/api/admin/cmspro/region';

        $stats = $this->regionService->getStats();
        $geoStats = $this->regionService->getGeoStats();

        $hasLevel3 = ($stats['province'] > 0 && $stats['city'] > 0 && $stats['district'] > 0);
        $hasStreet = ($stats['street'] > 0);
        // 村/社区（level=5）挂在街道下，无街道数据时村级选择器无意义
        $hasVillage = ($hasStreet && $stats['village'] > 0);
        $hasGeo = ($geoStats['with_geo'] > 0);

        // 「应用设置」中的显示级别：库里有数据但级别未放开时，选择器不渲染该层下拉
        $maxLevel = $this->regionLevels->maxLevel();
        $showDistrict = $hasLevel3 && $maxLevel >= Region::LEVEL_DISTRICT;
        $showStreet = $hasStreet && $maxLevel >= Region::LEVEL_STREET;
        $showVillage = $hasVillage && $maxLevel >= Region::LEVEL_VILLAGE;
        $levelLabel = $this->regionLevels->levelLabel($maxLevel);
        // 选择器可渲染：至少要有省市数据；显示级别低于区县时降级为仅省/市两级下拉
        $canSelect = $stats['city'] > 0 && ($hasLevel3 || $maxLevel < Region::LEVEL_DISTRICT);

        // 地图配置：未配置或加载失败时演示页回退 Leaflet + 高德瓦片
        $mapConfig = $this->mapSettingService->resolveEffective();

        return view('cmsproregion::Admin.demo', compact(
            'stats',
            'geoStats',
            'hasLevel3',
            'hasStreet',
            'hasVillage',
            'hasGeo',
            'maxLevel',
            'canSelect',
            'showDistrict',
            'showStreet',
            'showVillage',
            'levelLabel',
            'mapConfig',
            'apiBase',
            'publicMode'
        ));
    }

    /**
     * 开发模式「更新坐标」所需的百度 JSAPI AK
     *
     * 优先使用本应用生效配置（独立配置或关联地图应用且厂商为百度），
     * 其次回退到「地图」应用的浏览器端 AK，保持既有行为不变。
     */
    private function devBaiduAk(): string
    {
        $effective = $this->mapSettingService->resolveEffective();
        if ($effective['configured'] && $effective['provider'] === 'baidu') {
            return (string) $effective['key'];
        }

        return function_exists('cmspro_maps_config')
            ? (string) cmspro_maps_config('baidu_js_ak', '')
            : '';
    }

    /**
     * 「更新坐标」缺少百度 AK 时的具体原因（不含任何密钥）
     *
     * 按「配置组未注册 → 未启用地图 → 生效厂商非百度 → 未填写密钥」顺序判断，
     * 使前端提示能直接指向「应用设置」页中需要修正的那一项。
     */
    private function coordMapTip(): string
    {
        if (! $this->mapSettingService->configGroupReady()) {
            return '地图配置组尚未注册，请先在「应用管理」将本应用升级到 1.6.0，再到「应用设置」填写百度地图 AK';
        }

        $effective = $this->mapSettingService->resolveEffective();

        if ($effective['source'] === MapSettingService::SOURCE_NONE) {
            return '「应用设置」中地图配置来源为「不启用」，请改为「本应用独立配置」并选择百度地图、填写百度 JS AK';
        }

        if ($effective['configured'] && $effective['provider'] !== 'baidu') {
            $names = ['amap' => '高德地图', 'tencent' => '腾讯地图'];
            $name = $names[$effective['provider']] ?? $effective['provider'];

            return '「应用设置」当前生效厂商为' . $name . '，而「更新坐标」依赖百度地图地址解析，请改用百度地图并填写百度 JS AK';
        }

        return '「应用设置」中尚未填写百度 JS AK（关联「地图」应用时也未配置百度浏览器端 AK），请先完成配置';
    }

    /**
     * 获取省份列表
     */
    public function provinces(): JsonResponse
    {
        $provinces = $this->regionService->getProvinces();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $provinces,
        ]);
    }

    /**
     * 获取子级区划
     */
    public function children(Request $request): JsonResponse
    {
        $code = $request->input('code', '');
        $children = $this->regionService->getChildren($code);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $children,
        ]);
    }

    /**
     * 获取级联树形数据
     */
    public function tree(Request $request): JsonResponse
    {
        $maxLevel = (int) $request->input('max_level', 3);
        $maxLevel = min(max($maxLevel, 1), 5);

        $tree = $this->regionService->getCascaderData($maxLevel);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $tree,
        ]);
    }

    /**
     * 获取区划完整路径
     */
    public function path(Request $request): JsonResponse
    {
        $code = $request->input('code', '');
        $path = $this->regionService->getPath($code);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $path,
        ]);
    }

    /**
     * 按百度地图解析结果更新单个区划坐标（仅开发模式可用）
     */
    public function updateCoordinate(Request $request): JsonResponse
    {
        if (! config('app.dev_mode')) {
            return response()->json([
                'code' => 1,
                'message' => '该功能仅限开发模式（APP_DEV_MODE=true）使用',
                'data' => null,
            ]);
        }

        $code = trim((string) $request->input('code', ''));
        $lng = $request->input('lng');
        $lat = $request->input('lat');

        if ($code === '' || ! is_numeric($lng) || ! is_numeric($lat)) {
            return response()->json([
                'code' => 1,
                'message' => '参数不完整：code、lng、lat 均为必填项',
                'data' => null,
            ]);
        }

        $result = $this->regionService->updateBaiduCoordinate($code, (float) $lng, (float) $lat);

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ]);
    }

    /**
     * 获取单个区划当前库中的经纬度（仅开发模式可用）
     */
    public function coordinate(Request $request): JsonResponse
    {
        if (! config('app.dev_mode')) {
            return response()->json([
                'code' => 1,
                'message' => '该功能仅限开发模式（APP_DEV_MODE=true）使用',
                'data' => null,
            ]);
        }

        $code = trim((string) $request->input('code', ''));
        if ($code === '') {
            return response()->json([
                'code' => 1,
                'message' => '参数不完整：code 为必填项',
                'data' => null,
            ]);
        }

        $coordinate = $this->regionService->getCoordinate($code);
        if ($coordinate === null) {
            return response()->json([
                'code' => 1,
                'message' => '区划不存在：' . $code,
                'data' => null,
            ]);
        }

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $coordinate,
        ]);
    }

    /**
     * 获取批量更新坐标的候选区划（仅开发模式可用）
     */
    public function coordinateCandidates(Request $request): JsonResponse
    {
        if (! config('app.dev_mode')) {
            return response()->json([
                'code' => 1,
                'message' => '该功能仅限开发模式（APP_DEV_MODE=true）使用',
                'data' => null,
            ]);
        }

        $levels = array_filter(array_map('intval', explode(',', (string) $request->input('levels', '1,2,3,4'))));
        $onlyMissing = (bool) $request->input('only_missing', 1);
        $afterCode = trim((string) $request->input('after_code', ''));
        $limit = (int) $request->input('limit', 100);
        $limit = min(max($limit, 1), 500);

        $result = $this->regionService->getCoordinateCandidates($levels, $onlyMissing, $afterCode, $limit);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 批量写入区划坐标（仅开发模式可用）
     */
    public function updateCoordinateBatch(Request $request): JsonResponse
    {
        if (! config('app.dev_mode')) {
            return response()->json([
                'code' => 1,
                'message' => '该功能仅限开发模式（APP_DEV_MODE=true）使用',
                'data' => null,
            ]);
        }

        $items = $request->input('items', []);
        if (! is_array($items) || empty($items)) {
            return response()->json([
                'code' => 1,
                'message' => '参数不完整：items 不能为空',
                'data' => null,
            ]);
        }

        if (count($items) > 50) {
            return response()->json([
                'code' => 1,
                'message' => '单次最多提交 50 条坐标',
                'data' => null,
            ]);
        }

        $successCount = 0;
        $failures = [];

        foreach ($items as $item) {
            $code = trim((string) ($item['code'] ?? ''));
            $lng = $item['lng'] ?? null;
            $lat = $item['lat'] ?? null;

            if ($code === '' || ! is_numeric($lng) || ! is_numeric($lat)) {
                $failures[] = ['code' => $code, 'message' => '参数不完整'];
                continue;
            }

            $result = $this->regionService->updateBaiduCoordinate($code, (float) $lng, (float) $lat);
            if ($result['success']) {
                $successCount++;
            } else {
                $failures[] = ['code' => $code, 'message' => $result['message']];
            }
        }

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'success_count' => $successCount,
                'fail_count' => count($failures),
                'failures' => $failures,
            ],
        ]);
    }

    /**
     * 导入/更新数据（全量，保留兼容）
     */
    public function import(): JsonResponse
    {
        $result = $this->regionService->importData();

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => $result['stats'] ?? null,
        ]);
    }

    /**
     * 按省份导入三级数据（AJAX分步调用）
     */
    public function importByProvince(Request $request): JsonResponse
    {
        $provinceCode = $request->input('province_code', '');
        if (empty($provinceCode)) {
            return response()->json([
                'code' => 1,
                'message' => '请指定省份代码',
            ]);
        }

        $result = $this->regionService->importDataByProvince($provinceCode);

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'inserted' => $result['inserted'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'disabled' => $result['disabled'] ?? 0,
                'province' => $result['province'] ?? '',
                'stats' => $result['stats'] ?? null,
            ],
        ]);
    }

    /**
     * 获取数据统计
     */
    public function stats(): JsonResponse
    {
        $stats = $this->regionService->getStats();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $stats,
        ]);
    }

    /**
     * 区划数据列表（分页，支持筛选）
     */
    public function list(Request $request): JsonResponse
    {
        $query = Region::query();

        $level = $request->input('level');
        if ($level) {
            $query->where('level', $level);
        }

        $keyword = $request->input('keyword');
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        $parentId = $request->input('parent_id');
        if ($parentId !== null) {
            $query->where('parent_id', $parentId);
        }

        $page = max(1, (int) $request->input('page', 1));
        // 单页上限 200：该接口同时开放给前台公开访问，避免被构造超大 limit 拉取全量数据
        $limit = min(max((int) $request->input('limit', 20), 1), 200);

        $total = $query->count();
        $list = $query->orderBy('code')
            ->forPage($page, $limit)
            ->get();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'count' => $total,
            'data' => $list,
        ]);
    }

    /**
     * 清除缓存
     */
    public function clearCache(): JsonResponse
    {
        $this->regionService->clearCache();

        return response()->json([
            'code' => 0,
            'message' => '缓存已清除',
        ]);
    }

    /**
     * 获取待导入街道数据的省份列表
     */
    public function streetProvinces(): JsonResponse
    {
        $provinces = $this->regionService->getStreetImportProvinces();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $provinces,
        ]);
    }

    /**
     * 按省份导入街道数据（AJAX分步调用）
     */
    public function importStreet(Request $request): JsonResponse
    {
        set_time_limit(300);
        $provinceCode = $request->input('province_code', '');
        if (empty($provinceCode)) {
            return response()->json([
                'code' => 1,
                'message' => '请指定省份代码',
            ]);
        }

        $result = $this->regionService->importStreetByProvince($provinceCode);

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'imported' => $result['inserted'] ?? 0,
                'province' => $result['province'] ?? '',
                'stats' => $result['stats'] ?? null,
            ],
        ]);
    }

    /**
     * 获取指定省份下的区县列表（用于街道分批导入）
     */
    public function streetDistricts(Request $request): JsonResponse
    {
        $provinceCode = $request->input('province_code', '');
        if (empty($provinceCode)) {
            return response()->json(['code' => 1, 'message' => '请指定省份代码']);
        }

        set_time_limit(300);

        $districts = $this->regionService->getStreetImportDistricts($provinceCode);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $districts,
        ]);
    }

    /**
     * 按区县导入街道数据（AJAX分步调用，用于大省份防超时）
     */
    public function importStreetByDistrict(Request $request): JsonResponse
    {
        set_time_limit(300);
        $districtCode = $request->input('district_code', '');
        if (empty($districtCode)) {
            return response()->json(['code' => 1, 'message' => '请指定区县代码']);
        }

        $result = $this->regionService->importStreetByDistrict($districtCode);

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'imported' => $result['inserted'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'district' => $result['province'] ?? '',
            ],
        ]);
    }

    /**
     * 检测 GitHub 数据源连通性
     */
    public function checkGithubConnection(): JsonResponse
    {
        $result = $this->regionService->checkGithubConnection();

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
        ]);
    }

    /**
     * 清理本地数据文件
     */
    public function clearDataFiles(): JsonResponse
    {
        $result = $this->regionService->clearDataFiles();

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
        ]);
    }

    /**
     * 下载 list.json 文件
     */
    public function downloadListJson(): JsonResponse
    {
        set_time_limit(60);
        $result = $this->regionService->downloadListJson();

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'count' => $result['count'] ?? 0,
            ],
        ]);
    }

    /**
     * 获取待下载街道数据的区县列表
     */
    public function downloadDistricts(): JsonResponse
    {
        $districts = $this->regionService->getDownloadDistricts();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $districts,
        ]);
    }

    /**
     * 下载单个区县的街道数据文件
     */
    public function downloadDistrictData(Request $request): JsonResponse
    {
        set_time_limit(60);
        $districtCode = $request->input('district_code', '');
        if (empty($districtCode)) {
            return response()->json(['code' => 1, 'message' => '请指定区县代码']);
        }

        $result = $this->regionService->downloadDistrictData($districtCode);

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'downloaded' => $result['downloaded'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'empty' => $result['empty'] ?? 0,
            ],
        ]);
    }

    /**
     * 重建闭包表
     */
    public function rebuildClosure(): JsonResponse
    {
        set_time_limit(300);
        $this->regionService->rebuildClosureTable();

        return response()->json([
            'code' => 0,
            'message' => '闭包表重建完成',
        ]);
    }

    // ========== 快照导出方法 ==========

    /**
     * 导出全量数据快照包（生产端）
     *
     * 从库导出全量区划为 regions.json + meta.json 打 ZIP 下载，
     * 供上传版本服务器（Versionsvr「区划数据」服务）发布。
     * 失败返回 JSON（code=1），成功返回 ZIP 下载流（下载后自动清理临时包）。
     */
    public function exportSnapshot(Request $request)
    {
        set_time_limit(300);

        try {
            $snapshot = $this->regionSnapshotService->export($request->query('version'));
        } catch (\RuntimeException $e) {
            return response()->json([
                'code' => 1,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->download($snapshot['file_path'], $snapshot['file_name'])
            ->deleteFileAfterSend(true);
    }

    // ========== 在线同步官方数据方法 ==========

    /**
     * 检查远端官方数据更新（消费端）
     *
     * 查询版本服务器「区划数据」服务最新已发布版本，返回远端版本、统计与本地版本对比结果。
     */
    public function syncCheckUpdate(): JsonResponse
    {
        $info = $this->regionDataSyncService->checkUpdate();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $info,
        ]);
    }

    /**
     * 在线同步官方数据（消费端：全量替换落库）
     *
     * 下载 → SHA-256 校验 → 解包 → 契约校验 → staging 分批落库 → 闭包表重建 →
     * RENAME TABLE 原子切换 → 清缓存 → 记版本。
     *
     * 任务在响应发送后继续执行（见 runInBackground）：接口立即返回进度令牌，
     * 前端通过 sync-progress 轮询到 done/error 终态，避免长任务触发网关超时。
     */
    public function syncRegionData(Request $request): JsonResponse
    {
        $token = $this->progressToken($request);
        $version = $request->input('version');
        $maxLevel = (int) $request->input('max_level', RegionLevelService::MAX_LEVEL);

        // 同步级别只开放区县（3）/街道（4）/村社区（5）：更浅级别的选择器无实际意义，
        // 且全量替换语义下选浅级别会清除既有深层数据，需挡住误传参数
        if (! in_array($maxLevel, [3, 4, 5], true)) {
            return response()->json([
                'code' => 1,
                'message' => '同步级别仅支持 3（区县）/4（街道）/5（村/社区）',
                'data' => null,
            ]);
        }

        return $this->runInBackground($token, function () use ($token, $version, $maxLevel) {
            return $this->regionDataSyncService
                ->withProgressToken($token)
                ->sync($version, $maxLevel);
        });
    }

    /**
     * 查询在线同步进度（供前端轮询，实时反馈各阶段）
     */
    public function syncProgress(Request $request): JsonResponse
    {
        $progress = $this->regionDataSyncService->getProgress((string) $request->input('progress_token'));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $progress,
        ]);
    }

    // ========== 快照包导入方法（站点间分享） ==========

    /**
     * 导入快照包（消费端：全量替换落库）
     *
     * 接收其他站点「导出快照包」生成的 ZIP（regions.json + 可选 meta.json），
     * 校验契约后全量替换本地区划数据（staging + RENAME 原子切换），失败零影响。
     * 与在线同步共用进度机制（progress_token + sync-progress 轮询）与后台执行策略。
     */
    public function importSnapshot(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:zip'],
            'progress_token' => ['nullable', 'string', 'max:64'],
        ], [
            'file.required' => '请选择快照包文件',
            'file.max' => '快照包不能超过 50MB',
            'file.mimes' => '仅支持 zip 格式的快照包',
        ]);

        $token = $this->progressToken($request);

        // 上传文件暂存至 storage/app（zip 校验与解包需要真实磁盘路径；响应返回后临时文件会被回收）
        $tempPath = storage_path('app/cmspro-region-import-' . uniqid() . '.zip');

        try {
            $request->file('file')->move(dirname($tempPath), basename($tempPath));
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 1,
                'message' => '快照包接收失败：' . $e->getMessage(),
            ]);
        }

        return $this->runInBackground($token, function () use ($token, $tempPath) {
            try {
                return $this->regionDataSyncService
                    ->withProgressToken($token)
                    ->importFromSnapshot($tempPath);
            } finally {
                if (is_file($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }, function () use ($tempPath): void {
            // 互斥锁占用导致任务未启动时，及时清理已落盘的临时上传包
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        });
    }

    // ========== 长任务后台执行（同步 / 快照包导入） ==========

    /** 同步/导入任务互斥锁（响应先返回、任务仍在后台执行，需防重复触发并发写临时表） */
    private const TASK_LOCK_KEY = 'cmspro_region:data_task_lock';

    /** 互斥锁存活秒数（覆盖五级全量最长耗时，任务结束即释放） */
    private const TASK_LOCK_TTL = 1800;

    /**
     * 解析进度令牌：前端传入则沿用，未传自动生成。
     */
    protected function progressToken(Request $request): string
    {
        $token = trim((string) $request->input('progress_token'));

        return $token !== '' ? $token : 'prog_' . uniqid();
    }

    /**
     * 长任务后台执行：先返回响应，任务在响应发送后继续执行。
     *
     * 五级全量（64.8 万条区划 + 315 万条闭包）受磁盘随机写 IO 限制约需 13 分钟，
     * 远超 nginx `fastcgi_read_timeout`（默认 300s）：同步执行会让前端先收到超时错误，
     * 而 PHP 侧仍在跑（`max_execution_time=0`），造成「已超时但数据最终变了」的错觉。
     * 改为 `app()->terminating()` 注册任务——`Response::send()` 内已调用
     * `fastcgi_finish_request()`，请求连接随响应关闭，任务在后台继续，前端轮询
     * `sync-progress` 直到 done/error 终态。
     *
     * 单元测试环境无真实请求生命周期（`terminate()` 不会被调用），直接同步执行以便断言。
     *
     * @param callable():array $task 返回服务层结果数组的任务
     * @param callable|null $onRejected 互斥锁占用、任务未启动时的清理回调（如删除临时上传包）
     */
    protected function runInBackground(string $token, callable $task, ?callable $onRejected = null): JsonResponse
    {
        if (! Cache::add(self::TASK_LOCK_KEY, $token, self::TASK_LOCK_TTL)) {
            if ($onRejected !== null) {
                $onRejected();
            }

            return response()->json([
                'code' => 1,
                'message' => '已有区划数据同步/导入任务正在执行，请等待完成后再试',
            ]);
        }

        set_time_limit(0);
        ignore_user_abort(true);

        // 任务执行前释放 session 写锁，避免前端进度轮询请求被 session 写锁阻塞
        if (method_exists(request()->session(), 'save')) {
            request()->session()->save();
        }

        $runner = function () use ($token, $task): void {
            try {
                $task();
            } catch (\Throwable $e) {
                // 服务层内部已写入 error 进度；此处兜底服务层之外抛出的异常（如检查更新失败）
                $this->regionDataSyncService->failProgress($token, $e->getMessage());
                Log::warning('[cmspro.region] 区划数据任务失败', ['error' => $e->getMessage()]);
            } finally {
                Cache::forget(self::TASK_LOCK_KEY);
            }
        };

        if (app()->runningUnitTests()) {
            $runner();
        } else {
            app()->terminating($runner);
        }

        return response()->json([
            'code' => 0,
            'message' => '任务已启动，正在后台执行',
            'data' => ['started' => true, 'progress_token' => $token],
        ]);
    }

    // ========== 民政部API导入方法 ==========

    /**
     * 检测民政部API连通性
     */
    public function checkMcaConnection(): JsonResponse
    {
        $result = $this->regionService->checkMcaConnection();

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
        ]);
    }

    /**
     * 从民政部API导入三级区划数据（省/市/区县）
     */
    public function importFromMca(): JsonResponse
    {
        set_time_limit(600); // 10分钟超时

        $result = $this->regionService->importFromMca();

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'inserted' => $result['inserted'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'provinces' => $result['provinces'] ?? [],
                'stats' => $result['stats'] ?? null,
            ],
        ]);
    }

    /**
     * 从民政部API按省份导入街道数据
     */
    public function importStreetFromMca(Request $request): JsonResponse
    {
        set_time_limit(600);

        $provinceCode = $request->input('province_code', '');
        if (empty($provinceCode)) {
            return response()->json([
                'code' => 1,
                'message' => '请指定省份代码',
            ]);
        }

        $result = $this->regionService->importStreetByProvinceFromMca($provinceCode);

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'inserted' => $result['inserted'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'districts' => $result['districts'] ?? [],
                'stats' => $result['stats'] ?? null,
            ],
        ]);
    }

    /**
     * 从民政部API按区县导入街道数据
     */
    public function importStreetByDistrictFromMca(Request $request): JsonResponse
    {
        set_time_limit(300);

        $districtCode = $request->input('district_code', '');
        if (empty($districtCode)) {
            return response()->json([
                'code' => 1,
                'message' => '请指定区县代码',
            ]);
        }

        $result = $this->regionService->importStreetFromMca($districtCode);

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'inserted' => $result['inserted'] ?? 0,
                'updated' => $result['updated'] ?? 0,
            ],
        ]);
    }

    // ========== 差异化对比方法 ==========

    /**
     * 对比三级数据差异（API最新数据 vs 本地 list.json）
     */
    public function diffThreeLevelFromMca(): JsonResponse
    {
        set_time_limit(600);

        $result = $this->regionService->diffThreeLevelFromMca();

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'has_diff' => $result['has_diff'] ?? false,
                'added' => $result['added'] ?? [],
                'removed' => $result['removed'] ?? [],
                'renamed' => $result['renamed'] ?? [],
                'api_count' => $result['api_count'] ?? 0,
                'local_count' => $result['local_count'] ?? 0,
            ],
        ]);
    }

    /**
     * 对比街道数据差异（API最新数据 vs 本地缓存）
     * 支持按省份或按区县对比
     */
    public function diffStreetFromMca(Request $request): JsonResponse
    {
        set_time_limit(600);

        $provinceCode = $request->input('province_code', '');
        $districtCode = $request->input('district_code', '');

        if (!empty($districtCode)) {
            // 按区县对比
            $result = $this->regionService->diffStreetFromMca($districtCode);
        } elseif (!empty($provinceCode)) {
            // 按省份对比
            $result = $this->regionService->diffStreetByProvinceFromMca($provinceCode);
        } else {
            return response()->json([
                'code' => 1,
                'message' => '请指定省份代码或区县代码',
            ]);
        }

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => $result,
        ]);
    }

    /**
     * 获取待导入地理位置数据的省份列表
     */
    public function geoProvinces(): JsonResponse
    {
        $provinces = $this->regionService->getGeoProvinces();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $provinces,
        ]);
    }

    /**
     * 从 list.json 文件读取省份列表（用于数据库为空时的首次导入）
     */
    public function listJsonProvinces(): JsonResponse
    {
        $provinces = $this->regionService->getListJsonProvinces();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $provinces,
        ]);
    }

    /**
     * 按省份导入地理位置数据（AJAX分步调用）
     */
    public function importGeoDataByProvince(Request $request): JsonResponse
    {
        set_time_limit(300);

        $provinceCode = $request->input('province_code', '');
        if (empty($provinceCode)) {
            return response()->json([
                'code' => 1,
                'message' => '请指定省份代码',
            ]);
        }

        $result = $this->regionService->importGeoDataByProvince($provinceCode);

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'province' => $result['province'] ?? '',
            ],
        ]);
    }

    /**
     * 导入地理位置数据（从Excel文件）- 全量导入（保留兼容）
     */
    public function importGeoData(): JsonResponse
    {
        set_time_limit(300);

        $result = $this->regionService->importGeoData();

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'stats' => $result['stats'] ?? null,
            ],
        ]);
    }

    /**
     * 获取支持 GeoJSON 坐标导入的省份列表
     */
    public function geojsonProvinces(): JsonResponse
    {
        $provinces = $this->regionService->getGeojsonProvinces();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $provinces,
        ]);
    }

    /**
     * 补全港澳台区划数据（阿里 DataV 源，修复港澳无子级、台湾脏编码问题）
     */
    public function importHmtRegion(): JsonResponse
    {
        set_time_limit(300);

        $result = $this->regionService->importHmtRegionData();

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'fixed_taiwan' => $result['fixed_taiwan'] ?? 0,
                'inserted' => $result['inserted'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'details' => $result['details'] ?? [],
            ],
        ]);
    }

    /**
     * 从 geojson.cn 按省导入地理坐标（AJAX分步调用）
     */
    public function importGeoDataFromGeojson(Request $request): JsonResponse
    {
        set_time_limit(600);

        $provinceCode = $request->input('province_code', '');
        if (empty($provinceCode)) {
            return response()->json([
                'code' => 1,
                'message' => '请指定省份代码',
            ]);
        }

        $result = $this->regionService->importGeoDataFromGeojson($provinceCode);

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'],
            'data' => [
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'missing' => $result['missing'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'province' => $result['province'] ?? '',
            ],
        ]);
    }

    /**
     * 获取地理位置数据统计
     */
    public function geoStats(): JsonResponse
    {
        $stats = $this->regionService->getGeoStats();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $stats,
        ]);
    }
}
