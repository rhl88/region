<?php

namespace App\Apps\CmsproRegion\Services;

use App\Apps\CmsproMaps\Services\CoordinateConverter;
use App\Apps\CmsproRegion\Models\Region;
use App\Apps\CmsproRegion\Models\RegionClosure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegionService
{
    /**
     * 缓存时间（秒）
     */
    protected int $cacheTtl = 86400;

    /**
     * 缓存键前缀
     */
    protected string $cachePrefix = 'region';

    /**
     * 获取所有省份列表（带缓存）
     */
    public function getProvinces(): Collection
    {
        $data = Cache::remember(
            "{$this->cachePrefix}:provinces",
            $this->cacheTtl,
            fn() => Region::where('level', Region::LEVEL_PROVINCE)
                ->where('status', 1)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'level'])
                ->toArray()
        );
        return Region::hydrate($data);
    }

    /**
     * 根据父级 code 获取直接子级列表
     */
    public function getChildren(string $parentCode): Collection
    {
        $data = Cache::remember(
            "{$this->cachePrefix}:v{$this->cacheVersion()}:children:{$parentCode}",
            $this->cacheTtl,
            function () use ($parentCode) {
                $parent = Region::where('code', $parentCode)->first();
                if (!$parent) {
                    return [];
                }
                return Region::where('parent_id', $parent->id)
                    ->where('status', 1)
                    ->orderBy('code')
                    ->get(['id', 'code', 'name', 'level'])
                    ->toArray();
            }
        );
        return Region::hydrate($data);
    }

    /**
     * 获取单个区划当前库中的经纬度（供开发模式「单个更新」实时展示）
     *
     * 直接查库不走缓存，确保更新坐标后立即反映最新值。
     */
    public function getCoordinate(string $code): ?array
    {
        $region = Region::where('code', $code)->first(['code', 'name', 'level', 'lon', 'lat']);
        if (! $region) {
            return null;
        }

        return [
            'code' => $region->code,
            'name' => $region->name,
            'level' => $region->level,
            'lon' => $region->lon,
            'lat' => $region->lat,
        ];
    }

    /**
     * 按百度地图 BD-09 坐标更新区划经纬度（落库前转换为 GCJ-02）
     *
     * 应用内坐标统一为 GCJ-02，百度 JSAPI 正地址解析返回 BD-09，
     * 因此写库前复用「地图」应用的坐标转换能力，避免同一份数据混用两套坐标系。
     *
     * @return array{success: bool, message: string, data?: array}
     */
    public function updateBaiduCoordinate(string $code, float $lng, float $lat): array
    {
        $region = Region::where('code', $code)->first();
        if (! $region) {
            return ['success' => false, 'message' => '区划不存在：' . $code];
        }

        if (! class_exists(CoordinateConverter::class)) {
            return ['success' => false, 'message' => '依赖的「地图」应用未安装，无法完成 BD-09 到 GCJ-02 坐标转换'];
        }

        try {
            CoordinateConverter::assertValid($lng, $lat);
            $gcj = CoordinateConverter::bd09ToGcj02($lng, $lat);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $region->lon = round($gcj['lng'], 6);
        $region->lat = round($gcj['lat'], 6);
        $region->save();

        return [
            'success' => true,
            'message' => '坐标更新成功',
            'data' => [
                'code' => $region->code,
                'name' => $region->name,
                'level' => $region->level,
                'lon' => $region->lon,
                'lat' => $region->lat,
                'baidu_lon' => $lng,
                'baidu_lat' => $lat,
            ],
        ];
    }

    /**
     * 获取批量更新坐标的候选区划（按 code 升序游标分页）
     *
     * @param  int[]  $levels  层级列表（1-4）
     * @param  bool  $onlyMissing  仅返回缺少经纬度的区划
     * @param  string  $afterCode  游标：只返回 code 大于该值的区划
     * @param  int  $limit  本页条数
     * @return array{items: array, next_cursor: string, has_more: bool, total: int|null}
     */
    public function getCoordinateCandidates(
        array $levels,
        bool $onlyMissing,
        string $afterCode,
        int $limit
    ): array {
        $levels = array_values(array_intersect([1, 2, 3, 4, 5], array_map('intval', $levels)));
        if (empty($levels)) {
            // 默认不含村级：村级数据量达数十万条且地理编码精度有限，需用户显式勾选
            $levels = [1, 2, 3, 4];
        }

        $buildQuery = function () use ($levels, $onlyMissing, $afterCode) {
            $query = Region::where('status', 1)
                ->whereIn('level', $levels)
                ->orderBy('code');

            if ($onlyMissing) {
                $query->where(function ($q) {
                    $q->whereNull('lon')->orWhereNull('lat');
                });
            }

            if ($afterCode !== '') {
                $query->where('code', '>', $afterCode);
            }

            return $query;
        };

        $rows = $buildQuery()->limit($limit + 1)->get(['id', 'code', 'name', 'level', 'lon', 'lat']);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        // 仅在首屏统计总量，避免翻页时重复全表 count
        $total = $afterCode === '' ? $buildQuery()->count() : null;

        return [
            'items' => $this->buildCoordinateCandidateItems($rows),
            'next_cursor' => $rows->isNotEmpty() ? (string) $rows->last()->code : $afterCode,
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    /**
     * 组装候选区划的完整地址，供百度地理编码使用
     *
     * @param  Collection  $rows
     */
    protected function buildCoordinateCandidateItems(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        // 一次批量取出所有祖先，避免逐条查询产生 N+1
        $ancestorRows = DB::table('app_cmspro_region_closure as c')
            ->join('app_cmspro_region_region as r', 'r.id', '=', 'c.ancestor')
            ->whereIn('c.descendant', $rows->pluck('id')->all())
            ->where('c.depth', '>', 0)
            ->orderByDesc('c.depth')
            ->get(['c.descendant', 'r.name', 'r.level']);

        $ancestorMap = [];
        foreach ($ancestorRows as $ancestor) {
            $ancestorMap[(int) $ancestor->descendant][] = [
                'name' => $ancestor->name,
                'level' => (int) $ancestor->level,
            ];
        }

        $items = [];
        foreach ($rows as $row) {
            $nodes = $ancestorMap[(int) $row->id] ?? [];
            $nodes[] = ['name' => $row->name, 'level' => (int) $row->level];
            // 闭包表按 depth 倒序返回（近→远），地址需按省→市→区→街道排列
            usort($nodes, fn ($a, $b) => $a['level'] <=> $b['level']);

            $items[] = [
                'code' => (string) $row->code,
                'name' => $row->name,
                'level' => (int) $row->level,
                'address' => $this->buildCoordinateAddress($nodes),
                'city' => $nodes[0]['name'] ?? '',
                'lon' => $row->lon,
                'lat' => $row->lat,
            ];
        }

        return $items;
    }

    /**
     * 按「省+市 空格 区县+街道」规则组装百度地理编码请求地址
     *
     * 例：浙江省嘉兴市 秀洲区嘉北街道
     *
     * @param  array<int, array{name: string, level: int}>  $nodes  按层级升序排列的路径节点
     */
    protected function buildCoordinateAddress(array $nodes): string
    {
        $splitIndex = -1;
        foreach ($nodes as $index => $node) {
            if ($node['level'] >= Region::LEVEL_DISTRICT) {
                $splitIndex = $index;
                break;
            }
        }

        $names = array_column($nodes, 'name');
        if ($splitIndex <= 0) {
            return implode('', $names);
        }

        return implode('', array_slice($names, 0, $splitIndex))
            . ' '
            . implode('', array_slice($names, $splitIndex));
    }

    /**
     * 根据区划 code 获取完整路径（从省到当前节点）
     */
    public function getPath(string $code): Collection
    {
        $data = Cache::remember(
            "{$this->cachePrefix}:v{$this->cacheVersion()}:path:{$code}",
            $this->cacheTtl,
            function () use ($code) {
                $node = Region::where('code', $code)->first();
                if (!$node) {
                    return [];
                }

                // 通过闭包表查询所有祖先
                $ancestorIds = RegionClosure::where('descendant', $node->id)
                    ->where('depth', '>', 0)
                    ->orderByDesc('depth')
                    ->pluck('ancestor');

                $ancestors = Region::whereIn('id', $ancestorIds)
                    ->orderBy('level')
                    ->get(['id', 'code', 'name', 'level']);

                // 加上自身
                return $ancestors->push($node)->toArray();
            }
        );
        return Region::hydrate($data);
    }

    /**
     * 获取级联选择器数据（省→市→区，可选到街道）
     */
    public function getCascaderData(int $maxLevel = 3): array
    {
        $json = Cache::remember(
            "{$this->cachePrefix}:cascader:{$maxLevel}",
            $this->cacheTtl,
            function () use ($maxLevel) {
                $regions = Region::where('status', 1)
                    ->where('level', '<=', $maxLevel)
                    ->orderBy('code')
                    ->get(['id', 'code', 'name', 'level', 'parent_id']);

                return json_encode($this->buildCascaderTree($regions));
            }
        );
        return json_decode($json, true);
    }

    /**
     * 获取数据统计
     */
    public function getStats(): array
    {
        $stats = Region::select('level', DB::raw('count(*) as count'))
            ->groupBy('level')
            ->pluck('count', 'level')
            ->toArray();

        return [
            'province' => $stats[Region::LEVEL_PROVINCE] ?? 0,
            'city' => $stats[Region::LEVEL_CITY] ?? 0,
            'district' => $stats[Region::LEVEL_DISTRICT] ?? 0,
            'street' => $stats[Region::LEVEL_STREET] ?? 0,
            'village' => $stats[Region::LEVEL_VILLAGE] ?? 0,
            'total' => array_sum($stats),
        ];
    }

    /**
     * 导入三级区划数据（省/市/区县，从 list.json）
     * 差异化更新：根据 code 匹配，新增插入、改名/升级更新、撤销禁用
     * 支持两种 list.json 格式：{code: name} 扁平格式 和 树形嵌套格式
     */
    public function importData(): array
    {
        $dataPath = __DIR__ . '/../Data/list.json';

        if (!file_exists($dataPath)) {
            return ['success' => false, 'message' => '数据文件不存在'];
        }

        $jsonContent = file_get_contents($dataPath);
        $data = json_decode($jsonContent, true);

        if (!$data || !is_array($data)) {
            return ['success' => false, 'message' => '数据文件格式错误'];
        }

        try {
            // 自动识别数据格式：树形结构（含 data.children）或扁平结构（{code: name}）
            if (isset($data['data']['children'])) {
                // 树形嵌套格式（来自民政部API）
                $parsed = $this->parseTreeRegionData($data['data']);
            } else {
                // 扁平 {code: name} 格式（来自GitHub）
                $parsed = $this->parseRegionData($data);
            }
            $now = now()->format('Y-m-d H:i:s');

            // 构建源数据映射：code => [name, level, parent_code]
            $sourceMap = [];
            foreach ($parsed['provinces'] as $code => $name) {
                $sourceMap[$code] = ['name' => $name, 'level' => Region::LEVEL_PROVINCE, 'parent_code' => '0'];
            }
            foreach ($parsed['cities'] as $code => $name) {
                $provinceCode = substr($code, 0, 2) . '0000';
                $sourceMap[$code] = ['name' => $name, 'level' => Region::LEVEL_CITY, 'parent_code' => $provinceCode];
            }
            foreach ($parsed['districts'] as $code => $name) {
                $cityCode = substr($code, 0, 4) . '00';
                $provinceCode = substr($code, 0, 2) . '0000';
                // 判断父级：优先市级，若市级不在源数据中则挂在省级下
                $parentCode = isset($sourceMap[$cityCode]) ? $cityCode : $provinceCode;
                $sourceMap[$code] = ['name' => $name, 'level' => Region::LEVEL_DISTRICT, 'parent_code' => $parentCode];
            }

            // 获取现有数据映射：code => Region
            $existingMap = Region::whereIn('level', [1, 2, 3])
                ->get()
                ->keyBy('code');

            // 构建 code => id 映射（含已有和新增）
            $codeIdMap = $existingMap->mapWithKeys(fn($r) => [$r->code => $r->id])->toArray();

            $inserted = 0;
            $updated = 0;
            $disabled = 0;

            // ========== 第一阶段：处理源数据（新增/更新） ==========
            // 按 level 排序，确保父级先处理
            $sourceSorted = $sourceMap;
            uasort($sourceSorted, fn($a, $b) => $a['level'] <=> $b['level']);

            foreach ($sourceSorted as $code => $item) {
                $parentId = $codeIdMap[$item['parent_code']] ?? 0;

                if (isset($existingMap[$code])) {
                    // 已存在：检查是否需要更新
                    $region = $existingMap[$code];
                    $needsUpdate = false;

                    if ($region->name !== $item['name']) {
                        $region->name = $item['name'];
                        $needsUpdate = true;
                    }
                    if ($region->level !== $item['level']) {
                        $region->level = $item['level'];
                        $needsUpdate = true;
                    }
                    if ($region->parent_id !== $parentId) {
                        $region->parent_id = $parentId;
                        $needsUpdate = true;
                    }
                    if ($region->status !== 1) {
                        $region->status = 1;
                        $needsUpdate = true;
                    }

                    if ($needsUpdate) {
                        $region->update_time = $now;
                        $region->save();
                        $updated++;
                    }
                } else {
                    // 新增
                    $newId = DB::table('app_cmspro_region_region')->insertGetId([
                        'code' => $code,
                        'name' => $item['name'],
                        'level' => $item['level'],
                        'parent_id' => $parentId,
                        'status' => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    $codeIdMap[$code] = $newId;
                    $inserted++;
                }
            }

            // ========== 第二阶段：禁用源数据中不存在的三级区划 ==========
            $sourceCodes = array_keys($sourceMap);
            $obsoleteRegions = Region::whereIn('level', [1, 2, 3])
                ->whereNotIn('code', $sourceCodes)
                ->where('status', 1)
                ->get();

            foreach ($obsoleteRegions as $region) {
                $region->status = 0;
                $region->update_time = $now;
                $region->save();
                $disabled++;
            }

            // ========== 第三阶段：重建闭包表（仅在数据有变化时） ==========
            if ($inserted > 0 || $updated > 0 || $disabled > 0) {
                $this->rebuildClosureTable();
            }

            // 注意：不在此处清除缓存，由调用方在全部导入完成后统一清理

            $message = "三级数据更新完成";
            if ($inserted > 0) $message .= "，新增 {$inserted} 条";
            if ($updated > 0) $message .= "，更新 {$updated} 条";
            if ($disabled > 0) $message .= "，禁用 {$disabled} 条";

            return [
                'success' => true,
                'message' => $message,
                'inserted' => $inserted,
                'updated' => $updated,
                'disabled' => $disabled,
                'stats' => $this->getStats(),
            ];
        } catch (\Throwable $e) {
            Log::error('区划数据导入失败', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return ['success' => false, 'message' => '数据导入失败：' . $e->getMessage()];
        }
    }

    /**
     * 按省份导入三级区划数据（省/市/区县）
     * 用于AJAX分步导入，每次处理一个省份，避免一次性处理全部数据导致超时
     */
    public function importDataByProvince(string $provinceCode): array
    {
        $dataPath = __DIR__ . '/../Data/list.json';

        if (!file_exists($dataPath)) {
            return ['success' => false, 'message' => '数据文件不存在'];
        }

        $jsonContent = file_get_contents($dataPath);
        $data = json_decode($jsonContent, true);

        if (!$data || !is_array($data)) {
            return ['success' => false, 'message' => '数据文件格式错误'];
        }

        try {
            // 自动识别数据格式
            if (isset($data['data']['children'])) {
                $parsed = $this->parseTreeRegionData($data['data']);
            } else {
                $parsed = $this->parseRegionData($data);
            }
            $now = now()->format('Y-m-d H:i:s');
            $provincePrefix = substr($provinceCode, 0, 2);

            // 筛选该省份下的数据（省级+市级+区县级）
            $provinceSource = [];
            foreach ($parsed['provinces'] as $code => $name) {
                if (substr($code, 0, 2) === $provincePrefix) {
                    $provinceSource[$code] = ['name' => $name, 'level' => Region::LEVEL_PROVINCE, 'parent_code' => '0'];
                }
            }
            foreach ($parsed['cities'] as $code => $name) {
                if (substr($code, 0, 2) === $provincePrefix) {
                    $provinceSource[$code] = ['name' => $name, 'level' => Region::LEVEL_CITY, 'parent_code' => $provinceCode];
                }
            }
            foreach ($parsed['districts'] as $code => $name) {
                if (substr($code, 0, 2) === $provincePrefix) {
                    $cityCode = substr($code, 0, 4) . '00';
                    $provinceCodeForParent = substr($code, 0, 2) . '0000';
                    $parentCode = isset($provinceSource[$cityCode]) ? $cityCode : $provinceCodeForParent;
                    $provinceSource[$code] = ['name' => $name, 'level' => Region::LEVEL_DISTRICT, 'parent_code' => $parentCode];
                }
            }

            if (empty($provinceSource)) {
                return ['success' => true, 'message' => '该省份无数据', 'inserted' => 0, 'updated' => 0, 'disabled' => 0];
            }

            // 获取该省份下现有数据
            $existingCodes = Region::where('code', 'like', $provincePrefix . '%')
                ->whereIn('level', [1, 2, 3])
                ->pluck('code')
                ->toArray();
            $existingMap = Region::whereIn('code', $existingCodes)
                ->get()
                ->keyBy('code');

            // code => id 映射
            $codeIdMap = [];
            foreach ($existingMap as $region) {
                $codeIdMap[$region->code] = $region->id;
            }

            // 也加入源数据中已有的父级ID（处理跨省份引用）
            $allParentIds = Region::whereIn('code', array_column($provinceSource, 'parent_code'))
                ->where('code', '!=', '0')
                ->pluck('id', 'code')
                ->toArray();
            foreach ($allParentIds as $code => $id) {
                if (!isset($codeIdMap[$code])) {
                    $codeIdMap[$code] = $id;
                }
            }

            $inserted = 0;
            $updated = 0;
            $disabled = 0;

            // 按 level 排序处理
            $sourceSorted = $provinceSource;
            uasort($sourceSorted, fn($a, $b) => $a['level'] <=> $b['level']);

            foreach ($sourceSorted as $code => $item) {
                $parentId = $codeIdMap[$item['parent_code']] ?? 0;

                if (isset($existingMap[$code])) {
                    $region = $existingMap[$code];
                    $needsUpdate = false;

                    if ($region->name !== $item['name']) {
                        $region->name = $item['name'];
                        $needsUpdate = true;
                    }
                    if ($region->level !== $item['level']) {
                        $region->level = $item['level'];
                        $needsUpdate = true;
                    }
                    if ($region->parent_id !== $parentId) {
                        $region->parent_id = $parentId;
                        $needsUpdate = true;
                    }
                    if ($region->status !== 1) {
                        $region->status = 1;
                        $needsUpdate = true;
                    }

                    if ($needsUpdate) {
                        $region->update_time = $now;
                        $region->save();
                        $updated++;
                    }
                } else {
                    $newId = DB::table('app_cmspro_region_region')->insertGetId([
                        'code' => $code,
                        'name' => $item['name'],
                        'level' => $item['level'],
                        'parent_id' => $parentId,
                        'status' => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    $codeIdMap[$code] = $newId;
                    $inserted++;
                }
            }

            // 禁用该省份下源数据中不存在的三级区划
            $sourceCodes = array_keys($provinceSource);
            $obsoleteRegions = Region::where('code', 'like', $provincePrefix . '%')
                ->whereIn('level', [1, 2, 3])
                ->whereNotIn('code', $sourceCodes)
                ->where('status', 1)
                ->get();

            foreach ($obsoleteRegions as $region) {
                $region->status = 0;
                $region->update_time = $now;
                $region->save();
                $disabled++;
            }

            // 重建该省份的闭包表
            $this->rebuildClosureForProvince($provincePrefix);

            // 注意：不在此处清除缓存，由调用方在全部导入完成后统一清理

            $provinceName = $provinceSource[$provinceCode]['name'] ?? $provinceCode;
            $message = "{$provinceName} 数据更新完成";
            if ($inserted > 0) $message .= "，新增 {$inserted} 条";
            if ($updated > 0) $message .= "，更新 {$updated} 条";
            if ($disabled > 0) $message .= "，禁用 {$disabled} 条";

            return [
                'success' => true,
                'message' => $message,
                'inserted' => $inserted,
                'updated' => $updated,
                'disabled' => $disabled,
                'province' => $provinceName,
                'stats' => $this->getStats(),
            ];
        } catch (\Throwable $e) {
            Log::error('区划数据导入失败', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return ['success' => false, 'message' => '数据导入失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取待导入街道数据的省份列表
     * 从数据库获取所有省份，支持从民政部API动态获取街道数据
     */
    public function getStreetImportProvinces(): array
    {
        $provinces = Region::where('level', Region::LEVEL_PROVINCE)
            ->where('status', 1)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $result = [];
        foreach ($provinces as $province) {
            $prefix = substr($province->code, 0, 2);

            $streetCount = Region::where('level', Region::LEVEL_STREET)
                ->where('code', 'like', $prefix . '%')
                ->count();

            $districtCount = Region::where('level', Region::LEVEL_DISTRICT)
                ->where('code', 'like', $prefix . '%')
                ->count();

            $result[] = [
                'id' => $province->id,
                'code' => $province->code,
                'name' => $province->name,
                'file_count' => $districtCount,
                'street_count' => $streetCount,
            ];
        }

        return $result;
    }

    /**
     * 按省份导入街道/乡镇四级数据（差异化更新）
     * 根据 code 匹配：新增插入、改名更新、撤销禁用
     * 数据源：民政部API（优先读取本地缓存，无缓存则调用API并缓存）
     */
    public function importStreetByProvince(string $provinceCode): array
    {
        $province = Region::where('code', $provinceCode)
            ->where('level', Region::LEVEL_PROVINCE)
            ->first();

        if (!$province) {
            return ['success' => false, 'message' => '省份不存在'];
        }

        $provincePrefix = substr($provinceCode, 0, 2);

        $districts = Region::where('level', Region::LEVEL_DISTRICT)
            ->where('code', 'like', $provincePrefix . '%')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        if ($districts->isEmpty()) {
            return ['success' => false, 'message' => '该省份下无区县数据'];
        }

        try {
            $now = now()->format('Y-m-d H:i:s');
            $inserted = 0;
            $updated = 0;
            $disabled = 0;

            // 收集源数据中所有街道 code，以及已处理的区县 ID
            $allSourceStreetCodes = [];
            $processedDistrictIds = [];

            // 一次性查询该省份下所有现有街道，避免 N+1
            $existingStreetsAll = Region::where('level', Region::LEVEL_STREET)
                ->where('code', 'like', $provincePrefix . '%')
                ->get(['id', 'code', 'name', 'parent_id'])
                ->keyBy('code');

            foreach ($districts as $district) {
                // 调用民政部API获取街道数据（优先读取本地缓存）
                $result = $this->getStreetsFromMca($district->code);
                if (!$result['success']) {
                    continue;
                }

                $streets = $result['data'];
                if (empty($streets)) {
                    continue;
                }

                $processedDistrictIds[] = $district->id;

                // 筛选出属于当前区县的现有街道
                $existingStreets = $existingStreetsAll->where('parent_id', $district->id);

                foreach ($streets as $street) {
                    $streetCode = $street['code'];
                    $streetName = $street['name'];

                    $allSourceStreetCodes[] = $streetCode;

                    if (isset($existingStreets[$streetCode])) {
                        // 已存在：检查是否需要更新
                        $region = $existingStreets[$streetCode];
                        $needsUpdate = false;

                        if ($region->name !== $streetName) {
                            $region->name = $streetName;
                            $needsUpdate = true;
                        }
                        if ($region->parent_id !== $district->id) {
                            $region->parent_id = $district->id;
                            $needsUpdate = true;
                        }
                        if ($region->status !== 1) {
                            $region->status = 1;
                            $needsUpdate = true;
                        }

                        if ($needsUpdate) {
                            $region->update_time = $now;
                            $region->save();
                            $updated++;
                        }
                    } else {
                        // 新增（使用 upsert 避免重复 code 异常）
                        DB::table('app_cmspro_region_region')->upsert(
                            [
                                'code' => $streetCode,
                                'name' => $streetName,
                                'level' => Region::LEVEL_STREET,
                                'parent_id' => $district->id,
                                'status' => 1,
                                'create_time' => $now,
                                'update_time' => $now,
                            ],
                            ['code'],
                            ['name', 'parent_id', 'status', 'update_time']
                        );
                        $inserted++;
                    }
                }

                // 每处理一个区县暂停100ms，避免请求过快
                usleep(100000);
            }

            // 禁用源数据中不存在的街道（仅针对本次已处理的区县，避免误杀无数据文件的区县下街道）
            if (!empty($allSourceStreetCodes) && !empty($processedDistrictIds)) {
                $obsoleteStreets = Region::where('level', Region::LEVEL_STREET)
                    ->whereIn('parent_id', $processedDistrictIds)
                    ->whereNotIn('code', $allSourceStreetCodes)
                    ->where('status', 1)
                    ->get();

                foreach ($obsoleteStreets as $region) {
                    $region->status = 0;
                    $region->update_time = $now;
                    $region->save();
                    $disabled++;
                }
            }

            // 重建该省份的闭包表
            $this->rebuildClosureForProvince($provincePrefix);

            // 注意：不在此处清除缓存，由调用方在全部导入完成后统一清理

            $message = "{$province->name} 街道数据更新完成";
            if ($inserted > 0) $message .= "，新增 {$inserted} 条";
            if ($updated > 0) $message .= "，更新 {$updated} 条";
            if ($disabled > 0) $message .= "，禁用 {$disabled} 条";

            return [
                'success' => true,
                'message' => $message,
                'inserted' => $inserted,
                'updated' => $updated,
                'disabled' => $disabled,
                'province' => $province->name,
                'stats' => $this->getStats(),
            ];
        } catch (\Throwable $e) {
            Log::error('街道数据导入失败', [
                'province' => $provinceCode,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => '导入失败：' . $e->getMessage()];
        }
    }

    /**
     * 按区县导入街道/乡镇四级数据（单区县）
     * 用于大省份分批导入，每次只处理一个区县，避免超时
     * 数据源：民政部API（优先读取本地缓存，无缓存则调用API并缓存）
     */
    public function importStreetByDistrict(string $districtCode): array
    {
        $district = Region::where('code', $districtCode)
            ->where('level', Region::LEVEL_DISTRICT)
            ->first();

        // 区县不存在时，自动从 list.json 创建区县及其上级链路
        if (!$district) {
            $district = $this->ensureDistrictExists($districtCode);
            if (!$district) {
                return ['success' => false, 'message' => '区县不存在且无法自动创建'];
            }
        }

        // 调用民政部API获取街道数据（优先读取本地缓存）
        $result = $this->getStreetsFromMca($districtCode);
        if (!$result['success']) {
            return $result;
        }

        $streets = $result['data'];
        if (empty($streets)) {
            return ['success' => true, 'message' => $district->name . ' 无街道数据', 'inserted' => 0, 'updated' => 0, 'disabled' => 0];
        }

        try {
            $now = now()->format('Y-m-d H:i:s');
            $inserted = 0;
            $updated = 0;

            // 获取该区县下现有街道
            $existingStreets = Region::where('parent_id', $district->id)
                ->where('level', Region::LEVEL_STREET)
                ->get()
                ->keyBy('code');

            foreach ($streets as $street) {
                $streetCode = $street['code'];
                $streetName = $street['name'];

                if (isset($existingStreets[$streetCode])) {
                    $region = $existingStreets[$streetCode];
                    $needsUpdate = false;

                    if ($region->name !== $streetName) {
                        $region->name = $streetName;
                        $needsUpdate = true;
                    }
                    if ($region->status !== 1) {
                        $region->status = 1;
                        $needsUpdate = true;
                    }

                    if ($needsUpdate) {
                        $region->update_time = $now;
                        $region->save();
                        $updated++;
                    }
                } else {
                    $globalExists = Region::where('code', $streetCode)->exists();
                    DB::table('app_cmspro_region_region')->upsert(
                        [
                            'code' => $streetCode,
                            'name' => $streetName,
                            'level' => Region::LEVEL_STREET,
                            'parent_id' => $district->id,
                            'status' => 1,
                            'create_time' => $now,
                            'update_time' => $now,
                        ],
                        ['code'],
                        ['name', 'parent_id', 'status', 'update_time']
                    );
                    if ($globalExists) {
                        $updated++;
                    } else {
                        $inserted++;
                    }
                }
            }

            // 注意：不在此处清除缓存，由调用方在全部导入完成后统一清理

            $message = "{$district->name} 街道数据完成";
            if ($inserted > 0) $message .= "，新增 {$inserted} 条";
            if ($updated > 0) $message .= "，更新 {$updated} 条";

            return [
                'success' => true,
                'message' => $message,
                'inserted' => $inserted,
                'updated' => $updated,
                'disabled' => 0,
                'province' => $district->name,
            ];
        } catch (\Throwable $e) {
            Log::error('街道数据导入失败(按区县)', [
                'district' => $districtCode,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => '导入失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取指定省份下有待导入街道数据的区县列表
     * 从数据库获取区县列表，支持从民政部API动态获取街道数据
     */
    public function getStreetImportDistricts(string $provinceCode): array
    {
        $provincePrefix = substr($provinceCode, 0, 2);

        $districts = Region::where('level', Region::LEVEL_DISTRICT)
            ->where('status', 1)
            ->where('code', 'like', $provincePrefix . '%')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'parent_id']);

        $result = [];
        foreach ($districts as $district) {
            $hasStreet = Region::where('parent_id', $district->id)
                ->where('level', Region::LEVEL_STREET)
                ->exists();

            $result[] = [
                'id' => $district->id,
                'code' => $district->code,
                'name' => $district->name,
                'has_street' => $hasStreet ? 1 : 0,
            ];
        }

        return $result;
    }

    /**
     * 从 list.json 获取区划名称
     */
    protected function getRegionNameFromList(string $code): string
    {
        static $listData = null;
        if ($listData === null) {
            $listPath = __DIR__ . '/../Data/list.json';
            $listData = file_exists($listPath)
                ? json_decode(file_get_contents($listPath), true)
                : [];
        }
        return $listData[$code] ?? '';
    }

    /**
     * 确保区县记录存在，不存在则从 list.json 自动创建区县及其上级链路
     */
    protected function ensureDistrictExists(string $districtCode): ?Region
    {
        $listPath = __DIR__ . '/../Data/list.json';
        $listData = file_exists($listPath)
            ? json_decode(file_get_contents($listPath), true)
            : [];

        $now = now()->format('Y-m-d H:i:s');
        $provinceCode = substr($districtCode, 0, 2) . '0000';
        $cityCode = substr($districtCode, 0, 4) . '00';

        // 如果 list.json 中没有该区县，尝试从已有区县推断上级
        if (empty($listData[$districtCode])) {
            // 尝试查找数据库中同前缀的区县，推断其上级
            $samePrefix = Region::where('code', 'like', substr($districtCode, 0, 4) . '%')
                ->where('level', Region::LEVEL_DISTRICT)
                ->first();

            if ($samePrefix) {
                $parent = Region::find($samePrefix->parent_id);
                if ($parent && $parent->level == Region::LEVEL_CITY) {
                    $province = Region::where('code', $provinceCode)->where('level', Region::LEVEL_PROVINCE)->first();
                    if (!$province) {
                        $provinceParent = Region::find($parent->parent_id);
                        if ($provinceParent && $provinceParent->level == Region::LEVEL_PROVINCE) {
                            $province = $provinceParent;
                        }
                    }

                    $city = Region::where('code', $cityCode)->where('level', Region::LEVEL_CITY)->first();
                    if (!$city) {
                        $city = Region::create([
                            'code' => $cityCode,
                            'name' => $parent->parent->name ?? $parent->name,
                            'level' => Region::LEVEL_CITY,
                            'parent_id' => $province->id ?? $parent->parent_id,
                            'status' => 1,
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                    }

                    $districtName = $this->guessDistrictName($districtCode, $samePrefix);

                    return Region::create([
                        'code' => $districtCode,
                        'name' => $districtName,
                        'level' => Region::LEVEL_DISTRICT,
                        'parent_id' => $city->id,
                        'status' => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                }
            }

            // 回退逻辑：无同前缀区县时，通过代码结构和街道文件推断创建
            $streetFilePath = __DIR__ . '/../Data/code/' . $districtCode . '.json';
            if (!file_exists($streetFilePath)) {
                return null;
            }

            // 确保省级存在
            $province = Region::where('code', $provinceCode)->where('level', Region::LEVEL_PROVINCE)->first();
            if (!$province) {
                return null;
            }

            // 确定父级：优先市级，若市级不存在则直接挂省级
            $city = Region::where('code', $cityCode)->where('level', Region::LEVEL_CITY)->first();
            if ($city) {
                $parentId = $city->id;
            } else {
                // 检查 list.json 中是否有市级代码
                $cityName = $listData[$cityCode] ?? '';
                if (!empty($cityName)) {
                    $city = Region::create([
                        'code' => $cityCode,
                        'name' => $cityName,
                        'level' => Region::LEVEL_CITY,
                        'parent_id' => $province->id,
                        'status' => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    $parentId = $city->id;
                } else {
                    // 市级也不存在，直接挂在省级下
                    $parentId = $province->id;
                }
            }

            // 推断区县名称
            $districtName = $this->inferDistrictName($districtCode, $province->name);

            return Region::create([
                'code' => $districtCode,
                'name' => $districtName,
                'level' => Region::LEVEL_DISTRICT,
                'parent_id' => $parentId,
                'status' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        // list.json 中有该区县，按标准流程创建
        // 确保省级存在
        $province = Region::where('code', $provinceCode)->where('level', Region::LEVEL_PROVINCE)->first();
        if (!$province) {
            $provinceName = $listData[$provinceCode] ?? '';
            if (empty($provinceName)) {
                return null;
            }
            $province = Region::create([
                'code' => $provinceCode,
                'name' => $provinceName,
                'level' => Region::LEVEL_PROVINCE,
                'parent_id' => 0,
                'status' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        // 确保市级存在
        $city = Region::where('code', $cityCode)->where('level', Region::LEVEL_CITY)->first();
        if (!$city) {
            $cityName = $listData[$cityCode] ?? '';
            if (empty($cityName)) {
                // 市级不在 list.json 中，直接挂在省级下
                $parentId = $province->id;
            } else {
                $city = Region::create([
                    'code' => $cityCode,
                    'name' => $cityName,
                    'level' => Region::LEVEL_CITY,
                    'parent_id' => $province->id,
                    'status' => 1,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                $parentId = $city->id;
            }
        } else {
            $parentId = $city->id;
        }

        // 创建区县
        $districtName = $listData[$districtCode];
        $district = Region::create([
            'code' => $districtCode,
            'name' => $districtName,
            'level' => Region::LEVEL_DISTRICT,
            'parent_id' => $parentId,
            'status' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $district;
    }

    /**
     * 根据已有区县推断新区县名称
     */
    protected function guessDistrictName(string $districtCode, Region $samePrefixDistrict): string
    {
        // 优先使用已知名称映射
        $knownName = $this->inferDistrictName($districtCode, '');
        if ($knownName !== $districtCode) {
            return $knownName;
        }

        // 从同城市其他区县推断
        $cityCode = substr($districtCode, 0, 4) . '00';
        $cityDistricts = Region::where('code', 'like', $cityCode . '%')
            ->where('level', Region::LEVEL_DISTRICT)
            ->get(['code', 'name']);

        if ($cityDistricts->isNotEmpty()) {
            return $cityDistricts->first()->name . '（新）';
        }

        return $districtCode;
    }

    /**
     * 推断不在 list.json 中的区县名称
     * 用于处理街道数据文件存在但三级区划数据中无记录的特殊代码
     */
    protected function inferDistrictName(string $districtCode, string $provinceName): string
    {
        // 已知特殊区划代码映射（不在 list.json 中但有街道数据的代码）
        $knownNames = [
            // 北京 - 县级代码（1102XX）
            '110228' => '密云县',
            '110229' => '延庆县',
            // 天津 - 县级代码（1202XX）
            '120225' => '蓟县',
            // 河北 - 雄安新区/省直辖县（1390XX）
            '139001' => '定州市',
            '139002' => '辛集市',
            // 上海 - 县级代码（3102XX）
            '310108' => '静安区',
            '310230' => '崇明县',
            // 山东 - 莱芜（3712XX，2019年并入济南）
            '371202' => '莱芜区',
            '371203' => '钢城区',
            // 海南 - 直辖县级市
            '469003' => '儋州市',
            // 重庆 - 县级代码（5002XX）
            '500105' => '江北区',
            '500112' => '渝北区',
            '500228' => '梁平县',
            '500232' => '武隆县',
            '500234' => '开县',
        ];

        if (isset($knownNames[$districtCode])) {
            return $knownNames[$districtCode];
        }

        // 通用回退：省份名 + 代码后3位
        if (!empty($provinceName)) {
            return $provinceName . '辖区(' . substr($districtCode, -3) . ')';
        }

        return $districtCode;
    }

    /**
     * 检测 GitHub 数据源连通性
     * 尝试请求 list.json 的前100字节，仅判断是否可达
     */
    public function checkGithubConnection(): array
    {
        $url = 'https://raw.githubusercontent.com/mumuy/data_location/master/list.json';

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "Range: bytes=0-99\r\n",
            ],
        ]);

        $content = @file_get_contents($url, false, $ctx);

        if ($content === false) {
            return ['success' => false, 'message' => '无法连接 GitHub 数据源，请检查网络'];
        }

        return ['success' => true, 'message' => 'GitHub 数据源连接正常'];
    }

    /**
     * 清理本地数据文件（list.json + code/ 目录）
     * 更新前调用，确保从远程获取最新数据
     */
    public function clearDataFiles(): array
    {
        $dataDir = __DIR__ . '/../Data';
        $cleared = [];

        // 删除 list.json
        $listPath = $dataDir . '/list.json';
        if (file_exists($listPath)) {
            unlink($listPath);
            $cleared[] = 'list.json';
        }

        // 删除 code/ 目录及所有文件
        $codeDir = $dataDir . '/code';
        if (is_dir($codeDir)) {
            $files = glob($codeDir . '/*.json');
            $count = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    $count++;
                }
            }
            // 删除空目录
            @rmdir($codeDir);
            if ($count > 0) {
                $cleared[] = "code/ ({$count} 个文件)";
            }
        }

        $msg = empty($cleared) ? '无本地数据文件需要清理' : '已清理：' . implode('、', $cleared);
        return ['success' => true, 'message' => $msg];
    }

    /**
     * 从 GitHub 下载 list.json 文件
     * 覆盖保存到 Data/list.json
     */
    public function downloadListJson(): array
    {
        $url = 'https://raw.githubusercontent.com/mumuy/data_location/master/list.json';
        $content = @file_get_contents($url);

        if ($content === false) {
            return ['success' => false, 'message' => '下载 list.json 失败，无法连接数据源'];
        }

        $json = json_decode($content, true);
        if (empty($json) || !is_array($json)) {
            return ['success' => false, 'message' => '下载的 list.json 格式无效'];
        }

        $dataPath = __DIR__ . '/../Data/list.json';
        file_put_contents($dataPath, $content);

        $count = count($json);
        return ['success' => true, 'message' => "list.json 下载成功，共 {$count} 条记录", 'count' => $count];
    }

    /**
     * 获取待下载街道数据的区县列表
     * 从 list.json 提取区县代码，筛选出尚未下载的
     */
    public function getDownloadDistricts(): array
    {
        $dataPath = __DIR__ . '/../Data/list.json';
        if (!file_exists($dataPath)) {
            return [];
        }

        $data = json_decode(file_get_contents($dataPath), true);
        if (!$data || !is_array($data)) {
            return [];
        }

        $codeDir = __DIR__ . '/../Data/code';
        if (!is_dir($codeDir)) {
            mkdir($codeDir, 0755, true);
        }

        $result = [];
        foreach ($data as $code => $name) {
            $suffix4 = substr($code, 2, 4);
            $suffix2 = substr($code, 4, 2);
            // 仅区县级（非省级、非市级）
            if ($suffix4 !== '0000' && $suffix2 !== '00') {
                $filePath = $codeDir . '/' . $code . '.json';
                $result[] = [
                    'code' => $code,
                    'name' => $name,
                    'downloaded' => file_exists($filePath) && filesize($filePath) > 0 ? 1 : 0,
                ];
            }
        }

        return $result;
    }

    /**
     * 下载单个区县的街道数据文件
     * 从 GitHub 仓库下载 JSON 文件保存到 Data/code/ 目录
     */
    public function downloadDistrictData(string $districtCode): array
    {
        $codeDir = __DIR__ . '/../Data/code';
        if (!is_dir($codeDir)) {
            mkdir($codeDir, 0755, true);
        }

        $filePath = $codeDir . '/' . $districtCode . '.json';

        // 已下载则跳过
        if (file_exists($filePath) && filesize($filePath) > 0) {
            return ['success' => true, 'message' => '已下载', 'downloaded' => 0, 'skipped' => 1];
        }

        $baseUrl = 'https://raw.githubusercontent.com/mumuy/data_location/master/code/';
        $url = $baseUrl . $districtCode . '.json';

        $content = @file_get_contents($url);
        if ($content === false) {
            return ['success' => false, 'message' => '下载失败，无法连接数据源'];
        }

        $json = json_decode($content, true);
        if (empty($json) || !is_array($json)) {
            return ['success' => true, 'message' => '该区县无街道数据', 'downloaded' => 0, 'empty' => 1];
        }

        file_put_contents($filePath, $content);

        return ['success' => true, 'message' => '下载成功', 'downloaded' => 1];
    }

    /**
     * 完全重建闭包表
     * 删除所有闭包记录后，根据主表 parent_id 重新计算
     *
     * 村级数据纳入后节点总量达 59 万+、闭包记录约 290 万条，PHP 逐节点
     * 上溯构建耗时 30 分钟以上（Web 同步必然超时），改为 SQL 迭代下推：
     * - 先批量插入全部自身闭包（depth = 0）
     * - 再按深度逐层展开：第 d 层由第 d-1 层闭包沿 parent_id 上溯一级生成，
     *   每层一条 INSERT...SELECT 全程在库内完成，PHP 侧零数据搬运
     * - 最大闭包深度 = 最大层级 - 1（五级树为 4），省直辖等短链树天然少插
     *
     * @param string $regionTable 主表名（同步落库时传入 staging 表名）
     * @param string $closureTable 闭包表名（同步落库时传入 staging 表名）
     */
    public function rebuildClosureTable(
        string $regionTable = 'app_cmspro_region_region',
        string $closureTable = 'app_cmspro_region_closure'
    ): void {
        // 清空闭包表
        DB::table($closureTable)->delete();

        // 第 0 层：所有节点的自身闭包
        DB::affectingStatement(
            'INSERT INTO `' . $closureTable . '` (`ancestor`, `descendant`, `depth`) '
            . 'SELECT `id`, `id`, 0 FROM `' . $regionTable . '`'
        );

        // 逐层展开祖先链：第 depth 层 = 第 depth-1 层闭包的祖先再上溯一级父节点
        $maxDepth = max(0, (int) DB::table($regionTable)->max('level') - 1);
        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $inserted = DB::affectingStatement(
                'INSERT INTO `' . $closureTable . '` (`ancestor`, `descendant`, `depth`) '
                . 'SELECT `r`.`parent_id`, `c`.`descendant`, `c`.`depth` + 1 '
                . 'FROM `' . $closureTable . '` `c` '
                . 'JOIN `' . $regionTable . '` `r` ON `r`.`id` = `c`.`ancestor` '
                . 'WHERE `c`.`depth` = ? AND `r`.`parent_id` > 0',
                [$depth - 1]
            );

            // 该层无新增记录说明祖先链已全部展开
            if ($inserted === 0) {
                break;
            }
        }
    }

    /**
     * 重建指定省份的闭包表记录
     * 仅处理该省份下的区划节点，避免全量重建
     */
    protected function rebuildClosureForProvince(string $provincePrefix): void
    {
        // 获取该省份下所有节点
        $regions = DB::table('app_cmspro_region_region')
            ->where('code', 'like', $provincePrefix . '%')
            ->orderBy('level')
            ->get(['id', 'parent_id']);

        if ($regions->isEmpty()) {
            return;
        }

        $nodeIds = $regions->pluck('id')->toArray();

        // 删除这些节点的旧闭包记录（作为祖先或后代的）
        DB::table('app_cmspro_region_closure')
            ->whereIn('ancestor', $nodeIds)
            ->orWhereIn('descendant', $nodeIds)
            ->delete();

        // 还需要包含祖先链上的省份节点本身
        $provinceId = $regions->first()->id;
        $allRelatedIds = $nodeIds;

        // 追溯省份的祖先链
        $provinceRegion = DB::table('app_cmspro_region_region')
            ->where('id', $provinceId)
            ->first();

        if ($provinceRegion && $provinceRegion->parent_id > 0) {
            $currentId = $provinceRegion->parent_id;
            while ($currentId > 0) {
                $allRelatedIds[] = $currentId;
                $parent = DB::table('app_cmspro_region_region')->where('id', $currentId)->first();
                $currentId = $parent ? $parent->parent_id : 0;
            }
        }

        // 获取所有相关节点
        $allRegions = DB::table('app_cmspro_region_region')
            ->whereIn('id', array_unique($allRelatedIds))
            ->orderBy('level')
            ->get(['id', 'parent_id']);

        $parentMap = [];
        foreach ($allRegions as $region) {
            $parentMap[$region->id] = $region->parent_id;
        }

        // 重建闭包记录
        $closureBatch = [];
        foreach ($parentMap as $nodeId => $parentId) {
            $closureBatch[] = ['ancestor' => $nodeId, 'descendant' => $nodeId, 'depth' => 0];

            $currentId = $parentId;
            $depth = 1;
            while ($currentId > 0) {
                $closureBatch[] = ['ancestor' => $currentId, 'descendant' => $nodeId, 'depth' => $depth];
                $depth++;
                $currentId = $parentMap[$currentId] ?? 0;
            }
        }

        foreach (array_chunk($closureBatch, 500) as $chunk) {
            DB::table('app_cmspro_region_closure')->insert($chunk);
        }
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $keys = [
            "{$this->cachePrefix}:provinces",
            "{$this->cachePrefix}:cascader:3",
            "{$this->cachePrefix}:cascader:4",
            "{$this->cachePrefix}:cascader:5",
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        // children/path 动态缓存通过自增版本号整体失效（O(1)）：
        // 版本号自增后旧命名空间的键立即不可读，旧键由 TTL（24h）自然过期回收，
        // 避免村级数据量下逐键删除的百万次缓存调用与 Eloquent 全量实例化
        Cache::increment("{$this->cachePrefix}:version");
    }

    /**
     * 动态缓存键（children/path）的版本号
     *
     * 版本号不存在时返回 0（初始命名空间 v0），与 clearCache 的
     * Cache::increment 自增语义对齐，避免首次清除时版本号错位。
     */
    protected function cacheVersion(): int
    {
        return (int) Cache::get("{$this->cachePrefix}:version", 0);
    }

    // ========== 民政部官方API导入方法 ==========

    /**
     * 民政部API基础URL
     */
    protected string $mcaApiUrl = 'https://dmfw.mca.gov.cn/9095/xzqh/getList';
    
    /**
     * 民政部街道API URL（新接口）
     */
    protected string $mcaStreetApiUrl = 'https://dmfw.mca.gov.cn/xzqh/getCodeList2';
    
    /**
     * 民政部街道API表名
     */
    protected string $mcaStreetTableName = 'Xzqh20251231';

    /**
     * 调用民政部API
     */
    protected function callMcaApi(array $params): array
    {
        $url = $this->mcaApiUrl . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Encoding: gzip, deflate',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Referer: https://dmfw.mca.gov.cn/',
        ]);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => 'CURL错误: ' . $error];
        }

        if ($httpCode !== 200) {
            return ['success' => false, 'message' => 'HTTP错误: ' . $httpCode];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'JSON解析失败: ' . json_last_error_msg()];
        }

        if (($data['status'] ?? 0) !== 200) {
            return ['success' => false, 'message' => 'API返回错误: ' . ($data['message'] ?? '未知错误')];
        }

        return ['success' => true, 'data' => $data['data'] ?? null];
    }

    /**
     * 调用民政部街道API（POST方式，支持分页）
     */
    protected function callMcaStreetApi(string $parentCode, int $pageNum = 1, int $pageSize = 100, int $maxRetries = 3): array
    {
        $postData = http_build_query([
            'parentCode' => $parentCode,
            'placeCode' => '',
            'title' => '',
            'tableName' => $this->mcaStreetTableName,
            'type' => 3,
            'pageNum' => $pageNum,
            'pageSize' => $pageSize,
        ]);

        $retryCount = 0;
        $delay = 2;

        while ($retryCount < $maxRetries) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->mcaStreetApiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept: application/json, text/plain, */*',
                'Accept-Encoding: gzip, deflate',
                'Accept-Language: zh-CN,zh;q=0.9',
                'Referer: https://dmfw.mca.gov.cn/',
                'Content-Type: application/x-www-form-urlencoded',
            ]);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $retryCount++;
                Log::warning('民政部街道API调用失败，重试中', [
                    'parentCode' => $parentCode,
                    'pageNum' => $pageNum,
                    'error' => $error,
                    'retry' => $retryCount,
                ]);
                if ($retryCount < $maxRetries) {
                    sleep($delay);
                    $delay *= 2;
                    continue;
                }
                return ['success' => false, 'message' => 'CURL错误: ' . $error];
            }

            if ($httpCode !== 200) {
                $retryCount++;
                Log::warning('民政部街道API HTTP错误，重试中', [
                    'parentCode' => $parentCode,
                    'pageNum' => $pageNum,
                    'httpCode' => $httpCode,
                    'retry' => $retryCount,
                ]);
                if ($retryCount < $maxRetries) {
                    sleep($delay);
                    $delay *= 2;
                    continue;
                }
                return ['success' => false, 'message' => 'HTTP错误: ' . $httpCode];
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['success' => false, 'message' => 'JSON解析失败: ' . json_last_error_msg()];
            }

            if (($data['status'] ?? 0) !== 200) {
                return ['success' => false, 'message' => 'API返回错误: ' . ($data['message'] ?? '未知错误')];
            }

            return [
                'success' => true,
                'data' => $data['data'] ?? [],
                'total' => $data['total'] ?? 0,
                'pageNum' => $pageNum,
                'pageSize' => $pageSize,
            ];
        }

        return ['success' => false, 'message' => '超过最大重试次数'];
    }

    /**
     * 将API代码标准化为6位行政区划代码
     * 省级2位补0000，市级4位补00，区县级6位不补
     * 非数字编码（如"资料暂缺"）返回空串，由调用方跳过，防止截断产生脏数据
     */
    protected function normalizeCode(string $apiCode): string
    {
        // 非数字编码直接返回空串（历史问题：民政部API对台湾返回"资料暂缺"，
        // 旧逻辑截取前6字节入库成 code='资料' 的脏数据）
        if (!ctype_digit($apiCode)) {
            return '';
        }

        $code = $apiCode;
        $len = strlen($code);

        if ($len <= 2) {
            // 省级：补齐到6位
            return str_pad($code, 6, '0', STR_PAD_RIGHT);
        }
        if ($len <= 4) {
            // 市级：补齐到6位
            return str_pad($code, 6, '0', STR_PAD_RIGHT);
        }
        if ($len <= 6) {
            // 区县级：直接使用
            return $code;
        }
        // 超过6位（如12位API代码）：截取前6位
        return substr($code, 0, 6);
    }

    /**
     * 从民政部API导入三级区划数据（省/市/区县）
     * 全量导入，按省份逐个处理
     */
    public function importFromMca(): array
    {
        try {
            // 第一步：获取所有省份
            $result = $this->callMcaApi(['maxLevel' => 1]);
            if (!$result['success']) {
                return $result;
            }

            $provinces = $result['data']['children'] ?? [];
            if (empty($provinces)) {
                return ['success' => false, 'message' => '未获取到省份数据'];
            }

            $now = now()->format('Y-m-d H:i:s');
            $totalInserted = 0;
            $totalUpdated = 0;
            $provinceResults = [];

            // 第二步：逐个省份导入
            foreach ($provinces as $provinceData) {
                $provinceCode = $this->normalizeCode($provinceData['code']);
                $provinceName = $provinceData['name'];

                // 跳过无效编码（如台湾"资料暂缺"被 normalizeCode 过滤为空串）及台湾省
                if ($provinceCode === '' || $provinceCode === '830000') {
                    continue;
                }

                $provinceResult = $this->importProvinceFromMca($provinceCode, $provinceName, $provinceData);
                $provinceResults[] = [
                    'code' => $provinceCode,
                    'name' => $provinceName,
                    'inserted' => $provinceResult['inserted'] ?? 0,
                    'updated' => $provinceResult['updated'] ?? 0,
                ];

                $totalInserted += $provinceResult['inserted'] ?? 0;
                $totalUpdated += $provinceResult['updated'] ?? 0;

                // 每处理5个省份暂停100ms，避免请求过快
                usleep(100000);
            }

            // 第三步：重建闭包表
            $this->rebuildClosureTable();

            // 第四步：清除缓存
            $this->clearCache();

            return [
                'success' => true,
                'message' => "三级数据导入完成，新增 {$totalInserted} 条，更新 {$totalUpdated} 条",
                'inserted' => $totalInserted,
                'updated' => $totalUpdated,
                'provinces' => $provinceResults,
                'stats' => $this->getStats(),
            ];
        } catch (\Throwable $e) {
            Log::error('民政部API导入失败', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '导入失败: ' . $e->getMessage()];
        }
    }

    /**
     * 导入单个省份的三级数据
     * 逐级获取：省→市→区县（直辖市：省→区县）
     */
    protected function importProvinceFromMca(string $provinceCode, string $provinceName, array $provinceData): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $inserted = 0;
        $updated = 0;

        // 处理省级
        $provinceResult = $this->upsertRegion($provinceCode, $provinceName, Region::LEVEL_PROVINCE, 0, $now);
        $provinceId = $provinceResult['id'];
        $inserted += $provinceResult['inserted'];
        $updated += $provinceResult['updated'];

        // 获取省份的直接子级（市或区县）
        $result = $this->callMcaApi(['maxLevel' => 1, 'code' => $provinceCode]);
        if (!$result['success'] || empty($result['data']['children'])) {
            return compact('inserted', 'updated');
        }

        $children = $result['data']['children'];
        usleep(100000);

        foreach ($children as $childData) {
            $childCode = $this->normalizeCode($childData['code']);
            $childName = $childData['name'];
            $childLevel = (int) $childData['level'];

            // 跳过无效编码（非数字代码过滤后为空）
            if ($childCode === '') {
                continue;
            }

            if ($childLevel === Region::LEVEL_CITY) {
                // 地级市：挂省级下
                $cityResult = $this->upsertRegion($childCode, $childName, Region::LEVEL_CITY, $provinceId, $now);
                $cityId = $cityResult['id'];
                $inserted += $cityResult['inserted'];
                $updated += $cityResult['updated'];

                // 获取该市下的区县
                $districtResult = $this->callMcaApi(['maxLevel' => 1, 'code' => $childCode]);
                if ($districtResult['success'] && !empty($districtResult['data']['children'])) {
                    foreach ($districtResult['data']['children'] as $districtData) {
                        $districtCode = $this->normalizeCode($districtData['code']);
                        $districtName = $districtData['name'];

                        // 跳过无效编码
                        if ($districtCode === '') {
                            continue;
                        }

                        $districtUpsert = $this->upsertRegion($districtCode, $districtName, Region::LEVEL_DISTRICT, $cityId, $now);
                        $inserted += $districtUpsert['inserted'];
                        $updated += $districtUpsert['updated'];
                    }
                    usleep(100000);
                }
            } elseif ($childLevel === Region::LEVEL_DISTRICT) {
                // 直辖市下的区县：直接挂省级下
                $districtResult = $this->upsertRegion($childCode, $childName, Region::LEVEL_DISTRICT, $provinceId, $now);
                $inserted += $districtResult['inserted'];
                $updated += $districtResult['updated'];
            }
        }

        return compact('inserted', 'updated');
    }

    /**
     * 插入或更新区划记录
     */
    protected function upsertRegion(string $code, string $name, int $level, int $parentId, string $now): array
    {
        $existing = Region::where('code', $code)->first();

        if ($existing) {
            $needsUpdate = false;
            if ($existing->name !== $name) {
                $existing->name = $name;
                $needsUpdate = true;
            }
            if ($existing->level !== $level) {
                $existing->level = $level;
                $needsUpdate = true;
            }
            if ($existing->parent_id !== $parentId) {
                $existing->parent_id = $parentId;
                $needsUpdate = true;
            }
            if ($existing->status !== 1) {
                $existing->status = 1;
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $existing->update_time = $now;
                $existing->save();
                return ['id' => $existing->id, 'inserted' => 0, 'updated' => 1];
            }

            return ['id' => $existing->id, 'inserted' => 0, 'updated' => 0];
        }

        $newId = DB::table('app_cmspro_region_region')->insertGetId([
            'code' => $code,
            'name' => $name,
            'level' => $level,
            'parent_id' => $parentId,
            'status' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return ['id' => $newId, 'inserted' => 1, 'updated' => 0];
    }

    /**
     * 从民政部API获取指定区县的街道数据（优先读本地缓存，无缓存则调API并缓存）
     * @param string $districtCode 6位区县代码
     */
    public function getStreetsFromMca(string $districtCode): array
    {
        $codeDir = __DIR__ . '/../Data/code';
        $cacheFile = $codeDir . '/' . $districtCode . '.json';

        // 优先读取本地缓存
        if (file_exists($cacheFile) && filesize($cacheFile) > 0) {
            $cachedData = json_decode(file_get_contents($cacheFile), true);
            if (!empty($cachedData) && is_array($cachedData)) {
                $streets = [];
                foreach ($cachedData as $key => $value) {
                    if (is_array($value)) {
                        // 数组格式 [{code, name, ...}]
                        $streets[] = $value;
                    } else {
                        // {code: name} 格式（JSON数字键会被PHP转为int）
                        $streets[] = ['code' => (string) $key, 'name' => $value];
                    }
                }
                return ['success' => true, 'data' => $streets, 'from_cache' => true];
            }
        }

        // 无缓存，调用API获取
        $parentCode = $districtCode . '000000';
        $pageSize = 100;
        $pageNum = 1;
        $allStreets = [];

        try {
            while (true) {
                $result = $this->callMcaStreetApi($parentCode, $pageNum, $pageSize);
                if (!$result['success']) {
                    return $result;
                }

                $items = $result['data'];
                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    if (($item['level'] ?? 0) !== 4) {
                        continue;
                    }
                    $allStreets[] = [
                        'code' => $item['code'],
                        'name' => $item['name'],
                    ];
                }

                $total = $result['total'] ?? 0;
                if ($pageNum * $pageSize >= $total) {
                    break;
                }
                $pageNum++;
                usleep(100000);
            }
        } catch (\Throwable $e) {
            Log::error('获取街道数据失败', ['districtCode' => $districtCode, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '获取街道数据失败: ' . $e->getMessage()];
        }

        // 缓存到本地（{code: name} 格式，与现有格式一致）
        if (!empty($allStreets)) {
            if (!is_dir($codeDir)) {
                mkdir($codeDir, 0755, true);
            }
            $cacheData = [];
            foreach ($allStreets as $street) {
                $cacheData[$street['code']] = $street['name'];
            }
            file_put_contents($cacheFile, json_encode($cacheData, JSON_UNESCAPED_UNICODE));
        }

        return ['success' => true, 'data' => $allStreets, 'from_cache' => false];
    }

    /**
     * 从民政部API按区县导入街道数据
     */
    public function importStreetFromMca(string $districtCode): array
    {
        $district = Region::where('code', $districtCode)
            ->where('level', Region::LEVEL_DISTRICT)
            ->first();

        if (!$district) {
            return ['success' => false, 'message' => '区县不存在: ' . $districtCode];
        }

        // 从API获取街道数据
        $result = $this->getStreetsFromMca($districtCode);
        if (!$result['success']) {
            return $result;
        }

        $streets = $result['data'];
        if (empty($streets)) {
            return ['success' => true, 'message' => $district->name . ' 无街道数据', 'inserted' => 0, 'updated' => 0];
        }

        try {
            $now = now()->format('Y-m-d H:i:s');
            $inserted = 0;
            $updated = 0;

            // 获取现有街道
            $existingStreets = Region::where('parent_id', $district->id)
                ->where('level', Region::LEVEL_STREET)
                ->get()
                ->keyBy('code');

            foreach ($streets as $street) {
                $streetCode = $street['code'];
                $streetName = $street['name'];

                if (isset($existingStreets[$streetCode])) {
                    $region = $existingStreets[$streetCode];
                    $needsUpdate = false;

                    if ($region->name !== $streetName) {
                        $region->name = $streetName;
                        $needsUpdate = true;
                    }
                    if ($region->status !== 1) {
                        $region->status = 1;
                        $needsUpdate = true;
                    }

                    if ($needsUpdate) {
                        $region->update_time = $now;
                        $region->save();
                        $updated++;
                    }
                } else {
                    DB::table('app_cmspro_region_region')->insert([
                        'code' => $streetCode,
                        'name' => $streetName,
                        'level' => Region::LEVEL_STREET,
                        'parent_id' => $district->id,
                        'status' => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    $inserted++;
                }
            }

            // 重建该省份的闭包表
            $provincePrefix = substr($districtCode, 0, 2);
            $this->rebuildClosureForProvince($provincePrefix);

            return [
                'success' => true,
                'message' => "{$district->name} 街道数据导入完成，新增 {$inserted} 条，更新 {$updated} 条",
                'inserted' => $inserted,
                'updated' => $updated,
            ];
        } catch (\Throwable $e) {
            Log::error('街道数据导入失败', ['district' => $districtCode, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '导入失败: ' . $e->getMessage()];
        }
    }

    /**
     * 按省份导入街道数据（从民政部API）
     * 遍历该省份下所有区县，逐个导入街道
     */
    public function importStreetByProvinceFromMca(string $provinceCode): array
    {
        $province = Region::where('code', $provinceCode)
            ->where('level', Region::LEVEL_PROVINCE)
            ->first();

        if (!$province) {
            return ['success' => false, 'message' => '省份不存在'];
        }

        $provincePrefix = substr($provinceCode, 0, 2);

        // 获取该省份下所有区县
        $districts = Region::where('level', Region::LEVEL_DISTRICT)
            ->where('code', 'like', $provincePrefix . '%')
            ->orderBy('code')
            ->get();

        if ($districts->isEmpty()) {
            return ['success' => false, 'message' => '该省份下无区县数据'];
        }

        $totalInserted = 0;
        $totalUpdated = 0;
        $districtResults = [];

        foreach ($districts as $district) {
            $result = $this->importStreetFromMca($district->code);

            $districtResults[] = [
                'code' => $district->code,
                'name' => $district->name,
                'inserted' => $result['inserted'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'success' => $result['success'] ?? false,
            ];

            $totalInserted += $result['inserted'] ?? 0;
            $totalUpdated += $result['updated'] ?? 0;

            // 每个区县间隔200ms，避免请求过快
            usleep(200000);
        }

        // 清除缓存
        $this->clearCache();

        return [
            'success' => true,
            'message' => "{$province->name} 街道数据导入完成，新增 {$totalInserted} 条，更新 {$totalUpdated} 条",
            'inserted' => $totalInserted,
            'updated' => $totalUpdated,
            'districts' => $districtResults,
            'stats' => $this->getStats(),
        ];
    }

    /**
     * 检测民政部API连通性
     */
    public function checkMcaConnection(): array
    {
        $result = $this->callMcaApi(['maxLevel' => 0, 'code' => '110000']);
        if ($result['success']) {
            return ['success' => true, 'message' => '民政部API连接正常'];
        }
        return ['success' => false, 'message' => '民政部API连接失败: ' . ($result['message'] ?? '未知错误')];
    }

    // ========== 差异化对比方法 ==========

    /**
     * 从民政部API获取完整三级区划数据（不写入数据库，仅用于对比）
     * 返回 code => name 格式
     */
    protected function fetchThreeLevelFromMca(): array
    {
        // 获取所有省份
        $result = $this->callMcaApi(['maxLevel' => 1]);
        if (!$result['success']) {
            return $result;
        }

        $provinces = $result['data']['children'] ?? [];
        if (empty($provinces)) {
            return ['success' => false, 'message' => '未获取到省份数据'];
        }

        $sourceMap = [];

        foreach ($provinces as $provinceData) {
            $provinceCode = $this->normalizeCode($provinceData['code']);
            $provinceName = $provinceData['name'];

            // 跳过无效编码（如台湾"资料暂缺"被过滤为空串）及台湾省
            if ($provinceCode === '' || $provinceCode === '830000') {
                continue;
            }

            $sourceMap[$provinceCode] = $provinceName;

            // 获取省份子级
            $childResult = $this->callMcaApi(['maxLevel' => 1, 'code' => $provinceCode]);
            if (!$childResult['success'] || empty($childResult['data']['children'])) {
                usleep(100000);
                continue;
            }

            $children = $childResult['data']['children'];
            usleep(100000);

            foreach ($children as $childData) {
                $childCode = $this->normalizeCode($childData['code']);
                $childName = $childData['name'];
                $childLevel = (int) $childData['level'];

                // 跳过无效编码
                if ($childCode === '') {
                    continue;
                }

                $sourceMap[$childCode] = $childName;

                if ($childLevel === Region::LEVEL_CITY) {
                    // 获取市级子级（区县）
                    $districtResult = $this->callMcaApi(['maxLevel' => 1, 'code' => $childCode]);
                    if ($districtResult['success'] && !empty($districtResult['data']['children'])) {
                        foreach ($districtResult['data']['children'] as $districtData) {
                            $districtCode = $this->normalizeCode($districtData['code']);
                            if ($districtCode === '') {
                                continue;
                            }
                            $sourceMap[$districtCode] = $districtData['name'];
                        }
                        usleep(100000);
                    }
                }
            }
        }

        return ['success' => true, 'data' => $sourceMap];
    }

    /**
     * 对比三级数据差异（API最新数据 vs 本地 list.json）
     * 返回差异报告，不自动更新
     */
    public function diffThreeLevelFromMca(): array
    {
        // 从API获取最新三级区划数据
        $apiResult = $this->fetchThreeLevelFromMca();
        if (!$apiResult['success']) {
            return $apiResult;
        }

        $apiData = $apiResult['data'];

        // 读取本地 list.json
        $listPath = __DIR__ . '/../Data/list.json';
        if (!file_exists($listPath)) {
            return ['success' => false, 'message' => '本地 list.json 文件不存在'];
        }

        $localData = json_decode(file_get_contents($listPath), true);
        if (empty($localData) || !is_array($localData)) {
            return ['success' => false, 'message' => '本地 list.json 格式无效'];
        }

        // 对比差异
        $added = [];    // API有但本地没有
        $removed = [];  // 本地有但API没有
        $renamed = [];  // 两边都有但名称不同

        foreach ($apiData as $code => $name) {
            if (!isset($localData[$code])) {
                $added[] = ['code' => $code, 'name' => $name];
            } elseif ($localData[$code] !== $name) {
                $renamed[] = ['code' => $code, 'old_name' => $localData[$code], 'new_name' => $name];
            }
        }

        foreach ($localData as $code => $name) {
            if (!isset($apiData[$code])) {
                $removed[] = ['code' => $code, 'name' => $name];
            }
        }

        $hasDiff = !empty($added) || !empty($removed) || !empty($renamed);

        return [
            'success' => true,
            'has_diff' => $hasDiff,
            'added' => $added,
            'removed' => $removed,
            'renamed' => $renamed,
            'api_count' => count($apiData),
            'local_count' => count($localData),
            'message' => $hasDiff ? '发现数据差异，请查看详情' : '数据一致，无差异',
        ];
    }

    /**
     * 对比单个区县的街道数据差异（API最新数据 vs 本地缓存）
     * 返回差异报告，不自动更新
     */
    public function diffStreetFromMca(string $districtCode): array
    {
        $district = Region::where('code', $districtCode)
            ->where('level', Region::LEVEL_DISTRICT)
            ->first();

        if (!$district) {
            return ['success' => false, 'message' => '区县不存在: ' . $districtCode];
        }

        // 读取旧缓存数据
        $codeDir = __DIR__ . '/../Data/code';
        $cacheFile = $codeDir . '/' . $districtCode . '.json';
        $oldData = [];
        if (file_exists($cacheFile) && filesize($cacheFile) > 0) {
            $oldData = json_decode(file_get_contents($cacheFile), true) ?? [];
        }

        // 请求API获取最新数据
        $parentCode = $districtCode . '000000';
        $pageSize = 100;
        $pageNum = 1;
        $newData = [];

        while (true) {
            $result = $this->callMcaStreetApi($parentCode, $pageNum, $pageSize);
            if (!$result['success']) {
                return $result;
            }

            $items = $result['data'];
            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                if (($item['level'] ?? 0) !== 4) {
                    continue;
                }
                $newData[$item['code']] = $item['name'];
            }

            $total = $result['total'] ?? 0;
            if ($pageNum * $pageSize >= $total) {
                break;
            }
            $pageNum++;
            usleep(100000);
        }

        // 对比差异
        $added = [];
        $removed = [];
        $renamed = [];

        foreach ($newData as $code => $name) {
            if (!isset($oldData[$code])) {
                $added[] = ['code' => $code, 'name' => $name];
            } elseif ($oldData[$code] !== $name) {
                $renamed[] = ['code' => $code, 'old_name' => $oldData[$code], 'new_name' => $name];
            }
        }

        foreach ($oldData as $code => $name) {
            if (!isset($newData[$code])) {
                $removed[] = ['code' => $code, 'name' => $name];
            }
        }

        $hasDiff = !empty($added) || !empty($removed) || !empty($renamed);

        return [
            'success' => true,
            'has_diff' => $hasDiff,
            'district_code' => $districtCode,
            'district_name' => $district->name,
            'added' => $added,
            'removed' => $removed,
            'renamed' => $renamed,
            'old_count' => count($oldData),
            'new_count' => count($newData),
            'message' => $hasDiff ? "{$district->name} 发现街道数据差异" : "{$district->name} 街道数据一致",
        ];
    }

    /**
     * 按省份对比街道数据差异（API最新数据 vs 本地缓存）
     * 遍历该省份下所有区县，逐个对比
     */
    public function diffStreetByProvinceFromMca(string $provinceCode): array
    {
        $province = Region::where('code', $provinceCode)
            ->where('level', Region::LEVEL_PROVINCE)
            ->first();

        if (!$province) {
            return ['success' => false, 'message' => '省份不存在'];
        }

        $provincePrefix = substr($provinceCode, 0, 2);

        $districts = Region::where('level', Region::LEVEL_DISTRICT)
            ->where('code', 'like', $provincePrefix . '%')
            ->orderBy('code')
            ->get();

        if ($districts->isEmpty()) {
            return ['success' => false, 'message' => '该省份下无区县数据'];
        }

        $allAdded = 0;
        $allRemoved = 0;
        $allRenamed = 0;
        $districtResults = [];

        foreach ($districts as $district) {
            $result = $this->diffStreetFromMca($district->code);

            $addedCount = count($result['added'] ?? []);
            $removedCount = count($result['removed'] ?? []);
            $renamedCount = count($result['renamed'] ?? []);

            $districtResults[] = [
                'code' => $district->code,
                'name' => $district->name,
                'has_diff' => $result['has_diff'] ?? false,
                'added' => $addedCount,
                'removed' => $removedCount,
                'renamed' => $renamedCount,
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? '',
            ];

            $allAdded += $addedCount;
            $allRemoved += $removedCount;
            $allRenamed += $renamedCount;

            usleep(200000);
        }

        $hasDiff = $allAdded > 0 || $allRemoved > 0 || $allRenamed > 0;

        return [
            'success' => true,
            'has_diff' => $hasDiff,
            'province_code' => $provinceCode,
            'province_name' => $province->name,
            'districts' => $districtResults,
            'total_added' => $allAdded,
            'total_removed' => $allRemoved,
            'total_renamed' => $allRenamed,
            'message' => $hasDiff ? "{$province->name} 发现街道数据差异" : "{$province->name} 街道数据一致",
        ];
    }

    /**
     * 解析树形嵌套格式的 list.json 数据（来自民政部API）
     * 将树形结构展平为 provinces/cities/districts 三级
     */
    protected function parseTreeRegionData(array $rootNode): array
    {
        $provinces = [];
        $cities = [];
        $districts = [];

        $children = $rootNode['children'] ?? [];

        foreach ($children as $provinceNode) {
            $provinceCode = $this->normalizeCode($provinceNode['code']);
            $provinceName = $provinceNode['name'];

            // 跳过无效编码（如台湾"资料暂缺"被过滤为空串）
            if ($provinceCode === '') {
                continue;
            }

            $provinces[$provinceCode] = $provinceName;

            $provinceChildren = $provinceNode['children'] ?? [];

            foreach ($provinceChildren as $childNode) {
                $childCode = $this->normalizeCode($childNode['code']);
                $childName = $childNode['name'];
                $childLevel = (int) ($childNode['level'] ?? 0);

                // 跳过无效编码
                if ($childCode === '') {
                    continue;
                }

                if ($childLevel === Region::LEVEL_CITY) {
                    // 地级市
                    $cities[$childCode] = $childName;

                    // 获取市级下的区县
                    $cityChildren = $childNode['children'] ?? [];
                    foreach ($cityChildren as $districtNode) {
                        $districtCode = $this->normalizeCode($districtNode['code']);
                        if ($districtCode === '') {
                            continue;
                        }
                        $districts[$districtCode] = $districtNode['name'];
                    }
                } elseif ($childLevel === Region::LEVEL_DISTRICT) {
                    // 直辖市下的区县
                    $districts[$childCode] = $childName;
                }
            }
        }

        return compact('provinces', 'cities', 'districts');
    }

    /**
     * 解析 list.json 数据，按层级分类（扁平 {code: name} 格式）
     */
    protected function parseRegionData(array $data): array
    {
        $provinces = [];
        $cities = [];
        $districts = [];

        foreach ($data as $code => $name) {
            $suffix4 = substr($code, 2, 4);
            $suffix2 = substr($code, 4, 2);

            if ($suffix4 === '0000') {
                $provinces[$code] = $name;
            } elseif ($suffix2 === '00') {
                $cities[$code] = $name;
            } else {
                $districts[$code] = $name;
            }
        }

        return compact('provinces', 'cities', 'districts');
    }

    /**
     * 构建级联选择器树形数据
     */
    protected function buildCascaderTree(Collection $regions): array
    {
        $nodesById = [];
        $tree = [];

        foreach ($regions as $region) {
            $nodesById[$region->id] = [
                'value' => $region->code,
                'label' => $region->name,
                'level' => $region->level,
                'children' => [],
            ];
        }

        foreach ($regions as $region) {
            if ($region->parent_id > 0 && isset($nodesById[$region->parent_id])) {
                $nodesById[$region->parent_id]['children'][] = &$nodesById[$region->id];
            } else {
                $tree[] = &$nodesById[$region->id];
            }
        }

        $this->cleanEmptyChildren($tree);

        return $tree;
    }

    /**
     * 递归移除空 children 属性
     */
    protected function cleanEmptyChildren(array &$nodes): void
    {
        foreach ($nodes as &$node) {
            if (empty($node['children'])) {
                unset($node['children']);
            } else {
                $this->cleanEmptyChildren($node['children']);
            }
        }
    }

    /**
     * 获取Excel中所有省份列表（用于AJAX分步导入）
     */
    public function getGeoProvinces(): array
    {
        $filePath = __DIR__ . '/../Data/地理位置编码.xlsx';

        if (!file_exists($filePath)) {
            return [];
        }

        try {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            $provinceSet = [];

            for ($row = 2; $row <= $highestRow; $row++) {
                $province = $sheet->getCell('B' . $row)->getValue();
                $cityGeocode = $sheet->getCell('D' . $row)->getValue();

                if (!empty($province) && !empty($cityGeocode)) {
                    $cityGeocode = (string) $cityGeocode;
                    if (strlen($cityGeocode) === 6 && substr($cityGeocode, 4) === '00') {
                        $provinceCode = substr($cityGeocode, 0, 2) . '0000';
                        if (!isset($provinceSet[$provinceCode])) {
                            $provinceSet[$provinceCode] = $province;
                        }
                    }
                }
            }

            $provinces = [];
            foreach ($provinceSet as $code => $name) {
                $provinces[] = ['code' => $code, 'name' => $name];
            }

            usort($provinces, fn($a, $b) => $a['code'] <=> $b['code']);

            return $provinces;
        } catch (\Throwable $e) {
            Log::error('获取地理位置省份列表失败', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 从 list.json 文件读取省份列表（用于数据库为空时的首次导入）
     */
    public function getListJsonProvinces(): array
    {
        $dataPath = __DIR__ . '/../Data/list.json';

        if (!file_exists($dataPath)) {
            return [];
        }

        $jsonContent = file_get_contents($dataPath);
        $data = json_decode($jsonContent, true);

        if (!$data || !is_array($data)) {
            return [];
        }

        try {
            $provinces = [];

            if (isset($data['data']['children'])) {
                foreach ($data['data']['children'] as $province) {
                    $code = $this->normalizeCode($province['code']);
                    $name = $province['name'];
                    if ($code && $name) {
                        $provinces[] = ['code' => $code, 'name' => $name];
                    }
                }
            } else {
                foreach ($data as $code => $name) {
                    $suffix4 = substr($code, 2, 4);
                    if ($suffix4 === '0000') {
                        $provinces[] = ['code' => $code, 'name' => $name];
                    }
                }
            }

            usort($provinces, fn($a, $b) => $a['code'] <=> $b['code']);

            return $provinces;
        } catch (\Throwable $e) {
            Log::error('从list.json获取省份列表失败', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ========== GeoJSON 在线坐标导入（阿里 DataV，GCJ-02） ==========

    /**
     * 阿里 DataV GeoJSON 地址模板（{code} 为 "230000" 或 "230000_full"）
     * 单区域文件 {code}.json 含自身 center；{code}_full.json 的 features 为下级区划
     */
    protected string $geojsonBaseUrl = 'https://geo.datav.aliyun.com/areas_v3/bound/{code}.json';

    /**
     * 获取支持 GeoJSON 坐标导入的省份列表（库中省级节点，过滤掉无效编码节点）
     */
    public function getGeojsonProvinces(): array
    {
        $rows = Region::where('level', Region::LEVEL_PROVINCE)
            ->orderBy('code')
            ->get(['code', 'name']);

        $provinces = [];
        foreach ($rows as $row) {
            // 过滤历史导入产生的脏数据（code 非数字，无法拼出 GeoJSON 地址）
            if (!preg_match('/^\d{6}$/', (string) $row->code)) {
                continue;
            }
            $provinces[] = ['code' => $row->code, 'name' => $row->name];
        }

        return $provinces;
    }

    /**
     * 从阿里 DataV 拉取指定省份的 GeoJSON 并按层级更新坐标（AJAX 分步导入）
     *
     * 层级处理：{省code}.json 单文件为省级自身坐标；{省code}_full.json 的 features
     * 为下级区划（普通省份为市级，直辖市/港澳为区县级，level 字段区分）；
     * 市级 feature（level=city）再拉 {市code}_full.json 获取区县级坐标。
     *
     * @param string $provinceCode 6 位省级行编码
     * @return array{success: bool, message: string, updated?: int, skipped?: int, missing?: int, failed?: int, province?: string}
     */
    public function importGeoDataFromGeojson(string $provinceCode): array
    {
        if (!preg_match('/^\d{6}$/', $provinceCode)) {
            return ['success' => false, 'message' => '省份代码格式不正确'];
        }

        $province = Region::where('code', $provinceCode)->where('level', Region::LEVEL_PROVINCE)->first();
        if (!$province) {
            return ['success' => false, 'message' => '库中不存在该省级节点'];
        }

        $updated = 0;
        $skipped = 0;
        $missing = 0;
        $failed = 0;
        $now = now()->format('Y-m-d H:i:s');

        // 1. 拉取省级单文件，更新省级自身坐标
        $selfJson = $this->fetchGeojson($provinceCode);
        if (!$selfJson['success']) {
            return ['success' => false, 'message' => "拉取省份数据失败: {$selfJson['message']}"];
        }
        $selfProps = $selfJson['data']['features'][0]['properties'] ?? [];
        $updated += $this->applyGeojsonCoordinate(
            $provinceCode,
            $selfProps['center'] ?? $selfProps['centroid'] ?? null,
            $now,
            $skipped,
            $missing,
            $failed
        );

        // 2. 拉取省级 _full 文件，遍历下级区划（市级或直辖市区县/港澳区级）
        $provinceJson = $this->fetchGeojson($provinceCode . '_full');
        if (!$provinceJson['success']) {
            Log::warning('GeoJSON 省级下级文件拉取失败', ['code' => $provinceCode, 'error' => $provinceJson['message']]);
        } else {
            // 本省 DataV 下级名称索引，用于编码差异兜底（库中为新编码、DataV 沿用旧编码）
            $nameIndex = [];
            $applyByName = function (string $code, string $featureName, ?array $center) use ($now, &$updated, &$skipped, &$missing, &$failed, &$nameIndex): void {
                if ($this->applyGeojsonCoordinate($code, $center, $now, $skipped, $missing, $failed) === 1) {
                    $updated++;
                    return;
                }
                // 编码未命中且确有坐标时，按名称在本省范围内兜底匹配
                if ($missing > 0) {
                    $missing--;
                    $this->applyGeojsonCoordinateByName(substr($code, 0, 2), $featureName, $center, $now, $skipped, $failed, $nameIndex);
                }
            };

            foreach ($provinceJson['data']['features'] ?? [] as $feature) {
                $props = $feature['properties'] ?? [];
                $childCode = $props['adcode'] ?? null;
                if (!$childCode || !preg_match('/^\d{6}$/', (string) $childCode)) {
                    $failed++;
                    continue;
                }

                $applyByName((string) $childCode, (string) ($props['name'] ?? ''), $props['center'] ?? $props['centroid'] ?? null);

                // 市级（level=city）再拉取区县级文件；直辖市区县/港澳区级无需再拉
                if (($props['level'] ?? null) !== 'city') {
                    continue;
                }

                usleep(100000); // 限速，避免对数据源请求过快
                $cityJson = $this->fetchGeojson($childCode . '_full');
                if (!$cityJson['success']) {
                    Log::warning('GeoJSON 市级文件拉取失败', ['code' => $childCode, 'error' => $cityJson['message']]);
                    continue;
                }

                foreach ($cityJson['data']['features'] ?? [] as $districtFeature) {
                    $dProps = $districtFeature['properties'] ?? [];
                    $districtCode = $dProps['adcode'] ?? null;
                    if (!$districtCode || !preg_match('/^\d{6}$/', (string) $districtCode)) {
                        $failed++;
                        continue;
                    }

                    $applyByName((string) $districtCode, (string) ($dProps['name'] ?? ''), $dProps['center'] ?? $dProps['centroid'] ?? null);
                }
            }
        }

        $this->clearCache();

        return [
            'success' => true,
            'message' => "{$province->name} 坐标导入完成（GeoJSON）",
            'updated' => $updated,
            'skipped' => $skipped,
            'missing' => $missing,
            'failed' => $failed,
            'province' => $province->name,
        ];
    }

    /**
     * 补全港澳台区划数据（阿里 DataV 数据源）
     *
     * 背景：民政部 API 对港澳台数据残缺（香港/澳门无子级、台湾代码为"资料暂缺"），
     * 旧导入逻辑曾将"资料暂缺"截断为 code='资料' 的脏数据入库。
     *
     * 处理内容：
     * 1. 台湾脏数据修正：code='资料' → 710000，并更新省级坐标
     * 2. 香港（810000）：拉取 DataV full.json 补全 18 个区级节点（含坐标）
     * 3. 澳门（820000）：拉取 DataV full.json 补全 8 个区级节点（含坐标）
     * 4. 台湾（710000）：DataV 无下级数据，仅更新省级坐标
     * 5. 重建 81/82 前缀省份的闭包表记录
     */
    public function importHmtRegionData(): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $fixedTaiwan = 0;
        $inserted = 0;
        $updated = 0;
        $failed = 0;
        $details = [];

        try {
            // 1. 修正台湾省级脏数据（code='资料' → 710000）
            $taiwanDirty = Region::where('name', 'like', '%台湾%')->where('level', Region::LEVEL_PROVINCE)->get();
            foreach ($taiwanDirty as $region) {
                if (!ctype_digit($region->code)) {
                    $region->code = '710000';
                    $region->lon = null;
                    $region->lat = null;
                    $region->update_time = $now;
                    $region->save();
                    $fixedTaiwan++;
                }
            }

            // 2. 港澳区级补全 + 台湾省级坐标（逐省处理）
            $hmtProvinces = [
                ['code' => '810000', 'name' => '香港特别行政区', 'prefix' => '81'],
                ['code' => '820000', 'name' => '澳门特别行政区', 'prefix' => '82'],
                ['code' => '710000', 'name' => '台湾省', 'prefix' => '71'],
            ];

            foreach ($hmtProvinces as $item) {
                $provinceCode = $item['code'];

                // 确保省级节点存在（不存在则创建）
                $province = Region::where('code', $provinceCode)->first();
                if (!$province) {
                    $provinceId = DB::table('app_cmspro_region_region')->insertGetId([
                        'code' => $provinceCode,
                        'name' => $item['name'],
                        'level' => Region::LEVEL_PROVINCE,
                        'parent_id' => 0,
                        'status' => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    $province = Region::find($provinceId);
                    $inserted++;
                }

                // 拉取省级单文件更新省级自身坐标
                $selfJson = $this->fetchGeojson($provinceCode);
                $selfApplied = false;
                if ($selfJson['success']) {
                    $selfProps = $selfJson['data']['features'][0]['properties'] ?? [];
                    $center = $selfProps['center'] ?? $selfProps['centroid'] ?? null;
                    if (is_array($center) && count($center) >= 2 && is_numeric($center[0]) && is_numeric($center[1])) {
                        $province->lon = (float) $center[0];
                        $province->lat = (float) $center[1];
                        $province->update_time = $now;
                        $province->save();
                        $selfApplied = true;
                    }
                }

                // 拉取 _full 文件补全下级区划（台湾无 _full 文件，仅更新省级坐标）
                $fullJson = $this->fetchGeojson($provinceCode . '_full');
                $childInserted = 0;
                $childUpdated = 0;
                if ($fullJson['success']) {
                    foreach ($fullJson['data']['features'] ?? [] as $feature) {
                        $props = $feature['properties'] ?? [];
                        $childCode = $props['adcode'] ?? null;
                        if (!$childCode || !preg_match('/^\d{6}$/', (string) $childCode)) {
                            $failed++;
                            continue;
                        }

                        $center = $props['center'] ?? $props['centroid'] ?? null;
                        if (!is_array($center) || count($center) < 2 || !is_numeric($center[0]) || !is_numeric($center[1])) {
                            $failed++;
                            continue;
                        }

                        // upsert 区级节点（区级直接挂省级下，与港澳行政层级一致）
                        $existing = Region::where('code', $childCode)->first();
                        $lon = (float) $center[0];
                        $lat = (float) $center[1];

                        if ($existing) {
                            $existing->name = $props['name'] ?? $existing->name;
                            $existing->level = Region::LEVEL_DISTRICT;
                            $existing->parent_id = $province->id;
                            $existing->status = 1;
                            $existing->lon = $lon;
                            $existing->lat = $lat;
                            $existing->update_time = $now;
                            $existing->save();
                            $childUpdated++;
                        } else {
                            DB::table('app_cmspro_region_region')->insert([
                                'code' => $childCode,
                                'name' => $props['name'] ?? '',
                                'level' => Region::LEVEL_DISTRICT,
                                'parent_id' => $province->id,
                                'status' => 1,
                                'lon' => $lon,
                                'lat' => $lat,
                                'create_time' => $now,
                                'update_time' => $now,
                            ]);
                            $childInserted++;
                        }
                    }
                }

                // 重建该省闭包表记录
                $this->rebuildClosureForProvince($item['prefix']);

                $details[] = [
                    'code' => $provinceCode,
                    'name' => $item['name'],
                    'self_coord' => $selfApplied,
                    'inserted' => $childInserted,
                    'updated' => $childUpdated,
                ];

                $inserted += $childInserted;
                $updated += $childUpdated;

                usleep(100000);
            }

            $this->clearCache();

            return [
                'success' => true,
                'message' => "港澳台数据补全完成，新增 " . ($inserted) . " 条，更新 {$updated} 条，修正台湾脏编码 {$fixedTaiwan} 条",
                'fixed_taiwan' => $fixedTaiwan,
                'inserted' => $inserted,
                'updated' => $updated,
                'failed' => $failed,
                'details' => $details,
                'stats' => $this->getStats(),
            ];
        } catch (\Throwable $e) {
            Log::error('港澳台区划补全失败', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '补全失败: ' . $e->getMessage()];
        }
    }

    /**
     * 将 GeoJSON 单个节点的 center 坐标应用到区划记录
     *
     * @param string $code 6 位编码
     * @param array|null $center [lng, lat]
     * @param string $now 更新时间
     * @param int $skipped 引用计数：坐标相同跳过
     * @param int $missing 引用计数：库中无此编码
     * @param int $failed 引用计数：数据异常
     * @return int 实际更新条数（0 或 1）
     */
    protected function applyGeojsonCoordinate(string $code, ?array $center, string $now, int &$skipped, int &$missing, int &$failed): int
    {
        if (!is_array($center) || count($center) < 2 || !is_numeric($center[0]) || !is_numeric($center[1])) {
            $failed++;
            return 0;
        }

        $lon = (float) $center[0];
        $lat = (float) $center[1];

        $region = Region::where('code', $code)->first();
        if (!$region) {
            $missing++;
            return 0;
        }

        if ((float) $region->lon == $lon && (float) $region->lat == $lat) {
            $skipped++;
            return 0;
        }

        $region->lon = $lon;
        $region->lat = $lat;
        $region->update_time = $now;
        $region->save();

        return 1;
    }

    /**
     * 编码差异名称兜底：DataV 沿用旧编码而库中为新编码时（如繁昌区 340211→340212），
     * 在同省范围内按名称匹配库中记录并应用坐标
     *
     * @param string $provincePrefix 省级前 2 位（限定匹配范围，防跨省同名错配）
     * @param string $featureName DataV feature 自带名称（库中编码差异时按名称兜底）
     * @param array|null $center [lng, lat]
     * @param string $now 更新时间
     * @param int $skipped 引用计数：坐标相同跳过
     * @param int $failed 引用计数：数据异常
     * @param array $nameIndex 本省名称索引缓存 [name => Region]
     */
    protected function applyGeojsonCoordinateByName(
        string $provincePrefix,
        string $featureName,
        ?array $center,
        string $now,
        int &$skipped,
        int &$failed,
        array &$nameIndex
    ): void {
        if (!is_array($center) || count($center) < 2 || !is_numeric($center[0]) || !is_numeric($center[1])) {
            return;
        }

        if ($featureName === '') {
            return; // DataV 未提供名称，无从兜底
        }

        // 构建本省名称索引（惰性，一次/省）
        if (!isset($nameIndex[$provincePrefix])) {
            $nameIndex[$provincePrefix] = Region::where('code', 'like', $provincePrefix . '%')
                ->whereIn('level', [Region::LEVEL_CITY, Region::LEVEL_DISTRICT])
                ->get()
                ->keyBy('name')
                ->all();
        }

        $region = $nameIndex[$provincePrefix][$featureName] ?? null;
        if (!$region) {
            return; // 本省无同名记录（如 2023-24 新设区 DataV 未收录）
        }

        $lon = (float) $center[0];
        $lat = (float) $center[1];
        if ((float) $region->lon == $lon && (float) $region->lat == $lat) {
            $skipped++;
            return;
        }

        $region->lon = $lon;
        $region->lat = $lat;
        $region->update_time = $now;
        $region->save();

        Log::info('GeoJSON 名称兜底匹配成功', ['region_code' => $region->code, 'name' => $featureName]);
    }

    /**
     * 拉取并解析阿里 DataV GeoJSON 文件
     *
     * @param string $code 文件名（如 "230000" 或 "230000_full"）
     * @return array{success: bool, message?: string, data?: array}
     */
    protected function fetchGeojson(string $code): array
    {
        $url = str_replace('{code}', $code, $this->geojsonBaseUrl);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => 'CURL错误: ' . $error];
        }
        if ($httpCode !== 200) {
            return ['success' => false, 'message' => "HTTP状态码 {$httpCode}"];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || !isset($data['features'])) {
            return ['success' => false, 'message' => 'GeoJSON 解析失败'];
        }

        return ['success' => true, 'data' => $data];
    }

    /**
     * 按省份导入地理位置数据（AJAX分步导入）
     */
    public function importGeoDataByProvince(string $provinceCode): array
    {
        $filePath = __DIR__ . '/../Data/地理位置编码.xlsx';

        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => '地理位置数据文件不存在'];
        }

        try {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $now = now()->format('Y-m-d H:i:s');
            $provincePrefix = substr($provinceCode, 0, 2);

            for ($row = 2; $row <= $highestRow; $row++) {
                $districtGeocode = $sheet->getCell('F' . $row)->getValue();
                $lon = $sheet->getCell('G' . $row)->getValue();
                $lat = $sheet->getCell('H' . $row)->getValue();

                if (!is_numeric($districtGeocode) || !is_numeric($lon) || !is_numeric($lat)) {
                    $failed++;
                    continue;
                }

                $districtGeocode = (string) $districtGeocode;
                $lon = (float) $lon;
                $lat = (float) $lat;

                if (strlen($districtGeocode) !== 6 || substr($districtGeocode, 0, 2) !== $provincePrefix) {
                    continue;
                }

                $region = Region::where('code', $districtGeocode)->first();

                if (!$region) {
                    $skipped++;
                    continue;
                }

                if ($region->lon == $lon && $region->lat == $lat) {
                    $skipped++;
                    continue;
                }

                $region->lon = $lon;
                $region->lat = $lat;
                $region->update_time = $now;
                $region->save();

                $updated++;
            }

            $this->clearCache();

            $province = Region::where('code', $provinceCode)->value('name');

            return [
                'success' => true,
                'message' => "{$province} 地理位置数据导入完成",
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'province' => $province,
            ];
        } catch (\Throwable $e) {
            Log::error('地理位置数据导入失败', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '导入失败: ' . $e->getMessage()];
        }
    }

    /**
     * 从Excel文件导入地理位置数据（城市和区县）- 全量导入（保留兼容）
     * Excel文件格式：district_id | province | city | city_geocode | district | district_geocode | lon | lat
     */
    public function importGeoData(): array
    {
        $filePath = __DIR__ . '/../Data/地理位置编码.xlsx';

        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => '地理位置数据文件不存在'];
        }

        try {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $now = now()->format('Y-m-d H:i:s');

            for ($row = 2; $row <= $highestRow; $row++) {
                $districtGeocode = $sheet->getCell('F' . $row)->getValue();
                $lon = $sheet->getCell('G' . $row)->getValue();
                $lat = $sheet->getCell('H' . $row)->getValue();

                if (!is_numeric($districtGeocode) || !is_numeric($lon) || !is_numeric($lat)) {
                    $failed++;
                    continue;
                }

                $districtGeocode = (string) $districtGeocode;
                $lon = (float) $lon;
                $lat = (float) $lat;

                if (strlen($districtGeocode) !== 6) {
                    $failed++;
                    continue;
                }

                $region = Region::where('code', $districtGeocode)->first();

                if (!$region) {
                    $skipped++;
                    continue;
                }

                if ($region->lon == $lon && $region->lat == $lat) {
                    $skipped++;
                    continue;
                }

                $region->lon = $lon;
                $region->lat = $lat;
                $region->update_time = $now;
                $region->save();

                $updated++;
            }

            $this->clearCache();

            return [
                'success' => true,
                'message' => "地理位置数据导入完成，更新 {$updated} 条，跳过 {$skipped} 条，失败 {$failed} 条",
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'stats' => $this->getStats(),
            ];
        } catch (\Throwable $e) {
            Log::error('地理位置数据导入失败', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '导入失败: ' . $e->getMessage()];
        }
    }

    /**
     * 获取已导入地理位置数据的统计
     */
    public function getGeoStats(): array
    {
        $total = Region::count();
        $withGeo = Region::whereNotNull('lon')->whereNotNull('lat')->count();
        $provinceWithGeo = Region::where('level', Region::LEVEL_PROVINCE)
            ->whereNotNull('lon')
            ->whereNotNull('lat')
            ->count();
        $cityWithGeo = Region::where('level', Region::LEVEL_CITY)
            ->whereNotNull('lon')
            ->whereNotNull('lat')
            ->count();
        $districtWithGeo = Region::where('level', Region::LEVEL_DISTRICT)
            ->whereNotNull('lon')
            ->whereNotNull('lat')
            ->count();

        return [
            'total' => $total,
            'with_geo' => $withGeo,
            'province_with_geo' => $provinceWithGeo,
            'city_with_geo' => $cityWithGeo,
            'district_with_geo' => $districtWithGeo,
        ];
    }
}
