<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>行政区划数据管理</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <style>
        .stat-card{display:inline-block;width:23%;margin-right:2%;padding:20px;background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,0.08);text-align:center;box-sizing:border-box;vertical-align:top;}
        .stat-card:last-child{margin-right:0;}
        .stat-card .stat-num{font-size:32px;font-weight:bold;color:#2563eb;}
        .stat-card .stat-label{font-size:14px;color:#666;margin-top:8px;}
        .import-progress{margin-top:10px;padding:15px;background:#fffbe6;border:1px solid #ffe58f;border-radius:4px;display:none;}
        .import-progress .layui-progress{margin-top:8px;}
        .import-log{max-height:200px;overflow-y:auto;margin-top:8px;padding:8px;background:#fafafa;border-radius:4px;font-size:12px;line-height:1.8;color:#666;}
        .one-key-step{display:inline-block;margin-right:14px;padding:2px 10px;border-radius:10px;background:#f2f2f2;color:#999;}
        .one-key-step.active{background:#16b777;color:#fff;}
        .one-key-step.done{background:#e1f3d8;color:#67c23a;}
        /* 一键数据更新悬停下拉菜单 */
        .one-key-wrap{display:inline-block;position:relative;}
        .one-key-menu{display:none;position:absolute;top:100%;left:0;z-index:9999;min-width:190px;background:#fff;border:1px solid #e6e6e6;border-radius:4px;box-shadow:0 2px 12px rgba(0,0,0,.15);padding:5px 0;}
        .one-key-wrap:hover .one-key-menu{display:block;}
        .one-key-menu-title{padding:6px 15px;font-size:12px;color:#999;border-bottom:1px solid #f2f2f2;margin-bottom:4px;}
        .one-key-menu-item{padding:8px 15px;font-size:13px;color:#333;cursor:pointer;white-space:nowrap;}
        .one-key-menu-item:hover{background:#f2f2f2;color:#16b777;}
        .one-key-menu-item.layui-btn-disabled{color:#ccc;cursor:not-allowed;background:#fff;}
        /* 一键导入菜单项悬停用蓝色（匹配主按钮色），覆盖默认绿色 */
        .one-key-import-menu .one-key-menu-item:hover{color:#1e9fff;}
    </style>
</head>
<body>
<div class="pear-container">
    {{-- 统计卡片 --}}
    <div class="layui-card">
        <div class="layui-card-body">
            <div class="stat-card">
                <div class="stat-num" id="statProvince">{{ $stats['province'] ?? 0 }}</div>
                <div class="stat-label">省/直辖市</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" id="statCity">{{ $stats['city'] ?? 0 }}</div>
                <div class="stat-label">市</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" id="statDistrict">{{ $stats['district'] ?? 0 }}</div>
                <div class="stat-label">区/县</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" id="statStreet">{{ $stats['street'] ?? 0 }}</div>
                <div class="stat-label">街道/乡镇</div>
            </div>
        </div>
    </div>

    {{-- 操作栏 + 数据表格 --}}
    <div class="layui-card" style="margin-top:15px;">
        <div class="layui-card-body">
            <div style="margin-bottom:15px;">
                {{-- 主按钮：单击执行一键导入（本地链路）/一键数据更新（在线链路）；悬停弹出下拉菜单显示各阶段独立导入入口 --}}
                <div class="one-key-wrap">
                    <button class="layui-btn" id="oneKeyImportBtn" style="background:#1e9fff;"><i class="fa fa-database"></i> 一键导入</button>
                    <div class="one-key-menu one-key-import-menu">
                        <div class="one-key-menu-title">本地数据导入（手动执行单项）</div>
                        <div class="one-key-menu-item" data-btn="importBtn"><i class="fa fa-download"></i> 导入三级数据</div>
                        <div class="one-key-menu-item" data-btn="importStreetBtn"><i class="fa fa-building"></i> 导入街道数据</div>
                        <div class="one-key-menu-item" data-btn="importGeoBtn"><i class="fa fa-map-marker"></i> 导入地理位置</div>
                    </div>
                </div>
                <div class="one-key-wrap">
                    <button class="layui-btn" id="oneKeyUpdateBtn" style="background:#16b777;"><i class="fa fa-bolt"></i> 一键数据更新</button>
                    <div class="one-key-menu">
                        <div class="one-key-menu-title">数据更新（手动执行单项）</div>
                        <div class="one-key-menu-item" data-btn="importBtn"><i class="fa fa-download"></i> 导入三级数据</div>
                        <div class="one-key-menu-item" data-btn="importStreetBtn"><i class="fa fa-building"></i> 导入街道数据</div>
                        <div class="one-key-menu-item" data-btn="importGeoBtn"><i class="fa fa-map-marker"></i> 导入地理位置</div>
                        <div class="one-key-menu-item" data-btn="importGeojsonBtn"><i class="fa fa-globe"></i> 更新坐标</div>
                        <div class="one-key-menu-item" data-btn="importHmtBtn"><i class="fa fa-flag"></i> 补全港澳台</div>
                    </div>
                </div>
                <button class="layui-btn layui-btn-warm" id="clearCacheBtn"><i class="fa fa-refresh"></i> 清除缓存</button>
                <div class="layui-inline" style="margin-left:20px;">
                    <label class="layui-form-label" style="width:auto;">层级</label>
                    <div class="layui-input-inline layui-form" style="width:120px;margin-top: unset;">
                        <select id="levelFilter" lay-filter="levelFilter">
                            <option value="">全部</option>
                            <option value="1">省/直辖市</option>
                            <option value="2">市</option>
                            <option value="3">区/县</option>
                            <option value="4">街道/乡镇</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <input type="text" id="keywordInput" placeholder="搜索名称或代码" class="layui-input" style="width:200px;">
                </div>
                <button class="layui-btn" id="searchBtn"><i class="fa fa-search"></i> 搜索</button>
                <button class="layui-btn layui-btn-primary" id="updateDataBtn" style="float:right;"><i class="fa fa-cloud-download"></i> 更新数据</button>
            </div>

            {{-- 街道导入进度 --}}
            <div class="import-progress" id="level3Progress">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span>正在导入三级数据...</span>
                    <div>
                        <span id="level3Count">0/34</span>
                        <button type="button" class="close-progress-btn" data-target="level3Progress" style="margin-left:10px;background:none;border:none;cursor:pointer;font-size:16px;color:#999;" title="关闭">&times;</button>
                    </div>
                </div>
                <div class="layui-progress" lay-showpercent="true" lay-filter="level3ImportProgress">
                    <div class="layui-progress-bar" lay-percent="0%" id="level3ProgressBar"></div>
                </div>
                <div class="import-log" id="level3Log"></div>
            </div>

            <div class="import-progress" id="streetProgress">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span id="progressText">正在导入街道数据...</span>
                    <div>
                        <span id="progressCount">0/0</span>
                        <button type="button" id="closeProgressBtn" style="margin-left:10px;background:none;border:none;cursor:pointer;font-size:16px;color:#999;" title="关闭">&times;</button>
                    </div>
                </div>
                <div class="layui-progress" lay-showpercent="true" lay-filter="streetImportProgress">
                    <div class="layui-progress-bar" lay-percent="0%" id="progressBar"></div>
                </div>
                <div class="import-log" id="importLog"></div>
            </div>

            <div class="import-progress" id="geoProgress" style="display:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span id="geoProgressText">正在导入地理位置数据...</span>
                    <div>
                        <span id="geoProgressCount">0/0</span>
                        <button type="button" id="closeGeoProgressBtn" style="margin-left:10px;background:none;border:none;cursor:pointer;font-size:16px;color:#999;" title="关闭">&times;</button>
                    </div>
                </div>
                <div class="layui-progress" lay-showpercent="true" lay-filter="geoImportProgress">
                    <div class="layui-progress-bar" lay-percent="0%" id="geoProgressBar"></div>
                </div>
                <div class="import-log" id="geoImportLog"></div>
            </div>

            {{-- GeoJSON 在线坐标导入进度 --}}
            <div class="import-progress" id="geojsonProgress" style="display:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span id="geojsonProgressText">正在在线更新坐标数据...</span>
                    <div>
                        <span id="geojsonProgressCount">0/0</span>
                        <button type="button" id="closeGeojsonProgressBtn" style="margin-left:10px;background:none;border:none;cursor:pointer;font-size:16px;color:#999;" title="关闭">&times;</button>
                    </div>
                </div>
                <div class="layui-progress" lay-showpercent="true" lay-filter="geojsonImportProgress">
                    <div class="layui-progress-bar" lay-percent="0%" id="geojsonProgressBar"></div>
                </div>
                <div class="import-log" id="geojsonImportLog"></div>
            </div>

            {{-- 港澳台区划补全进度 --}}
            <div class="import-progress" id="hmtProgress" style="display:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span id="hmtProgressText">正在补全港澳台区划数据...</span>
                    <div>
                        <span id="hmtProgressCount">0/3</span>
                        <button type="button" id="closeHmtProgressBtn" style="margin-left:10px;background:none;border:none;cursor:pointer;font-size:16px;color:#999;" title="关闭">&times;</button>
                    </div>
                </div>
                <div class="layui-progress" lay-showpercent="true" lay-filter="hmtImportProgress">
                    <div class="layui-progress-bar" lay-percent="0%" id="hmtProgressBar"></div>
                </div>
                <div class="import-log" id="hmtImportLog"></div>
            </div>

            {{-- 一键导入/一键数据更新进度（共用四阶段串联链路） --}}
            <div class="import-progress" id="oneKeyProgress" style="display:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span id="oneKeyText">正在准备一键导入...</span>
                    <div>
                        <span id="oneKeyCount"></span>
                        <button type="button" id="closeOneKeyProgressBtn" style="margin-left:10px;background:none;border:none;cursor:pointer;font-size:16px;color:#999;" title="关闭">&times;</button>
                    </div>
                </div>
                <div style="margin-top:10px;font-size:12px;">
                    <span id="oneKeyStep1" class="one-key-step">① 三级区划</span>
                    <span id="oneKeyStep2" class="one-key-step">② 港澳台补全</span>
                    <span id="oneKeyStep3" class="one-key-step">③ 街道数据</span>
                    <span id="oneKeyStep4" class="one-key-step">④ 最新坐标</span>
                </div>
                <div class="layui-progress" lay-showpercent="true" lay-filter="oneKeyImportProgress">
                    <div class="layui-progress-bar" lay-percent="0%" id="oneKeyProgressBar"></div>
                </div>
                <div class="import-log" id="oneKeyImportLog"></div>
            </div>

            <div class="import-progress" id="downloadProgress">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span id="updateText">正在更新数据...</span>
                    <div>
                        <span id="updateCount"></span>
                        <button type="button" class="close-progress-btn" data-target="downloadProgress" style="margin-left:10px;background:none;border:none;cursor:pointer;font-size:16px;color:#999;" title="关闭">&times;</button>
                    </div>
                </div>
                <div class="layui-progress" lay-showpercent="true" lay-filter="updateProgress">
                    <div class="layui-progress-bar" lay-percent="0%"></div>
                </div>
                <div class="import-log" id="updateLog"></div>
            </div>

            <table id="regionTable" lay-filter="regionTable"></table>
        </div>
    </div>
</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['table', 'form', 'layer', 'jquery', 'element'], function(){
    var table = layui.table;
    var form = layui.form;
    var layer = layui.layer;
    var element = layui.element;
    var $ = layui.jquery;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    });

    // 渲染层级筛选下拉框（layui 美化）
    form.render('select');

    var levelNames = {1:'省/直辖市', 2:'市', 3:'区/县', 4:'街道/乡镇'};

    // 渲染数据表格
    table.render({
        elem: '#regionTable',
        url: '/api/admin/cmspro/region/list',
        page: true,
        limit: 20,
        cols: [[
            {field: 'id', title: 'ID'},
            {field: 'code', title: '区划代码'},
            {field: 'name', title: '区划名称'},
            {field: 'level', title: '层级', templet: function(d){
                return levelNames[d.level] || '未知';
            }},
            {field: 'lon', title: '经度', width: 120, templet: function(d){
                return d.lon ? d.lon.toFixed(6) : '-';
            }},
            {field: 'lat', title: '纬度', width: 120, templet: function(d){
                return d.lat ? d.lat.toFixed(6) : '-';
            }},
            {field: 'status', title: '状态', templet: function(d){
                return d.status === 1 ? '<span class="layui-badge layui-bg-green">启用</span>' : '<span class="layui-badge">禁用</span>';
            }},
            {field: 'create_time', title: '创建时间'}
        ]]
    });

    // 搜索
    $('#searchBtn').on('click', function(){
        table.reload('regionTable', {
            where: {
                level: $('#levelFilter').val(),
                keyword: $('#keywordInput').val()
            },
            page: {curr: 1}
        });
    });

    // 导入三级数据（AJAX分步）
    var level3ImportRunning = false;
    function triggerLevel3Import(){
        if(level3ImportRunning){
            layer.msg('数据正在导入中，请等待完成', {icon: 0});
            return;
        }

        layer.confirm('确定要导入/更新省市区三级数据吗？将按省份分步处理。', {icon: 3}, function(index){
            layer.close(index);
            startLevel3Import();
        });
    }
    $('#importBtn').on('click', triggerLevel3Import);

    function startLevel3Import(){
        level3ImportRunning = true;
        $('#importBtn').addClass('layui-btn-disabled').prop('disabled', true);
        syncOneKeyMenuState();
        $('#level3Progress').show();
        $('#level3Log').html('');
        element.progress('level3ImportProgress', '0%');

        // 先从数据库获取省份列表
        $.get('/api/admin/cmspro/region/provinces', function(res){
            if(res.code === 0 && res.data && res.data.length > 0){
                // 数据库有数据，使用数据库中的省份列表
                startLevel3ImportWithProvinces(res.data);
                return;
            }

            // 数据库为空，从 list.json 文件读取省份列表
            $.get('/api/admin/cmspro/region/list-json-provinces', function(res){
                if(res.code !== 0 || !res.data || res.data.length === 0){
                    layer.msg('获取省份列表失败', {icon: 2});
                    resetLevel3Import();
                    return;
                }
                startLevel3ImportWithProvinces(res.data);
            }).fail(function(){
                layer.msg('获取省份列表失败', {icon: 2});
                resetLevel3Import();
            });
        }).fail(function(){
            // 请求失败，尝试从 list.json 文件读取
            $.get('/api/admin/cmspro/region/list-json-provinces', function(res){
                if(res.code !== 0 || !res.data || res.data.length === 0){
                    layer.msg('获取省份列表失败', {icon: 2});
                    resetLevel3Import();
                    return;
                }
                startLevel3ImportWithProvinces(res.data);
            }).fail(function(){
                layer.msg('获取省份列表失败', {icon: 2});
                resetLevel3Import();
            });
        });
    }

    function startLevel3ImportWithProvinces(provinces){
        var total = provinces.length;
        var current = 0;
        var totalInserted = 0;
        var totalUpdated = 0;
        var totalDisabled = 0;

        function importNext(){
            if(current >= total){
                // 完成
                var msg = '三级数据导入完成，共处理 ' + total + ' 个省份';
                if(totalInserted > 0) msg += '，新增 ' + totalInserted + ' 条';
                if(totalUpdated > 0) msg += '，更新 ' + totalUpdated + ' 条';
                if(totalDisabled > 0) msg += '，禁用 ' + totalDisabled + ' 条';
                layer.msg(msg, {icon: 1});
                resetLevel3Import();
                refreshStats();
                table.reload('regionTable');
                return;
            }

            var province = provinces[current];
            current++;
            var percent = Math.round((current / total) * 100);
            element.progress('level3ImportProgress', percent + '%');
            $('#level3Count').text(current + '/' + total);
            $('#level3Log').append('<div>正在处理 ' + province.name + ' (' + current + '/' + total + ')...</div>');

            $.post('/api/admin/cmspro/region/import-by-province', {province_code: province.code}, function(res){
                if(res.code === 0){
                    if(res.data){
                        totalInserted += res.data.inserted || 0;
                        totalUpdated += res.data.updated || 0;
                        totalDisabled += res.data.disabled || 0;
                    }
                    $('#level3Log').append('<div style="color:green;">✓ ' + (res.message || province.name + ' 完成') + '</div>');
                } else {
                    $('#level3Log').append('<div style="color:red;">✗ ' + (res.message || '失败') + '</div>');
                }
                var logEl = document.getElementById('level3Log');
                if(logEl) logEl.scrollTop = logEl.scrollHeight;
                importNext();
            }).fail(function(){
                $('#level3Log').append('<div style="color:red;">✗ ' + province.name + ' 请求失败</div>');
                importNext();
            });
        }

        importNext();
    }

    function resetLevel3Import(){
        level3ImportRunning = false;
        $('#importBtn').removeClass('layui-btn-disabled').prop('disabled', false);
        syncOneKeyMenuState();
        $('#level3Progress').hide();
    }

    // 导入街道数据（AJAX分步）
    var streetImportRunning = false;
    function triggerStreetImport(){
        if(streetImportRunning){
            layer.msg('街道数据正在导入中，请等待完成', {icon: 0});
            return;
        }

        layer.confirm('确定要导入街道/乡镇四级数据吗？数据量较大（约4万+条），将按省份分步导入，请耐心等待。', {icon: 3}, function(index){
            layer.close(index);
            startStreetImport();
        });
    }
    $('#importStreetBtn').on('click', triggerStreetImport);

    function startStreetImport(){
        if(level3ImportRunning){
            layer.msg('三级数据正在导入中，请等待完成后再导入街道数据', {icon: 0});
            return;
        }
        streetImportRunning = true;
        $('#importStreetBtn').addClass('layui-btn-disabled').prop('disabled', true);
        syncOneKeyMenuState();
        $('#streetProgress').show();
        $('#importLog').html('');
        element.progress('streetImportProgress', '0%');

        // 先获取省份列表
        $.get('/api/admin/cmspro/region/street-provinces', function(res){
            if(res.code !== 0 || !res.data || res.data.length === 0){
                layer.msg('没有可导入的街道数据文件', {icon: 2});
                resetStreetImport();
                return;
            }

            var provinces = res.data;
            var provinceIndex = 0;
            var allDistricts = [];   // 扁平化的所有区县列表 [{code, name, provinceName}]
            var totalImported = 0;

            // 按省份逐个获取区县列表，收集全部区县后开始导入
            function fetchNextProvinceDistricts(){
                if(provinceIndex >= provinces.length){
                    // 所有省份的区县已收集完毕，开始按区县导入
                    if(allDistricts.length === 0){
                        layer.msg('没有可导入的街道数据', {icon: 1});
                        resetStreetImport();
                        return;
                    }
                    startDistrictImport();
                    return;
                }

                var province = provinces[provinceIndex];
                provinceIndex++;

                $.get('/api/admin/cmspro/region/street-districts', {province_code: province.code}, function(res){
                    if(res.code === 0 && res.data && res.data.length > 0){
                        for(var i = 0; i < res.data.length; i++){
                            allDistricts.push({
                                code: res.data[i].code,
                                name: res.data[i].name,
                                provinceName: province.name,
                                hasStreet: res.data[i].has_street
                            });
                        }
                    }
                    fetchNextProvinceDistricts();
                }).fail(function(){
                    appendLog(province.name + ': 获取区县列表失败，跳过');
                    fetchNextProvinceDistricts();
                });
            }

            // 开始按区县逐个导入
            function startDistrictImport(){
                var total = allDistricts.length;
                var current = 0;
                var failedDistricts = [];

                $('#progressText').text('正在准备导入街道数据...');
                $('#progressCount').text('0/' + total);

                importNextDistrict();

                function importNextDistrict(){
                    if(current >= total){
                        // 全部完成，检查是否有失败的区县
                        if(failedDistricts.length > 0){
                            appendLog('<span style="color:orange;">⚠ 发现 ' + failedDistricts.length + ' 个区县导入失败，正在重试...</span>');
                            setTimeout(function(){ retryFailedDistricts(); }, 500);
                            return;
                        }

                        $('#progressText').text('街道数据导入完成！');
                        element.progress('streetImportProgress', '100%');
                        appendLog('全部完成，共导入 ' + totalImported + ' 条街道数据');
                        refreshStats();
                        table.reload('regionTable');
                        resetStreetImport();
                        return;
                    }

                    var district = allDistricts[current];
                    current++;
                    var percent = Math.round((current / total) * 100);
                    element.progress('streetImportProgress', percent + '%');
                    $('#progressCount').text(current + '/' + total);
                    $('#progressText').text('正在导入: ' + district.provinceName + ' - ' + district.name + '...');

                    importDistrictWithRetry(district, 3, function(success, message){
                        if(success){
                            totalImported += (message.imported || 0);
                            var updated = message.updated || 0;
                            var logMsg = district.provinceName + ' - ' + district.name + ': ';
                            if(message.imported > 0) logMsg += '新增' + message.imported + '条';
                            if(updated > 0) logMsg += (message.imported > 0 ? '，' : '') + '更新' + updated + '条';
                            if(!message.imported && !updated) logMsg += '无变化';
                            appendLog(logMsg);
                        } else {
                            appendLog('<span style="color:red;">✗ ' + district.provinceName + ' - ' + district.name + ': ' + (message || '导入失败') + '</span>');
                            failedDistricts.push(district);
                        }
                        setTimeout(importNextDistrict, 50);
                    });
                }

                function importDistrictWithRetry(district, maxRetries, callback){
                    var retryCount = 0;
                    function attempt(){
                        $.post('/api/admin/cmspro/region/import-street-by-district', {district_code: district.code}, function(res){
                            if(res.code === 0){
                                callback(true, res.data);
                            } else {
                                retryCount++;
                                if(retryCount < maxRetries){
                                    appendLog('  重试第' + retryCount + '次...');
                                    setTimeout(attempt, retryCount * 2000);
                                } else {
                                    callback(false, res.message || '失败');
                                }
                            }
                        }).fail(function(){
                            retryCount++;
                            if(retryCount < maxRetries){
                                appendLog('  请求失败，重试第' + retryCount + '次...');
                                setTimeout(attempt, retryCount * 2000);
                            } else {
                                callback(false, '请求失败');
                            }
                        });
                    }
                    attempt();
                }

                function retryFailedDistricts(){
                    var failedTotal = failedDistricts.length;
                    var failedCurrent = 0;

                    function retryNext(){
                        if(failedCurrent >= failedTotal){
                            $('#progressText').text('街道数据导入完成！');
                            element.progress('streetImportProgress', '100%');
                            var remaining = failedDistricts.length;
                            if(remaining > 0){
                                appendLog('<span style="color:red;">✗ 仍有 ' + remaining + ' 个区县导入失败，请稍后重试</span>');
                            }
                            refreshStats();
                            table.reload('regionTable');
                            resetStreetImport();
                            return;
                        }

                        var district = failedDistricts[failedCurrent];
                        failedCurrent++;
                        $('#progressText').text('重试导入: ' + district.provinceName + ' - ' + district.name + '...');

                        importDistrictWithRetry(district, 3, function(success, message){
                            if(success){
                                totalImported += (message.imported || 0);
                                var updated = message.updated || 0;
                                var logMsg = '<span style="color:green;">✓ ' + district.provinceName + ' - ' + district.name + ': ';
                                if(message.imported > 0) logMsg += '新增' + message.imported + '条';
                                if(updated > 0) logMsg += (message.imported > 0 ? '，' : '') + '更新' + updated + '条';
                                appendLog(logMsg + '</span>');
                                failedDistricts.splice(failedDistricts.indexOf(district), 1);
                            } else {
                                appendLog('<span style="color:red;">✗ ' + district.provinceName + ' - ' + district.name + ': 重试失败</span>');
                            }
                            setTimeout(retryNext, 50);
                        });
                    }

                    retryNext();
                }
            }

            fetchNextProvinceDistricts();
        });
    }

    function resetStreetImport(){
        streetImportRunning = false;
        $('#importStreetBtn').removeClass('layui-btn-disabled').prop('disabled', false);
        syncOneKeyMenuState();
    }

    // 带重试的 AJAX 请求（指数退避：1s, 2s, 4s...）
    function ajaxWithRetry(url, data, maxRetries, onSuccess, onFail){
        var retryCount = 0;
        function attempt(){
            $.post(url, data, function(res){
                onSuccess(res);
            }).fail(function(){
                retryCount++;
                if(retryCount < maxRetries){
                    var delay = Math.pow(2, retryCount - 1) * 1000;
                    setTimeout(attempt, delay);
                } else {
                    onFail();
                }
            });
        }
        attempt();
    }

    // 更新数据（民政部API对比：三级数据 + 街道数据）
    var updateRunning = false;
    var report = { level3Added: 0, level3Removed: 0, level3Renamed: 0, streetAdded: 0, streetRemoved: 0, streetRenamed: 0 };

    $('#updateDataBtn').on('click', function(){
        if(updateRunning){
            layer.msg('数据正在更新中，请等待完成', {icon: 0});
            return;
        }
        if(level3ImportRunning || streetImportRunning){
            layer.msg('导入任务正在运行中，请等待完成后再更新', {icon: 0});
            return;
        }

        // 先检测民政部API连通性
        var btn = $(this);
        btn.addClass('layui-btn-disabled').prop('disabled', true);
        layer.msg('正在检测民政部API连通性...', {icon: 16, shade: 0.3, time: 0});

        $.get('/api/admin/cmspro/region/check-mca', function(res){
            layer.closeAll('msg');
            btn.removeClass('layui-btn-disabled').prop('disabled', false);

            if(res.code !== 0){
                layer.alert('<span style="color:red;font-size:14px;">✗ ' + res.message + '</span>', {
                    title: '数据源检测失败',
                    icon: 2,
                    btn: ['知道了']
                });
                return;
            }

            // 连通正常，提示确认
            layer.confirm(
                '<span style="color:green;">✓ 民政部API连接正常</span><br><br>' +
                '<span style="color:#e6a23c;font-weight:bold;">⚠ 更新前请通过应用管理备份数据！</span><br><br>' +
                '更新将执行以下操作：<br>' +
                '1. 对比三级数据（API vs 本地 list.json）<br>' +
                '2. 如有差异，可选择更新本地 list.json<br>' +
                '3. 对比街道数据（API vs 本地缓存）<br>' +
                '4. 如有差异，可选择更新本地缓存<br>' +
                '5. 本次对比会持续较长时间，执行期间请不要关闭浏览器或刷新页面<br><br>' +
                '确定要继续吗？',
                {icon: 3, title: '更新区划数据', btn: ['确认更新', '取消']},
                function(index){
                    layer.close(index);
                    startUpdate();
                }
            );
        }).fail(function(){
            layer.closeAll('msg');
            btn.removeClass('layui-btn-disabled').prop('disabled', false);
            layer.alert('<span style="color:red;">✗ 无法连接民政部API，请检查网络</span>', {
                title: '检测失败', icon: 2, btn: ['知道了']
            });
        });
    });

    function startUpdate(){
        updateRunning = true;
        report = { level3Added: 0, level3Removed: 0, level3Renamed: 0, streetAdded: 0, streetRemoved: 0, streetRenamed: 0 };
        $('#updateDataBtn').addClass('layui-btn-disabled').prop('disabled', true);
        $('#importBtn').addClass('layui-btn-disabled').prop('disabled', true);
        $('#importStreetBtn').addClass('layui-btn-disabled').prop('disabled', true);
        syncOneKeyMenuState();
        $('#downloadProgress').show();
        $('#updateLog').html('');
        element.progress('updateProgress', '0%');
        $('#updateCount').text('');

        appendUpdateLog('【准备】开始数据对比...');
        setTimeout(function(){ diffThreeLevel(); }, 100);
    }

    // 对比三级数据
    function diffThreeLevel(){
        $('#updateText').text('正在对比三级数据...');
        appendUpdateLog('【对比】三级数据（API vs 本地 list.json）...');

        $.post('/api/admin/cmspro/region/diff-three-level-from-mca', function(res){
            if(res.code !== 0){
                appendUpdateLog('<span style="color:red;">✗ 三级数据对比失败</span>');
                finishUpdate('三级数据对比失败');
                return;
            }

            var diff = res.data;
            report.level3ApiCount = diff.api_count || 0;
            report.level3LocalCount = diff.local_count || 0;

            if(diff.has_diff){
                appendUpdateLog('<span style="color:orange;">⚠ 三级数据发现差异：</span>');
                appendUpdateLog('  新增：' + diff.added.length + ' 条');
                appendUpdateLog('  删除：' + diff.removed.length + ' 条');
                appendUpdateLog('  改名：' + diff.renamed.length + ' 条');

                if(diff.added.length > 0){
                    appendUpdateLog('<span style="color:blue;">新增示例：</span>');
                    diff.added.slice(0, 5).forEach(function(item){
                        appendUpdateLog('  + ' + item.code + ' ' + item.name);
                    });
                    if(diff.added.length > 5) appendUpdateLog('  ... 等共 ' + diff.added.length + ' 条');
                }
                if(diff.removed.length > 0){
                    appendUpdateLog('<span style="color:blue;">删除示例：</span>');
                    diff.removed.slice(0, 5).forEach(function(item){
                        appendUpdateLog('  - ' + item.code + ' ' + item.name);
                    });
                    if(diff.removed.length > 5) appendUpdateLog('  ... 等共 ' + diff.removed.length + ' 条');
                }
                if(diff.renamed.length > 0){
                    appendUpdateLog('<span style="color:blue;">改名示例：</span>');
                    diff.renamed.slice(0, 5).forEach(function(item){
                        appendUpdateLog('  ~ ' + item.code + ' ' + item.old_name + ' → ' + item.new_name);
                    });
                    if(diff.renamed.length > 5) appendUpdateLog('  ... 等共 ' + diff.renamed.length + ' 条');
                }

                layer.confirm('三级数据发现差异（新增' + diff.added.length + '，删除' + diff.removed.length + '，改名' + diff.renamed.length + '），是否更新本地 list.json？', {
                    btn: ['更新三级数据', '跳过']
                }, function(index){
                    layer.close(index);
                    updateThreeLevel();
                });
            } else {
                appendUpdateLog('<span style="color:green;">✓ 三级数据一致，无差异</span>');
                element.progress('updateProgress', '30%');
                setTimeout(function(){ diffStreet(); }, 100);
            }
        }).fail(function(){
            appendUpdateLog('<span style="color:red;">✗ 三级数据对比请求失败</span>');
            finishUpdate('三级数据对比请求失败');
        });
    }

    // 更新三级数据（重新下载 list.json）
    function updateThreeLevel(){
        $('#updateText').text('正在更新三级数据...');
        appendUpdateLog('【更新】三级数据...');

        $.post('/api/admin/cmspro/region/download-list-json', function(res){
            if(res.code === 0){
                appendUpdateLog('<span style="color:green;">✓ list.json 已更新（' + res.data.count + ' 条记录）</span>');
                report.level3Updated = res.data.count;
            } else {
                appendUpdateLog('<span style="color:red;">✗ list.json 更新失败</span>');
            }
            element.progress('updateProgress', '30%');
            setTimeout(function(){ diffStreet(); }, 100);
        }).fail(function(){
            appendUpdateLog('<span style="color:red;">✗ list.json 更新请求失败</span>');
            element.progress('updateProgress', '30%');
            setTimeout(function(){ diffStreet(); }, 100);
        });
    }

    // 对比街道数据
    function diffStreet(){
        $('#updateText').text('正在对比街道数据...');
        appendUpdateLog('【对比】街道数据（API vs 本地缓存）...');

        $.get('/api/admin/cmspro/region/provinces', function(res){
            if(res.code !== 0 || !res.data || res.data.length === 0){
                appendUpdateLog('<span style="color:red;">✗ 获取省份列表失败</span>');
                finishUpdate('获取省份列表失败');
                return;
            }

            var provinces = res.data;
            var provinceIndex = 0;
            var allAdded = 0;
            var allRemoved = 0;
            var allRenamed = 0;

            function diffNextProvince(){
                if(provinceIndex >= provinces.length){
                    report.streetAdded = allAdded;
                    report.streetRemoved = allRemoved;
                    report.streetRenamed = allRenamed;

                    if(allAdded > 0 || allRemoved > 0 || allRenamed > 0){
                        appendUpdateLog('<span style="color:orange;">⚠ 街道数据发现差异：</span>');
                        appendUpdateLog('  新增：' + allAdded + ' 条');
                        appendUpdateLog('  删除：' + allRemoved + ' 条');
                        appendUpdateLog('  改名：' + allRenamed + ' 条');

                        layer.confirm('街道数据发现差异（新增' + allAdded + '，删除' + allRemoved + '，改名' + allRenamed + '），是否更新本地缓存？', {
                            btn: ['更新街道数据', '跳过']
                        }, function(index){
                            layer.close(index);
                            updateStreet();
                        });
                    } else {
                        appendUpdateLog('<span style="color:green;">✓ 街道数据一致，无差异</span>');
                        element.progress('updateProgress', '100%');
                        finishUpdate('');
                    }
                    return;
                }

                var province = provinces[provinceIndex];
                provinceIndex++;

                $.post('/api/admin/cmspro/region/diff-street-from-mca', {province_code: province.code}, function(res){
                    if(res.code === 0 && res.data){
                        allAdded += res.data.total_added || 0;
                        allRemoved += res.data.total_removed || 0;
                        allRenamed += res.data.total_renamed || 0;

                        if(res.data.districts){
                            res.data.districts.forEach(function(d){
                                if(d.has_diff){
                                    appendUpdateLog('  ' + (res.data.province_name || province.name) + ' - ' + d.name + '：+' + d.added + '，-' + d.removed + '，~' + d.renamed);
                                }
                            });
                        }
                    }
                    setTimeout(diffNextProvince, 100);
                }).fail(function(){
                    setTimeout(diffNextProvince, 100);
                });
            }

            diffNextProvince();
        });
    }

    // 更新街道数据
    function updateStreet(){
        $('#updateText').text('正在更新街道数据...');
        appendUpdateLog('【更新】街道数据...');

        $.get('/api/admin/cmspro/region/provinces', function(res){
            if(res.code !== 0 || !res.data || res.data.length === 0){
                appendUpdateLog('<span style="color:red;">✗ 获取省份列表失败</span>');
                finishUpdate('获取省份列表失败');
                return;
            }

            var provinces = res.data;
            var provinceIndex = 0;
            var totalUpdated = 0;

            function updateNextProvince(){
                if(provinceIndex >= provinces.length){
                    report.streetUpdated = totalUpdated;
                    appendUpdateLog('<span style="color:green;">✓ 街道数据更新完成（更新' + totalUpdated + '条）</span>');
                    element.progress('updateProgress', '100%');
                    finishUpdate('');
                    return;
                }

                var province = provinces[provinceIndex];
                provinceIndex++;

                $.post('/api/admin/cmspro/region/import-street-from-mca', {province_code: province.code}, function(res){
                    if(res.code === 0 && res.data){
                        totalUpdated += res.data.inserted || 0;
                    }
                    setTimeout(updateNextProvince, 100);
                }).fail(function(){
                    setTimeout(updateNextProvince, 100);
                });
            }

            updateNextProvince();
        });
    }



    // 完成更新，显示报告
    function finishUpdate(errorMsg){
        element.progress('updateProgress', '100%');
        updateRunning = false;
        $('#updateDataBtn').removeClass('layui-btn-disabled').prop('disabled', false);
        $('#importBtn').removeClass('layui-btn-disabled').prop('disabled', false);
        $('#importStreetBtn').removeClass('layui-btn-disabled').prop('disabled', false);
        syncOneKeyMenuState();

        if(errorMsg){
            appendUpdateLog('<span style="color:red;">✗ ' + errorMsg + '</span>');
            return;
        }

        // 重建闭包表
        appendUpdateLog('正在重建闭包表...');
        $.post('/api/admin/cmspro/region/rebuild-closure', function(res){
            if(res.code === 0){
                appendUpdateLog('<span style="color:green;">✓ 闭包表重建完成</span>');
            } else {
                appendUpdateLog('<span style="color:orange;">⚠ 闭包表重建失败</span>');
            }
            // 清理缓存
            appendUpdateLog('正在清理缓存...');
            $.post('/api/admin/cmspro/region/clear-cache', function(res){
                appendUpdateLog('<span style="color:green;">✓ 缓存清理完成</span>');
                showReport();
            }).fail(function(){
                appendUpdateLog('<span style="color:orange;">⚠ 缓存清理失败，请手动清除</span>');
                showReport();
            });
        }).fail(function(){
            appendUpdateLog('<span style="color:orange;">⚠ 闭包表重建请求失败</span>');
            showReport();
        });
    }

    function showReport(){
        refreshStats();
        table.reload('regionTable');

        var r = report;
        var reportHtml = '<div style="margin-top:8px;padding:10px;background:#f0f9eb;border:1px solid #e1f3d8;border-radius:4px;font-size:13px;line-height:2;">';
        reportHtml += '<strong>更新报告</strong><br>';
        reportHtml += '三级数据：API ' + (r.level3ApiCount || 0) + ' 条，本地 ' + (r.level3LocalCount || 0) + ' 条，新增 ' + r.level3Added + '，删除 ' + r.level3Removed + '，改名 ' + r.level3Renamed + '<br>';
        if(r.level3Updated > 0){
            reportHtml += '  list.json 已更新（' + r.level3Updated + ' 条）<br>';
        }
        reportHtml += '街道数据：新增 ' + r.streetAdded + '，删除 ' + r.streetRemoved + '，改名 ' + r.streetRenamed;
        if(r.streetUpdated > 0){
            reportHtml += '，本地缓存已更新（' + r.streetUpdated + ' 条）';
        }
        reportHtml += '</div>';
        $('#updateLog').append(reportHtml);
        $('#updateText').text('更新完成！');
        $('#updateCount').text('');
    }

    function appendUpdateLog(text){
        var log = $('#updateLog');
        log.html(log.html() + text + '<br>');
        log.scrollTop(log[0].scrollHeight);
    }

    // 关闭进度面板
    // 关闭进度面板
    $('.close-progress-btn').on('click', function(){
        var target = $(this).data('target');
        $('#' + target).slideUp(200);
    });

    function appendLog(text){
        var log = $('#importLog');
        log.html(log.html() + text + '<br>');
        log.scrollTop(log[0].scrollHeight);
    }

    // 清除缓存
    $('#clearCacheBtn').on('click', function(){
        $.post('/api/admin/cmspro/region/clear-cache', function(res){
            if(res.code === 0){
                layer.msg('缓存已清除', {icon: 1});
            } else {
                layer.msg(res.message || '操作失败', {icon: 2});
            }
        });
    });

    // 导入地理位置数据（AJAX分步导入）
    var geoImportRunning = false;
    function triggerGeoImport(){
        if(geoImportRunning){
            layer.msg('地理位置数据正在导入中，请等待完成', {icon: 0});
            return;
        }

        layer.confirm(
            '<span style="color:#e6a23c;font-weight:bold;">⚠ 导入地理位置数据</span><br><br>' +
            '将从本地Excel文件导入城市和区县的经纬度信息<br>' +
            '文件路径: Data/地理位置编码.xlsx<br>' +
            '预计处理约 3400 条记录，将按省份分步导入<br><br>' +
            '确定要继续吗？',
            {icon: 3, title: '导入地理位置数据', btn: ['确认导入', '取消']},
            function(index){
                layer.close(index);
                startGeoImport();
            }
        );
    }
    $('#importGeoBtn').on('click', triggerGeoImport);

    function startGeoImport(){
        if(level3ImportRunning || streetImportRunning || updateRunning){
            layer.msg('其他任务正在运行中，请等待完成', {icon: 0});
            return;
        }
        geoImportRunning = true;
        $('#importGeoBtn').addClass('layui-btn-disabled').prop('disabled', true);
        syncOneKeyMenuState();
        $('#geoProgress').show();
        $('#geoImportLog').html('');
        element.progress('geoImportProgress', '0%');

        $.get('/api/admin/cmspro/region/geo-provinces', function(res){
            if(res.code !== 0 || !res.data || res.data.length === 0){
                layer.msg('没有可导入的地理位置数据', {icon: 2});
                resetGeoImport();
                return;
            }

            var provinces = res.data;
            var total = provinces.length;
            var current = 0;
            var totalUpdated = 0;
            var failedProvinces = [];

            $('#geoProgressText').text('正在准备导入地理位置数据...');
            $('#geoProgressCount').text('0/' + total);

            importNextProvince();

            function importNextProvince(){
                if(current >= total){
                    if(failedProvinces.length > 0){
                        appendGeoLog('<span style="color:orange;">⚠ 发现 ' + failedProvinces.length + ' 个省份导入失败，正在重试...</span>');
                        setTimeout(function(){ retryFailedProvinces(); }, 500);
                        return;
                    }

                    $('#geoProgressText').text('地理位置数据导入完成！');
                    element.progress('geoImportProgress', '100%');
                    appendGeoLog('全部完成，共更新 ' + totalUpdated + ' 条地理位置数据');
                    refreshStats();
                    table.reload('regionTable');
                    resetGeoImport();
                    return;
                }

                var province = provinces[current];
                current++;
                var percent = Math.round((current / total) * 100);
                element.progress('geoImportProgress', percent + '%');
                $('#geoProgressCount').text(current + '/' + total);
                $('#geoProgressText').text('正在导入: ' + province.name + '...');

                $.post('/api/admin/cmspro/region/import-geo-data-by-province', {province_code: province.code}, function(res){
                    if(res.code === 0){
                        var data = res.data;
                        totalUpdated += (data.updated || 0);
                        var logMsg = province.name + ': ';
                        if(data.updated > 0) logMsg += '更新' + data.updated + '条';
                        if(data.skipped > 0) logMsg += (data.updated > 0 ? '，' : '') + '跳过' + data.skipped + '条';
                        if(!data.updated && !data.skipped) logMsg += '无数据';
                        appendGeoLog(logMsg);
                    } else {
                        appendGeoLog('<span style="color:red;">✗ ' + province.name + ': ' + (res.message || '导入失败') + '</span>');
                        failedProvinces.push(province);
                    }
                    setTimeout(importNextProvince, 50);
                }).fail(function(){
                    appendGeoLog('<span style="color:red;">✗ ' + province.name + ': 请求失败</span>');
                    failedProvinces.push(province);
                    setTimeout(importNextProvince, 50);
                });
            }

            function retryFailedProvinces(){
                var failedTotal = failedProvinces.length;
                var failedCurrent = 0;

                function retryNext(){
                    if(failedCurrent >= failedTotal){
                        $('#geoProgressText').text('地理位置数据导入完成！');
                        element.progress('geoImportProgress', '100%');
                        var remaining = failedProvinces.length;
                        if(remaining > 0){
                            appendGeoLog('<span style="color:red;">✗ 仍有 ' + remaining + ' 个省份导入失败，请稍后重试</span>');
                        }
                        refreshStats();
                        table.reload('regionTable');
                        resetGeoImport();
                        return;
                    }

                    var province = failedProvinces[failedCurrent];
                    failedCurrent++;
                    $('#geoProgressText').text('重试导入: ' + province.name + '...');

                    $.post('/api/admin/cmspro/region/import-geo-data-by-province', {province_code: province.code}, function(res){
                        if(res.code === 0){
                            var data = res.data;
                            totalUpdated += (data.updated || 0);
                            appendGeoLog('<span style="color:green;">✓ ' + province.name + ': 更新' + (data.updated || 0) + '条</span>');
                            failedProvinces.splice(failedProvinces.indexOf(province), 1);
                        } else {
                            appendGeoLog('<span style="color:red;">✗ ' + province.name + ': 重试失败</span>');
                        }
                        setTimeout(retryNext, 50);
                    }).fail(function(){
                        appendGeoLog('<span style="color:red;">✗ ' + province.name + ': 重试请求失败</span>');
                        setTimeout(retryNext, 50);
                    });
                }

                retryNext();
            }
        });
    }

    function appendGeoLog(msg){
        $('#geoImportLog').append(msg + '<br>');
        $('#geoImportLog').scrollTop($('#geoImportLog')[0].scrollHeight);
    }

    function resetGeoImport(){
        geoImportRunning = false;
        $('#importGeoBtn').removeClass('layui-btn-disabled').prop('disabled', false);
        syncOneKeyMenuState();
        $('#geoProgress').hide();
    }

    // GeoJSON 在线更新坐标（geojson.cn，分省AJAX导入）
    var geojsonImportRunning = false;
    function triggerGeojsonImport(){
        if(geojsonImportRunning){
            layer.msg('在线坐标更新正在执行中，请等待完成', {icon: 0});
            return;
        }

        layer.confirm(
            '<span style="color:#e6a23c;font-weight:bold;">⚠ 在线更新坐标（GeoJSON）</span><br><br>' +
            '将从 geojson.cn 在线拉取省/市/县三级行政中心坐标（GCJ-02）<br>' +
            '数据编码为最新行政区划，可修复旧坐标源中已撤销区划的缺失<br>' +
            '需联网，按省份分步更新，含港澳台数据<br><br>' +
            '确定要继续吗？',
            {icon: 3, title: '在线更新坐标（GeoJSON）', btn: ['确认执行', '取消']},
            function(index){
                layer.close(index);
                startGeojsonImport();
            }
        );
    }
    $('#importGeojsonBtn').on('click', triggerGeojsonImport);

    function startGeojsonImport(){
        if(geoImportRunning || level3ImportRunning || streetImportRunning || updateRunning){
            layer.msg('其他任务正在运行中，请等待完成', {icon: 0});
            return;
        }
        geojsonImportRunning = true;
        $('#importGeojsonBtn').addClass('layui-btn-disabled').prop('disabled', true);
        syncOneKeyMenuState();
        $('#geojsonProgress').show();
        $('#geojsonImportLog').html('');
        element.progress('geojsonImportProgress', '0%');

        $.get('/api/admin/cmspro/region/geojson-provinces', function(res){
            if(res.code !== 0 || !res.data || res.data.length === 0){
                layer.msg('没有可更新的省份数据', {icon: 2});
                resetGeojsonImport();
                return;
            }

            var provinces = res.data;
            var total = provinces.length;
            var current = 0;
            var totalUpdated = 0;
            var totalMissing = 0;
            var failedProvinces = [];

            $('#geojsonProgressText').text('正在准备在线更新坐标...');
            $('#geojsonProgressCount').text('0/' + total);

            importNextGeojsonProvince();

            function importNextGeojsonProvince(){
                if(current >= total){
                    if(failedProvinces.length > 0){
                        appendGeojsonLog('<span style="color:orange;">⚠ ' + failedProvinces.length + ' 个省份失败，正在重试...</span>');
                        setTimeout(retryFailedGeojsonProvinces, 500);
                        return;
                    }

                    finishGeojsonImport();
                    return;
                }

                var province = provinces[current];
                current++;
                var percent = Math.round((current / total) * 100);
                element.progress('geojsonImportProgress', percent + '%');
                $('#geojsonProgressCount').text(current + '/' + total);
                $('#geojsonProgressText').text('正在更新: ' + province.name + '（含市县拉取，稍慢属正常）...');

                $.post('/api/admin/cmspro/region/import-geo-data-from-geojson', {province_code: province.code}, function(res){
                    if(res.code === 0){
                        var data = res.data;
                        totalUpdated += (data.updated || 0);
                        totalMissing += (data.missing || 0);
                        appendGeojsonLog(formatGeojsonLog(province.name, data));
                    } else {
                        appendGeojsonLog('<span style="color:red;">✗ ' + province.name + ': ' + (res.message || '更新失败') + '</span>');
                        failedProvinces.push(province);
                    }
                    setTimeout(importNextGeojsonProvince, 50);
                }).fail(function(){
                    appendGeojsonLog('<span style="color:red;">✗ ' + province.name + ': 请求失败</span>');
                    failedProvinces.push(province);
                    setTimeout(importNextGeojsonProvince, 50);
                });
            }

            function retryFailedGeojsonProvinces(){
                var failedTotal = failedProvinces.length;
                var failedCurrent = 0;

                function retryNext(){
                    if(failedCurrent >= failedTotal){
                        var remaining = failedProvinces.length;
                        if(remaining > 0){
                            appendGeojsonLog('<span style="color:red;">✗ 仍有 ' + remaining + ' 个省份失败，请稍后重试</span>');
                        }
                        finishGeojsonImport();
                        return;
                    }

                    var province = failedProvinces[failedCurrent];
                    failedCurrent++;
                    $('#geojsonProgressText').text('重试: ' + province.name + '...');

                    $.post('/api/admin/cmspro/region/import-geo-data-from-geojson', {province_code: province.code}, function(res){
                        if(res.code === 0){
                            totalUpdated += (res.data.updated || 0);
                            totalMissing += (res.data.missing || 0);
                            appendGeojsonLog('<span style="color:green;">✓ ' + formatGeojsonLog(province.name, res.data) + '</span>');
                            failedProvinces.splice(failedProvinces.indexOf(province), 1);
                        } else {
                            appendGeojsonLog('<span style="color:red;">✗ ' + province.name + ': 重试失败</span>');
                        }
                        setTimeout(retryNext, 50);
                    }).fail(function(){
                        appendGeojsonLog('<span style="color:red;">✗ ' + province.name + ': 重试请求失败</span>');
                        setTimeout(retryNext, 50);
                    });
                }

                retryNext();
            }

            function finishGeojsonImport(){
                $('#geojsonProgressText').text('在线坐标更新完成！');
                element.progress('geojsonImportProgress', '100%');
                appendGeojsonLog('全部完成，共更新 ' + totalUpdated + ' 条坐标'
                    + (totalMissing > 0 ? '，' + totalMissing + ' 条库中无对应编码（多为数据源比区划库新，属正常）' : ''));
                refreshStats();
                table.reload('regionTable');
                resetGeojsonImport();
            }

            // 单省结果日志：更新/相同/未匹配/异常 分开展示，便于识别需关注的部分
            function formatGeojsonLog(name, data){
                var msg = name + ': ';
                var parts = [];
                if(data.updated > 0) parts.push('更新' + data.updated);
                if(data.skipped > 0) parts.push('坐标相同' + data.skipped);
                if(data.missing > 0) parts.push('<span style="color:orange;">未匹配' + data.missing + '</span>');
                if(data.failed > 0) parts.push('<span style="color:red;">异常' + data.failed + '</span>');
                msg += parts.length > 0 ? parts.join('，') + '条' : '无数据';
                return msg;
            }
        });
    }

    function appendGeojsonLog(msg){
        $('#geojsonImportLog').append(msg + '<br>');
        $('#geojsonImportLog').scrollTop($('#geojsonImportLog')[0].scrollHeight);
    }

    $('#closeGeojsonProgressBtn').on('click', function(){
        $('#geojsonProgress').slideUp(200);
    });

    function resetGeojsonImport(){
        geojsonImportRunning = false;
        $('#importGeojsonBtn').removeClass('layui-btn-disabled').prop('disabled', false);
        syncOneKeyMenuState();
        $('#geojsonProgress').hide();
    }

    // 港澳台区划补全（阿里 DataV 源：修台湾脏编码 + 补香港18区/澳门8区）
    var hmtImportRunning = false;
    function triggerHmtImport(){
        if(hmtImportRunning){
            layer.msg('港澳台补全正在执行中，请等待完成', {icon: 0});
            return;
        }

        layer.confirm(
            '<span style="color:#e6a23c;font-weight:bold;">⚠ 补全港澳台区划数据</span><br><br>' +
            '将从阿里 DataV 在线拉取数据（需联网）：<br>' +
            '· 修正台湾省级脏编码（code=资料 → 710000）并更新坐标<br>' +
            '· 补全香港 18 个区级节点（含坐标）<br>' +
            '· 补全澳门 8 个区级节点（含坐标）<br>' +
            '· 台湾无下级公开数据，仅更新省级坐标<br>' +
            '完成后自动重建闭包表并清除缓存<br><br>' +
            '确定要继续吗？',
            {icon: 3, title: '补全港澳台区划', btn: ['确认执行', '取消']},
            function(index){
                layer.close(index);
                startHmtImport();
            }
        );
    }
    $('#importHmtBtn').on('click', triggerHmtImport);

    function startHmtImport(){
        if(geoImportRunning || level3ImportRunning || streetImportRunning || updateRunning || geojsonImportRunning){
            layer.msg('其他任务正在运行中，请等待完成', {icon: 0});
            return;
        }
        hmtImportRunning = true;
        $('#importHmtBtn').addClass('layui-btn-disabled').prop('disabled', true);
        syncOneKeyMenuState();
        $('#hmtProgress').show();
        $('#hmtImportLog').html('');
        $('#hmtProgressText').text('正在拉取港澳台数据（阿里 DataV）...');
        $('#hmtProgressCount').text('0/3');
        element.progress('hmtImportProgress', '0%');

        $.post('/api/admin/cmspro/region/import-hmt-region', function(res){
            if(res.code === 0){
                var data = res.data;
                element.progress('hmtImportProgress', '100%');
                $('#hmtProgressCount').text('3/3');

                if(data.fixed_taiwan > 0){
                    appendHmtLog('<span style="color:green;">✓ 台湾省级脏编码已修正为 710000（' + data.fixed_taiwan + ' 条）</span>');
                }
                $.each(data.details || [], function(i, item){
                    var parts = [];
                    if(item.self_coord) parts.push('省级坐标已更新');
                    if(item.inserted > 0) parts.push('新增 ' + item.inserted + ' 个区级');
                    if(item.updated > 0) parts.push('更新 ' + item.updated + ' 个区级');
                    if(parts.length === 0) parts.push('无下级数据（仅省级）');
                    var color = item.inserted > 0 ? 'green' : '';
                    appendHmtLog('<span style="color:' + color + ';">✓ ' + item.name + ': ' + parts.join('，') + '</span>');
                });
                if(data.failed > 0){
                    appendHmtLog('<span style="color:orange;">⚠ ' + data.failed + ' 条数据异常未处理</span>');
                }

                $('#hmtProgressText').text('港澳台数据补全完成！');
                layer.msg(res.message, {icon: 1, time: 3000});
                refreshStats();
                table.reload('regionTable');
            } else {
                appendHmtLog('<span style="color:red;">✗ ' + (res.message || '补全失败') + '</span>');
                layer.msg(res.message || '补全失败', {icon: 2});
            }
            resetHmtImport();
        }).fail(function(){
            appendHmtLog('<span style="color:red;">✗ 请求失败，请检查网络</span>');
            layer.msg('请求失败，请检查网络', {icon: 2});
            resetHmtImport();
        });
    }

    function appendHmtLog(msg){
        $('#hmtImportLog').append(msg + '<br>');
        $('#hmtImportLog').scrollTop($('#hmtImportLog')[0].scrollHeight);
    }

    $('#closeHmtProgressBtn').on('click', function(){
        $('#hmtProgress').slideUp(200);
    });

    function resetHmtImport(){
        hmtImportRunning = false;
        $('#importHmtBtn').removeClass('layui-btn-disabled').prop('disabled', false);
        syncOneKeyMenuState();
        $('#hmtProgress').hide();
    }

    // 一键数据更新（四阶段串联：三级区划 → 港澳台补全 → 街道数据 → 最新坐标）
    // 两个主按钮的下拉菜单项共用此映射：点击 → 调用对应功能的入口函数（与原按钮逻辑完全一致）
    var oneKeyMenuTriggers = {
        'importBtn': triggerLevel3Import,
        'importStreetBtn': triggerStreetImport,
        'importGeoBtn': triggerGeoImport,
        'importGeojsonBtn': triggerGeojsonImport,
        'importHmtBtn': triggerHmtImport
    };
    $('.one-key-menu-item').on('click', function(){
        if($(this).hasClass('layui-btn-disabled')){
            layer.msg('任务执行中，请等待完成', {icon: 0});
            return;
        }
        var fn = oneKeyMenuTriggers[$(this).data('btn')];
        if(fn) fn();
    });

    // 运行态互斥同步：任一导入任务运行时，禁用两个主按钮 + 下拉菜单；全部空闲时恢复
    function syncOneKeyMenuState(){
        var busy = level3ImportRunning || streetImportRunning || geoImportRunning
            || geojsonImportRunning || updateRunning || hmtImportRunning || oneKeyRunning;
        var items = $('.one-key-menu-item');
        if(busy){
            $('#oneKeyUpdateBtn, #oneKeyImportBtn').addClass('layui-btn-disabled').prop('disabled', true);
            items.addClass('layui-btn-disabled');
        } else {
            $('#oneKeyUpdateBtn, #oneKeyImportBtn').removeClass('layui-btn-disabled').prop('disabled', false);
            items.removeClass('layui-btn-disabled');
        }
    }

    var oneKeyRunning = false;
    var oneKeyMode = 'online';          // 链路模式：local=本地导入(3阶段) / online=在线更新(4阶段)
    var oneKeyTitle = '一键数据更新';    // 当前链路标题（用于完成态文案）
    var oneKeyFailedDistricts = [];   // 街道阶段失败区县（重试用）
    var oneKeyFailedGeoProvinces = []; // 坐标阶段失败省份（重试用）
    var oneKeyCurrentIndex = 0;       // 街道阶段当前区县索引
    var oneKeyTotalImported = 0;      // 街道阶段累计导入条数
    var oneKeyGeoIndex = 0;            // 坐标阶段当前省份索引
    var oneKeyGeoUpdated = 0;          // 坐标阶段累计更新条数
    var oneKeyL3Provinces = [];        // 三级阶段省份列表
    var oneKeyL3Index = 0;             // 三级阶段当前省份索引
    var oneKeyL3Inserted = 0;          // 三级阶段累计新增
    var oneKeyL3Updated = 0;           // 三级阶段累计更新

    // 一键导入 / 一键数据更新：两条独立链路
    // 一键导入（本地）：三级区划(list.json) → 街道数据(本地缓存优先) → 地理位置(xlsx)，全程不依赖在线 API
    // 一键数据更新（在线）：三级区划(民政部) → 港澳台补全(DataV) → 街道数据(API缓存优先) → 最新坐标(GeoJSON)
    function triggerOneKeyRun(source){
        if(oneKeyRunning){
            layer.msg('任务正在执行中，请等待完成', {icon: 0});
            return;
        }
        if(level3ImportRunning || streetImportRunning || geoImportRunning || geojsonImportRunning || updateRunning || hmtImportRunning){
            layer.msg('其他导入任务正在运行中，请等待完成后再执行', {icon: 0});
            return;
        }

        if(source === 'import'){
            layer.confirm(
                '<span style="color:#e6a23c;font-weight:bold;">⚠ 一键导入（本地数据）</span><br><br>' +
                '将使用应用内置的本地数据包按顺序自动执行三个阶段（无需联网）：<br>' +
                '① 三级区划：从本地 list.json 逐省导入省/市/区县<br>' +
                '② 街道数据：逐区县导入（优先读取本地缓存，约4万+条，耗时最长）<br>' +
                '③ 地理位置：从本地 Excel 为城市/区县写入经纬度<br><br>' +
                '<span style="color:#e6a23c;">首次导入全量数据，街道阶段为大头，执行期间请不要关闭或刷新页面。</span><br><br>' +
                '确定要开始吗？',
                {icon: 3, title: '一键导入', btn: ['开始导入', '取消']},
                function(index){
                    layer.close(index);
                    startOneKeyUpdate('一键导入', 'local');
                }
            );
        } else {
            layer.confirm(
                '<span style="color:#e6a23c;font-weight:bold;">⚠ 一键数据更新（全在线源）</span><br><br>' +
                '将按顺序自动执行四个阶段（需联网）：<br>' +
                '① 三级区划：从民政部 API 逐省更新省/市/区县<br>' +
                '② 港澳台补全：从阿里 DataV 修正台湾编码、补全港澳区级<br>' +
                '③ 街道数据：逐区县拉取（约4万+条，耗时最长）<br>' +
                '④ 最新坐标：从阿里 DataV 逐省更新坐标<br><br>' +
                '<span style="color:#e6a23c;">增量更新已有数据，全程预计耗时较长（街道阶段为大头），执行期间请不要关闭或刷新页面。</span><br><br>' +
                '确定要开始吗？',
                {icon: 3, title: '一键数据更新', btn: ['开始更新', '取消']},
                function(index){
                    layer.close(index);
                    startOneKeyUpdate('一键数据更新', 'online');
                }
            );
        }
    }

    $('#oneKeyUpdateBtn').on('click', function(){
        triggerOneKeyRun('update');
    });

    $('#oneKeyImportBtn').on('click', function(){
        triggerOneKeyRun('import');
    });

    function startOneKeyUpdate(title, mode){
        title = title || '一键数据更新';
        mode = mode || 'online';
        oneKeyMode = mode;
        oneKeyTitle = title;
        oneKeyRunning = true;
        oneKeyFailedDistricts = [];
        oneKeyFailedGeoProvinces = [];
        oneKeyCurrentIndex = 0;
        oneKeyTotalImported = 0;
        oneKeyGeoIndex = 0;
        oneKeyGeoUpdated = 0;
        oneKeyL3Provinces = [];
        oneKeyL3Index = 0;
        oneKeyL3Inserted = 0;
        oneKeyL3Updated = 0;
        $('#oneKeyUpdateBtn, #oneKeyImportBtn').addClass('layui-btn-disabled').prop('disabled', true);
        // 禁用其余全部按钮与下拉菜单项，避免并发冲突
        $('#importBtn, #importStreetBtn, #importGeoBtn, #importGeojsonBtn, #importHmtBtn, #updateDataBtn')
            .addClass('layui-btn-disabled').prop('disabled', true);
        $('.one-key-menu-item').addClass('layui-btn-disabled');
        syncOneKeyMenuState();
        $('#oneKeyProgress').show();
        $('#oneKeyImportLog').html('');
        $('#oneKeyCount').text('');
        $('#oneKeyText').text('正在准备' + title + '...');
        element.progress('oneKeyImportProgress', '0%');
        resetOneKeySteps();

        var stageCount = (mode === 'local') ? 3 : 4;
        appendOneKeyLog('【开始】' + title + '（' + (mode === 'local' ? '本地数据' : '在线数据') + '），共 ' + stageCount + ' 个阶段');
        setTimeout(runOneKeyStep1, 100);
    }

    function resetOneKeySteps(){
        for(var i = 1; i <= 4; i++){
            $('#oneKeyStep' + i).removeClass('active done');
        }
        // 本地模式无港澳台阶段，隐藏第4步（最新坐标）标签
        $('#oneKeyStep4').toggle(oneKeyMode !== 'local');
    }

    function setOneKeyStep(step){
        for(var i = 1; i <= 4; i++){
            var el = $('#oneKeyStep' + i);
            if(i < step) el.removeClass('active').addClass('done');
            else if(i === step) el.removeClass('done').addClass('active');
            else el.removeClass('active done');
        }
    }

    function appendOneKeyLog(msg){
        var log = $('#oneKeyImportLog');
        log.html(log.html() + msg + '<br>');
        log.scrollTop(log[0].scrollHeight);
    }

    function setOneKeyProgress(percent){
        element.progress('oneKeyImportProgress', percent + '%');
    }

    function finishOneKeyUpdate(errorMsg){
        setOneKeyProgress(100);
        $('#oneKeyUpdateBtn, #oneKeyImportBtn').removeClass('layui-btn-disabled').prop('disabled', false);
        $('#importBtn, #importStreetBtn, #importGeoBtn, #importGeojsonBtn, #importHmtBtn, #updateDataBtn')
            .removeClass('layui-btn-disabled').prop('disabled', false);
        $('.one-key-menu-item').removeClass('layui-btn-disabled');
        syncOneKeyMenuState();

        if(errorMsg){
            $('#oneKeyText').text(oneKeyTitle + '失败');
            appendOneKeyLog('<span style="color:red;">✗ ' + errorMsg + '</span>');
        } else {
            $('#oneKeyText').text(oneKeyTitle + '完成！');
            setOneKeyProgress(100);
            appendOneKeyLog('【结束】全部阶段执行完毕');
        }
        for(var i = 1; i <= 4; i++){
            $('#oneKeyStep' + i).removeClass('active').addClass('done');
        }
        refreshStats();
        table.reload('regionTable');
        oneKeyRunning = false;
    }

    // 阶段1：三级区划按省分步导入（与「导入三级数据」按钮同模式，避免单请求超时）
    // local 模式：list-json-provinces / import-by-province 均从本地 Data/list.json 读取
    // online 模式：同一组接口，但数据源为民政部 API 同步下来的本地缓存（download 接口维护）
    function runOneKeyStep1(){
        setOneKeyStep(1);
        var isLocal = (oneKeyMode === 'local');
        $('#oneKeyText').text('【阶段1/' + (isLocal ? '3' : '4') + '】正在获取省份列表...');
        $('#oneKeyCount').text('');
        appendOneKeyLog('【阶段1】三级区划（' + (isLocal ? '本地 list.json' : '民政部 API') + '，按省分步）...');

        $.get('/api/admin/cmspro/region/list-json-provinces', function(res){
            if(res.code !== 0 || !res.data || res.data.length === 0){
                finishOneKeyUpdate('获取省份列表失败，无法导入三级区划');
                return;
            }
            oneKeyL3Provinces = res.data;
            appendOneKeyLog('共 ' + oneKeyL3Provinces.length + ' 个省份，开始导入...');
            importNextL3Province();
        }).fail(function(){
            finishOneKeyUpdate('获取省份列表失败，请检查数据文件' + (isLocal ? '' : '与网络'));
        });
    }

    function importNextL3Province(){
        var total = oneKeyL3Provinces.length;

        if(oneKeyL3Index >= total){
            $('#oneKeyCount').text('');
            appendOneKeyLog('<span style="color:green;">✓ 三级区划完成：新增 ' + oneKeyL3Inserted + ' 条，更新 ' + oneKeyL3Updated + ' 条</span>');
            setOneKeyProgress(5);
            setTimeout(runOneKeyStep2, 100);
            return;
        }

        var province = oneKeyL3Provinces[oneKeyL3Index];
        oneKeyL3Index++;
        setOneKeyProgress(Math.round((oneKeyL3Index / total) * 5));
        $('#oneKeyCount').text(oneKeyL3Index + '/' + total);
        var isLocal = (oneKeyMode === 'local');
        $('#oneKeyText').text('【阶段1/' + (isLocal ? '3' : '4') + '】正在导入三级区划: ' + province.name + '...');

        $.post('/api/admin/cmspro/region/import-by-province', {province_code: province.code}, function(res){
            if(res.code === 0){
                var data = res.data || {};
                oneKeyL3Inserted += (data.inserted || 0);
                oneKeyL3Updated += (data.updated || 0);
            } else {
                appendOneKeyLog('<span style="color:red;">✗ ' + province.name + ': ' + (res.message || '导入失败') + '</span>');
            }
            setTimeout(importNextL3Province, 50);
        }).fail(function(){
            appendOneKeyLog('<span style="color:red;">✗ ' + province.name + ': 请求失败</span>');
            setTimeout(importNextL3Province, 50);
        });
    }

    // 阶段2：local 模式跳过（无港澳台阶段，直入街道）；online 模式为港澳台补全（单请求，DataV 源）
    function runOneKeyStep2(){
        if(oneKeyMode === 'local'){
            // 本地模式：无港澳台阶段，进入街道阶段（进度 30% 与在线模式衔接）
            setTimeout(runOneKeyStep3, 100);
            return;
        }
        setOneKeyStep(2);
        $('#oneKeyText').text('【阶段2/4】正在补全港澳台区划（阿里 DataV）...');
        appendOneKeyLog('【阶段2】港澳台补全（阿里 DataV）...');

        $.post('/api/admin/cmspro/region/import-hmt-region', function(res){
            if(res.code === 0){
                var data = res.data || {};
                if(data.fixed_taiwan > 0){
                    appendOneKeyLog('<span style="color:green;">✓ 台湾省级脏编码已修正（' + data.fixed_taiwan + ' 条）</span>');
                }
                appendOneKeyLog('<span style="color:green;">✓ 港澳台补全完成：新增 ' + (data.inserted || 0) + ' 条，更新 ' + (data.updated || 0) + ' 条</span>');
                if(data.failed > 0){
                    appendOneKeyLog('<span style="color:orange;">⚠ ' + data.failed + ' 条数据异常未处理</span>');
                }
                setOneKeyProgress(30);
                setTimeout(runOneKeyStep3, 100);
            } else {
                // 港澳台补全失败不中止整体流程，记录警告继续
                appendOneKeyLog('<span style="color:orange;">⚠ 港澳台补全失败（' + (res.message || '未知错误') + '），继续后续阶段</span>');
                setTimeout(runOneKeyStep3, 100);
            }
        }).fail(function(){
            appendOneKeyLog('<span style="color:orange;">⚠ 港澳台补全请求失败，继续后续阶段</span>');
            setTimeout(runOneKeyStep3, 100);
        });
    }

    // 阶段3：街道数据（逐区县串行，收集区县后导入；两种模式接口一致，仅数据源文案不同）
    function runOneKeyStep3(){
        setOneKeyStep(3);
        var isLocal = (oneKeyMode === 'local');
        var totalStages = isLocal ? 3 : 4;
        $('#oneKeyText').text('【阶段3/' + totalStages + '】正在收集街道数据区县列表...');
        $('#oneKeyCount').text('');
        appendOneKeyLog('【阶段3】街道数据（' + (isLocal ? '本地缓存优先，无缓存则调 API' : '民政部 API，缓存优先') + '）...');

        $.get('/api/admin/cmspro/region/provinces', function(res){
            if(res.code !== 0 || !res.data || res.data.length === 0){
                appendOneKeyLog('<span style="color:orange;">⚠ 库中无省份数据，跳过街道阶段</span>');
                setTimeout(runOneKeyStep4, 100);
                return;
            }

            var provinces = res.data;
            var provinceIndex = 0;
            var allDistricts = [];

            // 逐省收集区县列表
            function fetchNextProvinceDistricts(){
                if(provinceIndex >= provinces.length){
                    if(allDistricts.length === 0){
                        appendOneKeyLog('<span style="color:orange;">⚠ 未收集到区县，跳过街道阶段</span>');
                        setTimeout(runOneKeyStep4, 100);
                        return;
                    }
                    appendOneKeyLog('共收集 ' + allDistricts.length + ' 个区县，开始导入街道...');
                    importNextDistrict();
                    return;
                }

                var province = provinces[provinceIndex];
                provinceIndex++;

                $.get('/api/admin/cmspro/region/street-districts', {province_code: province.code}, function(res){
                    if(res.code === 0 && res.data && res.data.length > 0){
                        for(var i = 0; i < res.data.length; i++){
                            allDistricts.push({
                                code: res.data[i].code,
                                name: res.data[i].name,
                                provinceName: province.name
                            });
                        }
                    }
                    fetchNextProvinceDistricts();
                }).fail(function(){
                    appendOneKeyLog(province.name + ': 获取区县列表失败，跳过');
                    fetchNextProvinceDistricts();
                });
            }

            // 逐区县导入街道
            function importNextDistrict(){
                var total = allDistricts.length;

                if(oneKeyFailedDistricts.length > 0 && oneKeyFailedDistricts[0]._retried !== true){
                    // 首轮结束，重试失败区县
                    var failed = oneKeyFailedDistricts.slice();
                    oneKeyFailedDistricts = [];
                    for(var i = 0; i < failed.length; i++){
                        failed[i]._retried = true;
                        allDistricts.push(failed[i]);
                    }
                    appendOneKeyLog('<span style="color:orange;">⚠ ' + failed.length + ' 个区县失败，追加重试...</span>');
                    setTimeout(importNextDistrict, 500);
                    return;
                }

                if(oneKeyCurrentIndex >= total){
                    $('#oneKeyCount').text('');
                    if(oneKeyFailedDistricts.length > 0){
                        appendOneKeyLog('<span style="color:red;">✗ 仍有 ' + oneKeyFailedDistricts.length + ' 个区县导入失败</span>');
                    }
                    appendOneKeyLog('<span style="color:green;">✓ 街道阶段完成，共导入 ' + oneKeyTotalImported + ' 条</span>');
                    oneKeyCurrentIndex = 0;
                    oneKeyTotalImported = 0;
                    setTimeout(runOneKeyStep4, 100);
                    return;
                }

                var district = allDistricts[oneKeyCurrentIndex];
                oneKeyCurrentIndex++;
                var percent = Math.round((oneKeyCurrentIndex / total) * 100);
                // 街道阶段占整体进度的 30%~85%
                setOneKeyProgress(30 + Math.round(percent * 0.55));
                $('#oneKeyCount').text(oneKeyCurrentIndex + '/' + total);
                var totalStages = isLocal ? 3 : 4;
                $('#oneKeyText').text('【阶段3/' + totalStages + '】正在导入街道: ' + district.provinceName + ' - ' + district.name + '...');

                $.post('/api/admin/cmspro/region/import-street-by-district-from-mca', {district_code: district.code}, function(res){
                    if(res.code === 0){
                        var data = res.data || {};
                        oneKeyTotalImported += (data.inserted || 0);
                        if(data.inserted > 0 || data.updated > 0){
                            appendOneKeyLog(district.provinceName + ' - ' + district.name + ': 新增' + (data.inserted || 0) + '，更新' + (data.updated || 0));
                        }
                    } else {
                        appendOneKeyLog('<span style="color:red;">✗ ' + district.provinceName + ' - ' + district.name + ': ' + (res.message || '导入失败') + '</span>');
                        if(district._retried !== true){
                            oneKeyFailedDistricts.push(district);
                        }
                    }
                    setTimeout(importNextDistrict, 50);
                }).fail(function(){
                    appendOneKeyLog('<span style="color:red;">✗ ' + district.provinceName + ' - ' + district.name + ': 请求失败</span>');
                    if(district._retried !== true){
                        oneKeyFailedDistricts.push(district);
                    }
                    setTimeout(importNextDistrict, 50);
                });
            }

            fetchNextProvinceDistricts();
        }).fail(function(){
            appendOneKeyLog('<span style="color:orange;">⚠ 获取省份列表失败，跳过街道阶段</span>');
            setTimeout(runOneKeyStep4, 100);
        });
    }

    // 阶段4：最新坐标
    // local 模式：从本地 Excel（地理位置编码.xlsx）全量导入坐标（单请求）
    // online 模式：从阿里 DataV GeoJSON 逐省更新
    function runOneKeyStep4(){
        if(oneKeyMode === 'local'){
            setOneKeyStep(4);
            $('#oneKeyText').text('【阶段3/3】正在导入地理位置（本地 Excel）...');
            $('#oneKeyCount').text('');
            appendOneKeyLog('【阶段3】地理位置（本地 Excel，城市/区县坐标）...');
            setOneKeyProgress(86);

            $.post('/api/admin/cmspro/region/import-geo-data', function(res){
                if(res.code === 0){
                    var data = res.data || {};
                    var parts = [];
                    if(data.updated > 0) parts.push('更新' + data.updated);
                    if(data.skipped > 0) parts.push('坐标相同' + data.skipped);
                    if(data.failed > 0) parts.push('<span style="color:red;">异常' + data.failed + '</span>');
                    appendOneKeyLog('<span style="color:green;">✓ 地理位置完成' + (parts.length > 0 ? '：' + parts.join('，') + ' 条' : '') + '</span>');
                } else {
                    appendOneKeyLog('<span style="color:orange;">⚠ 地理位置导入失败（' + (res.message || '未知错误') + '），不中止流程</span>');
                }
                finishOneKeyUpdate();
            }).fail(function(){
                appendOneKeyLog('<span style="color:orange;">⚠ 地理位置导入请求失败，不中止流程</span>');
                finishOneKeyUpdate();
            });
            return;
        }

        setOneKeyStep(4);
        $('#oneKeyText').text('【阶段4/4】正在获取可更新省份...');
        $('#oneKeyCount').text('');
        appendOneKeyLog('【阶段4】最新坐标（阿里 DataV GeoJSON）...');

        $.get('/api/admin/cmspro/region/geojson-provinces', function(res){
            if(res.code !== 0 || !res.data || res.data.length === 0){
                appendOneKeyLog('<span style="color:orange;">⚠ 无可更新省份，流程结束</span>');
                finishOneKeyUpdate();
                return;
            }

            var provinces = res.data;
            var total = provinces.length;

            function importNextGeoProvince(){
                if(oneKeyGeoIndex >= total){
                    $('#oneKeyCount').text('');
                    if(oneKeyFailedGeoProvinces.length > 0){
                        appendOneKeyLog('<span style="color:red;">✗ 仍有 ' + oneKeyFailedGeoProvinces.length + ' 个省份坐标更新失败</span>');
                    }
                    appendOneKeyLog('<span style="color:green;">✓ 坐标阶段完成，共更新 ' + oneKeyGeoUpdated + ' 条</span>');
                    oneKeyGeoIndex = 0;
                    oneKeyGeoUpdated = 0;
                    finishOneKeyUpdate();
                    return;
                }

                var province = provinces[oneKeyGeoIndex];
                oneKeyGeoIndex++;
                var percent = Math.round((oneKeyGeoIndex / total) * 100);
                // 坐标阶段占整体进度的 85%~99%
                setOneKeyProgress(85 + Math.round(percent * 0.14));
                $('#oneKeyCount').text(oneKeyGeoIndex + '/' + total);
                $('#oneKeyText').text('【阶段4/4】正在更新坐标: ' + province.name + '...');

                $.post('/api/admin/cmspro/region/import-geo-data-from-geojson', {province_code: province.code}, function(res){
                    if(res.code === 0){
                        var data = res.data || {};
                        oneKeyGeoUpdated += (data.updated || 0);
                        var parts = [];
                        if(data.updated > 0) parts.push('更新' + data.updated);
                        if(data.skipped > 0) parts.push('坐标相同' + data.skipped);
                        if(data.missing > 0) parts.push('<span style="color:orange;">未匹配' + data.missing + '</span>');
                        if(data.failed > 0) parts.push('<span style="color:red;">异常' + data.failed + '</span>');
                        appendOneKeyLog(province.name + ': ' + (parts.length > 0 ? parts.join('，') + '条' : '无数据'));
                    } else {
                        appendOneKeyLog('<span style="color:red;">✗ ' + province.name + ': ' + (res.message || '更新失败') + '</span>');
                        oneKeyFailedGeoProvinces.push(province);
                    }
                    setTimeout(importNextGeoProvince, 50);
                }).fail(function(){
                    appendOneKeyLog('<span style="color:red;">✗ ' + province.name + ': 请求失败</span>');
                    oneKeyFailedGeoProvinces.push(province);
                    setTimeout(importNextGeoProvince, 50);
                });
            }

            importNextGeoProvince();
        }).fail(function(){
            appendOneKeyLog('<span style="color:orange;">⚠ 获取省份列表失败，流程结束</span>');
            finishOneKeyUpdate();
        });
    }

    $('#closeOneKeyProgressBtn').on('click', function(){
        if(oneKeyRunning){
            layer.msg('任务执行中，暂不能关闭面板', {icon: 0});
            return;
        }
        $('#oneKeyProgress').slideUp(200);
    });

    // 刷新统计
    function refreshStats(){
        $.get('/api/admin/cmspro/region/stats', function(res){
            if(res.code === 0){
                $('#statProvince').text(res.data.province);
                $('#statCity').text(res.data.city);
                $('#statDistrict').text(res.data.district);
                $('#statStreet').text(res.data.street);
            }
        });
    }

    // 检测是否已有数据，无数据时提示一键导入（本地数据链路）
    $.get('/api/admin/cmspro/region/stats', function(res){
        if(res.code === 0 && res.data.total === 0){
            layer.msg('暂无区划数据，请点击「一键导入」按钮导入', {icon: 0, time: 5000});
        }
    });
});
</script>
</body>
</html>
