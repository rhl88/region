<?php

namespace App\Apps\CmsproRegion\Services;

use App\Apps\CmsproRegion\Models\Region;
use App\Enums\Status;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * 在线同步官方区划数据服务（消费端）
 *
 * 从版本服务器（Versionsvr 应用「区划数据」服务）在线同步官方行政区划数据：
 *   GET  {api_url}/api/versionsvr/region-data/latest             → 最新版本信息
 *   GET  {api_url}/api/versionsvr/region-data/download/{version} → 数据包 zip
 *
 * 同步采用「全量替换」策略（与版本服务器发布契约配套）：
 * 下载 → SHA-256 校验 → 解包（防 Zip Slip）→ 契约校验 →
 * 单事务全删重插（分层 chunk 批量插入，parent_code → parent_id）→ 闭包表重建 →
 * 清缓存 → 记版本（ConfigItem）。
 *
 * 安全网：
 * - 契约校验在进事务前执行，非法数据直接拒绝，不动本地库
 * - 落库全程单事务包裹，任一步失败整体回滚，本地数据零影响
 * - 版本服务器历史版本天然支持回退（download/{version}），无需本地留档
 */
class RegionDataSyncService
{
    /** 数据包下载与临时目录（相对 storage/） */
    private const PACK_DIR = 'app_cmspro_region/syncpack';

    /** 解包临时目录（相对 storage/，用后即删） */
    private const WORK_DIR_PREFIX = 'app_cmspro_region/syncwork-';

    /** 数据包内固定文件名（与版本服务器发布契约一致） */
    private const REGIONS_FILE = 'regions.json';

    /** 单次同步最大区划记录数（与版本服务器 20 万上限一致） */
    private const MAX_REGIONS = 200000;

    /** 区划编码最大长度（9 位街道级编码） */
    private const MAX_CODE_LENGTH = 9;

    /** 分层批量插入分块大小 */
    private const CHUNK_SIZE = 500;

    /** 本地保留最近下载数（与清理策略一致） */
    private const KEEP_PACKS = 3;

    public function __construct(protected RegionService $regionService)
    {
    }

    /**
     * 检查远端最新官方数据版本。
     *
     * @return array{available:bool,message:string,version:string,region_count:int,stats:array<string,int>,changelog:string,file_size:int,checksum:string,release_time:string,local_version:string,local_count:int,need_update:bool}
     */
    public function checkUpdate(): array
    {
        $localVersion = $this->localVersion();
        $localCount = Region::count();

        $base = [
            'available' => false,
            'message' => '',
            'version' => '',
            'region_count' => 0,
            'stats' => [],
            'changelog' => '',
            'file_size' => 0,
            'checksum' => '',
            'release_time' => '',
            'local_version' => $localVersion,
            'local_count' => $localCount,
            'need_update' => false,
        ];

        try {
            $response = $this->httpClient()->get($this->apiUrl('/api/versionsvr/region-data/latest'));

            if (! $response->successful()) {
                return array_merge($base, ['message' => '版本服务返回异常（HTTP ' . $response->status() . '）']);
            }

            $payload = $response->json();
            if ((int) ($payload['code'] ?? -1) !== 0 || empty($payload['data'])) {
                return array_merge($base, ['message' => (string) ($payload['message'] ?? '暂无已发布的官方区划数据')]);
            }

            $data = $payload['data'];
            $version = (string) ($data['version'] ?? '');

            return array_merge($base, [
                'available' => true,
                'message' => 'ok',
                'version' => $version,
                'region_count' => (int) ($data['region_count'] ?? 0),
                'stats' => (array) ($data['stats'] ?? []),
                'changelog' => (string) ($data['changelog'] ?? ''),
                'file_size' => (int) ($data['file_size'] ?? 0),
                'checksum' => (string) ($data['checksum'] ?? ''),
                'release_time' => (string) ($data['release_time'] ?? ''),
                'need_update' => $version !== '' && $version !== $localVersion,
            ]);
        } catch (Throwable $e) {
            Log::warning('[cmspro.region] 区划数据版本检查失败', ['error' => $e->getMessage()]);

            return array_merge($base, ['message' => '无法连接版本服务：' . $e->getMessage()]);
        }
    }

    /**
     * 在线同步官方区划数据（全量替换落库）。
     *
     * @param string|null $version 指定同步版本（支持历史版本回退），null 表示最新版本
     * @return array{synced:bool,message:string,version:string,count:int,stats:array<string,int>,duration:float}
     *
     * @throws RuntimeException 版本服务不可用、下载失败、校验失败、数据契约非法或落库失败时
     */
    public function sync(?string $version = null): array
    {
        $info = $this->checkUpdate();
        if (! $info['available']) {
            throw new RuntimeException($info['message'] !== '' ? $info['message'] : '版本服务不可用');
        }

        $requested = trim((string) $version);
        $version = $requested !== '' ? $requested : $info['version'];

        // 未显式指定版本且本地已是最新：跳过下载（避免重复同步）
        if ($requested === '' && $info['local_version'] === $version) {
            return [
                'synced' => false,
                'message' => '本地已是最新版本 ' . $version . '，无需同步',
                'version' => $version,
                'count' => (int) Region::count(),
                'stats' => [],
                'duration' => 0,
            ];
        }

        // 最新版本才有 latest 接口声明的校验和；指定历史版本时跳过校验和比对
        $expectedChecksum = $version === $info['version'] ? $info['checksum'] : '';

        $packDir = storage_path(self::PACK_DIR);
        $this->ensureDir($packDir);
        $zipPath = $packDir . '/cmspro-region-data-' . $version . '.zip';

        try {
            $response = $this->httpClient()->get(
                $this->apiUrl('/api/versionsvr/region-data/download/' . rawurlencode($version))
            );

            if (! $response->successful()) {
                throw new RuntimeException('数据包下载失败（HTTP ' . $response->status() . '）');
            }

            File::put($zipPath, $response->body());

            if ($expectedChecksum !== '' && hash_file('sha256', $zipPath) !== $expectedChecksum) {
                throw new RuntimeException('数据包校验失败，可能已损坏或被篡改');
            }

            $regions = $this->readRegionsFromZip($zipPath);
            $validated = $this->validateRegions($regions);

            $startTime = microtime(true);
            $this->importRegions($validated['regions']);
            $duration = round(microtime(true) - $startTime, 2);

            $this->regionService->clearCache();
            $this->saveVersion($version);
            $this->cleanOldPacks($packDir);

            Log::info('[cmspro.region] 官方区划数据同步完成', [
                'version' => $version,
                'count' => $validated['count'],
                'duration' => $duration,
            ]);

            return [
                'synced' => true,
                'message' => '同步完成',
                'version' => $version,
                'count' => $validated['count'],
                'stats' => $validated['stats'],
                'duration' => $duration,
            ];
        } catch (Throwable $e) {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }

            throw $e instanceof RuntimeException ? $e : new RuntimeException('区划数据同步失败：' . $e->getMessage());
        }
    }

    /**
     * 本地已同步的官方数据版本号（空字符串表示从未同步）。
     */
    public function localVersion(): string
    {
        try {
            return (string) ConfigItem::where('code', 'app_cmspro_region_data_sync_version')->value('value');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * 数据包契约校验（与版本服务器校验规则、快照导出契约完全一致）。
     *
     * @return array{count:int,stats:array<string,int>,regions:array<int,array<string,mixed>>}
     */
    protected function validateRegions(array $regions): array
    {
        if ($regions === []) {
            throw new RuntimeException('未解析到任何区划记录');
        }

        if (count($regions) > self::MAX_REGIONS) {
            throw new RuntimeException('区划记录数超过上限 ' . self::MAX_REGIONS);
        }

        $normalized = [];
        $codes = [];
        $stats = ['province' => 0, 'city' => 0, 'district' => 0, 'street' => 0];
        $levelKeys = [
            Region::LEVEL_PROVINCE => 'province',
            Region::LEVEL_CITY => 'city',
            Region::LEVEL_DISTRICT => 'district',
            Region::LEVEL_STREET => 'street',
        ];

        foreach (array_values($regions) as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException('第 ' . ($index + 1) . ' 条区划记录必须是对象');
            }

            foreach (['code', 'name', 'level', 'parent_code'] as $field) {
                if (! isset($row[$field]) || trim((string) $row[$field]) === '') {
                    throw new RuntimeException('第 ' . ($index + 1) . ' 条区划记录缺少必填字段 ' . $field . ' 或为空');
                }
            }

            $code = trim((string) $row['code']);
            if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
                throw new RuntimeException('区划编码 ' . $code . ' 超过 ' . self::MAX_CODE_LENGTH . ' 位');
            }

            if (isset($codes[$code])) {
                throw new RuntimeException('区划编码 ' . $code . ' 重复，每条记录的 code 必须唯一');
            }
            $codes[$code] = true;

            $level = (int) $row['level'];
            if (! isset($levelKeys[$level])) {
                throw new RuntimeException('区划 ' . $code . ' 的 level 必须为 1（省）/2（市）/3（区县）/4（街道）');
            }

            $statusValue = (int) ($row['status'] ?? 1);

            $normalized[] = [
                'code' => $code,
                'name' => mb_substr(trim((string) $row['name']), 0, 50),
                'level' => $level,
                'parent_code' => trim((string) $row['parent_code']),
                'status' => $statusValue === 0 ? 0 : 1,
                'lon' => is_numeric($row['lon'] ?? null) ? (float) $row['lon'] : null,
                'lat' => is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : null,
            ];

            $stats[$levelKeys[$level]]++;
        }

        // 引用闭环：parent_code 必须为 '0' 或引用数据集中存在的编码
        foreach ($normalized as $row) {
            if ($row['parent_code'] !== '0' && ! isset($codes[$row['parent_code']])) {
                throw new RuntimeException('区划 ' . $row['code'] . ' 引用了不存在的上级编码 ' . $row['parent_code']);
            }
        }

        // 数据集必须至少包含一条省级记录
        if ($stats['province'] === 0) {
            throw new RuntimeException('数据集必须至少包含一条省级（level=1）记录');
        }

        // 按 level + code 排序，保证导入时父级先于子级
        usort($normalized, function (array $a, array $b): int {
            return [$a['level'], $a['code']] <=> [$b['level'], $b['code']];
        });

        return [
            'count' => count($normalized),
            'stats' => $stats,
            'regions' => $normalized,
        ];
    }

    /**
     * 全量替换落库：单事务全删 → 按 level 分层 chunk 批量插入（parent_code → parent_id）→ 闭包表重建。
     *
     * 任一步失败整体回滚，本地数据零影响。
     *
     * @param array<int,array<string,mixed>> $regions 校验并排序后的全量记录
     */
    protected function importRegions(array $regions): void
    {
        DB::transaction(function () use ($regions): void {
            // 全删（MySQL 下 TRUNCATE 会隐式提交事务，必须用 DELETE）
            DB::table('app_cmspro_region_closure')->delete();
            DB::table('app_cmspro_region_region')->delete();

            $now = date('Y-m-d H:i:s');
            $codeMap = []; // code => id，逐层收集供下层 parent 解析

            foreach ([1, 2, 3, 4] as $level) {
                $levelRows = array_values(array_filter(
                    $regions,
                    static fn (array $row): bool => (int) $row['level'] === $level
                ));

                foreach (array_chunk($levelRows, self::CHUNK_SIZE) as $chunk) {
                    $batch = [];
                    foreach ($chunk as $row) {
                        $batch[] = [
                            'code' => $row['code'],
                            'name' => $row['name'],
                            'level' => (int) $row['level'],
                            'parent_id' => $row['parent_code'] === '0'
                                ? 0
                                : (int) ($codeMap[$row['parent_code']] ?? 0),
                            'status' => (int) $row['status'],
                            'lon' => $row['lon'],
                            'lat' => $row['lat'],
                            'create_time' => $now,
                            'update_time' => $now,
                        ];
                    }
                    DB::table('app_cmspro_region_region')->insert($batch);
                }

                // 收集本层 code => id（契约已保证父级先于子级，此映射必命中）
                foreach (
                    DB::table('app_cmspro_region_region')
                        ->where('level', $level)
                        ->pluck('id', 'code') as $code => $id
                ) {
                    $codeMap[(string) $code] = (int) $id;
                }
            }

            // 闭包表全量重建（事务内，失败随事务回滚）
            $this->regionService->rebuildClosureTable();
        });
    }

    /**
     * 解包数据包并读取 regions.json（防 Zip Slip，兼容包内多一层包裹目录）。
     *
     * @return array<int,mixed> 区划记录数组
     */
    protected function readRegionsFromZip(string $zipPath): array
    {
        $workDir = storage_path(self::WORK_DIR_PREFIX . uniqid());
        $this->ensureDir($workDir);

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('数据包损坏或无法打开');
            }

            // 防 Zip Slip：校验包内条目路径合法性
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                if (str_contains($entryName, '..')
                    || str_starts_with($entryName, '/')
                    || preg_match('/^[A-Za-z]:/', $entryName) === 1) {
                    $zip->close();
                    throw new RuntimeException('数据包包含非法的路径条目');
                }
            }

            if ($zip->extractTo($workDir) !== true) {
                $zip->close();
                throw new RuntimeException('数据包解包失败');
            }
            $zip->close();

            // 定位 regions.json（兼容包内多一层包裹目录）
            $regionsFile = null;
            foreach ([$workDir . '/' . self::REGIONS_FILE, $workDir . '/*/' . self::REGIONS_FILE] as $pattern) {
                $candidates = glob($pattern) ?: [];
                if ($candidates !== []) {
                    $regionsFile = $candidates[0];
                    break;
                }
            }

            if ($regionsFile === null) {
                throw new RuntimeException('数据包缺少 ' . self::REGIONS_FILE);
            }

            $regions = json_decode((string) File::get($regionsFile), true);
            if (! is_array($regions)) {
                throw new RuntimeException(self::REGIONS_FILE . ' 不是合法的 JSON 数组');
            }

            return $regions;
        } finally {
            if (is_dir($workDir)) {
                File::deleteDirectory($workDir);
            }
        }
    }

    /**
     * 记录本地已同步的官方数据版本（写入配置项，随应用卸载一并清理）。
     *
     * 配置组由框架在应用安装/升级时按 manifest config_groups 声明落库，
     * 未升级站点（组不存在）静默跳过，不影响同步结果。
     */
    protected function saveVersion(string $version): void
    {
        try {
            $code = 'app_cmspro_region_data_sync_version';
            $item = ConfigItem::where('code', $code)->first();

            if ($item !== null) {
                $item->update(['value' => $version]);
            } else {
                $group = ConfigGroup::where('code', 'app_cmspro_region_sync_settings')->first();
                if ($group === null) {
                    return;
                }

                ConfigItem::create([
                    'group_id' => $group->id,
                    'name' => '已同步数据版本',
                    'code' => $code,
                    'value' => $version,
                    'type' => 'text',
                    'tips' => '最近一次在线同步的官方区划数据版本号（系统自动维护，请勿手工修改）',
                    'sort' => 0,
                    'status' => Status::ENABLED,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('[cmspro.region] 同步版本记录失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 清理旧数据包（保留最近 KEEP_PACKS 个）。
     */
    protected function cleanOldPacks(string $packDir): void
    {
        try {
            $files = glob($packDir . '/*.zip') ?: [];
            if (count($files) <= self::KEEP_PACKS) {
                return;
            }

            usort($files, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
            foreach (array_slice($files, self::KEEP_PACKS) as $old) {
                @unlink($old);
            }
        } catch (Throwable $e) {
            // 清理失败不影响同步结果
        }
    }

    /**
     * 构造版本服务 API 完整地址（沿用系统既有 apps.market.api_url 约定）。
     */
    protected function apiUrl(string $path): string
    {
        return rtrim((string) config('apps.market.api_url', 'https://v5.cmspro.cn'), '/') . $path;
    }

    /**
     * HTTP 客户端（下载 4.7 万条约 2MB 数据包，放宽超时）。
     */
    protected function httpClient()
    {
        return Http::timeout(300)->connectTimeout(10);
    }

    /**
     * 确保目录存在。
     */
    protected function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
    }
}
