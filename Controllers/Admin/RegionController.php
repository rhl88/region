<?php

namespace App\Apps\CmsproRegion\Controllers\Admin;

use App\Apps\CmsproRegion\Models\Region;
use App\Apps\CmsproRegion\Services\RegionService;
use App\Apps\CmsproRegion\Services\RegionSnapshotService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegionController extends Controller
{
    protected RegionService $regionService;

    protected RegionSnapshotService $regionSnapshotService;

    public function __construct(RegionService $regionService, RegionSnapshotService $regionSnapshotService)
    {
        $this->regionService = $regionService;
        $this->regionSnapshotService = $regionSnapshotService;
    }

    /**
     * 数据管理页面
     */
    public function index()
    {
        $stats = $this->regionService->getStats();

        return view('cmsproregion::Admin.index', compact('stats'));
    }

    /**
     * 数据演示页面
     */
    public function demo()
    {
        $stats = $this->regionService->getStats();
        $geoStats = $this->regionService->getGeoStats();

        $hasLevel3 = ($stats['province'] > 0 && $stats['city'] > 0 && $stats['district'] > 0);
        $hasStreet = ($stats['street'] > 0);
        $hasGeo = ($geoStats['with_geo'] > 0);

        return view('cmsproregion::Admin.demo', compact('stats', 'geoStats', 'hasLevel3', 'hasStreet', 'hasGeo'));
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
        $maxLevel = min(max($maxLevel, 1), 4);

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

        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 20);

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
