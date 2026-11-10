<?php

use App\Apps\CmsproRegion\Models\Region;
use App\Apps\CmsproRegion\Services\RegionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 数据库变更脚本
 *
 * 版本号: V2026110901
 * 变更描述: 回填 14,291 个「统计区划代码有、本地库找不到上级乡镇」的村/社区，按人工逐项查证的真实
 *           归属写入 parent_id。已锁决策：一甲保留原 12 位统计代码（不重编码）、二甲 L3 落点村直接挂
 *           区县并跳过 L4、三甲兵团团场按行政隶属挂师市 659001-659013 下既有 L4（无 L4 则跳级挂师市 L3）。
 * 影响表: app_cmspro_region_region（INSERT level=5 村/社区）、app_cmspro_region_closure（全量重建）
 * 变更类型: DML（纯 INSERT；uk_code 实测零冲突，无 UPDATE）
 * 创建日期: 2026-11-09
 * 关联需求: 14,425 未匹配村归属回填（对账闭合 14425 = 14291 写入 + 9 同名跳过 + 125 缺口）
 * 数据源: Data/backfill_unmatched_villages_20261109.csv（22 列，按表头取「村code / 村名 / 目标节点id」，
 *         对列序鲁棒）。该 CSV 为本地一次性数据源，不随仓库分发：文件缺失时 up()/down() 均静默跳过。
 *         本批已在生产库执行完毕，其他站点通过「在线同步官方数据」直接获取含本批的全量数据。
 * 风险等级: 中（14,291 行 INSERT + 闭包表全量重建约 290 万行；RegionDataSyncService 在线同步/导入快照
 *           为全删重插，会抹掉此批，恢复方式为重新执行在线同步/导入快照）
 *
 * 回滚方案: 见 down()。本批为纯 INSERT 且村 code 唯一保留，回滚按 CSV 内 code 集合定位 level=5 行，
 *           先删闭包表 descendant 命中行（村为叶子，仅作为 descendant 出现），再删主表对应 id；
 *           删除叶子节点后闭包表天然保持一致，无需全量重建，最后清缓存。
 */
return new class extends Migration
{
    /**
     * 分批写入大小，沿用 RegionDataSyncService::CHUNK_SIZE
     */
    private int $chunkSize = 2000;

    /**
     * code 集合 IN 查询分片大小
     */
    private int $codeChunk = 1000;

    public function up(): void
    {
        // 测试库以「干净库」为前提，本批 14,291 行生产回填数据不得灌入，否则污染各用例的记录数断言
        if (app()->environment('testing')) {
            return;
        }

        if (! Schema::hasTable('app_cmspro_region_region')) {
            return;
        }

        $rows = $this->planRows();
        if ($rows === []) {
            Log::info('区划回填迁移：数据文件缺失或无有效行，跳过');
            return;
        }

        $existing = $this->existingCodes(array_column($rows, 'code'));
        $now = date('Y-m-d H:i:s');
        $payload = [];
        $skipped = 0;

        foreach ($rows as $row) {
            if (isset($existing[$row['code']])) {
                $skipped++;
                continue;
            }
            $payload[] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'level' => Region::LEVEL_VILLAGE,
                'parent_id' => $row['parent_id'],
                'status' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ];
        }

        DB::transaction(function () use ($payload) {
            foreach (array_chunk($payload, $this->chunkSize) as $chunk) {
                DB::table('app_cmspro_region_region')->insert($chunk);
            }
        });

        $service = app(RegionService::class);
        if ($payload !== []) {
            // 新增节点需重建闭包表；SQL 迭代下推全量重建，复用应用既有方法
            $service->rebuildClosureTable();
        }
        $service->clearCache();

        Log::info('区划回填迁移完成', ['写入' => count($payload), '跳过已存在' => $skipped]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_region_region')) {
            return;
        }

        $codes = array_column($this->planRows(), 'code');
        foreach (array_chunk($codes, $this->codeChunk) as $chunk) {
            $ids = DB::table('app_cmspro_region_region')
                ->where('level', Region::LEVEL_VILLAGE)
                ->whereIn('code', $chunk)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                continue;
            }

            DB::table('app_cmspro_region_closure')->whereIn('descendant', $ids)->delete();
            DB::table('app_cmspro_region_region')->whereIn('id', $ids)->delete();
        }

        app(RegionService::class)->clearCache();
    }

    /**
     * 读取回填计划 CSV，返回 [code, name, parent_id] 行集合
     *
     * 按表头名定位列，对列序鲁棒；首列去 UTF-8 BOM；过滤 code/名为空或父 id 非法的脏行。
     *
     * @return array<int, array{code: string, name: string, parent_id: int}>
     */
    protected function planRows(): array
    {
        $path = __DIR__ . '/../Data/backfill_unmatched_villages_20261109.csv';
        // 必须先判存在再 fopen：文件缺失时 fopen 触发的 E_WARNING 会被框架错误处理器
        // 转成 ErrorException 抛出，使「静默跳过」失效并导致站点升级中断
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return [];
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $index = array_flip(array_map('trim', $header));
        $codeCol = $index['村code'] ?? null;
        $nameCol = $index['村名'] ?? null;
        $parentCol = $index['目标节点id'] ?? null;

        if ($codeCol === null || $nameCol === null || $parentCol === null) {
            fclose($handle);
            return [];
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $code = trim((string) ($line[$codeCol] ?? ''));
            $name = trim((string) ($line[$nameCol] ?? ''));
            $parentId = (int) ($line[$parentCol] ?? 0);
            if ($code === '' || $name === '' || $parentId <= 0) {
                continue;
            }
            $rows[] = ['code' => $code, 'name' => $name, 'parent_id' => $parentId];
        }

        fclose($handle);
        return $rows;
    }

    /**
     * 预取库内已存在的 code 集合（分片 IN 查询），用于幂等跳过
     *
     * @param array<int, string> $codes
     * @return array<string, bool>
     */
    protected function existingCodes(array $codes): array
    {
        $existing = [];
        foreach (array_chunk($codes, $this->codeChunk) as $chunk) {
            $found = DB::table('app_cmspro_region_region')
                ->whereIn('code', $chunk)
                ->pluck('code')
                ->all();
            foreach ($found as $code) {
                $existing[$code] = true;
            }
        }
        return $existing;
    }
};
