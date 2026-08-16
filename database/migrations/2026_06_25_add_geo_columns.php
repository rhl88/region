<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_cmspro_region_region', function (Blueprint $table) {
            $table->decimal('lon', 10, 7)->nullable()->comment('经度')->after('status');
            $table->decimal('lat', 10, 7)->nullable()->comment('纬度')->after('lon');
        });
    }

    public function down(): void
    {
        Schema::table('app_cmspro_region_region', function (Blueprint $table) {
            $table->dropColumn(['lon', 'lat']);
        });
    }
};
