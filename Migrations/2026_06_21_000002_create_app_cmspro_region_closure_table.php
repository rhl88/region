<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_region_closure')) {
            return;
        }

        Schema::create('app_cmspro_region_closure', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->unsignedBigInteger('ancestor')->comment('祖先节点ID');
            $table->unsignedBigInteger('descendant')->comment('后代节点ID');
            $table->unsignedInteger('depth')->default(0)->comment('距离：0表示自身，1表示直接子级，2表示孙级');

            $table->unique(['ancestor', 'descendant'], 'uk_ancestor_descendant');
            $table->index('descendant', 'idx_descendant');
            $table->index(['ancestor', 'depth'], 'idx_ancestor_depth');
        });

        // 表注释为 MySQL 专属语法，SQLite（测试库）不支持
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `app_cmspro_region_closure` COMMENT '中国行政区划闭包表'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_region_closure');
    }
};
