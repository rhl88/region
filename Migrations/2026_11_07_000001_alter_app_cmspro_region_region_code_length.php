<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 数据库变更脚本
 *
 * 版本号: V2026110701
 * 变更描述: 区划主表 code 字段扩容 varchar(9) → varchar(12)，支撑「村/社区」五级区划（12 位统计用区划代码）
 * 影响表: app_cmspro_region_region
 * 变更类型: DDL（结构变更，同类型扩容，MySQL 不锁写，约 59 万行数十秒内完成）
 * 创建日期: 2026-11-07
 * 关联需求: 村级区划纳入在线同步数据包
 * 风险等级: 低（扩容兼容存量 6/9 位编码，不涉及数据转换）
 *
 * 回滚方案: 见 down()（varchar(12) → varchar(9)，执行前须确认库内无 12 位村级编码，否则截断丢失）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_cmspro_region_region')) {
            return;
        }

        // 幂等：已是 12 位长度（新建表迁移直接建 12 位）则跳过
        if ($this->codeLength() >= 12) {
            return;
        }

        // SQLite/PostgreSQL 的 varchar 仅类型亲和不强制长度，无需 DDL；MySQL 走原生 MODIFY 保留字段注释
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `app_cmspro_region_region` '
                . 'MODIFY COLUMN `code` VARCHAR(12) NOT NULL '
                . "COMMENT '行政区划代码，如110000、110101、110101001、110101001234'"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_region_region')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql' && $this->codeLength() > 9) {
            // 回滚前必须确认无 12 位村级编码，否则唯一索引重建时可能因截断冲突失败
            DB::statement(
                'ALTER TABLE `app_cmspro_region_region` '
                . 'MODIFY COLUMN `code` VARCHAR(9) NOT NULL '
                . "COMMENT '行政区划代码，如110000、110101、110101001'"
            );
        }
    }

    /**
     * 读取 code 字段当前字符长度（非 MySQL 或表结构缺失时返回 0）
     */
    protected function codeLength(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return 0;
        }

        return (int) DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', 'app_cmspro_region_region')
            ->where('column_name', 'code')
            ->value('character_maximum_length');
    }
};
