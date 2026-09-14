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
        .demo-container { max-width: 900px; margin: 0 auto; }
        #geoMap{ z-index: 1; }
        .demo-card { margin-bottom: 20px; }
        .status-card { display: inline-block; width: 31%; margin-right: 2%; padding: 16px; border-radius: 8px; box-sizing: border-box; vertical-align: top; text-align: center; }
        .status-card:last-child { margin-right: 0; }
        .status-card .status-icon { font-size: 36px; margin-bottom: 8px; }
        .status-card .status-title { font-size: 14px; color: #666; margin-bottom: 4px; }
        .status-card .status-desc { font-size: 12px; color: #999; }
        .status-ok { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .status-ok .status-icon { color: #22c55e; }
        .status-missing { background: #fef3c7; border: 1px solid #fde68a; }
        .status-missing .status-icon { color: #f59e0b; }
        .cascader-area { padding: 20px; background: #f8fafc; border-radius: 6px; }
        .cascader-area select { width: 160px; height: 36px; padding: 0 10px; border: 1px solid #e2e8f0; border-radius: 4px; margin-right: 10px; margin-bottom: 10px; font-size: 14px; }
        .selected-path { margin-top: 10px; padding: 10px 14px; background: #eff6ff; border-radius: 4px; font-size: 14px; color: #1e40af; }
        .geo-info { margin-top: 15px; }
        .geo-info-row { display: flex; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .geo-info-row:last-child { border-bottom: none; }
        .geo-info-label { width: 100px; font-size: 13px; color: #666; }
        .geo-info-value { flex: 1; font-size: 14px; color: #333; }
        .geo-info-value .coord { font-family: 'Courier New', monospace; }
        .geo-map-wrap { margin-top: 15px; }
        #geoMap { height: 320px; border-radius: 6px; border: 1px solid #e2e8f0; }
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
        <div class="layui-card-body">
            <div class="status-card {{ $hasLevel3 ? 'status-ok' : 'status-missing' }}">
                <div class="status-icon"><i class="fa fa-{{ $hasLevel3 ? 'check-circle' : 'exclamation-triangle' }}"></i></div>
                <div class="status-title">三级区划数据</div>
                <div class="status-desc">{{ $hasLevel3 ? "省{$stats['province']} / 市{$stats['city']} / 区县{$stats['district']}" : '未导入' }}</div>
            </div>
            <div class="status-card {{ $hasStreet ? 'status-ok' : 'status-missing' }}">
                <div class="status-icon"><i class="fa fa-{{ $hasStreet ? 'check-circle' : 'exclamation-triangle' }}"></i></div>
                <div class="status-title">街道/乡镇数据</div>
                <div class="status-desc">{{ $hasStreet ? $stats['street'] . ' 条' : '未导入' }}</div>
            </div>
            <div class="status-card {{ $hasGeo ? 'status-ok' : 'status-missing' }}">
                <div class="status-icon"><i class="fa fa-{{ $hasGeo ? 'check-circle' : 'exclamation-triangle' }}"></i></div>
                <div class="status-title">地理位置数据</div>
                <div class="status-desc">{{ $hasGeo ? $geoStats['with_geo'] . ' 条含坐标' : '未导入' }}</div>
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
            <div class="cascader-area">
                <select id="provinceSelect"><option value="">请选择省份</option></select>
                <select id="citySelect"><option value="">请选择城市</option></select>
                <select id="districtSelect"><option value="">请选择区县</option></select>
                @if($hasStreet)
                <select id="streetSelect"><option value="">请选择街道/乡镇</option></select>
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

            {{-- 地图定位演示 --}}
            <div class="geo-map-wrap" id="geoMapWrap" style="display:none;">
                <div id="geoMap"></div>
            </div>
            <div class="empty-state" id="mapLoadFail" style="display:none;">
                <div class="empty-icon"><i class="fa fa-map-o"></i></div>
                <div class="empty-text">地图组件加载失败，请检查网络后刷新重试</div>
            </div>
            @else
            <div class="empty-state" style="margin-top:15px;">
                <div class="empty-icon"><i class="fa fa-map-marker"></i></div>
                <div class="empty-text">地理位置数据未导入，无法演示地址位置信息</div>
            </div>
            @endif
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
    var hasGeo = {{ $hasGeo ? 'true' : 'false' }};
    var levelNames = {1:'省/直辖市', 2:'市', 3:'区/县', 4:'街道/乡镇'};

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
            var provinceName = $('#provinceSelect option:selected').text();
            $('#selectedPath').text(provinceName);
            $('#geoInfo').hide();
            return;
        }
        var provinceName = $('#provinceSelect option:selected').text();
        var cityName = $('#citySelect option:selected').text();
        $('#selectedPath').text(provinceName + ' > ' + cityName);
        $('#geoInfo').hide();
        $('#geoMapWrap').hide();
        $.get('/api/admin/cmspro/region/children?code='+code, function(res){
            if(res.code !== 0) return;
            var html = '<option value="">请选择区县</option>';
            res.data.forEach(function(item){
                html += '<option value="'+item.code+'">'+item.name+'</option>';
            });
            $('#districtSelect').html(html);
            if(hasStreet) $('#streetSelect').html('<option value="">请选择街道/乡镇</option>');
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
            $('#selectedPath').text(provinceName + ' > ' + cityName);
            $('#geoInfo').hide();
            return;
        }

        $('#selectedPath').text(provinceName + ' > ' + cityName + ' > ' + districtName);

        // 查询地理位置信息
        if(hasGeo){
            loadGeoInfo(code);
        }

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

                    // 有坐标时在地图上定位
                    if(region.lon && region.lat){
                        showOnMap(parseFloat(region.lon), parseFloat(region.lat), fullPath);
                    } else {
                        $('#geoMapWrap').hide();
                    }
                }
            });
        });
    }

    // 地图定位（Leaflet + 高德瓦片，坐标为GCJ-02，与高德瓦片匹配）
    var map = null;
    var marker = null;

    function initMap(){
        if(typeof L === 'undefined'){
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

    function showOnMap(lon, lat, label){
        if(!initMap()) return;
        $('#geoMapWrap').show();
        // 容器从 display:none 变为可见后需刷新尺寸，否则地图渲染异常
        setTimeout(function(){
            map.invalidateSize();
            map.setView([lat, lon], 12, { animate: true });
            if(marker){
                map.removeLayer(marker);
            }
            marker = L.marker([lat, lon]).addTo(map)
                .bindPopup(label)
                .openPopup();
        }, 60);
    }

    // 初始化
    loadProvinces();
});
</script>
</body>
</html>
