<?php

namespace App\Apps\CmsproRegion\Services;

use App\Apps\CmsproRegion\Models\Region;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * 区划数据快照导出服务（生产端）
 *
 * 将库中全量行政区划导出为「全量数据快照包」（regions.json + meta.json），
 * 打包为 cmspro-region-data-{version}.zip 供下载，上传至版本服务器
 * （Versionsvr「区划数据」服务）发布，各站点区划应用在线同步。
 *
 * 导出契约与版本服务器校验规则（Versionsvr RegionDataService::validateRegions）完全对齐：
 * - regions.json：[{code, name, level, parent_code, status, lon, lat}, ...]，按 level + code 排序
 * - meta.json：{version, count, stats:{province,city,district,street,village}, generated_at, format}
 * - 顶级记录 parent_code 固定为 '0'；status 0/1 均导出；无坐标记录 lon/lat 为 null
 */
class RegionSnapshotService
{
    /** 快照包内固定文件名（与版本服务器发布包契约一致） */
    public const REGIONS_FILE = 'regions.json';

    public const META_FILE = 'meta.json';

    /** 单次导出最大记录数（与版本服务器 70 万上限一致，五级全量约 64 万条） */
    private const MAX_REGIONS = 700000;

    /** 版本号格式：字母/数字/点/下划线/连字符，最长 32 位 */
    private const VERSION_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

    /**
     * 导出全量数据快照包。
     *
     * @param string|null $version 快照版本号，留空自动生成（YYYYMMDD + 3 位序号）
     * @return array{file_path:string, file_name:string, version:string, count:int, stats:array<string,int>, file_size:int, checksum:string}
     *
     * @throws RuntimeException 无省级数据、版本号非法、数据完整性异常或打包失败时
     */
    public function export(?string $version = null): array
    {
        $version = $this->resolveVersion($version);

        // 前置校验：必须已有省级数据（空库/半途库不可导出）
        $provinceCount = Region::where('level', Region::LEVEL_PROVINCE)->count();
        if ($provinceCount === 0) {
            throw new RuntimeException('库中暂无省级（level=1）区划数据，请先完成数据导入再导出快照包');
        }

        // id => code 映射已取消：64 万条映射常驻内存（实测约 370MB）且父级 code
        // 可在流式查询中左连父表一次取回，导出峰值内存降至百 MB 以内
        $token = uniqid();
        $regionsFile = storage_path('app/cmspro-region-snapshot-' . $token . '-regions.json');
        $metaFile = storage_path('app/cmspro-region-snapshot-' . $token . '-meta.json');
        $fileName = 'cmspro-region-data-' . $version . '.zip';
        $zipPath = storage_path('app/' . $fileName);

        $success = false;

        try {
            $count = $this->writeRegionsJson($regionsFile);
            $stats = $this->collectStats();

            $this->writeMetaJson($metaFile, $version, $count, $stats);
            $this->buildZip($zipPath, $regionsFile, $metaFile);

            $success = true;

            return [
                'file_path' => $zipPath,
                'file_name' => $fileName,
                'version' => $version,
                'count' => $count,
                'stats' => $stats,
                'file_size' => (int) filesize($zipPath),
                'checksum' => (string) hash_file('sha256', $zipPath),
            ];
        } finally {
            // 清理中间文件；失败时连同快照包一并清理（成功后快照包由下载方负责删除）
            @unlink($regionsFile);
            @unlink($metaFile);
            if (! $success && is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * 流式写出 regions.json：按 level 1→5 分遍，层内按 code 排序整层游标读取。
     *
     * 63.8 万条数据量下原 `orderBy(level)->orderBy(code)->chunk()` 为 offset 翻页且
     * 每页全表 filesort（实测前 2 万条耗时 457 秒）。改为每层一条流式查询：命中
     * (level, code) 复合索引直接按编码序输出，无 offset、无 filesort、无分页往返；
     * 父级 code 由左连父表一次取回，不再构建 64 万条 id → code 映射（省约 370MB 内存）。
     *
     * @return int 导出记录总数
     */
    protected function writeRegionsJson(string $regionsFile): int
    {
        $levelKeys = [
            Region::LEVEL_PROVINCE => 'province',
            Region::LEVEL_CITY => 'city',
            Region::LEVEL_DISTRICT => 'district',
            Region::LEVEL_STREET => 'street',
            Region::LEVEL_VILLAGE => 'village',
        ];

        $handle = fopen($regionsFile, 'wb');
        if ($handle === false) {
            throw new RuntimeException('无法创建导出临时文件');
        }

        $count = 0;

        try {
            fwrite($handle, '[');

            foreach (array_keys($levelKeys) as $level) {
                $rows = DB::table('app_cmspro_region_region as r')
                    ->leftJoin('app_cmspro_region_region as p', 'p.id', '=', 'r.parent_id')
                    ->select(
                        'r.id',
                        'r.code',
                        'r.name',
                        'r.level',
                        'r.parent_id',
                        'r.status',
                        'r.lon',
                        'r.lat',
                        'p.code as parent_code'
                    )
                    ->where('r.level', $level)
                    ->orderBy('r.code')
                    ->cursor();

                foreach ($rows as $region) {
                    // 数据完整性防御：level 必须为 1-5
                    if (! isset($levelKeys[(int) $region->level])) {
                        throw new RuntimeException('区划 ' . $region->code . ' 的 level 非法：' . $region->level . '，请先修复数据');
                    }

                    // parent_id → parent_code：顶级为 '0'，子级为父记录 code
                    $parentId = (int) $region->parent_id;
                    if ($parentId === 0) {
                        $parentCode = '0';
                    } elseif ($region->parent_code !== null) {
                        $parentCode = (string) $region->parent_code;
                    } else {
                        throw new RuntimeException('区划 ' . $region->code . ' 引用了不存在的上级记录（parent_id=' . $region->parent_id . '），请先修复数据');
                    }

                    $row = [
                        'code' => (string) $region->code,
                        'name' => (string) $region->name,
                        'level' => (int) $region->level,
                        'parent_code' => $parentCode,
                        'status' => (int) $region->status === 0 ? 0 : 1,
                        'lon' => $region->lon === null ? null : (float) $region->lon,
                        'lat' => $region->lat === null ? null : (float) $region->lat,
                    ];

                    fwrite($handle, ($count > 0 ? ',' : '') . json_encode($row, JSON_UNESCAPED_UNICODE));
                    $count++;

                    if ($count > self::MAX_REGIONS) {
                        throw new RuntimeException('导出记录数超过上限 ' . self::MAX_REGIONS);
                    }
                }
            }

            fwrite($handle, ']');
        } finally {
            fclose($handle);
        }

        if ($count === 0) {
            throw new RuntimeException('未导出任何区划记录');
        }

        return $count;
    }

    /**
     * 统计五级数量。
     *
     * @return array<string,int>
     */
    protected function collectStats(): array
    {
        $stats = Region::query()
            ->select('level', DB::raw('count(*) as count'))
            ->groupBy('level')
            ->pluck('count', 'level')
            ->all();

        return [
            'province' => (int) ($stats[Region::LEVEL_PROVINCE] ?? 0),
            'city' => (int) ($stats[Region::LEVEL_CITY] ?? 0),
            'district' => (int) ($stats[Region::LEVEL_DISTRICT] ?? 0),
            'street' => (int) ($stats[Region::LEVEL_STREET] ?? 0),
            'village' => (int) ($stats[Region::LEVEL_VILLAGE] ?? 0),
        ];
    }

    /**
     * 写出 meta.json（版本与统计，日期时间统一 Y-m-d H:i:s 格式）。
     *
     * @param array<string,int> $stats
     */
    protected function writeMetaJson(string $metaFile, string $version, int $count, array $stats): void
    {
        $meta = json_encode([
            'version' => $version,
            'count' => $count,
            'stats' => $stats,
            'generated_at' => date('Y-m-d H:i:s'),
            'format' => 'code,name,level,parent_code,status,lon,lat',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($meta === false || file_put_contents($metaFile, $meta) === false) {
            throw new RuntimeException('meta.json 写入失败');
        }
    }

    /**
     * 打包快照 ZIP（regions.json + meta.json，与版本服务器发布包同构）。
     */
    protected function buildZip(string $zipPath, string $regionsFile, string $metaFile): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('创建快照包失败');
        }

        $zip->addFile($regionsFile, self::REGIONS_FILE);
        $zip->addFile($metaFile, self::META_FILE);
        $zip->close();

        if (! is_file($zipPath)) {
            throw new RuntimeException('快照包写入失败');
        }
    }

    /**
     * 解析版本号：显式传入则校验格式，留空自动生成（YYYYMMDD + 3 位序号）。
     *
     * 序号固定为 001：导出端无发布记录表，无法推算当日序号，
     * 与已有版本冲突时可在导出时自定义版本号。
     */
    protected function resolveVersion(?string $version): string
    {
        if ($version === null || trim($version) === '') {
            return date('Ymd') . '001';
        }

        $version = trim($version);
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new RuntimeException('版本号格式非法：仅允许字母、数字、点、下划线、连字符，最长 32 位');
        }

        return $version;
    }
}
