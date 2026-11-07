<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>应用设置</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <style>
        .setting-container { width: 100%; }
        .setting-card { margin-bottom: 20px; }
        .layui-form-label { white-space: nowrap; }
        /* 当前生效状态：一行摘要，便于确认演示页会使用哪套地图 */
        .effective-box { padding: 12px 16px; border-radius: 6px; font-size: 13px; line-height: 1.8; }
        .effective-on { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .effective-off { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        /* 未注册配置组 / 关联应用不可用等阻断性提示 */
        .alert-box { padding: 12px 16px; border-radius: 6px; font-size: 13px; line-height: 1.8; margin-bottom: 15px; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .config-tips { color: #999; font-size: 12px; line-height: 1.7; }
        .key-status { color: #16b777; font-size: 12px; margin-right: 6px; }
        .key-missing { color: #ffb800; font-size: 12px; margin-right: 6px; }
        /* 关联「地图」应用时的只读状态表 */
        .maps-status { width: 100%; border-collapse: collapse; font-size: 13px; }
        .maps-status th, .maps-status td { padding: 8px 12px; border: 1px solid #f1f5f9; text-align: left; }
        .maps-status th { width: 140px; background: #f8fafc; color: #666; font-weight: normal; }
    </style>
</head>
<body>
<div class="pear-container setting-container">

    <div class="layui-card setting-card">
        <div class="layui-card-header">应用设置 · 地图配置</div>
        <div class="layui-card-body">
            <blockquote class="layui-elem-quote">
                本应用不内置任何地图密钥。密钥须由使用者自行到各厂商开放平台申请后填入；
                文本框直接显示已配置的密钥，修改时输入新值覆盖保存，清空文本框并保存即删除该密钥。
            </blockquote>

            @if(!$groupReady)
            <div class="alert-box alert-danger">
                <i class="fa fa-exclamation-circle"></i>
                地图配置组尚未注册（应用配置项由框架在安装 / 升级时按 manifest.json 写入）。
                请先到「应用管理」将本应用升级到 1.6.0 及以上版本，再返回本页保存设置；升级前点击「保存设置」会弹出该原因。
            </div>
            @endif

            {{-- 当前生效配置摘要 --}}
            <div class="effective-box {{ $effective['configured'] ? 'effective-on' : 'effective-off' }}">
                <i class="fa fa-{{ $effective['configured'] ? 'check-circle' : 'info-circle' }}"></i>
                @if($effective['configured'])
                    当前生效：{{ ['tencent' => '腾讯地图', 'amap' => '高德地图', 'baidu' => '百度地图'][$effective['provider']] ?? $effective['provider'] }}
                    （来源：{{ $effective['source'] === 'own' ? '本应用独立配置' : '关联「地图」应用' }}，坐标系：{{ strtoupper(str_replace('0', '-0', $effective['coordinate_system'])) }}），
                    数据演示页将使用该厂商官方 JS API 渲染底图、定位与行政边界。
                @else
                    当前未启用厂商地图：{{ $effective['message'] }}。
                @endif
            </div>
        </div>
    </div>

    <form class="layui-form setting-card" lay-filter="settingForm">
        <div class="layui-card">
            <div class="layui-card-header">配置来源</div>
            <div class="layui-card-body">
                <div class="layui-form-item">
                    <label class="layui-form-label">地图配置来源</label>
                    <div class="layui-input-block">
                        <input type="radio" name="map_source" value="none" title="不启用（Leaflet + 高德瓦片）" lay-filter="mapSource" @checked(($values['map_source'] ?? 'none') === 'none')>
                        <input type="radio" name="map_source" value="own" title="本应用独立配置" lay-filter="mapSource" @checked(($values['map_source'] ?? 'none') === 'own')>
                        <input type="radio" name="map_source" value="maps" title="关联「地图」应用配置" lay-filter="mapSource" @checked(($values['map_source'] ?? 'none') === 'maps')>
                        <div class="config-tips">
                            「不启用」时数据演示页沿用 Leaflet + 高德瓦片底图；「独立配置」使用下方本应用填写的厂商与密钥；
                            「关联地图应用」跟随「地图」应用（CmsproMaps）的厂商与前端密钥，该应用改配置后本页无需重复维护。
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 独立配置：厂商 + 该厂商所需的前端密钥（默认全部隐藏，由 JS 按所选厂商显示） --}}
        <div class="layui-card" id="ownPanel" style="display:none;">
            <div class="layui-card-header">独立地图配置</div>
            <div class="layui-card-body">
                <div class="layui-form-item">
                    <label class="layui-form-label" for="map_provider">地图厂商</label>
                    <div class="layui-input-block">
                        <select name="map_provider" id="map_provider" lay-filter="mapProvider">
                            <option value="baidu" @selected(($values['map_provider'] ?? 'baidu') === 'baidu')>百度地图</option>
                            <option value="amap" @selected(($values['map_provider'] ?? 'baidu') === 'amap')>高德地图</option>
                            <option value="tencent" @selected(($values['map_provider'] ?? 'baidu') === 'tencent')>腾讯地图</option>
                        </select>
                        <div class="config-tips">选择哪个厂商就只显示该厂商需要填写的密钥项；已填写的其他厂商密钥仍会保留并一并提交，切换厂商无需重新申请。</div>
                    </div>
                </div>

                <div class="layui-form-item provider-field" data-provider="baidu" style="display:none;">
                    <label class="layui-form-label" for="baidu_js_ak">百度 JS AK</label>
                    <div class="layui-input-block">
                        <input type="text" name="baidu_js_ak" id="baidu_js_ak" value="{{ $values['baidu_js_ak'] ?? '' }}" class="layui-input" autocomplete="off" placeholder="请输入百度地图 JS API AK">
                        <div class="config-tips">
                            @if($hasKeys['baidu_js_ak'] ?? false)<span class="key-status">已配置</span>@else<span class="key-missing">未配置</span>@endif
                            申请入口：lbsyun.baidu.com → 控制台 → 应用类型选「浏览器端」并开启 JavaScript GL，同时配置 Referer 白名单。
                        </div>
                    </div>
                </div>

                <div class="layui-form-item provider-field" data-provider="amap" style="display:none;">
                    <label class="layui-form-label" for="amap_js_key">高德 JS Key</label>
                    <div class="layui-input-block">
                        <input type="text" name="amap_js_key" id="amap_js_key" value="{{ $values['amap_js_key'] ?? '' }}" class="layui-input" autocomplete="off" placeholder="请输入高德地图 JS API Key">
                        <div class="config-tips">
                            @if($hasKeys['amap_js_key'] ?? false)<span class="key-status">已配置</span>@else<span class="key-missing">未配置</span>@endif
                            申请入口：lbs.amap.com → 控制台 → 应用类型选「Web端(JS API)」，并配置域名白名单。
                        </div>
                    </div>
                </div>

                <div class="layui-form-item provider-field" data-provider="amap" style="display:none;">
                    <label class="layui-form-label" for="amap_security_code">高德安全密钥</label>
                    <div class="layui-input-block">
                        <input type="text" name="amap_security_code" id="amap_security_code" value="{{ $values['amap_security_code'] ?? '' }}" class="layui-input" autocomplete="off" placeholder="请输入 securityJsCode">
                        <div class="config-tips">
                            @if($hasKeys['amap_security_code'] ?? false)<span class="key-status">已配置</span>@else<span class="key-missing">未配置</span>@endif
                            高德 JS API 2.0 强制要求安全密钥，不填将报 INVALID_USER_SCODE。
                        </div>
                    </div>
                </div>

                <div class="layui-form-item provider-field" data-provider="tencent" style="display:none;">
                    <label class="layui-form-label" for="tencent_js_key">腾讯 JS Key</label>
                    <div class="layui-input-block">
                        <input type="text" name="tencent_js_key" id="tencent_js_key" value="{{ $values['tencent_js_key'] ?? '' }}" class="layui-input" autocomplete="off" placeholder="请输入腾讯地图 JS API Key">
                        <div class="config-tips">
                            @if($hasKeys['tencent_js_key'] ?? false)<span class="key-status">已配置</span>@else<span class="key-missing">未配置</span>@endif
                            申请入口：lbs.qq.com → 控制台 → 创建应用 → 添加 Key（勾选 Web端 JavaScript GL），并配置 Referer 白名单。
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 关联「地图」应用：只读展示当前关联结果 --}}
        <div class="layui-card" id="mapsPanel" style="display:none;">
            <div class="layui-card-header">关联「地图」应用</div>
            <div class="layui-card-body">
                <table class="maps-status">
                    <tr>
                        <th>地图应用状态</th>
                        <td>
                            @if($mapsStatus['installed'])
                                <span class="key-status">已安装并启用</span>
                            @else
                                <span class="key-missing">未安装或未启用</span>选择此来源时演示页会回退到 Leaflet + 高德瓦片底图。
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>跟随的地图厂商</th>
                        <td>{{ $mapsStatus['installed'] ? (['tencent' => '腾讯地图', 'amap' => '高德地图', 'baidu' => '百度地图'][$mapsStatus['provider']] ?? ($mapsStatus['provider'] ?: '未配置')) : '-' }}</td>
                    </tr>
                    <tr>
                        <th>前端密钥</th>
                        <td>
                            @if(!$mapsStatus['installed'])
                                -
                            @elseif($mapsStatus['configured'])
                                <span class="key-status">已配置</span>密钥由「地图」应用维护，本页不展示、也不下发到前端以外的位置。
                            @else
                                <span class="key-missing">未配置</span>请到「地图」应用的设置页填入该厂商的前端密钥。
                            @endif
                        </td>
                    </tr>
                </table>
                <div class="config-tips" style="margin-top:10px;">
                    关联模式下厂商与密钥完全取自「地图」应用；如需本应用使用不同厂商，请改选「本应用独立配置」。
                </div>
            </div>
        </div>

        <div class="layui-card">
            <div class="layui-card-body" style="text-align:right;">
                {{-- 配置组未注册时按钮仍可点击：点击后由后端返回明确原因，避免静默无响应 --}}
                <button type="submit" class="layui-btn layui-btn-normal" lay-submit lay-filter="save">
                    <i class="fa fa-save"></i> 保存设置
                </button>
            </div>
        </div>
    </form>

</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['form', 'layer', 'jquery'], function(){
    var layer = layui.layer;
    var form = layui.form;
    var $ = layui.jquery;
    var submitting = false;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: { 401: function(){ top.location.href = '/admin/login'; } }
    });

    // 配置来源切换：仅「独立配置」展示厂商与密钥，仅「关联地图应用」展示关联状态
    function togglePanels(source){
        $('#ownPanel').toggle(source === 'own');
        $('#mapsPanel').toggle(source === 'maps');
    }

    // 厂商切换：只显示所选厂商需要填写的密钥项（高德为 Key + 安全密钥两项）。
    // 其余厂商输入框仅隐藏、值仍留在表单中，提交时一并保存，避免切换厂商丢失已填密钥。
    function toggleProviderFields(provider){
        $('#ownPanel .provider-field').each(function(){
            $(this).toggle($(this).data('provider') === provider);
        });
    }

    togglePanels($('input[name="map_source"]:checked').val() || 'none');
    toggleProviderFields($('#map_provider').val() || 'baidu');

    form.on('radio(mapSource)', function(data){
        togglePanels(data.value);
    });

    form.on('select(mapProvider)', function(data){
        toggleProviderFields(data.value);
    });

    /** 解析后端 message；401 已由 statusCode 处理，避免重复弹窗。 */
    function showError(xhr){
        if(xhr && xhr.status === 401){ return; }
        var message = '请求失败，请稍后重试';
        try{
            var body = JSON.parse((xhr && xhr.responseText) || '{}');
            message = body.message || message;
        }catch(e){ /* 非 JSON 响应沿用默认提示 */ }
        layer.msg(message, { icon: 2 });
    }

    form.on('submit(save)', function(data){
        if(submitting){ return false; }
        submitting = true;
        var loadIndex = layer.load(2);

        $.ajax({
            url: '/api/admin/cmspro/region/setting',
            type: 'POST',
            data: data.field,
            success: function(res){
                layer.close(loadIndex);
                submitting = false;
                if(res.code === 0){
                    layer.msg(res.message || '保存成功', { icon: 1, time: 1200 }, function(){
                        // 重新加载以刷新「当前生效配置」摘要
                        location.reload();
                    });
                    return;
                }
                layer.msg(res.message || '保存失败', { icon: 2 });
            },
            error: function(xhr){
                layer.close(loadIndex);
                submitting = false;
                showError(xhr);
            }
        });
        return false;
    });
});
</script>
</body>
</html>
