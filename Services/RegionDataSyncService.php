<?php

namespace App\Apps\CmsproRegion\Services;

use App\Apps\CmsproRegion\Models\Region;
use App\Enums\Status;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\Cache;
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

    private const META_FILE = 'meta.json';

    /** 单次同步最大区划记录数（与版本服务器 70 万上限一致，五级全量约 64 万条） */
    private const MAX_REGIONS = 700000;

    /** 区划编码最大长度（12 位村级编码） */
    private const MAX_CODE_LENGTH = 12;

    /** 分层批量插入分块大小（单行 10 参数 × 2000 = 2 万，低于 PDO 6.5 万参数上限） */
    private const CHUNK_SIZE = 2000;

    /** 流式读取块大小（1MB：fread 次数与常驻内存的平衡点） */
    private const READ_CHUNK_SIZE = 1048576;

    /** 本地保留最近下载数（与清理策略一致） */
    private const KEEP_PACKS = 3;

    /** 同步进度缓存键前缀（供前端轮询，长任务执行期间实时反馈各阶段） */
    private const PROGRESS_KEY_PREFIX = 'cmspro_region:sync_progress:';

    /** 同步进度缓存存活秒数（覆盖最长同步耗时，过期自动清理） */
    private const PROGRESS_TTL = 600;

    /** 当前同步的进度令牌（为空表示不上报进度，如服务层直接调用） */
    protected ?string $progressToken = null;

    public function __construct(protected RegionService $regionService)
    {
    }

    /**
     * 设置进度令牌：非空时同步过程各阶段实时写入缓存，供前端轮询展示进度。
     */
    public function withProgressToken(?string $token): static
    {
        $token = trim((string) $token);
        $this->progressToken = $token !== '' ? $token : null;

        return $this;
    }

    /**
     * 读取同步进度（供轮询接口）。令牌无对应进度时返回 idle 态。
     *
     * @return array{state:string,percent:int,stage:string,message:string}
     */
    public function getProgress(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['state' => 'idle', 'percent' => 0, 'stage' => '', 'message' => ''];
        }

        $progress = Cache::get(self::PROGRESS_KEY_PREFIX . $token);

        return is_array($progress)
            ? $progress
            : ['state' => 'idle', 'percent' => 0, 'stage' => '', 'message' => ''];
    }

    /**
     * 上报同步进度到缓存（令牌为空时静默跳过）。
     */
    protected function reportProgress(string $state, int $percent, string $stage, string $message = ''): void
    {
        if ($this->progressToken === null) {
            return;
        }

        Cache::put(self::PROGRESS_KEY_PREFIX . $this->progressToken, [
            'state' => $state,
            'percent' => max(0, min(100, $percent)),
            'stage' => $stage,
            'message' => $message,
        ], self::PROGRESS_TTL);
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

            // 官方版本须高于本地版本才提示更新：本地为先行修正版（高于官方）时
            // 拒绝提示更新，防止误点「在线同步」用官方旧包全量覆盖本地新数据
            $outdated = $version !== '' && $localVersion !== '' && strcmp($version, $localVersion) < 0;

            return array_merge($base, [
                'available' => true,
                'message' => $outdated
                    ? '本地版本 ' . $localVersion . ' 已高于官方最新版本 ' . $version . '，无需同步'
                    : 'ok',
                'version' => $version,
                'region_count' => (int) ($data['region_count'] ?? 0),
                'stats' => (array) ($data['stats'] ?? []),
                'changelog' => (string) ($data['changelog'] ?? ''),
                'file_size' => (int) ($data['file_size'] ?? 0),
                'checksum' => (string) ($data['checksum'] ?? ''),
                'release_time' => (string) ($data['release_time'] ?? ''),
                'need_update' => ! $outdated && $version !== '' && $version !== $localVersion,
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

        // 官方最新版本低于本地版本时拒绝自动同步：防止本地先行修正的数据被官方旧包全量回退
        // （显式指定版本号的历史回退不受此限）
        if ($requested === '' && $info['version'] !== '' && $info['local_version'] !== ''
            && strcmp($info['version'], $info['local_version']) < 0) {
            throw new RuntimeException(
                '本地版本 ' . $info['local_version'] . ' 已高于官方最新版本 ' . $info['version']
                . '，自动同步会将本地数据回退为官方旧版；如确认需要回退，请显式指定版本号'
            );
        }

        // 最新版本才有 latest 接口声明的校验和；指定历史版本时跳过校验和比对
        $expectedChecksum = $version === $info['version'] ? $info['checksum'] : '';

        $packDir = storage_path(self::PACK_DIR);
        $this->ensureDir($packDir);
        $zipPath = $packDir . '/cmspro-region-data-' . $version . '.zip';

        try {
            $this->reportProgress('running', 5, '正在连接版本服务器并下载数据包...');

            $response = $this->httpClient()->get(
                $this->apiUrl('/api/versionsvr/region-data/download/' . rawurlencode($version))
            );

            if (! $response->successful()) {
                throw new RuntimeException('数据包下载失败（HTTP ' . $response->status() . '）');
            }

            File::put($zipPath, $response->body());

            $this->reportProgress('running', 20, '正在校验数据包完整性（SHA-256）...');

            if ($expectedChecksum !== '' && hash_file('sha256', $zipPath) !== $expectedChecksum) {
                throw new RuntimeException('数据包校验失败，可能已损坏或被篡改');
            }

            $this->reportProgress('running', 30, '正在解包并校验数据契约...');

            $package = $this->readPackageFromZip($zipPath);
            $jsonlFile = null;

            try {
                // 流式校验：逐条扫描写 JSONL 临时文件，不整体载入内存
                // （村级数据 58 万条 / 67MB 全量解码会耗尽 512M memory_limit）
                $validated = $this->validateRegionsFromFile($package['regions_file']);
                $jsonlFile = $validated['jsonl_file'];

                $this->reportProgress('running', 45, '数据校验通过，共 ' . $validated['count'] . ' 条，正在全量替换落库...');

                $startTime = microtime(true);
                $this->importRegions($jsonlFile, (int) ceil($validated['stats']['village'] / self::CHUNK_SIZE));
                $duration = round(microtime(true) - $startTime, 2);
            } finally {
                // regions.json 转存文件与校验产出的 JSONL 均为大体积临时文件（67MB 级），处理后立即清理
                @unlink($package['regions_file']);
                if ($jsonlFile !== null) {
                    @unlink($jsonlFile);
                }
            }

            $this->reportProgress('running', 90, '落库完成，正在清除缓存并记录版本...');

            $this->regionService->clearCache();
            $this->saveVersion($version);
            $this->cleanOldPacks($packDir);

            $this->reportProgress('done', 100, '同步完成', '版本 ' . $version . '，共 ' . $validated['count'] . ' 条，耗时 ' . $duration . ' 秒');

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

            $message = $e instanceof RuntimeException ? $e->getMessage() : '区划数据同步失败：' . $e->getMessage();
            $this->reportProgress('error', 100, '同步失败', $message);

            throw $e instanceof RuntimeException ? $e : new RuntimeException($message);
        }
    }

    /**
     * 导入快照包（站点间分享，全量替换落库）。
     *
     * 接收其他站点「导出快照包」生成的 cmspro-region-data-{version}.zip，
     * 走与在线同步相同的校验与落库链路：解包（防 Zip Slip）→ 契约校验 →
     * 单事务全删重插（parent_code → parent_id）→ 闭包表重建 → 清缓存 → 记版本。
     * 区别仅在数据来源（上传文件而非版本服务器下载）与版本号（取包内 meta.json，
     * 缺失时回退 snapshot-{Y-m-d H:i:s 去符号}），因此不做 SHA-256 校验（无可信校验和来源）。
     *
     * 安全网与在线同步一致：契约校验在进事务前执行、落库单事务包裹，任一步失败本地数据零影响。
     *
     * @return array{synced:bool,message:string,version:string,count:int,stats:array<string,int>,duration:float}
     *
     * @throws RuntimeException 数据契约非法或落库失败时
     */
    public function importFromSnapshot(string $zipPath): array
    {
        try {
            $this->reportProgress('running', 25, '正在解包并校验快照包...');

            $package = $this->readPackageFromZip($zipPath);
            $jsonlFile = null;

            try {
                // 流式校验：逐条扫描写 JSONL 临时文件，不整体载入内存
                // （村级数据 58 万条 / 67MB 全量解码会耗尽 512M memory_limit）
                $validated = $this->validateRegionsFromFile($package['regions_file']);
                $jsonlFile = $validated['jsonl_file'];

                // 版本号：优先包内 meta.json 声明值；缺失（手工打包）时按导入时刻回退
                $version = $package['meta_version'] !== ''
                    ? $package['meta_version']
                    : 'snapshot-' . date('YmdHis');

                $this->reportProgress('running', 45, '快照包校验通过，共 ' . $validated['count'] . ' 条，正在全量替换落库...');

                $startTime = microtime(true);
                $this->importRegions($jsonlFile, (int) ceil($validated['stats']['village'] / self::CHUNK_SIZE));
                $duration = round(microtime(true) - $startTime, 2);
            } finally {
                // regions.json 转存文件与校验产出的 JSONL 均为大体积临时文件（67MB 级），处理后立即清理
                @unlink($package['regions_file']);
                if ($jsonlFile !== null) {
                    @unlink($jsonlFile);
                }
            }

            $this->reportProgress('running', 90, '落库完成，正在清除缓存并记录版本...');

            $this->regionService->clearCache();
            $this->saveVersion($version);

            $this->reportProgress('done', 100, '导入完成', '版本 ' . $version . '，共 ' . $validated['count'] . ' 条，耗时 ' . $duration . ' 秒');

            Log::info('[cmspro.region] 快照包导入完成', [
                'version' => $version,
                'count' => $validated['count'],
                'duration' => $duration,
            ]);

            return [
                'synced' => true,
                'message' => '导入完成',
                'version' => $version,
                'count' => $validated['count'],
                'stats' => $validated['stats'],
                'duration' => $duration,
            ];
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : '快照包导入失败：' . $e->getMessage();
            $this->reportProgress('error', 100, '导入失败', $message);

            throw $e instanceof RuntimeException ? $e : new RuntimeException($message);
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

        $codes = [];
        $stats = ['province' => 0, 'city' => 0, 'district' => 0, 'street' => 0, 'village' => 0];
        $levelKeys = [
            Region::LEVEL_PROVINCE => 'province',
            Region::LEVEL_CITY => 'city',
            Region::LEVEL_DISTRICT => 'district',
            Region::LEVEL_STREET => 'street',
            Region::LEVEL_VILLAGE => 'village',
        ];

        $normalized = [];
        foreach (array_values($regions) as $index => $row) {
            $item = $this->normalizeRegionRow($row, $index, $codes);
            $normalized[] = $item;
            $stats[$levelKeys[$item['level']]]++;
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
     * 流式校验并规范化 regions.json 文件（大文件不整体载入内存）。
     *
     * 单遍扫描：逐条校验规范化后写 JSONL 临时文件（供分层导入流式读取），
     * 同时收集编码集合、上级引用集合与五级统计。常驻内存仅为两个编码集合
     * （58 万条约 45MB），远低于全量解码的 430MB。
     *
     * @return array{count:int,stats:array<string,int>,jsonl_file:string}
     *
     * @throws RuntimeException 任一校验不通过或临时文件读写失败时
     */
    protected function validateRegionsFromFile(string $regionsFile): array
    {
        $packDir = storage_path(self::PACK_DIR);
        $this->ensureDir($packDir);

        $jsonlFile = $packDir . '/regions-rows-' . uniqid() . '.jsonl';
        $handle = fopen($jsonlFile, 'wb');
        if ($handle === false) {
            throw new RuntimeException('无法创建区划数据临时文件');
        }

        $codes = [];
        $parentCodes = [];
        $stats = ['province' => 0, 'city' => 0, 'district' => 0, 'street' => 0, 'village' => 0];
        $levelKeys = [
            Region::LEVEL_PROVINCE => 'province',
            Region::LEVEL_CITY => 'city',
            Region::LEVEL_DISTRICT => 'district',
            Region::LEVEL_STREET => 'street',
            Region::LEVEL_VILLAGE => 'village',
        ];
        $count = 0;

        try {
            foreach ($this->streamRegionRows($regionsFile) as $index => $row) {
                $normalized = $this->normalizeRegionRow($row, $index, $codes);

                $line = json_encode($normalized, JSON_UNESCAPED_UNICODE);
                if ($line === false || fwrite($handle, $line . "\n") === false) {
                    throw new RuntimeException('区划数据临时文件写入失败');
                }

                // 记录上级引用者编码，闭环校验时反查不存在的上级
                if ($normalized['parent_code'] !== '0') {
                    $parentCodes[$normalized['parent_code']] = $normalized['code'];
                }

                $stats[$levelKeys[$normalized['level']]]++;
                $count++;

                if ($count > self::MAX_REGIONS) {
                    throw new RuntimeException('区划记录数超过上限 ' . self::MAX_REGIONS);
                }
            }

            fclose($handle);

            if ($count === 0) {
                throw new RuntimeException('未解析到任何区划记录');
            }

            // 引用闭环：parent_code 必须为 '0' 或引用数据集中存在的编码
            foreach ($parentCodes as $parentCode => $childCode) {
                if (! isset($codes[$parentCode])) {
                    throw new RuntimeException('区划 ' . $childCode . ' 引用了不存在的上级编码 ' . $parentCode);
                }
            }

            // 数据集必须至少包含一条省级记录
            if ($stats['province'] === 0) {
                throw new RuntimeException('数据集必须至少包含一条省级（level=1）记录');
            }
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($jsonlFile);
            throw $e;
        }

        return [
            'count' => $count,
            'stats' => $stats,
            'jsonl_file' => $jsonlFile,
        ];
    }

    /**
     * 校验并规范化单条区划记录（validateRegions 与流式校验共用）。
     *
     * 校验规则：必填字段非空、code 全局唯一且不超长、level 限定 1-5；
     * 规范化：name 截 50 字符、status 归一 0/1、lon/lat 非数值置 null。
     *
     * @param mixed $row 原始记录
     * @param int $index 记录序号（0 起，错误提示用）
     * @param array<string,bool> $codes 已收集编码集合（引用传入，自动登记实现去重）
     * @return array<string,mixed> 规范化记录
     *
     * @throws RuntimeException 任一校验不通过时
     */
    protected function normalizeRegionRow(mixed $row, int $index, array &$codes): array
    {
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
        if ($level < 1 || $level > 5) {
            throw new RuntimeException('区划 ' . $code . ' 的 level 必须为 1（省）/2（市）/3（区县）/4（街道）/5（村/社区）');
        }

        return [
            'code' => $code,
            'name' => mb_substr(trim((string) $row['name']), 0, 50),
            'level' => $level,
            'parent_code' => trim((string) $row['parent_code']),
            'status' => (int) ($row['status'] ?? 1) === 0 ? 0 : 1,
            'lon' => is_numeric($row['lon'] ?? null) ? (float) $row['lon'] : null,
            'lat' => is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : null,
        ];
    }

    /**
     * 流式逐条提取 regions.json（JSON 数组）中的区划记录。
     *
     * 村级数据 58 万条 / 67MB 整体 json_decode 会产生 400MB+ 常驻数组，因此用
     * 指针扫描 + 字符串感知的大括号平衡逐对象提取：单条解码即抛即用，
     * 全程常驻内存恒定（缓冲区 1MB 级）。
     *
     * @return \Generator<int,array<string,mixed>> 依次 yield 每条记录（key 为 0 起序号）
     *
     * @throws RuntimeException 文件不可读、顶层不是 JSON 数组或记录无法解析时
     */
    protected function streamRegionRows(string $regionsFile): \Generator
    {
        $handle = fopen($regionsFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('regions.json 区划数据文件无法读取');
        }

        try {
            $buffer = '';
            $pos = 0;
            $depth = 0;
            $objStart = 0;
            $inString = false;
            $escape = false;
            $seenArrayStart = false;
            $index = 0;

            while (true) {
                $length = strlen($buffer);

                while ($pos < $length) {
                    $char = $buffer[$pos];

                    if ($depth === 0) {
                        // 对象外：'[' 与分隔符直接跳过，'{' 开启对象扫描
                        if ($char === '{') {
                            $depth = 1;
                            $objStart = $pos;
                            $inString = false;
                            $escape = false;
                        } elseif ($char === '[') {
                            $seenArrayStart = true;
                        }
                        $pos++;
                        continue;
                    }

                    if ($inString) {
                        if ($escape) {
                            $escape = false;
                        } elseif ($char === '\\') {
                            $escape = true;
                        } elseif ($char === '"') {
                            $inString = false;
                        }
                    } elseif ($char === '"') {
                        $inString = true;
                    } elseif ($char === '{') {
                        $depth++;
                    } elseif ($char === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $json = substr($buffer, $objStart, $pos - $objStart + 1);
                            $row = json_decode($json, true);
                            if (! is_array($row)) {
                                throw new RuntimeException('regions.json 第 ' . ($index + 1) . ' 条区划记录解析失败');
                            }
                            $pos++;
                            yield $index => $row;
                            $index++;
                            continue;
                        }
                    }
                    $pos++;
                }

                if (feof($handle)) {
                    break;
                }

                // 缓冲区耗尽：保留未完成对象前缀，读下一块
                if ($depth > 0) {
                    $buffer = substr($buffer, $objStart);
                    $pos -= $objStart;
                    $objStart = 0;
                } else {
                    $buffer = substr($buffer, $pos);
                    $pos = 0;
                }

                $chunk = fread($handle, self::READ_CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buffer .= $chunk;
            }

            if (! $seenArrayStart) {
                throw new RuntimeException(self::REGIONS_FILE . ' 不是合法的 JSON 数组');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 逐行迭代 JSONL 临时文件（每行一条规范化记录，供分层导入流式读取）。
     *
     * @return \Generator<int,array<string,mixed>>
     */
    protected function iterateJsonlRows(string $jsonlFile): \Generator
    {
        $handle = fopen($jsonlFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('区划数据临时文件读取失败');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 全量替换落库：单事务全删 → 按 level 分层多遍扫描 JSONL 批量插入
     * （parent_code → parent_id）→ 闭包表重建。
     *
     * 分层导入对校验产出的 JSONL 按 level 1→5 各扫一遍：层序天然保证父级先于
     * 子级落库，层内顺序无关，故不依赖 regions.json 的排序。任一步失败整体
     * 回滚，本地数据零影响。
     *
     * @param string $jsonlFile 校验产出的 JSONL 中间文件（每行一条规范化记录）
     * @param int $villageBatches 村级层批次数（由校验统计推算，0 表示不上报村级细分进度）
     */
    protected function importRegions(string $jsonlFile, int $villageBatches = 0): void
    {
        DB::transaction(function () use ($jsonlFile, $villageBatches): void {
            // 全删（MySQL 下 TRUNCATE 会隐式提交事务，必须用 DELETE）
            DB::table('app_cmspro_region_closure')->delete();
            DB::table('app_cmspro_region_region')->delete();

            $now = date('Y-m-d H:i:s');
            $codeMap = []; // code => id，逐层收集供下层 parent 解析

            // 各层落库进度基准（45% 起，闭包重建前推进到 82%），村级层为数据大头占最大区间
            $levelBasePercent = [1 => 48, 2 => 52, 3 => 56, 4 => 58, 5 => 60];
            $levelNames = [1 => '省级', 2 => '市级', 3 => '区县级', 4 => '街道级', 5 => '村级'];

            foreach ([1, 2, 3, 4, 5] as $level) {
                $batch = [];
                $batchIndex = 0;

                foreach ($this->iterateJsonlRows($jsonlFile) as $row) {
                    if ((int) $row['level'] !== $level) {
                        continue;
                    }

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

                    if (count($batch) >= self::CHUNK_SIZE) {
                        $this->insertRegionBatch($batch, $level, $batchIndex, $villageBatches);
                        $batch = [];
                        $batchIndex++;
                    }
                }

                if ($batch !== []) {
                    $this->insertRegionBatch($batch, $level, $batchIndex, $villageBatches);
                }

                // 收集本层 code => id（分层导入已保证父级先于子级，此映射必命中）
                // 村级层达 54 万+，chunkById 流式收集避免一次性 pluck 全量驻留内存
                DB::table('app_cmspro_region_region')
                    ->where('level', $level)
                    ->chunkById(5000, function ($rows) use (&$codeMap): void {
                        foreach ($rows as $row) {
                            $codeMap[(string) $row->code] = (int) $row->id;
                        }
                    });

                if ($level < 5) {
                    $this->reportProgress('running', $levelBasePercent[$level], '已写入' . ($levelNames[$level] ?? '') . '数据，继续处理下级...');
                }
            }

            // 闭包表全量重建（事务内，失败随事务回滚）
            $this->reportProgress('running', 84, '正在重建层级关系（闭包表）...');
            $this->regionService->rebuildClosureTable();
        });
    }

    /**
     * 单批插入区划记录；村级层按批进度细分上报。
     *
     * @param array<int,array<string,mixed>> $batch 本批落库记录
     */
    protected function insertRegionBatch(array $batch, int $level, int $batchIndex, int $villageBatchTotal): void
    {
        DB::table('app_cmspro_region_region')->insert($batch);

        // 村级层数据量最大（数十万条），按 chunk 进度细分上报（60% → 82%）
        if ($level === 5 && $villageBatchTotal > 0) {
            $percent = 60 + (int) round((($batchIndex + 1) / $villageBatchTotal) * 22);
            $this->reportProgress('running', $percent, '正在写入村级数据（' . ($batchIndex + 1) . '/' . $villageBatchTotal . ' 批）...');
        }
    }

    /**
     * 解包数据包并转存 regions.json（防 Zip Slip，兼容包内多一层包裹目录）。
     *
     * regions.json 体积大（村级数据 67MB），不整体载入内存：转存为持久文件后
     * 返回路径，由调用方流式校验/导入并在 finally 中清理。
     *
     * @return array{regions_file:string,meta_version:string} 转存后的 regions.json 路径与包内声明的版本号（缺失为空串）
     */
    protected function readPackageFromZip(string $zipPath): array
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

            // 定位文件（兼容包内多一层包裹目录）
            $locate = function (string $fileName) use ($workDir): ?string {
                foreach ([$workDir . '/' . $fileName, $workDir . '/*/' . $fileName] as $pattern) {
                    $candidates = glob($pattern) ?: [];
                    if ($candidates !== []) {
                        return $candidates[0];
                    }
                }

                return null;
            };

            $regionsFile = $locate(self::REGIONS_FILE);
            if ($regionsFile === null) {
                throw new RuntimeException('数据包缺少 ' . self::REGIONS_FILE);
            }

            // meta.json 可选：存在时取包内声明的版本号（供导入后记录本地版本）
            $metaVersion = '';
            $metaFile = $locate(self::META_FILE);
            if ($metaFile !== null) {
                $meta = json_decode((string) File::get($metaFile), true);
                if (is_array($meta)) {
                    $metaVersion = trim((string) ($meta['version'] ?? ''));
                }
            }

            // 转存 regions.json 到数据包目录（storage 同卷 rename，避免大文件复制开销；
            // 放在最后一步，成功即返回，转存文件由调用方 finally 清理）
            $packDir = storage_path(self::PACK_DIR);
            $this->ensureDir($packDir);
            $persistFile = $packDir . '/regions-' . uniqid() . '.json';
            if (! @rename($regionsFile, $persistFile)) {
                throw new RuntimeException(self::REGIONS_FILE . ' 转存失败');
            }

            return ['regions_file' => $persistFile, 'meta_version' => $metaVersion];
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
                // 配置组由框架在应用安装/升级时按 manifest 落库；未升级的站点组可能不存在，
                // 此处自愈创建，确保版本号一定写入（否则前端「本地版本」恒显示「未同步」）
                $group = ConfigGroup::firstOrCreate(
                    ['code' => 'app_cmspro_region_sync_settings'],
                    [
                        'name' => '数据同步设置',
                        'app_id' => 'cmspro.region',
                        'sort' => 0,
                        'status' => Status::ENABLED,
                    ]
                );

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
     * HTTP 客户端（下载 58 万条约 5MB 数据包，放宽超时）。
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
