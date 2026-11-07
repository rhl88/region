<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_region_region')) {
            return;
        }

        Schema::create('app_cmspro_region_region', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('code', 12)->comment('行政区划代码，如110000、110101、110101001、110101001234');
            $table->string('name', 50)->comment('区划名称，如北京市、东城区');
            $table->unsignedTinyInteger('level')->default(1)->comment('层级：1-省 2-市 3-区县 4-街道/乡镇 5-村/社区');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('父级ID，顶级为0');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：0-禁用 1-启用');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->unique('code', 'uk_code');
            $table->index('parent_id', 'idx_parent_id');
            $table->index('level', 'idx_level');
            $table->index(['parent_id', 'level'], 'idx_parent_level');
        });

        // 表注释为 MySQL 专属语法，SQLite（测试库）不支持
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `app_cmspro_region_region` COMMENT '中国行政区划主表'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_region_region');
    }
};
