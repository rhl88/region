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
                <button class="layui-btn layui-btn-normal" id="importBtn"><i class="fa fa-download"></i> 导入三级数据</button>
                <button class="layui-btn" id="importStreetBtn"><i class="fa fa-building"></i> 导入街道数据</button>
                <button class="layui-btn layui-btn-success" id="importGeoBtn"><i class="fa fa-map-marker"></i> 导入地理位置</button>
                <button class="layui-btn layui-btn-warm" id="clearCacheBtn"><i class="fa fa-refresh"></i> 清除缓存</button>
                <div class="layui-inline" style="margin-left:20px;">
                    <label class="layui-form-label" style="width:auto;padding:0 10px;">层级筛选</label>
                    <div class="layui-input-inline" style="width:120px;">
                        <select id="levelFilter">
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
    $('#importBtn').on('click', function(){
        if(level3ImportRunning){
            layer.msg('数据正在导入中，请等待完成', {icon: 0});
            return;
        }

        layer.confirm('确定要导入/更新省市区三级数据吗？将按省份分步处理。', {icon: 3}, function(index){
            layer.close(index);
            startLevel3Import();
        });
    });

    function startLevel3Import(){
        level3ImportRunning = true;
        $('#importBtn').addClass('layui-btn-disabled').prop('disabled', true);
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
        $('#level3Progress').hide();
    }

    // 导入街道数据（AJAX分步）
    var streetImportRunning = false;
    $('#importStreetBtn').on('click', function(){
        if(streetImportRunning){
            layer.msg('街道数据正在导入中，请等待完成', {icon: 0});
            return;
        }

        layer.confirm('确定要导入街道/乡镇四级数据吗？数据量较大（约4万+条），将按省份分步导入，请耐心等待。', {icon: 3}, function(index){
            layer.close(index);
            startStreetImport();
        });
    });

    function startStreetImport(){
        if(level3ImportRunning){
            layer.msg('三级数据正在导入中，请等待完成后再导入街道数据', {icon: 0});
            return;
        }
        streetImportRunning = true;
        $('#importStreetBtn').addClass('layui-btn-disabled').prop('disabled', true);
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
    $('#importGeoBtn').on('click', function(){
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
    });

    function startGeoImport(){
        if(level3ImportRunning || streetImportRunning || updateRunning){
            layer.msg('其他任务正在运行中，请等待完成', {icon: 0});
            return;
        }
        geoImportRunning = true;
        $('#importGeoBtn').addClass('layui-btn-disabled').prop('disabled', true);
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
        $('#geoProgress').hide();
    }

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

    // 检测是否已有数据，无数据时提示导入
    $.get('/api/admin/cmspro/region/stats', function(res){
        if(res.code === 0 && res.data.total === 0){
            layer.msg('暂无区划数据，请点击「导入三级数据」按钮导入', {icon: 0, time: 5000});
        }
    });
});
</script>
</body>
</html>
