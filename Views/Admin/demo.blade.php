<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>区划数据演示</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <style>
        .demo-container { width: 100%; }
        #geoMap{ z-index: 1; }
        .demo-card { margin-bottom: 20px; }
        /* 数据状态卡片：图标与文字同行，压缩高度（选择器带 .layui-card-body 以压过框架默认块级样式） */
        .layui-card-body.status-row { display: flex; flex-wrap: wrap; gap: 16px; }
        .status-card { display: flex; flex: 1 1 260px; align-items: center; min-width: 0; padding: 10px 14px; border-radius: 6px; box-sizing: border-box; text-align: left; }
        .status-card .status-icon { flex: none; font-size: 20px; line-height: 1; margin-right: 10px; }
        .status-card .status-body { flex: 1; min-width: 0; }
        .status-card .status-title { font-size: 13px; color: #666; margin-bottom: 2px; }
        .status-card .status-desc { font-size: 12px; color: #999; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .status-ok { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .status-ok .status-icon { color: #22c55e; }
        .status-missing { background: #fef3c7; border: 1px solid #fde68a; }
        .status-missing .status-icon { color: #f59e0b; }
        .cascader-area { padding: 20px; background: #f8fafc; border-radius: 6px; }
        .cascader-area select { width: 150px; height: 36px; padding: 0 10px; border: 1px solid #e2e8f0; border-radius: 4px; margin-right: 10px; margin-bottom: 10px; font-size: 14px; }
        .selected-path { margin-top: 10px; padding: 10px 14px; background: #eff6ff; border-radius: 4px; font-size: 14px; color: #1e40af; }
        .geo-info { margin-top: 15px; }
        .geo-info-row { display: flex; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .geo-info-row:last-child { border-bottom: none; }
        .geo-info-label { width: 100px; font-size: 13px; color: #666; }
        .geo-info-value { flex: 1; font-size: 14px; color: #333; }
        .geo-info-value .coord { font-family: 'Courier New', monospace; }
        /* 地图常驻显示：高度取「左栏高度」与 520px 的较大值（flex 链：.demo-right → .geo-map-wrap → #geoMap） */
        .geo-map-wrap { flex: 1; display: flex; flex-direction: column; }
        /* box-sizing 让 1px 边框计入宽度，否则 border-box 比父容器宽 2px 导致右边缘被裁 */
        #geoMap { flex: 1; width: 100%; min-height: 520px; box-sizing: border-box; border-radius: 6px; border: 1px solid #e2e8f0; }
        /* 区划选择器演示：左侧选择信息、右侧地图（等高，右侧填满剩余高度） */
        .demo-split { display: flex; align-items: stretch; gap: 20px; }
        /* 左栏按比例占宽（宽屏时把更多空间让给地图），并限定上下限避免过窄换行或过宽 */
        .demo-left { flex: 0 0 26%; min-width: 360px; max-width: 420px; }
        .demo-right { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        @media (max-width: 992px) {
            .demo-split { flex-direction: column; }
            .demo-left { flex: none; width: 100%; min-width: 0; max-width: 100%; }
            .demo-right { width: 100%; }
            #geoMap { flex: none; height: 340px; min-height: 0; }
        }
        .import-guide { padding: 16px 20px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 6px; margin-bottom: 15px; }
        .import-guide .guide-title { font-size: 14px; font-weight: bold; color: #c2410c; margin-bottom: 8px; }
        .import-guide .guide-desc { font-size: 13px; color: #9a3412; line-height: 1.8; }
        .import-guide .guide-action { margin-top: 10px; }
        .empty-state { text-align: center; padding: 40px 20px; color: #999; }
        .empty-state .empty-icon { font-size: 48px; color: #cbd5e1; margin-bottom: 12px; }
        .empty-state .empty-text { font-size: 14px; }
    </style>
</head>
<body>
<div class="pear-container demo-container">

    {{-- 数据状态总览 --}}
    <div class="layui-card demo-card">
        <div class="layui-card-header">数据状态总览</div>
        <div class="layui-card-body status-row">
            <div class="status-card {{ $hasLevel3 ? 'status-ok' : 'status-missing' }}">
                <div class="status-icon"><i class="fa fa-{{ $hasLevel3 ? 'check-circle' : 'exclamation-triangle' }}"></i></div>
                <div class="status-body">
                    <div class="status-title">三级区划数据</div>
                    <div class="status-desc">{{ $hasLevel3 ? "省{$stats['province']} / 市{$stats['city']} / 区县{$stats['district']}" : '未导入' }}</div>
                </div>
            </div>
            <div class="status-card {{ $hasStreet ? 'status-ok' : 'status-missing' }}">
                <div class="status-icon"><i class="fa fa-{{ $hasStreet ? 'check-circle' : 'exclamation-triangle' }}"></i></div>
                <div class="status-body">
                    <div class="status-title">街道乡镇 / 村社区数据</div>
                    <div class="status-desc">
                        @if($hasStreet)
                            {{ $stats['street'] . ' 条街道' }}@if($stats['village'] > 0){{ ' / ' . $stats['village'] . ' 条村社区' }}@endif
                        @else
                            未导入
                        @endif
                    </div>
                </div>
            </div>
            <div class="status-card {{ $hasGeo ? 'status-ok' : 'status-missing' }}">
                <div class="status-icon"><i class="fa fa-{{ $hasGeo ? 'check-circle' : 'exclamation-triangle' }}"></i></div>
                <div class="status-body">
                    <div class="status-title">地理位置数据</div>
                    <div class="status-desc">{{ $hasGeo ? $geoStats['with_geo'] . ' 条含坐标' : '未导入' }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- 缺失数据导入引导 --}}
    @if(!$hasLevel3 || !$hasStreet || !$hasGeo)
    <div class="layui-card demo-card">
        <div class="layui-card-header">数据导入引导</div>
        <div class="layui-card-body">
            @if(!$hasLevel3)
            <div class="import-guide">
                <div class="guide-title"><i class="fa fa-exclamation-triangle"></i> 三级区划数据未导入</div>
                <div class="guide-desc">省/市/区县三级数据是区划选择器的基础，未导入时无法进行数据演示。请先在数据管理页点击「一键导入」完成全量数据导入（无需联网），或仅导入三级数据后再使用演示功能。</div>
                <div class="guide-action">
                    <a href="/admin/cmspro/region" class="layui-btn layui-btn-normal layui-btn-sm"><i class="fa fa-database"></i> 前往一键导入</a>
                </div>
            </div>
            @endif

            @if(!$hasStreet)
            <div class="import-guide">
                <div class="guide-title"><i class="fa fa-exclamation-triangle"></i> 街道/乡镇数据未导入</div>
                <div class="guide-desc">街道级数据为可选数据，未导入时区划选择器仅支持省/市/区县三级联动。如需四级联动演示，请导入街道数据。</div>
                <div class="guide-action">
                    <a href="/admin/cmspro/region" class="layui-btn layui-btn-warm layui-btn-sm"><i class="fa fa-building"></i> 前往导入街道数据</a>
                </div>
            </div>
            @endif

            @if(!$hasGeo)
            <div class="import-guide">
                <div class="guide-title"><i class="fa fa-exclamation-triangle"></i> 地理位置数据未导入</div>
                <div class="guide-desc">经纬度数据用于展示区划的地理位置信息，未导入时无法演示地址位置信息。如需查看经纬度，请导入地理位置数据。</div>
                <div class="guide-action">
                    <a href="/admin/cmspro/region" class="layui-btn layui-btn-success layui-btn-sm"><i class="fa fa-map-marker"></i> 前往导入地理位置</a>
                </div>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- 区划选择器演示 --}}
    <div class="layui-card demo-card">
        <div class="layui-card-header">区划选择器演示</div>
        <div class="layui-card-body">
            @if($hasLevel3)
            <div class="demo-split">
                {{-- 左侧：级联选择 + 地理位置信息 --}}
                <div class="demo-left">
                    <div class="cascader-area">
                        <select id="provinceSelect"><option value="">请选择省份</option></select>
                        <select id="citySelect"><option value="">请选择城市</option></select>
                        <select id="districtSelect"><option value="">请选择区县</option></select>
                        @if($hasStreet)
                        <select id="streetSelect"><option value="">请选择街道/乡镇</option></select>
                        @endif
                        @if($hasVillage)
                        <select id="villageSelect"><option value="">请选择村/社区</option></select>
                        @endif
                        <div class="selected-path" id="selectedPath">请选择区划</div>
                    </div>

                    {{-- 地理位置信息 --}}
                    @if($hasGeo)
                    <div class="geo-info" id="geoInfo" style="display:none;">
                        <div class="geo-info-row">
                            <div class="geo-info-label">区划代码</div>
                            <div class="geo-info-value" id="geoCode">-</div>
                        </div>
                        <div class="geo-info-row">
                            <div class="geo-info-label">区划名称</div>
                            <div class="geo-info-value" id="geoName">-</div>
                        </div>
                        <div class="geo-info-row">
                            <div class="geo-info-label">完整路径</div>
                            <div class="geo-info-value" id="geoPath">-</div>
                        </div>
                        <div class="geo-info-row">
                            <div class="geo-info-label">经度</div>
                            <div class="geo-info-value"><span class="coord" id="geoLon">-</span></div>
                        </div>
                        <div class="geo-info-row">
                            <div class="geo-info-label">纬度</div>
                            <div class="geo-info-value"><span class="coord" id="geoLat">-</span></div>
                        </div>
                    </div>
                    @endif
                </div>

                {{-- 右侧：地图定位演示 --}}
                <div class="demo-right">
                    @if($hasGeo)
                    <div class="geo-map-wrap" id="geoMapWrap">
                        <div id="geoMap"></div>
                    </div>
                    <div class="empty-state" id="mapLoadFail" style="display:none;">
                        <div class="empty-icon"><i class="fa fa-map-o"></i></div>
                        <div class="empty-text">地图组件加载失败，请检查网络后刷新重试</div>
                    </div>
                    @else
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa fa-map-marker"></i></div>
                        <div class="empty-text">地理位置数据未导入，无法演示地址位置信息</div>
                    </div>
                    @endif
                </div>
            </div>
            @else
            <div class="empty-state">
                <div class="empty-icon"><i class="fa fa-database"></i></div>
                <div class="empty-text">三级区划数据未导入，无法演示区划选择器<br>请先在数据管理页点击「一键导入」</div>
            </div>
            @endif
        </div>
    </div>

</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script src="https://cdn.bootcdn.net/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
layui.use(['layer', 'jquery'], function(){
    var layer = layui.layer;
    var $ = layui.jquery;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    });

    var hasLevel3 = {{ $hasLevel3 ? 'true' : 'false' }};
    var hasStreet = {{ $hasStreet ? 'true' : 'false' }};
    var hasVillage = {{ $hasVillage ? 'true' : 'false' }};
    var hasGeo = {{ $hasGeo ? 'true' : 'false' }};
    var levelNames = {1:'省/直辖市', 2:'市', 3:'区/县', 4:'街道/乡镇', 5:'村/社区'};
    // 村/社区下拉的空选项，上级切换时统一用它复位
    var VILLAGE_EMPTY = '<option value="">请选择村/社区</option>';

    if(!hasLevel3){
        return;
    }

    // 加载省份列表
    function loadProvinces(){
        $.get('/api/admin/cmspro/region/provinces', function(res){
            if(res.code !== 0) return;
            var html = '<option value="">请选择省份</option>';
            res.data.forEach(function(item){
                html += '<option value="'+item.code+'">'+item.name+'</option>';
            });
            $('#provinceSelect').html(html);
            $('#citySelect').html('<option value="">请选择城市</option>');
            $('#districtSelect').html('<option value="">请选择区县</option>');
            if(hasStreet) $('#streetSelect').html('<option value="">请选择街道/乡镇</option>');
            if(hasVillage) $('#villageSelect').html(VILLAGE_EMPTY);
            $('#selectedPath').text('请选择区划');
            $('#geoInfo').hide();
        });
    }

    // 省份切换
    $('#provinceSelect').on('change', function(){
        var code = $(this).val();
        if(!code){
            $('#citySelect').html('<option value="">请选择城市</option>');
            $('#districtSelect').html('<option value="">请选择区县</option>');
            if(hasStreet) $('#streetSelect').html('<option value="">请选择街道/乡镇</option>');
            if(hasVillage) $('#villageSelect').html(VILLAGE_EMPTY);
            $('#selectedPath').text('请选择区划');
            $('#geoInfo').hide();
            return;
        }
        var provinceName = $('#provinceSelect option:selected').text();
        $('#selectedPath').text(provinceName);
        $('#geoInfo').hide();
        $.get('/api/admin/cmspro/region/children?code='+code, function(res){
            if(res.code !== 0) return;
            var html = '<option value="">请选择城市</option>';
            res.data.forEach(function(item){
                html += '<option value="'+item.code+'">'+item.name+'</option>';
            });
            $('#citySelect').html(html);
            $('#districtSelect').html('<option value="">请选择区县</option>');
            if(hasStreet) $('#streetSelect').html('<option value="">请选择街道/乡镇</option>');
            if(hasVillage) $('#villageSelect').html(VILLAGE_EMPTY);
            // 直辖市的区县直接挂在省级下，选中省份后同样展示地理信息
            if(hasGeo) loadGeoInfo(code);
        });
    });

    // 城市切换
    $('#citySelect').on('change', function(){
        var code = $(this).val();
        if(!code){
            $('#districtSelect').html('<option value="">请选择区县</option>');
            if(hasStreet) $('#streetSelect').html('<option value="">请选择街道/乡镇</option>');
            if(hasVillage) $('#villageSelect').html(VILLAGE_EMPTY);
            var provinceName = $('#provinceSelect option:selected').text();
            $('#selectedPath').text(provinceName);
            $('#geoInfo').hide();
            return;
        }
        var provinceName = $('#provinceSelect option:selected').text();
        var cityName = $('#citySelect option:selected').text();
        $('#selectedPath').text(provinceName + ' > ' + cityName);
        $('#geoInfo').hide();
        resetMapView();
        $.get('/api/admin/cmspro/region/children?code='+code, function(res){
            if(res.code !== 0) return;
            var html = '<option value="">请选择区县</option>';
            res.data.forEach(function(item){
                html += '<option value="'+item.code+'">'+item.name+'</option>';
            });
            $('#districtSelect').html(html);
            if(hasStreet) $('#streetSelect').html('<option value="">请选择街道/乡镇</option>');
            if(hasVillage) $('#villageSelect').html(VILLAGE_EMPTY);
            // 选中市后展示地理信息（有坐标数据时）
            if(hasGeo) loadGeoInfo(code);
        });
    });

    // 区县切换
    $('#districtSelect').on('change', function(){
        var code = $(this).val();
        var provinceName = $('#provinceSelect option:selected').text();
        var cityName = $('#citySelect option:selected').text();
        var districtName = $('#districtSelect option:selected').text();

        if(!code){
            if(hasStreet) $('#streetSelect').html('<option value="">请选择街道/乡镇</option>');
            if(hasVillage) $('#villageSelect').html(VILLAGE_EMPTY);
            $('#selectedPath').text(provinceName + ' > ' + cityName);
            $('#geoInfo').hide();
            return;
        }

        $('#selectedPath').text(provinceName + ' > ' + cityName + ' > ' + districtName);

        // 查询地理位置信息
        if(hasGeo){
            loadGeoInfo(code);
        }

        // 区县变更：街道、村/社区均需重新选择
        if(hasVillage) $('#villageSelect').html(VILLAGE_EMPTY);

        // 有街道数据时加载街道
        if(hasStreet){
            $.get('/api/admin/cmspro/region/children?code='+code, function(res){
                if(res.code !== 0) return;
                var html = '<option value="">请选择街道/乡镇</option>';
                res.data.forEach(function(item){
                    html += '<option value="'+item.code+'">'+item.name+'</option>';
                });
                $('#streetSelect').html(html);
            });
        }
    });

    // 街道切换
    if(hasStreet){
        $('#streetSelect').on('change', function(){
            var code = $(this).val();
            var provinceName = $('#provinceSelect option:selected').text();
            var cityName = $('#citySelect option:selected').text();
            var districtName = $('#districtSelect option:selected').text();
            var streetName = $('#streetSelect option:selected').text();
            var path = provinceName + ' > ' + cityName + ' > ' + districtName;
            if(streetName && code){
                path += ' > ' + streetName;
            }
            $('#selectedPath').text(path);

            if(code && hasGeo){
                loadGeoInfo(code);
            }

            // 街道变更后先复位村/社区，再按街道加载其下辖村/社区（复用 children 接口）
            if(hasVillage){
                $('#villageSelect').html(VILLAGE_EMPTY);
                if(code){
                    $.get('/api/admin/cmspro/region/children?code='+code, function(res){
                        if(res.code !== 0) return;
                        var villageHtml = VILLAGE_EMPTY;
                        res.data.forEach(function(item){
                            villageHtml += '<option value="'+item.code+'">'+item.name+'</option>';
                        });
                        $('#villageSelect').html(villageHtml);
                    });
                }
            }
        });
    }

    // 村/社区切换：村级数据暂无经纬度，选中后仅回显完整路径，地理信息展示「无坐标数据」
    if(hasVillage){
        $('#villageSelect').on('change', function(){
            var code = $(this).val();
            var names = [
                $('#provinceSelect option:selected').text(),
                $('#citySelect option:selected').text(),
                $('#districtSelect option:selected').text(),
                $('#streetSelect option:selected').text()
            ];
            if(code){
                names.push($('#villageSelect option:selected').text());
            }
            $('#selectedPath').text(names.join(' > '));

            if(code && hasGeo){
                loadGeoInfo(code);
            }
        });
    }

    // 加载地理位置信息
    function loadGeoInfo(code){
        // 先获取区划详情（含经纬度）
        $.get('/api/admin/cmspro/region/path?code='+code, function(res){
            if(res.code !== 0) return;
            var pathArr = res.data.map(function(item){ return item.name; });
            var fullPath = pathArr.join(' > ');

            // 通过列表接口获取经纬度
            $.get('/api/admin/cmspro/region/list?keyword='+code+'&limit=1', function(listRes){
                if(listRes.code === 0 && listRes.data && listRes.data.length > 0){
                    var region = listRes.data[0];
                    $('#geoCode').text(region.code || '-');
                    $('#geoName').text(region.name || '-');
                    $('#geoPath').text(fullPath || '-');
                    $('#geoLon').text(region.lon ? parseFloat(region.lon).toFixed(6) : '无坐标数据');
                    $('#geoLat').text(region.lat ? parseFloat(region.lat).toFixed(6) : '无坐标数据');
                    $('#geoInfo').show();

                    // 有坐标时在地图上定位并绘制行政边界
                    if(region.lon && region.lat){
                        showOnMap(region, fullPath);
                    } else {
                        resetMapView();
                    }
                }
            });
        });
    }

    // 地图定位（Leaflet + 高德瓦片，坐标为GCJ-02，与高德瓦片匹配）
    var map = null;
    var marker = null;
    var boundaryLayer = null;   // 当前行政区划边界图层
    var boundarySeq = 0;        // 边界请求序号：级联快速切换时丢弃过期响应

    // 边界数据源：阿里 DataV GeoJSON（GCJ-02，与后端 RegionService::$geojsonBaseUrl 同源）
    var BOUNDARY_URL = 'https://geo.datav.aliyun.com/areas_v3/bound/';

    function initMap(){
        if(typeof L === 'undefined'){
            // CDN 加载失败：隐藏空地图容器，仅展示降级提示
            $('#geoMapWrap').hide();
            $('#mapLoadFail').show();
            return false;
        }
        if(!map){
            map = L.map('geoMap', { zoomControl: true }).setView([35.86166, 104.195397], 4);
            L.tileLayer('https://webrd0{s}.is.autonavi.com/appmaptile?lang=zh_cn&size=1&scale=1&style=8&x={x}&y={y}&z={z}', {
                subdomains: ['1', '2', '3', '4'],
                maxZoom: 18,
                attribution: '&copy; 高德地图'
            }).addTo(map);
        }
        return true;
    }

    /**
     * 清除已绘制的边界图层
     * boundarySeq 自增使在途请求失效，避免过期响应把边界画回来
     */
    function clearBoundary(){
        boundarySeq++;
        if(boundaryLayer && map){
            map.removeLayer(boundaryLayer);
        }
        boundaryLayer = null;
    }

    /**
     * 清除地图上的选中态（中心点标记 + 行政边界），底图保持常驻显示
     * 用于级联切换、或所选区划无坐标时复位
     */
    function resetMapView(){
        clearBoundary();
        if(marker && map){
            map.removeLayer(marker);
        }
        marker = null;
    }

    /**
     * 绘制行政区划边界（阿里 DataV GeoJSON）
     *
     * DataV 仅收录省/市/区县三级（6 位编码），街道级返回 404；
     * 未收录或请求失败时静默降级，只保留中心点标记。
     * 绘制成功后视口自适应到边界范围（等效高德 setFitView）。
     */
    function drawBoundary(code, level){
        clearBoundary();
        if(!code || String(code).length !== 6 || Number(level) > 3){
            return;
        }

        var seq = ++boundarySeq;
        // 用原生 fetch：跨域请求不能带全局 X-CSRF-TOKEN 头，否则会触发 CORS 预检被数据源拒绝
        // referrerPolicy 必须为 no-referrer：DataV 有防盗链，带外站 Referer 的请求一律返回 403
        fetch(BOUNDARY_URL + code + '.json', { referrerPolicy: 'no-referrer' })
            .then(function(response){
                if(!response.ok){
                    console.warn('[区划边界] 接口返回 ' + response.status + '，code=' + code);
                    return null;
                }
                return response.json();
            })
            .then(function(geojson){
                if(seq !== boundarySeq || !map || !geojson){
                    return;
                }
                boundaryLayer = L.geoJSON(geojson, {
                    style: { color: '#0091ea', weight: 1, fillColor: '#80d8ff', fillOpacity: 0.4 }
                }).addTo(map);
                map.fitBounds(boundaryLayer.getBounds(), { padding: [20, 20] });
            })
            .catch(function(error){
                // DataV 未收录（如新设区县）或网络异常时降级为仅中心点标记，打印日志便于排查
                if(seq === boundarySeq){
                    boundaryLayer = null;
                }
                console.warn('[区划边界] 加载失败，code=' + code, error);
            });
    }

    /**
     * 在地图上定位所选区划：标记中心点 + 绘制行政边界
     *
     * @param {Object} region   区划记录（含 code/name/level/lon/lat）
     * @param {String} fullPath 完整路径，用于标记弹窗文案
     */
    function showOnMap(region, fullPath){
        if(!initMap()) return;
        var lat = parseFloat(region.lat);
        var lon = parseFloat(region.lon);
        // 左栏内容高度变化会带动地图容器尺寸变化，定位前先刷新一次
        map.invalidateSize();
        map.setView([lat, lon], 12, { animate: true });
        if(marker){
            map.removeLayer(marker);
        }
        marker = L.marker([lat, lon]).addTo(map)
            .bindPopup(fullPath)
            .openPopup();
        // 边界请求成功后会以 fitBounds 覆盖上面的中心点视图
        drawBoundary(region.code, region.level);
    }

    // 初始化
    loadProvinces();
    // 地图常驻显示：页面打开即渲染全国底图，选中区划后再定位与绘制边界
    if(hasGeo) initMap();
});
</script>
</body>
</html>
