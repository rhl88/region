<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 数据库变更脚本
 *
 * 版本号: V2026110801
 * 变更描述: 区划主表新增 (level, code) 复合索引，消除全量导出按 level+code 排序的全表扫描与 filesort
 * 影响表: app_cmspro_region_region
 * 变更类型: DDL（新增索引，MySQL 8 在线 DDL 不阻塞读写，64 万行约数十秒）
 * 创建日期: 2026-11-08
 * 关联需求: 63.8 万条数据量下导出/导入/同步超时优化（P0）
 * 风险等级: 低（仅新增索引，不改字段与数据；额外占用磁盘约 20MB）
 *
 * 回滚方案: 见 down()，删除 idx_level_code 索引，数据零影响
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_cmspro_region_region')) {
            return;
        }

        // 幂等：索引已存在（重复执行或手工建过）则跳过
        if (Schema::hasIndex('app_cmspro_region_region', 'idx_level_code')) {
            return;
        }

        Schema::table('app_cmspro_region_region', function (Blueprint $table) {
            $table->index(['level', 'code'], 'idx_level_code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_region_region')) {
            return;
        }

        if (! Schema::hasIndex('app_cmspro_region_region', 'idx_level_code')) {
            return;
        }

        Schema::table('app_cmspro_region_region', function (Blueprint $table) {
            $table->dropIndex('idx_level_code');
        });
    }
};