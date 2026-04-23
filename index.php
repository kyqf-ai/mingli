<?php
/**
 * 紫微斗数单文件系统
 * 整合了所有PHP文件、HTML、CSS和JavaScript（除lunar.min.js外）
 * 功能完整保留：排盘计算、AI报告生成、移动端适配、三方四正查看等
 *
 * 2025-03-22 新增：来因宫 & 暗合宫 完整支持
 * - 来因宫显示于中宫，并写入AI报告
 * - 每宫暗合索引/宫职，命宫暗合特殊解读
 *
 * 优化更新：修复移动端菜单横竖屏失效、增加不存在闰月校验、优化选择按钮UI、AI报告增加年份
 */

// ============================================================
// 第一部分：PHP核心类定义
// ============================================================

// 错误控制
error_reporting(0);
ini_set('display_errors', 0);

// 检查是否请求API
if (isset($_GET['action']) && $_GET['action'] === 'api') {
    handleApiRequest();
    exit;
}

// 检查是否请求处理排盘
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    handleProcessRequest();
    exit;
}

// 如果不是API请求，显示主页面
displayMainPage();

// ============================================================
// 核心函数定义
// ============================================================

/**
 * 处理API请求（返回JSON数据）
 */
function handleApiRequest() {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        
        // 验证必需参数
        $required = ['year_gan', 'year_zhi', 'hour_gan', 'hour_zhi', 'lunar_month', 'lunar_day'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                throw new Exception("缺少必需参数: $field");
            }
        }
        
        // 使用DateTimeHandler处理数据
        $handler = new DateTimeHandler($input);
        $ziwei = new ZiWei($handler->getPanData());
        $result = $ziwei->calculate();
        $displayData = $handler->getDisplayData();
        
        $palacesRaw = $result['palaces'];
        $infoRaw = $result['info'];
        
        // 构建响应数据
        $response = [
            'success' => true,
            'basic' => [
                'name' => $displayData['name'] ?? '命主',
                'gender' => (($displayData['sex'] ?? 1) == 1) ? '男' : '女',
                'bazi' => $displayData['bazi_str'] ?? '',
                'ming_ju' => $infoRaw['bureau'] ?? '',
                'ming_zhu' => $infoRaw['ming_zhu'] ?? '',
                'shen_zhu' => $infoRaw['shen_zhu'] ?? '',
                'ming_gong' => $infoRaw['ming_gong'] ?? '',
                'shen_gong' => $infoRaw['shen_gong'] ?? '',
                'birth_info' => $displayData ?? []
            ],
            'palaces' => [],
            'info' => [
                'ming_gong_index' => $infoRaw['ming_gong_index'] ?? 0,
                'shen_gong_index' => $infoRaw['shen_gong_index'] ?? 0,
                // ----- 新增：来因宫 -----
                'lai_yin' => $infoRaw['lai_yin'] ?? null
            ]
        ];
        
        // 辅助函数：计算三方四正
        function getSanFangSiZheng($idx) {
            $indices = [];
            $indices[] = $idx;              // 本宫
            $indices[] = ($idx + 4) % 12;   // 三合1
            $indices[] = ($idx + 8) % 12;   // 三合2
            $indices[] = ($idx + 6) % 12;   // 对宫
            return array_unique($indices);
        }
        
        $fu_xing_list = ['文昌', '文曲', '左辅', '右弼', '天魁', '天钺', '禄存', '天马', '擎羊', '陀罗', '火星', '铃星', '地空', '地劫'];
        
        foreach ($palacesRaw as $p) {
            $major = []; $minor = []; $extra = []; 
            $borrowed = []; // 借星
            $changsheng = []; // 长生十二神
            
            // 提取长生十二神
            foreach ($p['main_stars'] as $s) {
                if (isset($s['type']) && $s['type'] === 'small-text') {
                    $changsheng[] = $s['name'];
                }
            }
            
            // 检查是否空宫
            $hasMajor = false;
            foreach ($p['main_stars'] as $s) {
                if (isset($s['type']) && $s['type'] == 'major') {
                    $hasMajor = true;
                    break;
                }
            }
            
            // 空宫借星
            if (!$hasMajor) {
                $oppIdx = ($p['index'] + 6) % 12;
                $oppStars = $palacesRaw[$oppIdx]['main_stars'] ?? [];
                foreach ($oppStars as $os) {
                    if (isset($os['type']) && $os['type'] == 'major') {
                        $borrowed[] = [
                            'name' => $os['name'] ?? '',
                            'brightness' => $os['brightness'] ?? '',
                            'sihua' => $os['sihua'] ?? '',
                            'type' => 'major',
                            'is_borrowed' => true
                        ];
                    }
                }
            }
            
            // 分类星曜
            foreach ($p['main_stars'] as $s) {
                // 跳过长生神
                if (isset($s['type']) && $s['type'] === 'small-text') continue;
                
                $item = [
                    'name' => $s['name'] ?? '',
                    'brightness' => $s['brightness'] ?? '',
                    'sihua' => $s['sihua'] ?? '',
                    'type' => $s['type'] ?? 'minor'
                ];
                
                if (isset($s['type']) && $s['type'] == 'major') {
                    $major[] = $item;
                } elseif (in_array($s['name'] ?? '', $fu_xing_list) || (isset($s['type']) && ($s['type'] == 'ji' || $s['type'] == 'sha'))) {
                    $minor[] = $item;
                } else {
                    $extra[] = $item;
                }
            }
            
            // 宫位数据结构
            $palaceData = [
                'index' => $p['index'],
                'pos' => $p['gan'] . $p['zhi'],
                'name' => $p['name'],
                'is_ming' => $p['is_ming'],
                'is_shen' => $p['is_shen'],
                'daxian' => $p['daxian'],
                'stars' => [
                    'major' => $major,
                    'minor' => $minor,
                    'extra' => $extra,
                    'borrowed' => $borrowed
                ],
                'ages' => $p['ages'] ?? '',
				'liu_nian_ages' => $p['liu_nian_ages'] ?? '',
                'gods' => [
                    'cs' => implode('、', $changsheng),
                    'boshi' => $p['boshi'] ?? '',
                    'suijian' => $p['suijian'] ?? '',
                    'jiangxing' => $p['jiangxing'] ?? ''
                ],
                'sanfang_indices' => getSanFangSiZheng($p['index']),
                // ----- 新增：暗合宫 -----
                'an_he_index' => $p['an_he_index'] ?? -1,
                'an_he_gong' => $p['an_he_gong'] ?? ''
            ];
            
            $response['palaces'][] = $palaceData;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

/**
 * 处理排盘请求（返回HTML命盘）
 */
function handleProcessRequest() {
    try {
        // 预处理数据
        $dateHandler = new DateTimeHandler($_POST);
        $panData = $dateHandler->getPanData();
        $displayInfo = $dateHandler->getDisplayData();
        
        // 核心排盘计算
        $ziwei = new ZiWei($panData);
        $result = $ziwei->calculate();
        
        $palaces = $result['palaces'];
        $info = $result['info'];
        
        // 准备渲染辅助变量
        $shapeSetting = $_POST['shape_setting'] ?? 'square';
        $shapeClass = ($shapeSetting === 'round') ? 'shape-round' : 'shape-square';
        
        // 12宫格布局映射
        $gridMap = [
            '0-0' => 3, '0-1' => 4, '0-2' => 5, '0-3' => 6,
            '1-0' => 2, '1-3' => 7,
            '2-0' => 1, '2-3' => 8,
            '3-0' => 0, '3-1' => 11, '3-2' => 10, '3-3' => 9
        ];
        
        // 渲染命盘
        renderPanGrid($palaces, $info, $displayInfo, $gridMap, $shapeClass);
        
    } catch (Exception $e) {
        echo "<div class='error'>排盘出错：" . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

/**
 * 渲染星曜
 */
function renderStar($star) {
    $className = 'star ' . $star['type'];
    $style = $star['style'] ?? '';
    
    // 四化处理
    $sihuaClass = $star['sihua'] ?? '';
    if ($sihuaClass) $className .= ' sihua-' . $sihuaClass;
    
    echo "<span class='{$className}' style='{$style}'>";
    echo "<div class='star-name'>";
    
    $name = $star['name'];
    $len = mb_strlen($name, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        echo "<span>" . mb_substr($name, $i, 1, 'UTF-8') . "</span>";
    }
    
    echo "</div>";
    
    if (!empty($star['brightness']) && $star['brightness'] !== '-') {
        echo "<div class='star-brightness'>" . htmlspecialchars($star['brightness']) . "</div>";
    }
    
    if ($sihuaClass) {
        $map = ['禄' => '禄', '权' => '权', '科' => '科', '忌' => '忌'];
        echo "<div class='star-sihua'>" . ($map[$sihuaClass] ?? '') . "</div>";
    }
    
    echo "</span>";
}

/**
 * 渲染中宫单元格
 */
function renderCenterCell($data, $info) {
    $name = $data['name'];
    $sexText = ($data['sex'] == 1) ? '男' : '女';
    $yearGan = $data['year_gan'];
    $isYang = in_array($yearGan, ['甲','丙','戊','庚','壬']);
    $yinYang = $isYang ? '阳' : '阴';
    
    $dateTypeLabel = ($data['date_type'] === 'lunar') ? '公历生日：' : '公历生日：';
    $dateVal = $data['solar_date'];
    
    $lunarStr = "农历：" . ($data['traditional_lunar_date'] ?? '');
    
    $baziRaw = $data['bazi_str'];
    $cleanBazi = preg_replace('/\s*\([^)]*\)/', '', $baziRaw);
    $baziParts = explode(' ', $cleanBazi);
    $baziCols = array_slice($baziParts, 0, 4);
    
    echo '<div class="center-cell" data-index="-1">';
    echo "<h2 class='cc-name'>{$name}</h2>";
    echo "<div class='cc-row cc-meta'>";
    echo "<span>{$yinYang}{$sexText}</span>";
    echo "<span class='divider'>|</span><span>{$data['zodiac']}</span>";
    echo "<span class='divider'>|</span><span>{$data['age']}</span>";
    echo "</div>";
    
    echo "<div class='cc-row cc-specs'>";
    echo "<span>{$info['bureau']}</span>";
    echo "<span class='divider'>•</span>";
    echo "<span>{$info['ziwei_pos']}</span>";
    echo "</div>";
    
    echo "<div class='cc-bazi-grid'>";
    $labels = ['年', '月', '日', '时'];
    foreach ($baziCols as $idx => $gz) {
        $label = $labels[$idx] ?? '';
        $val = mb_substr($gz, 0, 2); 
        echo "<div class='bazi-col'><span class='bz-label'>{$label}</span><span class='bz-val'>{$val}</span></div>";
    }
    echo "</div>";
    
    echo "<div class='cc-birth-check'>{$dateTypeLabel} {$dateVal}</div>";
    echo "<div class='cc-habit-lunar'>{$lunarStr}</div>";
    
    $mz = str_replace('命主：', '', $info['ming_zhu']);
    $sz = str_replace('身主：', '', $info['shen_zhu']);
    echo "<div class='cc-row cc-owners'>";
    echo "<span class='owner-item'><span class='ol'>命主</span><span class='ov'>{$mz}</span></span>";
    echo "<span class='owner-item'><span class='ol'>身主</span><span class='ov'>{$sz}</span></span>";
    echo "</div>";
    
    // ----- 新增：显示来因宫 -----
    if (!empty($info['lai_yin']['gong'])) {
        $laiYinGong = $info['lai_yin']['gong'];
        $laiYinZhi = $info['lai_yin']['zhi'];
        $laiYinGan = $info['lai_yin']['gan'];
        echo "<div class='cc-row cc-laiyin'>";
        echo "<span class='laiyin-label'><i class='fas fa-seedling'></i> 来因宫</span>";
        echo "<span class='laiyin-value'>{$laiYinGong} ({$laiYinGan}{$laiYinZhi})</span>";
        echo "</div>";
    }
    
    if ($data['is_late_zi']) {
        echo "<div class='cc-leap-note'><i class='fas fa-clock'></i> 晚子时 (日干支算次日)</div>";
    }
    
    echo '</div>';
}

/**
 * 渲染宫位单元格
 */
function renderPalaceCell($gong) {
    echo '<div class="cell" data-index="' . $gong['index'] . '" data-gong-name="' . htmlspecialchars($gong['name']) . '" data-pos="' . htmlspecialchars($gong['gan'] . $gong['zhi']) . '" data-daxian="' . ($gong['daxian'] ?? '') . '">';
    
    // 头部：宫名
    echo '<div class="gong-header">';
    echo "<div class='gong-name'>";
    echo htmlspecialchars($gong['name']);
    if ($gong['is_ming']) echo "<span class='tag ming-tag'>(命)</span>";
    if ($gong['is_shen']) echo "<span class='tag shen-tag'>(身)</span>";
    echo "</div>";
    
    // 长生十二神
    echo '<div class="changsheng-container">';
    foreach ($gong['main_stars'] as $star) {
        if ($star['type'] === 'small-text') {
            echo "<span class='changsheng-item'>" . htmlspecialchars($star['name']) . "</span>";
        }
    }
    echo '</div>';
    echo '</div>';
    
    // 中部：星曜
    echo '<div class="stars-container">';
    
    $stars = array_filter($gong['main_stars'], function($s) {
        return $s['type'] !== 'small-text';
    });
    
    usort($stars, function($a, $b) {
        $order = ['major' => 1, 'ji' => 2, 'sha' => 3, 'peach' => 4, 'luck' => 5, 'bad' => 6, 'minor' => 99];
        $oa = $order[$a['type']] ?? 50;
        $ob = $order[$b['type']] ?? 50;
        return $oa - $ob;
    });
    
    foreach ($stars as $star) {
        renderStar($star);
    }
    echo '</div>';
    
    // ===== 新增：流年与小限 =====
    if (!empty($gong['liu_nian_ages'])) {
        echo '<div class="gong-liunian">流年: ' . htmlspecialchars($gong['liu_nian_ages']) . '</div>';
    }
    if (!empty($gong['ages'])) {
        echo '<div class="gong-ages">小限: ' . htmlspecialchars($gong['ages']) . '</div>';
    }
    
    // 底部：神煞 - 横排显示
    if (!empty($gong['boshi']) || !empty($gong['suijian']) || !empty($gong['jiangxing'])) {
        echo '<div class="gong-shensha">';
        if ($gong['boshi']) {
            echo "<span class='shensha-item boshi-group' title='博士'>" . htmlspecialchars($gong['boshi']) . "</span>";
        }
        if ($gong['jiangxing']) {
            echo "<span class='shensha-item jiang-group' title='将星'>" . htmlspecialchars($gong['jiangxing']) . "</span>";
        }
        if ($gong['suijian']) {
            echo "<span class='shensha-item suijian-group' title='岁建'>" . htmlspecialchars($gong['suijian']) . "</span>";
        }
        echo '</div>';
    }
    
    // 底部：干支与大限
    echo '<div class="gong-footer">';
    echo "<div class='gong-gz'>" . htmlspecialchars($gong['gan'] . $gong['zhi']) . "</div>";
    if ($gong['daxian']) {
        echo "<div class='gong-daxian'>" . $gong['daxian'] . "</div>";
    }
    echo '</div>';
    
    echo '</div>';
}

/**
 * 渲染整个命盘网格
 */
function renderPanGrid($palaces, $info, $displayInfo, $gridMap, $shapeClass) {
    ?>
    <div class="pan-grid-container">
        <div class="pan-grid <?php echo $shapeClass; ?>" id="panGrid">
    <?php
    for ($r = 0; $r < 4; $r++) {
        for ($c = 0; $c < 4; $c++) {
            // 中宫合并
            if (($r == 1 || $r == 2) && ($c == 1 || $c == 2)) {
                if ($r == 1 && $c == 1) {
                    renderCenterCell($displayInfo, $info);
                }
                continue;
            }
            
            $key = "$r-$c";
            if (isset($gridMap[$key])) {
                $idx = $gridMap[$key];
                $gong = $palaces[$idx];
                renderPalaceCell($gong);
            }
        }
    }
    ?>
        </div>
        
        <!-- 移动端帮助面板（简化版） -->
        <div class="mobile-help-panel" id="mobileHelpPanel">
            <div class="help-content">
                <h3><i class="fas fa-info-circle"></i> 使用说明</h3>
                <ul>
                    <li><strong>查看三方四正</strong>：点击任意宫位，其三方四正宫位会高亮显示</li>
                    <li><strong>复制星曜</strong>：长按星曜名称即可复制</li>
                    <li><strong>切换风格</strong>：侧边菜单可选择圆形/方形宫格</li>
                </ul>
                <button class="close-help-btn">我知道了</button>
            </div>
        </div>
    </div>
    <?php
}

/**
 * 显示主页面
 */
function displayMainPage() {
    // 设置默认时间
    $now = date('Y-m-d\TH:i');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#b71c1c">
    <title>紫微斗数排盘系统 - 专业命理分析</title>
    <script src="lunar.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        <?php echo getCSS(); ?>
    </style>
</head>
<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <div class="wrapper">
        <!-- 侧边栏：输入表单 -->
        <div class="sidebar open" id="sidebar">
            <div class="sidebar-header">
                <h2>紫微斗数排盘</h2>
                <button class="close-sidebar-btn" id="closeSidebarBtn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="mainForm">
                <div class="form-item">
                    <label>姓名</label>
                    <input type="text" name="name" value="命主" placeholder="请输入姓名">
                    <div class="form-note">仅用于显示，不影响排盘</div>
                </div>

                <div class="form-item">
                    <label>性别</label>
                    <select name="sex">
                        <option value="1">男（乾造）</option>
                        <option value="0">女（坤造）</option>
                    </select>
                </div>

                <div class="form-item">
                    <label>日期类型</label>
                    <select id="dateType" name="date_type" onchange="toggleDateType()">
                        <option value="solar">公历（阳历）</option>
                        <option value="lunar">农历（阴历）</option>
                    </select>
                </div>

                <div class="form-item">
                    <label>出生日期时间</label>
                    <input type="datetime-local" id="birth_datetime" name="birth_datetime" required value="<?php echo $now; ?>">
                    <div class="form-hint">
                        <i class="fas fa-clock"></i> 请自己查询真太阳时输入会更准。
                    </div>
                </div>

                <div class="form-item" id="leapMonthContainer" style="display: none;">
                    <label class="checkbox-label">
                        <input type="checkbox" id="isLeapMonth" name="is_leap_month" value="1">
                        <span>是闰月</span>
                    </label>
                    <div class="form-note">仅当农历输入且为闰月时勾选</div>
                </div>

                <div class="form-item">
                    <label>子时处理 (23:00-01:00)</label>
                    <select id="zi_shi_method" name="zi_shi_method">
                        <option value="auto">自动 (23点后算次日干支)</option>
                        <option value="early">早子时 (23点后仍算当日)</option>
                    </select>
                    <div class="form-note">紫微斗数传统多采用晚子时规则</div>
                </div>

                <div class="form-item">
                    <label>排盘风格</label>
                    <div class="radio-group inline">
                        <label class="radio-label">
                            <input type="radio" name="shape_setting" value="round" onchange="updateShape()">
                            <span>圆形</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="shape_setting" value="square" checked onchange="updateShape()">
                            <span>方形</span>
                        </label>
                    </div>
                </div>

                <!-- 关键：隐藏域，用于传输前端计算好的标准数据给后端 -->
                <input type="hidden" name="year_gan" id="year_gan">
                <input type="hidden" name="year_zhi" id="year_zhi">
                <input type="hidden" name="hour_gan" id="hour_gan">
                <input type="hidden" name="hour_zhi" id="hour_zhi">
                <input type="hidden" name="lunar_month" id="lunar_month">
                <input type="hidden" name="lunar_day" id="lunar_day">
                <input type="hidden" name="bazi_str" id="bazi_str">
                <input type="hidden" name="birth_date" id="birth_date">
                <input type="hidden" name="is_late_zi" id="is_late_zi">
                
                <div class="button-container">
                    <button type="button" class="submit-btn" id="submitBtn" onclick="generateChart()">
                        <i class="fas fa-calculator"></i> 生成命盘
                    </button>
                    <button type="button" class="submit-btn ai-report-btn" onclick="getTextReport()">
                        <i class="fas fa-robot"></i> 生成AI解读文本
                    </button>
                </div>             
            </form>
        </div>

        <!-- 主内容区：排盘结果 -->
        <div id="panResult" class="main-content">
            <div class="loading">
                <div style="font-size: 48px; margin-bottom: 20px; color: var(--accent);">
                    <i class="fas fa-yin-yang"></i>
                </div>
                <h3 style="margin-bottom: 10px;">紫微斗数排盘系统</h3>
                <p>请填写左侧信息并点击"生成命盘"</p>
                <p style="font-size: 14px; color: #888; margin-top: 20px;">
                    <i class="fas fa-mobile-alt"></i> 移动端已优化，点击宫位查看三方四正
                </p>
            </div>
        </div>
    </div>
    
    <script>
        <?php echo getJavaScript(); ?>
    </script>
</body>
</html>
    <?php
}

// ============================================================
// 第二部分：核心PHP类定义
// ============================================================

/**
 * 紫微斗数基础数据类
 */
class ZiWeiData
{
    public static $DI_ZHI = ['子', '丑', '寅', '卯', '辰', '巳', '午', '未', '申', '酉', '戌', '亥'];
    public static $SHI_ER_GONG = ['兄弟', '夫妻', '子女', '财帛', '疾厄', '迁移', '交友', '官禄', '田宅', '福德', '父母'];
    public static $NA_YIN = [
        '甲子' => '海中金', '乙丑' => '海中金', '丙寅' => '炉中火', '丁卯' => '炉中火',
        '戊辰' => '大林木', '己巳' => '大林木', '庚午' => '路旁土', '辛未' => '路旁土',
        '壬申' => '剑锋金', '癸酉' => '剑锋金', '甲戌' => '山头火', '乙亥' => '山头火',
        '丙子' => '涧下水', '丁丑' => '涧下水', '戊寅' => '城头土', '己卯' => '城头土',
        '庚辰' => '白蜡金', '辛巳' => '白蜡金', '壬午' => '杨柳木', '癸未' => '杨柳木',
        '甲申' => '泉中水', '乙酉' => '泉中水', '丙戌' => '屋上土', '丁亥' => '屋上土',
        '戊子' => '霹雳火', '己丑' => '霹雳火', '庚寅' => '松柏木', '辛卯' => '松柏木',
        '壬辰' => '长流水', '癸巳' => '长流水', '甲午' => '砂中金', '乙未' => '砂中金',
        '丙申' => '山下火', '丁酉' => '山下火', '戊戌' => '平地木', '己亥' => '平地木',
        '庚子' => '壁上土', '辛丑' => '壁上土', '壬寅' => '金箔金', '癸卯' => '金箔金',
        '甲辰' => '佛灯火', '乙巳' => '佛灯火', '丙午' => '天河水', '丁未' => '天河水',
        '戊申' => '大驿土', '己酉' => '大驿土', '庚戌' => '钗钏金', '辛亥' => '钗钏金',
        '壬子' => '桑柘木', '癸丑' => '桑柘木', '甲寅' => '大溪水', '乙卯' => '大溪水',
        '丙辰' => '沙中土', '丁巳' => '沙中土', '戊午' => '天上火', '己未' => '天上火',
        '庚申' => '石榴木', '辛酉' => '石榴木', '壬戌' => '大海水', '癸亥' => '大海水'
    ];
    public static $LU_CUN = ['甲'=>'寅', '乙'=>'卯', '丙'=>'巳', '丁'=>'午', '戊'=>'巳', '己'=>'午', '庚'=>'申', '辛'=>'酉', '壬'=>'亥', '癸'=>'子'];
    public static $TIAN_GUAN = ['甲'=>'未', '乙'=>'辰', '丙'=>'巳', '丁'=>'寅', '戊'=>'卯', '己'=>'酉', '庚'=>'亥', '辛'=>'酉', '壬'=>'戌', '癸'=>'午'];
    public static $TIAN_FU = ['甲'=>'酉', '乙'=>'申', '丙'=>'子', '丁'=>'亥', '戊'=>'卯', '己'=>'寅', '庚'=>'午', '辛'=>'巳', '壬'=>'午', '癸'=>'巳'];
    public static $TIAN_CHU = ['甲'=>'巳', '乙'=>'午', '丙'=>'子', '丁'=>'巳', '戊'=>'午', '己'=>'申', '庚'=>'寅', '辛'=>'午', '壬'=>'酉', '癸'=>'亥'];
    public static $HONG_YAN = ['甲'=>'午', '乙'=>'申', '丙'=>'寅', '丁'=>'未', '戊'=>'辰', '己'=>'辰', '庚'=>'戌', '辛'=>'酉', '壬'=>'子', '癸'=>'申'];
    public static $KUI_YUE = [
        '甲'=>['丑','未'], '戊'=>['丑','未'], '庚'=>['丑','未'],
        '乙'=>['子','申'], '己'=>['子','申'],
        '丙'=>['亥','酉'], '丁'=>['亥','酉'],
        '辛'=>['寅','午'],
        '壬'=>['卯','巳'], '癸'=>['卯','巳']
    ];
    public static $TIAN_DE = ['子'=>'酉', '丑'=>'戌', '寅'=>'亥', '卯'=>'子', '辰'=>'丑', '巳'=>'寅', '午'=>'卯', '未'=>'辰', '申'=>'巳', '酉'=>'午', '戌'=>'未', '亥'=>'申'];
    public static $YUE_DE = ['子'=>'巳', '丑'=>'午', '寅'=>'未', '卯'=>'申', '辰'=>'酉', '巳'=>'戌', '午'=>'亥', '未'=>'子', '申'=>'丑', '酉'=>'寅', '戌'=>'卯', '亥'=>'辰'];
    public static $GU_GUA = [
        '亥'=>['寅','戌'], '子'=>['寅','戌'], '丑'=>['寅','戌'],
        '寅'=>['巳','丑'], '卯'=>['巳','丑'], '辰'=>['巳','丑'],
        '巳'=>['申','辰'], '午'=>['申','辰'], '未'=>['申','辰'],
        '申'=>['亥','未'], '酉'=>['亥','未'], '戌'=>['亥','未']
    ];
    public static $TIAN_MA = ['申'=>'寅', '子'=>'寅', '辰'=>'寅', '寅'=>'申', '午'=>'申', '戌'=>'申', '巳'=>'亥', '酉'=>'亥', '丑'=>'亥', '亥'=>'巳', '卯'=>'巳', '未'=>'巳'];
    public static $FEI_LIAN = ['子'=>'申', '丑'=>'酉', '寅'=>'戌', '卯'=>'巳', '辰'=>'午', '巳'=>'未', '午'=>'寅', '未'=>'卯', '申'=>'辰', '酉'=>'亥', '戌'=>'子', '亥'=>'丑'];
    public static $PO_SUI = ['子'=>'巳', '丑'=>'丑', '寅'=>'酉', '卯'=>'巳', '辰'=>'丑', '巳'=>'酉', '午'=>'巳', '未'=>'丑', '申'=>'酉', '酉'=>'巳', '戌'=>'丑', '亥'=>'酉'];
    public static $NIAN_JIE = ['子'=>'戌', '丑'=>'酉', '寅'=>'申', '卯'=>'未', '辰'=>'午', '巳'=>'巳', '午'=>'辰', '未'=>'卯', '申'=>'寅', '酉'=>'丑', '戌'=>'子', '亥'=>'亥'];
    public static $JIE_SHA = ['申'=>'巳', '子'=>'巳', '辰'=>'巳', '寅'=>'亥', '午'=>'亥', '戌'=>'亥', '巳'=>'寅', '酉'=>'寅', '丑'=>'寅', '亥'=>'申', '卯'=>'申', '未'=>'申'];
    public static $LONG_DE = ['子'=>'未', '丑'=>'申', '寅'=>'酉', '卯'=>'戌', '辰'=>'亥', '巳'=>'子', '午'=>'丑', '未'=>'寅', '申'=>'卯', '酉'=>'辰', '戌'=>'巳', '亥'=>'午'];
    public static $MONTH_DA_HAO = [1=>'申', 2=>'酉', 3=>'戌', 4=>'亥', 5=>'子', 6=>'丑', 7=>'寅', 8=>'卯', 9=>'辰', 10=>'巳', 11=>'午', 12=>'未'];
    public static $TIAN_YUE = [1=>'戌', 2=>'巳', 3=>'辰', 4=>'寅', 5=>'未', 6=>'卯', 7=>'亥', 8=>'未', 9=>'寅', 10=>'午', 11=>'戌', 12=>'寅'];
    public static $TIAN_WU = [1=>'巳', 2=>'申', 3=>'寅', 4=>'亥', 5=>'巳', 6=>'申', 7=>'寅', 8=>'亥', 9=>'巳', 10=>'申', 11=>'寅', 12=>'亥'];
    public static $SI_HUA = [
        '甲' => ['廉贞禄', '破军权', '武曲科', '太阳忌'],
        '乙' => ['天机禄', '天梁权', '紫微科', '太阴忌'],
        '丙' => ['天同禄', '天机权', '文昌科', '廉贞忌'],
        '丁' => ['太阴禄', '天同权', '天机科', '巨门忌'],
        '戊' => ['贪狼禄', '太阴权', '右弼科', '天机忌'],
        '己' => ['武曲禄', '贪狼权', '天梁科', '文曲忌'],
        '庚' => ['太阳禄', '武曲权', '天同科', '太阴忌'],
        '辛' => ['巨门禄', '太阳权', '文曲科', '文昌忌'],
        '壬' => ['天梁禄', '紫微权', '左辅科', '武曲忌'],
        '癸' => ['破军禄', '巨门权', '太阴科', '贪狼忌']
    ];
    public static $BO_SHI_12 = ['博士', '力士', '青龙', '小耗', '将军', '奏书', '飞廉', '喜神', '病符', '大耗', '伏兵', '官府'];
    public static $SUI_JIAN_12 = ['岁建', '晦气', '丧门', '贯索', '官符', '小耗', '岁破', '龙德', '白虎', '天德', '吊客', '病符'];
    public static $JIANG_XING_12 = ['将星', '攀鞍', '岁驿', '息神', '华盖', '劫煞', '灾煞', '天煞', '指背', '咸池', '月煞', '亡神'];
    public static $CHANG_SHENG_12 = ['长生', '沐浴', '冠带', '临官', '帝旺', '衰', '病', '死', '墓', '绝', '胎', '养'];
    public static $JIE_KONG = [
        '甲'=>['申','酉'], '己'=>['申','酉'], '乙'=>['午','未'], '庚'=>['午','未'],
        '丙'=>['辰','巳'], '辛'=>['辰','巳'], '丁'=>['寅','卯'], '壬'=>['寅','卯'],
        '戊'=>['子','丑'], '癸'=>['子','丑'],
    ];
    public static $ZHU_XING_GUAN_XI = [
        '紫微' => ['平', '庙', '旺', '旺', '得', '旺', '庙', '庙', '旺', '旺', '得', '旺'],
        '天机' => ['庙', '陷', '得', '旺', '利', '平', '庙', '陷', '得', '旺', '利', '平'],
        '太阳' => ['陷', '不', '旺', '庙', '旺', '旺', '旺', '得', '得', '陷', '不', '陷'],
        '武曲' => ['旺', '庙', '得', '利', '庙', '平', '旺', '庙', '得', '利', '庙', '平'],
        '天同' => ['旺', '不', '利', '平', '平', '庙', '陷', '不', '旺', '平', '平', '庙'],
        '廉贞' => ['平', '利', '庙', '平', '利', '陷', '平', '利', '庙', '平', '利', '陷'],
        '天府' => ['庙', '庙', '庙', '得', '庙', '得', '旺', '庙', '得', '旺', '庙', '得'],
        '太阴' => ['庙', '庙', '旺', '陷', '陷', '陷', '不', '不', '利', '不', '旺', '庙'],
        '贪狼' => ['旺', '庙', '平', '利', '庙', '陷', '旺', '庙', '平', '利', '庙', '陷'],
        '巨门' => ['旺', '不', '庙', '庙', '陷', '旺', '旺', '不', '庙', '庙', '陷', '旺'],
        '天相' => ['庙', '庙', '庙', '陷', '得', '得', '庙', '得', '庙', '陷', '得', '得'],
        '天梁' => ['庙', '旺', '庙', '庙', '庙', '陷', '庙', '旺', '陷', '得', '庙', '陷'],
        '七杀' => ['旺', '庙', '庙', '旺', '庙', '平', '旺', '庙', '庙', '庙', '庙', '平'],
        '破军' => ['庙', '旺', '得', '陷', '旺', '平', '庙', '旺', '得', '陷', '旺', '平'],
    ];
    public static $MINOR_STAR_BRIGHTNESS = [
        '文昌' => ['得', '庙', '陷', '利', '得', '庙', '陷', '利', '得', '庙', '陷', '利'],
        '文曲' => ['得', '庙', '平', '旺', '得', '庙', '陷', '旺', '得', '庙', '陷', '旺'],
        '左辅' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙'],
        '右弼' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙'],
        '天魁' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙'],
        '天钺' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙'],
        '擎羊' => ['陷', '庙', '-', '陷', '庙', '-', '陷', '庙', '-', '陷', '庙', '-'],
        '陀罗' => ['-', '庙', '陷', '-', '庙', '陷', '-', '庙', '陷', '-', '庙', '陷'],
        '火星' => ['陷', '得', '庙', '利', '陷', '得', '庙', '利', '陷', '得', '庙', '利'],
        '铃星' => ['陷', '得', '庙', '利', '陷', '得', '庙', '利', '陷', '得', '庙', '利'],
        '地空' => ['陷', '陷', '陷', '陷', '陷', '庙', '陷', '陷', '陷', '陷', '陷', '庙'],
        '地劫' => ['陷', '陷', '陷', '陷', '陷', '庙', '陷', '陷', '陷', '陷', '陷', '庙'],
        '天姚' => ['陷', '陷', '陷', '庙', '陷', '陷', '利', '陷', '庙', '庙', '陷', '陷'],
        '红鸾' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙'],
        '天喜' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙'],
        '禄存' => ['庙', '旺', '庙', '庙', '旺', '庙', '庙', '旺', '庙', '庙', '旺', '庙'],
        '天马' => ['旺', '-', '旺', '-', '-', '旺', '-', '-', '旺', '-', '-', '-'],
        '天刑' => ['陷', '陷', '庙', '庙', '陷', '陷', '陷', '陷', '庙', '庙', '陷', '陷'],
        '天官' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙'],
        '天福' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙'],
        '解神' => ['庙', '利', '庙', '利', '庙', '利', '庙', '利', '庙', '利', '庙', '利'],
        '天巫' => ['庙', '平', '庙', '平', '庙', '平', '庙', '平', '庙', '平', '庙', '平'],
        '龙池' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙'],
        '凤阁' => ['庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙', '庙']
    ];
    public static $MING_ZHU = ['子'=>'贪狼', '丑'=>'巨门', '寅'=>'禄存', '卯'=>'文曲', '辰'=>'廉贞', '巳'=>'武曲', '午'=>'破军', '未'=>'武曲', '申'=>'廉贞', '酉'=>'文曲', '戌'=>'禄存', '亥'=>'巨门'];
    public static $SHEN_ZHU = ['子'=>'火星', '丑'=>'天相', '寅'=>'天梁', '卯'=>'天同', '辰'=>'文昌', '巳'=>'天机', '午'=>'火星', '未'=>'天相', '申'=>'天梁', '酉'=>'天同', '戌'=>'文昌', '亥'=>'天机'];

    // ----- 新增：来因宫与暗合宫 -----
    // 来因宫固定位置（五虎遁）
    public static $LAI_YIN_POSITION = [
        '甲' => '戌', '乙' => '酉', '丙' => '申', '丁' => '未',
        '戊' => '午', '己' => '巳', '庚' => '辰', '辛' => '卯',
        '壬' => '寅', '癸' => '亥'
    ];

    // 暗合宫映射表（基于zhiArray索引：0寅→9亥）
    // 六合关系：寅亥、卯戌、辰酉、巳申、午未、子丑
    public static $AN_HE_MAP = [
        0  => 9,  // 寅 → 亥 (索引9)
        1  => 8,  // 卯 → 戌 (索引8)
        2  => 7,  // 辰 → 酉 (索引7)
        3  => 6,  // 巳 → 申 (索引6)
        4  => 5,  // 午 → 未 (索引5)
        5  => 4,  // 未 → 午 (索引4)
        6  => 3,  // 申 → 巳 (索引3)
        7  => 2,  // 酉 → 辰 (索引2)
        8  => 1,  // 戌 → 卯 (索引1)
        9  => 0,  // 亥 → 寅 (索引0)
        10 => 11, // 子 → 丑 (索引11)
        11 => 10, // 丑 → 子 (索引10)
    ];
}

/**
 * 紫微斗数排盘引擎
 */
class ZiWei
{
    private $input;
    private $palaces = [];
    private $mingGongIdx = 0;
    private $shenGongIdx = 0;
    private $wuXingJu;
    private $mingZhu = '';
    private $shenZhu = '';
    private $ziweiStarIdx = 0;
    
    // ----- 新增：来因宫属性 -----
    private $laiYinGongIdx = null;
    private $laiYinGongName = '';
    private $laiYinGongGan = '';
    
    private $zhiMap = [
        '寅' => 0, '卯' => 1, '辰' => 2, '巳' => 3, 
        '午' => 4, '未' => 5, '申' => 6, '酉' => 7, 
        '戌' => 8, '亥' => 9, '子' => 10, '丑' => 11
    ];
    
    private $zhiArray = [
        '寅', '卯', '辰', '巳', '午', '未', 
        '申', '酉', '戌', '亥', '子', '丑'
    ];
    
    private $stdZhiMap = [
        '子' => 0, '丑' => 1, '寅' => 2, '卯' => 3, 
        '辰' => 4, '巳' => 5, '午' => 6, '未' => 7, 
        '申' => 8, '酉' => 9, '戌' => 10, '亥' => 11
    ];

    public function __construct($input)
    {
        $this->input = $input;
    }

    public function calculate()
    {
        $this->initPalaces();
        $this->arrangeMingShen();
        $this->calculateWuXingJu();
        $this->setGongNames();
        $this->arrangeMajorStars();
        $this->arrangeMinorStars();
        $this->arrangeMiscStars();
        $this->arrangeShenSha();
        $this->arrangeChangSheng();
        $this->applySiHua();
        $this->calculateDaXian();
        $this->calculateMingShenZhu();

        // ----- 新增：来因宫与暗合宫 -----
        $this->calculateLaiYinGong();
        $this->addAnHeToPalaces();
        $this->calculateAges();

        return [
            'palaces' => $this->palaces,
            'info' => [
                'bureau' => $this->getBureauName($this->wuXingJu),
                'ming_zhu' => $this->mingZhu,
                'shen_zhu' => $this->shenZhu,
                'ming_gong' => '命宫：' . $this->zhiArray[$this->mingGongIdx] . '宫',
                'shen_gong' => '身宫：' . $this->zhiArray[$this->shenGongIdx] . '宫',
                'ziwei_pos' => '紫微星：' . ($this->palaces[$this->ziweiStarIdx]['zhi'] ?? '') . '宫',
                'ming_gong_index' => $this->mingGongIdx,
                'shen_gong_index' => $this->shenGongIdx,
                // 来因宫信息
                'lai_yin' => [
                    'index' => $this->laiYinGongIdx,
                    'gong'  => $this->laiYinGongName,
                    'zhi'   => ($this->laiYinGongIdx !== null) ? $this->zhiArray[$this->laiYinGongIdx] : '',
                    'gan'   => $this->laiYinGongGan,
                ]
            ]
        ];
    }

    private function initPalaces()
    {
        $yearGan = $this->input['year_gan'];
        $tigerHeads = [
            '甲' => '丙', '己' => '丙', '乙' => '戊', '庚' => '戊',
            '丙' => '庚', '辛' => '庚', '丁' => '壬', '壬' => '壬',
            '戊' => '甲', '癸' => '甲'
        ];
        
        $startGan = $tigerHeads[$yearGan] ?? '丙';
        $ganOrder = ['甲', '乙', '丙', '丁', '戊', '己', '庚', '辛', '壬', '癸'];
        $startGanIdx = array_search($startGan, $ganOrder);

        for ($i = 0; $i < 12; $i++) {
            $ganIdx = ($startGanIdx + $i) % 10;
            $this->palaces[$i] = [
                'index' => $i,
                'zhi' => $this->zhiArray[$i],
                'gan' => $ganOrder[$ganIdx],
                'name' => '',
                'is_ming' => false,
                'is_shen' => false,
                'daxian' => '',
                'main_stars' => [],
                'boshi' => '',
                'suijian' => '',
                'jiangxing' => '',
                // 暗合宫占位，稍后填充
                'an_he_index' => -1,
                'an_he_gong' => ''
            ];
        }
    }

    /**
     * ----- 新增：计算来因宫 -----
     */
    private function calculateLaiYinGong() {
        $yearGan = $this->input['year_gan'];
        $zhi = ZiWeiData::$LAI_YIN_POSITION[$yearGan] ?? null;
        if (!$zhi) return;

        // 将地支转换为当前索引体系（zhiMap 寅→0）
        $zhiIndex = $this->zhiMap[$zhi] ?? null;
        if ($zhiIndex === null) return;

        $this->laiYinGongIdx = $zhiIndex;
        $this->laiYinGongName = $this->palaces[$zhiIndex]['name'] ?? '';
        $this->laiYinGongGan = $this->palaces[$zhiIndex]['gan'] ?? '';
    }

    /**
     * ----- 新增：为所有宫位添加暗合宫信息 -----
     */
    private function addAnHeToPalaces() {
        foreach ($this->palaces as &$palace) {
            $idx = $palace['index'];
            $anHeIdx = ZiWeiData::$AN_HE_MAP[$idx] ?? null;
            if ($anHeIdx !== null) {
                $palace['an_he_index'] = $anHeIdx;
                $palace['an_he_gong'] = $this->palaces[$anHeIdx]['name'] ?? '';
            }
        }
    }

    private function arrangeMingShen()
    {
        $month = (int)$this->input['lunar_month'];
        $hourZhi = $this->input['hour_zhi'];
        $hourStep = $this->stdZhiMap[$hourZhi];

        $mingIdx = ($month - 1) - $hourStep;
        $this->mingGongIdx = ($mingIdx % 12 + 12) % 12;

        $shenIdx = ($month - 1) + $hourStep;
        $this->shenGongIdx = ($shenIdx % 12 + 12) % 12;

        $this->palaces[$this->mingGongIdx]['is_ming'] = true;
        $this->palaces[$this->shenGongIdx]['is_shen'] = true;
    }

    private function calculateWuXingJu()
    {
        $mingGan = $this->palaces[$this->mingGongIdx]['gan'];
        $mingZhi = $this->palaces[$this->mingGongIdx]['zhi'];
        $key = $mingGan . $mingZhi;
        $naYin = ZiWeiData::$NA_YIN[$key] ?? '';
        
        if (mb_strpos($naYin, '金') !== false) {
            $this->wuXingJu = 4;
        } elseif (mb_strpos($naYin, '木') !== false) {
            $this->wuXingJu = 3;
        } elseif (mb_strpos($naYin, '水') !== false) {
            $this->wuXingJu = 2;
        } elseif (mb_strpos($naYin, '土') !== false) {
            $this->wuXingJu = 5;
        } elseif (mb_strpos($naYin, '火') !== false) {
            $this->wuXingJu = 6;
        } else {
            $this->wuXingJu = 2;
        }
    }

    private function setGongNames()
    {
        $names = ZiWeiData::$SHI_ER_GONG; 
        $this->palaces[$this->mingGongIdx]['name'] = '命宫';
        
        for ($i = 0; $i < 11; $i++) {
            $idx = ($this->mingGongIdx - 1 - $i + 12) % 12;
            $this->palaces[$idx]['name'] = $names[$i] . '宫';
        }
    }

    private function arrangeMajorStars()
    {
        $day = (int)$this->input['lunar_day'];
        $bureau = $this->wuXingJu;
        
        $this->ziweiStarIdx = $this->getZiWeiLocation($day, $bureau);
        $tianfuIndex = (12 - $this->ziweiStarIdx) % 12;

        $zwOffsets = [
            0 => '紫微', 11 => '天机', 9 => '太阳', 8 => '武曲', 
            7 => '天同', 4 => '廉贞'
        ];
        foreach ($zwOffsets as $offset => $star) {
            $this->addStar(($this->ziweiStarIdx + $offset) % 12, $star, 'major');
        }

        $tfOffsets = [
            0 => '天府', 1 => '太阴', 2 => '贪狼', 3 => '巨门',
            4 => '天相', 5 => '天梁', 6 => '七杀', 10 => '破军'
        ];
        foreach ($tfOffsets as $offset => $star) {
            $this->addStar(($tianfuIndex + $offset) % 12, $star, 'major');
        }
    }

    private function arrangeMinorStars()
    {
        $yearGan = $this->input['year_gan'];
        $yearZhi = $this->input['year_zhi'];
        $month = (int)$this->input['lunar_month'];
        $hourZhi = $this->input['hour_zhi'];
        $hStep = $this->stdZhiMap[$hourZhi];

        $luZhi = ZiWeiData::$LU_CUN[$yearGan] ?? null;
        if ($luZhi) {
            $luIdx = $this->zhiMap[$luZhi];
            $this->addStar($luIdx, '禄存', 'ji');
            $this->addStar(($luIdx + 1) % 12, '擎羊', 'sha');
            $this->addStar(($luIdx - 1 + 12) % 12, '陀罗', 'sha');
        }

        $changIdx = (8 - $hStep + 12) % 12;
        $quIdx = (2 + $hStep) % 12;
        $this->addStar($changIdx, '文昌', 'ji');
        $this->addStar($quIdx, '文曲', 'ji');

        $zuoIdx = (2 + ($month - 1)) % 12;
        $youIdx = (8 - ($month - 1) + 12) % 12;
        $this->addStar($zuoIdx, '左辅', 'ji');
        $this->addStar($youIdx, '右弼', 'ji');

        if (isset(ZiWeiData::$KUI_YUE[$yearGan])) {
            $kuiZhi = ZiWeiData::$KUI_YUE[$yearGan][0];
            $yueZhi = ZiWeiData::$KUI_YUE[$yearGan][1];
            $this->addStar($this->zhiMap[$kuiZhi], '天魁', 'ji');
            $this->addStar($this->zhiMap[$yueZhi], '天钺', 'ji');
        }

        $kongIdx = (9 - $hStep + 12) % 12;
        $jieIdx = (9 + $hStep) % 12;
        $this->addStar($kongIdx, '地空', 'sha');
        $this->addStar($jieIdx, '地劫', 'sha');

        $huoStart = 0; $lingStart = 0;
        if (in_array($yearZhi, ['寅','午','戌'])) { 
            $huoStart = 11; $lingStart = 1;
        } elseif (in_array($yearZhi, ['申','子','辰'])) { 
            $huoStart = 0; $lingStart = 8;
        } elseif (in_array($yearZhi, ['巳','酉','丑'])) { 
            $huoStart = 1; $lingStart = 8;
        } elseif (in_array($yearZhi, ['亥','卯','未'])) { 
            $huoStart = 7; $lingStart = 8;
        }
        $this->addStar(($huoStart + $hStep) % 12, '火星', 'sha');
        $this->addStar(($lingStart + $hStep) % 12, '铃星', 'sha');
        
        $maZhi = ZiWeiData::$TIAN_MA[$yearZhi] ?? null;
        if ($maZhi) {
            $this->addStar($this->zhiMap[$maZhi], '天马', 'ji');
        }
    }

    private function arrangeMiscStars()
    {
        $yearGan = $this->input['year_gan'];
        $yearZhi = $this->input['year_zhi'];
        $month = (int)$this->input['lunar_month'];
        $day = (int)$this->input['lunar_day'];
        $hStep = $this->stdZhiMap[$this->input['hour_zhi']];
        $yIdx = $this->stdZhiMap[$yearZhi];

        if(isset(ZiWeiData::$TIAN_GUAN[$yearGan])) $this->addStar($this->zhiMap[ZiWeiData::$TIAN_GUAN[$yearGan]], '天官', 'luck');
        if(isset(ZiWeiData::$TIAN_FU[$yearGan]))   $this->addStar($this->zhiMap[ZiWeiData::$TIAN_FU[$yearGan]], '天福', 'luck');
        if(isset(ZiWeiData::$TIAN_CHU[$yearGan]))  $this->addStar($this->zhiMap[ZiWeiData::$TIAN_CHU[$yearGan]], '天厨', 'luck');
        //红艳可能是不存在的星曜 if(isset(ZiWeiData::$HONG_YAN[$yearGan]))  $this->addStar($this->zhiMap[ZiWeiData::$HONG_YAN[$yearGan]], '红艳', 'peach');
        
        if(isset(ZiWeiData::$TIAN_DE[$yearZhi]))  $this->addStar($this->zhiMap[ZiWeiData::$TIAN_DE[$yearZhi]], '天德', 'luck');
        if(isset(ZiWeiData::$YUE_DE[$yearZhi]))   $this->addStar($this->zhiMap[ZiWeiData::$YUE_DE[$yearZhi]], '月德', 'luck');
        if(isset(ZiWeiData::$FEI_LIAN[$yearZhi])) $this->addStar($this->zhiMap[ZiWeiData::$FEI_LIAN[$yearZhi]], '蜚廉', 'bad');
        if(isset(ZiWeiData::$PO_SUI[$yearZhi]))   $this->addStar($this->zhiMap[ZiWeiData::$PO_SUI[$yearZhi]], '破碎', 'bad');
        if(isset(ZiWeiData::$NIAN_JIE[$yearZhi])) $this->addStar($this->zhiMap[ZiWeiData::$NIAN_JIE[$yearZhi]], '年解', 'luck');
        if(isset(ZiWeiData::$LONG_DE[$yearZhi]))  $this->addStar($this->zhiMap[ZiWeiData::$LONG_DE[$yearZhi]], '龙德', 'luck');
        if(isset(ZiWeiData::$JIE_SHA[$yearZhi]))  $this->addStar($this->zhiMap[ZiWeiData::$JIE_SHA[$yearZhi]], '劫煞', 'bad');

        if(isset(ZiWeiData::$GU_GUA[$yearZhi])) {
            $this->addStar($this->zhiMap[ZiWeiData::$GU_GUA[$yearZhi][0]], '孤辰', 'bad');
            $this->addStar($this->zhiMap[ZiWeiData::$GU_GUA[$yearZhi][1]], '寡宿', 'bad');
        }

        $nianDaHaoMap = [
            '子'=>'未', '丑'=>'午', '寅'=>'酉', '卯'=>'申', '辰'=>'亥', '巳'=>'戌', 
            '午'=>'丑', '未'=>'子', '申'=>'卯', '酉'=>'寅', '戌'=>'巳', '亥'=>'辰'
        ];
        if(isset($nianDaHaoMap[$yearZhi])) {
            $this->addStar($this->zhiMap[$nianDaHaoMap[$yearZhi]], '大耗', 'bad');
        }

        $huagaiMap = [
            '子'=>'辰','辰'=>'辰','申'=>'辰','丑'=>'丑','巳'=>'丑','酉'=>'丑',
            '寅'=>'戌','午'=>'戌','戌'=>'戌','卯'=>'未','未'=>'未','亥'=>'未'
        ];
        if(isset($huagaiMap[$yearZhi])) $this->addStar($this->zhiMap[$huagaiMap[$yearZhi]], '华盖', 'luck');
        
        $xianchiMap = [
            '子'=>'酉','辰'=>'酉','申'=>'酉','丑'=>'午','巳'=>'午','酉'=>'午',
            '寅'=>'卯','午'=>'卯','戌'=>'卯','卯'=>'子','未'=>'子','亥'=>'子'
        ];
        if(isset($xianchiMap[$yearZhi])) $this->addStar($this->zhiMap[$xianchiMap[$yearZhi]], '咸池', 'peach');

        $this->addStar((2 + $yIdx) % 12, '龙池', 'luck');
        $this->addStar((8 - $yIdx + 12) % 12, '凤阁', 'luck');

        $this->addStar((4 - $yIdx + 12) % 12, '天哭', 'bad');
        $this->addStar((4 + $yIdx) % 12, '天虚', 'bad');

        $this->addStar((1 - $yIdx + 12) % 12, '红鸾', 'peach');
        $this->addStar((1 - $yIdx + 6 + 12) % 12, '天喜', 'peach');

        $this->addStar(($yIdx + 11) % 12, '天空', 'bad');

        // 截空和副截空
        $yangGans = ['甲', '丙', '戊', '庚', '壬'];
        $yangZhis = ['子', '寅', '辰', '午', '申', '戌'];
        $isYangGan = in_array($yearGan, $yangGans);
        if (isset(ZiWeiData::$JIE_KONG[$yearGan])) {
            foreach(ZiWeiData::$JIE_KONG[$yearGan] as $zhi) {
                $isYangZhi = in_array($zhi, $yangZhis);
                $isZhengKong = ($isYangGan && $isYangZhi) || (!$isYangGan && !$isYangZhi);
                $name = $isZhengKong ? '截空' : '副截';
                $this->addStar($this->zhiMap[$zhi], $name, 'bad');
            }
        }

        $stdZhiOrder = ['子','丑','寅','卯','辰','巳','午','未','申','酉','戌','亥'];
        $ganOrder = ['甲','乙','丙','丁','戊','己','庚','辛','壬','癸'];
        $ganIndex = array_search($yearGan, $ganOrder);
        $zhiIndex = array_search($yearZhi, $stdZhiOrder);
        
        // 计算当前旬的两个空亡地支（kong1Idx永远是阳支，kong2Idx永远是阴支）
        $kong1Idx = ($zhiIndex - $ganIndex + 10 + 12) % 12; 
        $kong2Idx = ($zhiIndex - $ganIndex + 11 + 12) % 12; 
        
        // 判断阴阳年：阳年正空为阳、副空为阴；阴年正空为阴、副空为阳
        $isYangGan = in_array($yearGan, ['甲', '丙', '戊', '庚', '壬']);
        if ($isYangGan) {
            $xunKongIndex = $kong1Idx;
            $fuXunIndex = $kong2Idx;
        } else {
            $xunKongIndex = $kong2Idx;
            $fuXunIndex = $kong1Idx;
        }
        
        $this->addStar($this->zhiMap[$stdZhiOrder[$xunKongIndex]], '旬空', 'bad');
        $this->addStar($this->zhiMap[$stdZhiOrder[$fuXunIndex]], '副旬', 'bad');


        if(isset(ZiWeiData::$MONTH_DA_HAO[$month])) $this->addStar($this->zhiMap[ZiWeiData::$MONTH_DA_HAO[$month]], '月耗', 'bad');
        if(isset(ZiWeiData::$TIAN_WU[$month])) $this->addStar($this->zhiMap[ZiWeiData::$TIAN_WU[$month]], '天巫', 'luck');
        if(isset(ZiWeiData::$TIAN_YUE[$month])) $this->addStar($this->zhiMap[ZiWeiData::$TIAN_YUE[$month]], '天月', 'bad');
        
        $tianxingIdx = (7 + $month - 1) % 12;
        $this->addStar(($tianxingIdx + 12) % 12, '天刑', 'bad');
        
        $tianYaoIndex = (11 + $month - 1) % 12;
        $this->addStar($tianYaoIndex, '天姚', 'peach');

        $yinshaMap = [
            1=>'寅',2=>'子',3=>'戌',4=>'申',5=>'午',6=>'辰',
            7=>'寅',8=>'子',9=>'戌',10=>'申',11=>'午',12=>'辰'
        ];
        if(isset($yinshaMap[$month])) $this->addStar($this->zhiMap[$yinshaMap[$month]], '阴煞', 'bad');
        
        $yuejieMap = [
            1=>'申', 2=>'申', 3=>'戌', 4=>'戌', 5=>'子', 6=>'子', 
            7=>'寅', 8=>'寅', 9=>'辰', 10=>'辰', 11=>'午', 12=>'午'
        ];
        if(isset($yuejieMap[$month])) $this->addStar($this->zhiMap[$yuejieMap[$month]], '解神', 'luck');

        $zuoIdx = (2 + ($month - 1)) % 12;
        $youIdx = (8 - ($month - 1) + 12) % 12;
        $this->addStar(($zuoIdx + $day - 1) % 12, '三台', 'luck');
        $this->addStar(($youIdx - ($day - 1) + 120) % 12, '八座', 'luck');

        $changIdx = (8 - $hStep + 12) % 12;
        $quIdx = (2 + $hStep) % 12;
        $this->addStar(($changIdx + $day - 2 + 120) % 12, '恩光', 'luck');
        $this->addStar(($quIdx + $day - 2 + 120) % 12, '天贵', 'luck');

        $hIdx = $this->stdZhiMap[$this->input['hour_zhi']];
        $this->addStar((4 + $hIdx) % 12, '台辅', 'luck');
        $this->addStar((0 + $hIdx) % 12, '封诰', 'luck');

        $this->addStar(($this->mingGongIdx + $yIdx) % 12, '天才', 'luck');
        $this->addStar(($this->shenGongIdx + $yIdx) % 12, '天寿', 'luck');

        $this->addStar(($this->mingGongIdx - 7 + 12) % 12, '天伤', 'bad');
        $this->addStar(($this->mingGongIdx - 5 + 12) % 12, '天使', 'bad');
    }

    private function arrangeShenSha()
    {
        $yearGan = $this->input['year_gan'];
        $yearZhi = $this->input['year_zhi'];
        $sex = (int)$this->input['sex'];
        $isYangGan = in_array($yearGan, ['甲','丙','戊','庚','壬']);
        $isClockwise = ($sex == ($isYangGan ? 1 : 0));

        $luZhi = ZiWeiData::$LU_CUN[$yearGan] ?? null;
        if ($luZhi) {
            $luIdx = $this->zhiMap[$luZhi];
            foreach (ZiWeiData::$BO_SHI_12 as $i => $name) {
                $idx = $isClockwise ? ($luIdx + $i) % 12 : ($luIdx - $i + 12) % 12;
                $this->palaces[$idx]['boshi'] = $name;
            }
        }

        $yIdx = $this->zhiMap[$yearZhi];
        foreach (ZiWeiData::$SUI_JIAN_12 as $i => $name) {
            $idx = ($yIdx + $i) % 12;
            $this->palaces[$idx]['suijian'] = $name;
        }

        $jiangStart = 0;
        if (in_array($yearZhi, ['寅','午','戌'])) $jiangStart = 4;
        elseif (in_array($yearZhi, ['申','子','辰'])) $jiangStart = 10;
        elseif (in_array($yearZhi, ['巳','酉','丑'])) $jiangStart = 7;
        elseif (in_array($yearZhi, ['亥','卯','未'])) $jiangStart = 1;

        foreach (ZiWeiData::$JIANG_XING_12 as $i => $name) {
            $idx = ($jiangStart + $i) % 12;
            $this->palaces[$idx]['jiangxing'] = $name;
        }
    }

    private function arrangeChangSheng()
    {
        $bureau = $this->wuXingJu;
        $gender = (int)$this->input['sex'];
        $yearGan = $this->input['year_gan'];
        $isYangGan = in_array($yearGan, ['甲','丙','戊','庚','壬']);
        $isClockwise = (($gender == 1 && $isYangGan) || ($gender == 0 && !$isYangGan));
        
        $changShengMap = [2 => 6, 3 => 9, 4 => 3, 5 => 6, 6 => 0];
        $startIdx = $changShengMap[$bureau] ?? 6;

        foreach (ZiWeiData::$CHANG_SHENG_12 as $i => $starName) {
            $idx = $isClockwise ? ($startIdx + $i) % 12 : ($startIdx - $i + 12) % 12;
            $this->addStar($idx, $starName, 'small-text');
        }
    }

    private function applySiHua()
    {
        $yearGan = $this->input['year_gan'];
        $sihua = ZiWeiData::$SI_HUA[$yearGan] ?? [];

        foreach ($sihua as $entry) {
            $starName = mb_substr($entry, 0, -1);
            $type = mb_substr($entry, -1);
            
            foreach ($this->palaces as &$palace) {
                foreach ($palace['main_stars'] as &$star) {
                    if ($star['name'] == $starName) {
                        $star['sihua'] = $type;
                        if (in_array($star['type'], ['minor', 'luck', 'bad'])) {
                            $star['style'] = 'border: 1px solid #f00;'; 
                        }
                    }
                }
            }
        }
    }

    private function calculateDaXian()
    {
        $sex = (int)$this->input['sex']; 
        $yearGan = $this->input['year_gan'];
        $isYangGan = in_array($yearGan, ['甲','丙','戊','庚','壬']);
        $isClockwise = ($sex == 1 && $isYangGan) || ($sex == 0 && !$isYangGan);
        $startAge = $this->wuXingJu;

        for ($i = 0; $i < 12; $i++) {
            $offset = $isClockwise ? $i : -$i;
            $idx = ($this->mingGongIdx + $offset + 12) % 12;
            $s = $startAge + $i * 10;
            $e = $s + 9;
            $this->palaces[$idx]['daxian'] = "{$s}-{$e}";
        }
    }

    private function calculateMingShenZhu()
    {
        $yearZhi = $this->input['year_zhi'];
        $mingZhi = $this->palaces[$this->mingGongIdx]['zhi'];
        
        $this->mingZhu = '命主：' . (ZiWeiData::$MING_ZHU[$mingZhi] ?? '待定');
        $this->shenZhu = '身主：' . (ZiWeiData::$SHEN_ZHU[$yearZhi] ?? '待定');
    }

    private function addStar($idx, $name, $type)
    {
        $idx = ($idx % 12 + 12) % 12;
        $zhi = $this->palaces[$idx]['zhi'];
        $pos = $this->stdZhiMap[$zhi];
        
        $brightness = '-';
        if ($type == 'major' && isset(ZiWeiData::$ZHU_XING_GUAN_XI[$name])) {
            $brightness = ZiWeiData::$ZHU_XING_GUAN_XI[$name][$pos] ?? '-';
        } elseif (isset(ZiWeiData::$MINOR_STAR_BRIGHTNESS[$name])) {
            $brightness = ZiWeiData::$MINOR_STAR_BRIGHTNESS[$name][$pos] ?? '-';
        }

        $this->palaces[$idx]['main_stars'][] = [
            'name' => $name,
            'raw_name' => $name,
            'type' => $type,
            'brightness' => $brightness,
            'sihua' => '',
            'style' => ''
        ];
    }

    private function getBureauName($num)
    {
        $names = [
            2 => '水二局', 
            3 => '木三局', 
            4 => '金四局', 
            5 => '土五局', 
            6 => '火六局'
        ];
        return $names[$num] ?? $num.'局'; 
    }

    private function getZiWeiLocation($day, $bureau)
    {
        switch ($bureau) {
            case 2:
                $map = [
                    1=>11,2=>0,3=>0,4=>1,5=>1,6=>2,7=>2,8=>3,9=>3,10=>4,
                    11=>4,12=>5,13=>5,14=>6,15=>6,16=>7,17=>7,18=>8,19=>8,20=>9,
                    21=>9,22=>10,23=>10,24=>11,25=>11,26=>0,27=>0,28=>1,29=>1,30=>2
                ];
                return $map[$day] ?? 0;
            case 3:
                $map = [
                    1=>2,2=>11,3=>0,4=>3,5=>0,6=>1,7=>4,8=>1,9=>2,10=>5,
                    11=>2,12=>3,13=>6,14=>3,15=>4,16=>7,17=>4,18=>5,19=>8,20=>5,
                    21=>6,22=>9,23=>6,24=>7,25=>10,26=>7,27=>8,28=>11,29=>8,30=>9
                ];
                return $map[$day] ?? 0;
            case 4:
                $map = [
                    1=>9,2=>2,3=>11,4=>0,5=>10,6=>3,7=>0,8=>1,9=>11,10=>4,
                    11=>1,12=>2,13=>0,14=>5,15=>2,16=>3,17=>1,18=>6,19=>3,20=>4,
                    21=>2,22=>7,23=>4,24=>5,25=>3,26=>8,27=>5,28=>6,29=>4,30=>9
                ];
                return $map[$day] ?? 0;
            case 5:
                $map = [
                    1=>4,2=>9,3=>2,4=>11,5=>0,6=>5,7=>10,8=>3,9=>0,10=>1,
                    11=>6,12=>11,13=>4,14=>5,15=>2,16=>7,17=>0,18=>5,19=>2,20=>3,
                    21=>8,22=>1,23=>6,24=>3,25=>4,26=>9,27=>2,28=>7,29=>4,30=>5
                ];
                return $map[$day] ?? 0;
            case 6:
                $map = [
                    1=>7,2=>4,3=>9,4=>2,5=>11,6=>0,7=>8,8=>5,9=>10,10=>3,
                    11=>0,12=>1,13=>9,14=>6,15=>11,16=>4,17=>1,18=>2,19=>10,20=>7,
                    21=>0,22=>5,23=>2,24=>3,25=>11,26=>8,27=>1,28=>6,29=>3,30=>4
                ];
                return $map[$day] ?? 0;
        }
        return 0;
    }
    /**
     * 新增：同时计算每个宫位的“流年”与“小限”虚岁
     */
    private function calculateAges()
    {
        $yearZhi = $this->input['year_zhi'];
        $sex = (int)$this->input['sex']; // 1:男, 0:女
        
        // --- 1. 计算小限 (Xiao Xian) ---
        // 起点：寅午戌起辰，申子辰起戌，巳酉丑起未，亥卯未起丑
        $xiaoStart = '辰';
        if (in_array($yearZhi, ['寅', '午', '戌'])) $xiaoStart = '辰';
        elseif (in_array($yearZhi, ['申', '子', '辰'])) $xiaoStart = '戌';
        elseif (in_array($yearZhi, ['巳', '酉', '丑'])) $xiaoStart = '未';
        elseif (in_array($yearZhi, ['亥', '卯', '未'])) $xiaoStart = '丑';
        
        $xiaoIdx = $this->zhiMap[$xiaoStart];
        $isClockwise = ($sex === 1); // 男顺女逆
        
        $xiaoArr = array_fill(0, 12, []);
        for ($age = 1; $age <= 84; $age++) {
            $step = ($age - 1) % 12;
            $idx = $isClockwise ? ($xiaoIdx + $step) % 12 : ($xiaoIdx - $step + 12) % 12;
            $xiaoArr[$idx][] = $age;
        }

        // --- 2. 计算流年/太岁 (Liu Nian) ---
        // 起点：永远在生年地支所在的宫位起1岁，且永远顺时针排列
        $liuStartIdx = $this->zhiMap[$yearZhi];
        $liuArr = array_fill(0, 12, []);
        for ($age = 1; $age <= 84; $age++) {
            $step = ($age - 1) % 12;
            $idx = ($liuStartIdx + $step) % 12; // 永远顺行
            $liuArr[$idx][] = $age;
        }
        
        // --- 3. 存入宫位 ---
        foreach ($this->palaces as $i => &$palace) {
            $palace['ages'] = implode(' ', $xiaoArr[$i]);
            $palace['liu_nian_ages'] = implode(' ', $liuArr[$i]);
        }
    }

}

/**
 * 日期时间处理器
 */
class DateTimeHandler
{
    private $input = [];
    private $data = [];

    public function __construct($input) {
        $this->input = $input;
        $this->process();
    }

    private function process() {
        $this->data['name'] = htmlspecialchars($this->input['name'] ?? '命主', ENT_QUOTES, 'UTF-8');
        $this->data['sex'] = isset($this->input['sex']) ? (int)$this->input['sex'] : 1;
        
        $this->data['year_gan'] = $this->input['year_gan'] ?? '甲';
        $this->data['year_zhi'] = $this->input['year_zhi'] ?? '子';
        $this->data['hour_gan'] = $this->input['hour_gan'] ?? '甲';
        $this->data['hour_zhi'] = $this->input['hour_zhi'] ?? '子';
        
        $lunarMonth = (int)($this->input['lunar_month'] ?? 1);
        $lunarDay = (int)($this->input['lunar_day'] ?? 1);
        
        $isLeap = false;
        if ($lunarMonth < 0) {
            $isLeap = true;
            $lunarMonth = abs($lunarMonth);
        } elseif (isset($this->input['is_leap_month']) && $this->input['is_leap_month'] == 1) {
            $isLeap = true;
        }

        $this->data['display_lunar_month'] = $lunarMonth;
        $this->data['display_lunar_day'] = $lunarDay;
        $this->data['is_leap_month'] = $isLeap;

        $panMonth = $lunarMonth;
        if ($isLeap) {
            if ($lunarDay > 15) {
                $panMonth = $lunarMonth + 1;
                if ($panMonth > 12) $panMonth = 1;
            }
        }
        $this->data['pan_lunar_month'] = $panMonth;
        $this->data['pan_lunar_day'] = $lunarDay;

        $this->data['bazi_str'] = $this->input['bazi_str'] ?? '';
        $this->data['solar_date'] = $this->input['birth_date'] ?? '';
        $this->data['is_late_zi'] = !empty($this->input['is_late_zi']);
        $this->data['date_type'] = $this->input['date_type'] ?? 'solar';
        $this->data['hour_zhi'] = $this->input['hour_zhi'] ?? '子';
        
        $zodiacs = [
            '子' => '鼠', '丑' => '牛', '寅' => '虎', '卯' => '兔',
            '辰' => '龙', '巳' => '蛇', '午' => '马', '未' => '羊',
            '申' => '猴', '酉' => '鸡', '戌' => '狗', '亥' => '猪'
        ];
        $this->data['zodiac'] = $zodiacs[$this->data['year_zhi']] ?? '';
        
        $this->data['age'] = $this->calculateNominalAge();
        
        // ===== 新增：将农历转换为传统中文格式 (二〇二五年正月初一) =====
        $solarYear = (int)substr($this->data['solar_date'], 0, 4);
        $solarMonth = (int)substr($this->data['solar_date'], 5, 2);
        
        // 校准农历年份：如果公历是1或2月，但农历是10, 11, 12月，说明农历还没跨年
        $lunarYearNum = $solarYear;
        if ($solarMonth <= 2 && $lunarMonth >= 10) {
            $lunarYearNum = $solarYear - 1;
        }
        
        // 年份转中文
        $cnNum = ['〇','一','二','三','四','五','六','七','八','九'];
        $yearStr = '';
        $yearStrNum = (string)$lunarYearNum;
        for ($i = 0; $i < strlen($yearStrNum); $i++) {
            $yearStr .= $cnNum[(int)$yearStrNum[$i]] ?? '';
        }
        
        // 月份转中文
        $cnMonthMap = [1=>'正月', 2=>'二月', 3=>'三月', 4=>'四月', 5=>'五月', 6=>'六月', 7=>'七月', 8=>'八月', 9=>'九月', 10=>'十月', 11=>'冬月', 12=>'腊月'];
        // 日期转中文
        $cnDayMap = [
            1=>'初一', 2=>'初二', 3=>'初三', 4=>'初四', 5=>'初五', 6=>'初六', 7=>'初七', 8=>'初八', 9=>'初九', 10=>'初十',
            11=>'十一', 12=>'十二', 13=>'十三', 14=>'十四', 15=>'十五', 16=>'十六', 17=>'十七', 18=>'十八', 19=>'十九', 20=>'二十',
            21=>'廿一', 22=>'廿二', 23=>'廿三', 24=>'廿四', 25=>'廿五', 26=>'廿六', 27=>'廿七', 28=>'廿八', 29=>'廿九', 30=>'三十'
        ];
        
        $mStr = $cnMonthMap[$lunarMonth] ?? $lunarMonth.'月';
        $dStr = $cnDayMap[$lunarDay] ?? $lunarDay.'日';
        $leapStr = $isLeap ? '闰' : '';
        
        $this->data['traditional_lunar_date'] = $yearStr . '年' . $leapStr . $mStr . $dStr;
    } // process() 方法结束


    private function calculateNominalAge() {  //虚岁计算
        if (!empty($this->data['solar_date'])) {
            try {
                $birthDate = new DateTime($this->data['solar_date']);
                $currentDate = new DateTime();
                if ($birthDate > $currentDate) {
                    return '1岁';
                }
                $diff = $currentDate->diff($birthDate);
                $zhouSui = $diff->y;
                $xuSui = $zhouSui + 1;
                return $xuSui . '岁';
            } catch (Exception $e) {
                return '-';
            }
        }
        return '-';
    }


    public function getPanData() {
        return [
            'sex' => $this->data['sex'],
            'year_gan' => $this->data['year_gan'],
            'year_zhi' => $this->data['year_zhi'],
            'hour_gan' => $this->data['hour_gan'],
            'hour_zhi' => $this->data['hour_zhi'],
            'lunar_month' => $this->data['pan_lunar_month'],
            'lunar_day' => $this->data['pan_lunar_day']
        ];
    }

    public function getDisplayData() {
        return $this->data;
    }
}

// ============================================================
// 第三部分：获取CSS和JavaScript内容（已重构移动端）
// ============================================================

function getCSS() {
    return '
        :root {
            /* --- 基础色调：宣纸与墨色 --- */
            --border-color: #a1887f;
            --bg: #faf9f6;
            --text: #3e2723;
            --accent: #b71c1c;
            --ming-color: #c2185b;
            --shen-color: #388e3c;

            /* --- 星曜颜色主题 --- */
            --major-bg: linear-gradient(180deg, #f3e5f5, #e1bee7); 
            --major-text: #4a148c;
            --major-border: #ab47bc;

            --ji-bg: linear-gradient(180deg, #e0f2f1, #b2dfdb);
            --ji-text: #00695c;
            --ji-border: #26a69a;

            --sha-bg: linear-gradient(180deg, #fbe9e7, #ffccbc);
            --sha-text: #bf360c;
            --sha-border: #ff7043;

            --peach-bg: linear-gradient(180deg, #fce4ec, #f8bbd0);
            --peach-text: #880e4f;
            --peach-border: #ec407a;

            --luck-bg: linear-gradient(180deg, #f1f8e9, #dcedc8);
            --luck-text: #33691e;
            --luck-border: #8bc34a;

            --bad-bg: linear-gradient(180deg, #f5f5f5, #e0e0e0);
            --bad-text: #455a64;
            --bad-border: #90a4ae;

            --minor-bg: linear-gradient(180deg, #fafafa, #f5f5f5);
            --minor-text: #757575;
            --minor-border: #e0e0e0;

            /* 四化星渐变背景 */
            --lu: #2e7d32;      /* 深绿 */
            --quan: #1565c0;    /* 深蓝 */
            --ke: #ef6c00;      /* 深橙 */
            --ji: #c62828;      /* 深红 */

            /* 高亮颜色 */
            --highlight-color: rgba(255, 245, 157, 0.7);
            --highlight-border: #ffca28;
            --hl-sanfang: rgba(255, 243, 224, 0.9);
            --hl-current: rgba(255, 235, 238, 0.9);

            /* 间距和圆角 */
            --sp-xs: 4px; --sp-sm: 8px; --sp-md: 16px; --sp-lg: 24px; --sp-xl: 32px;
            --rd-sm: 4px; --rd-md: 8px; --rd-lg: 12px; --rd-full: 50%;
            --shadow-card: 0 10px 30px rgba(141, 110, 99, 0.15);
            --trans: 0.3s ease;
        }
        
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { 
            font-size: 16px; 
            scroll-behavior: smooth; 
            -webkit-text-size-adjust: 100%;
            -moz-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", "Helvetica Neue", Helvetica, Arial, sans-serif;
            line-height: 1.6;
            padding: var(--sp-md);
            min-height: 100vh;
            overflow-x: hidden;
            touch-action: manipulation;
        }
        
        /* 移动端特殊优化 */
        input, select, button, textarea {
            font-size: 16px !important;
            max-width: 100%;
        }
        
        /* 滚动条美化 */
        .sidebar::-webkit-scrollbar, .stars-container::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track, .stars-container::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb, .stars-container::-webkit-scrollbar-thumb { 
            background-color: rgba(0,0,0,0.2); border-radius: var(--rd-full); 
        }
        
        .wrapper {
            display: flex; gap: var(--sp-lg); max-width: 1440px; 
            margin: 0 auto; align-items: flex-start;
            transition: transform var(--trans);
            position: relative;
        }
        
        .sidebar {
            width: 340px; flex-shrink: 0;
            background: #fff; 
            border-radius: var(--rd-lg); box-shadow: var(--shadow-card);
            position: sticky; top: var(--sp-md); z-index: 100;
            max-height: calc(100vh - var(--sp-md) * 2); overflow-y: auto;
            padding: 0;
        }
        
        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--sp-lg) var(--sp-xl);
            border-bottom: 1px solid #eee;
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .sidebar h2 {
            color: var(--text); font-size: 1.75rem; 
            margin: 0;
            letter-spacing: 1px;
        }
        
        .close-sidebar-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #666;
            padding: 8px;
            cursor: pointer;
            display: none;
            border-radius: var(--rd-full);
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
        }
        
        .close-sidebar-btn:hover {
            background: #f5f5f5;
        }
        
        .sidebar form {
            padding: var(--sp-xl);
            padding-top: 0;
        }
        
        .main-content {
            flex: 1; min-width: 0;
            background: #fff; padding: var(--sp-lg);
            border-radius: var(--rd-lg); box-shadow: var(--shadow-card);
            overflow-x: auto;
        }
        
        .form-item { margin-bottom: var(--sp-md); }
        .form-item label { display: block; margin-bottom: var(--sp-xs); font-weight: 600; color: #5d4037; font-size: 0.875rem; }
        
        .form-item :is(input[type="text"], input[type="datetime-local"], select) {
            width: 100%; padding: var(--sp-sm) var(--sp-md);
            border: 1px solid #d7ccc8; border-radius: var(--rd-md);
            font-size: 0.9375rem; transition: var(--trans);
            background: #fff;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        .form-item :is(input[type="text"], input[type="datetime-local"], select):focus {
            outline: none; border-color: var(--border-color);
            box-shadow: 0 0 0 3px rgba(141, 110, 99, 0.2);
        }
        
        .checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.9375rem;
        }
        .checkbox-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            margin: 0;
        }
        
        .radio-group.inline { display: flex; flex-wrap: wrap; gap: var(--sp-md); margin-top: var(--sp-xs); }
        .radio-group.inline .radio-label {
            cursor: pointer;
            margin: 0;
            position: relative;
        }
        .radio-group.inline .radio-label input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        .radio-group.inline .radio-label span {
            display: inline-block;
            padding: 8px 24px;
            background: #f5f5f5;
            border: 1px solid #d7ccc8;
            border-radius: var(--rd-md);
            transition: var(--trans);
            font-size: 0.9375rem;
            color: #5d4037;
        }
        .radio-group.inline .radio-label:hover span {
            background: #e0e0e0;
        }
        .radio-group.inline input:checked + span {
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            border-color: var(--accent);
            box-shadow: 0 2px 8px rgba(183, 28, 28, 0.3);
        }
        
        .button-container {
            display: flex;
            flex-direction: column;
            gap: var(--sp-sm);
            margin-top: var(--sp-lg);
            padding: 0;
            position: relative;
            background: #fff;
        }
        
        .submit-btn {
            width: 100%; padding: var(--sp-md); 
            background: var(--text); color: white; border: none;
            border-radius: var(--rd-md); font-weight: 700; cursor: pointer;
            transition: var(--trans); display: flex; justify-content: center; gap: var(--sp-xs);
            font-size: 1rem;
            -webkit-tap-highlight-color: transparent;
        }
        .submit-btn:hover:not(:disabled) { background: #3e2723; transform: translateY(-1px); }
        .submit-btn:disabled { background: #bcaaa4; cursor: not-allowed; transform: none; }
        .submit-btn.ai-report-btn { background: #1565c0; }
        .submit-btn.ai-report-btn:hover { background: #0d47a1; }
        
        /* 命盘容器 */
        .pan-grid-container {
            width: 100%;
            position: relative;
        }
        
        .pan-hint {
            text-align: center;
            margin-bottom: 15px;
            color: #666;
            font-size: 13px;
            padding: 10px;
            background: #f9f9f9;
            border-radius: var(--rd-md);
            border-left: 4px solid var(--accent);
        }
        
        /* 命盘网格 - 完全自适应，无固定宽高，无缩放 */
        .pan-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            grid-template-rows: repeat(4, auto); /* 高度由内容撑开 */
            gap: 2px;
            background: var(--border-color);
            border: 6px solid var(--border-color);
            border-radius: var(--rd-md);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            width: 100%;
        }
        
        .cell {
            background: #fff; 
            padding: 10px 6px 42px 6px;
            display: flex; 
            flex-direction: column; 
            position: relative;
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
            min-height: 210px; /* 保证基本高度，但可扩展 */
        }
        
        .gong-header {
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start;
            margin-bottom: 8px; 
            height: 28px;
            border-bottom: 1px solid #eee; 
            padding-bottom: 4px;
        }
        .gong-name { 
            font-weight: 900; 
            font-size: 1.05rem; 
            color: var(--text); 
            line-height: 1; 
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .tag { font-size: 0.75rem; margin-left: 2px; }
        .ming-tag { color: var(--ming-color); }
        .shen-tag { color: var(--shen-color); }
        
        .changsheng-container {
            display: flex; 
            gap: 2px; 
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .changsheng-item {
            font-size: 0.875rem; /* 字号调大到 14px (原为0.75) */
            font-weight: 600; /* 加粗一点使其更醒目 */
            background: #e3f2fd; /* 背景色稍微提亮一点 */
            color: #1565c0; /* 字体颜色加深 */
            padding: 2px 6px; /* 增加一点内边距让文字不拥挤 */
            border-radius: 4px; 
            /* 删除了 transform: scale(0.9); 取消强制缩小 */
            margin-bottom: 2px;
        }
        
        /* 星曜容器 - 高度自适应，不再固定最大高度 */
        .stars-container {
            flex: 1; 
            display: flex; 
            flex-wrap: wrap; 
            align-content: flex-start;
            gap: 1px; 
            overflow-y: auto;
            overflow-x: hidden;
            padding: 2px 0;
            max-height: none; /* 移除固定高度限制 */
            min-height: 60px;
            -webkit-overflow-scrolling: touch;
        }
        
        .stars-container::-webkit-scrollbar {
            width: 6px;
        }
        .stars-container::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.05);
            border-radius: 3px;
        }
        .stars-container::-webkit-scrollbar-thumb {
            background: rgba(0,0,0,0.2);
            border-radius: 3px;
        }
        
        /* 星曜样式 - 移动端优化 */
        .star {
            font-size: 0.8125rem; 
            font-weight: bold;
            display: inline-flex; 
            flex-direction: column; 
            align-items: center;
            position: relative; 
            transition: transform 0.2s, box-shadow 0.2s;
            min-width: 18px; 
            width: 18px;
            margin: 0 1px 8px 1px;
            border-radius: var(--rd-sm);
            border: 1px solid rgba(0,0,0,0.05);
            padding: 1px 2px 14px 2px;
            cursor: pointer;
        }
        
        .star-name {
            display: flex; 
            flex-direction: column; 
            align-items: center;
            justify-content: center; 
            width: 100%; 
            min-height: 20px;
            margin-bottom: 0;
        }
        
        .star-name span {
            display: block;
            line-height: 1;
            height: 1.2em;
            margin: 0;
            padding: 0;
        }
        
        .star:hover { transform: translateY(-2px); z-index: 10; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        
        /* 星曜颜色定义 */
        .star.major { background: var(--major-bg); color: var(--major-text); border-color: var(--major-border); box-shadow: 0 0 6px rgba(156, 39, 176, 0.3); }
        .star.ji { background: var(--ji-bg); color: var(--ji-text); border-color: var(--ji-border); box-shadow: 0 0 4px rgba(0, 188, 212, 0.25); }
        .star.sha { background: var(--sha-bg); color: var(--sha-text); border-color: var(--sha-border); box-shadow: 0 0 5px rgba(230, 81, 0, 0.3); }
        .star.peach { background: var(--peach-bg); color: var(--peach-text); border-color: var(--peach-border); box-shadow: 0 0 4px rgba(236, 64, 122, 0.25); }
        .star.luck { background: var(--luck-bg); color: var(--luck-text); border-color: var(--luck-border); }
        .star.bad { background: var(--bad-bg); color: var(--bad-text); border-color: var(--bad-border); }
        .star.minor { background: var(--minor-bg); color: var(--minor-text); border-color: var(--minor-border); }
        .star.small-text { font-size: 0.75rem; min-height: 36px; padding-bottom: 16px; background: #f0f0f0; color: #666; }
        
        .star-brightness {
            position: absolute; 
            bottom: 2px;
            left: 0; 
            right: 0; 
            font-size: 0.625rem; 
            text-align: center; 
            color: rgba(0,0,0,0.5);
            font-weight: normal;
            line-height: 1;
        }
        
        /* 四化标签 */
        .star-sihua {
            position: absolute; 
            bottom: -22px;
            left: 50%;
            transform: translateX(-50%);
            width: 20px; 
            height: 20px; 
            line-height: 20px;
            border-radius: 50%; 
            color: white; 
            font-size: 0.7rem;
            text-align: center; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            border: 1px solid #fff; 
            font-weight: bold;
            z-index: 5;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .star.sihua-禄 .star-sihua { background: var(--lu); }
        .star.sihua-权 .star-sihua { background: var(--quan); }
        .star.sihua-科 .star-sihua { background: var(--ke); }
        .star.sihua-忌 .star-sihua { background: var(--ji); }
        
        /* 神煞区域 */
        .gong-shensha {
            position: absolute; 
            bottom: 36px; 
            left: 6px; 
            right: 6px;
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            gap: 4px; 
            padding: 2px 0;
            border-top: 1px solid #f0f0f0; 
            border-bottom: 1px solid #f0f0f0;
            background: rgba(255, 255, 255, 0.9);
            z-index: 2;
            height: 24px;
        }
        
        .shensha-item {
            font-size: 0.6875rem; 
            font-weight: 500; 
            color: #616161;
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis;
            flex: 1; 
            text-align: center;
            padding: 1px 0;
        }
        
        .boshi-group { color: #7b1fa2; background: rgba(123, 31, 162, 0.1); border-radius: 2px; }
        .suijian-group { color: #ef6c00; background: rgba(239, 108, 0, 0.1); border-radius: 2px; }
        .jiang-group { color: #00796b; background: rgba(0, 121, 107, 0.1); border-radius: 2px; }
        
        /* 宫位底部 */
        .gong-footer {
            position: absolute; 
            bottom: 8px; 
            left: 8px; 
            right: 8px;
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            padding-top: 2px;
            border-top: 1px solid #eee;
        }
        
        .gong-gz { 
            font-size: 0.9375rem; 
            font-weight: bold; 
            color: var(--text); 
        }
        
        .gong-daxian { 
            background: var(--text); 
            color: #fff; 
            font-size: 0.75rem; 
            padding: 1px 8px; 
            border-radius: 10px; 
            font-weight: bold;
        }
        
        /* 中宫样式 */
        .center-cell {
            grid-column: 2 / 4; 
            grid-row: 2 / 4;
            background: linear-gradient(135deg, #fffbf0, #fff8e1);
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center;
            padding: var(--sp-lg); 
            border: 2px solid #e0d6c2; 
            border-radius: var(--rd-md);
            text-align: center;
            -webkit-tap-highlight-color: transparent;
            min-height: 200px;
        }
        
        .cc-name {
            font-size: 1.15rem; 
            font-weight: 900; 
            color: var(--text);
            border-bottom: 2px solid #dacbb8; 
            padding-bottom: var(--sp-md); 
            margin-bottom: var(--sp-md); 
            width: 100%;
        }
        
        .cc-row { 
            display: flex; 
            gap: var(--sp-sm); 
            align-items: center; 
            margin-bottom: var(--sp-xs); 
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .cc-bazi-grid {
            display: flex; 
            justify-content: center; 
            gap: var(--sp-md); 
            width: 100%;
            background: rgba(255,255,255,0.8); 
            padding: var(--sp-md);
            border: 1px solid #d7ccc8; 
            border-radius: var(--rd-md); 
            margin: var(--sp-md) 0;
            flex-wrap: wrap;
        }
        
        .bazi-col { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            min-width: 50px;
        }
        
        .bz-val { 
            font-size: 1.05rem; 
            font-weight: 900; 
            color: var(--text); 
        }
        
        /* 高亮样式 */
        .cell.highlight, .center-cell.highlight {
            background: var(--hl-sanfang) !important;
            box-shadow: 0 0 0 2px var(--highlight-border), 0 0 15px rgba(255, 152, 0, 0.3);
            border-color: var(--highlight-border) !important; 
            z-index: 10;
        }
        
        .cell.current-highlight, .center-cell.current-highlight {
            background: var(--hl-current) !important;
            box-shadow: 0 0 0 2px #f44336, 0 0 20px rgba(244, 67, 54, 0.4);
            border-color: #f44336 !important; 
            z-index: 15;
        }
        
        :is(.cell, .center-cell).highlight .star { box-shadow: 0 0 8px rgba(255,193,7,0.4); }
        :is(.cell, .center-cell).current-highlight .star { box-shadow: 0 0 10px rgba(244,67,54,0.4); }
        
        /* 移动端帮助面板 */
        .mobile-help-panel {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: var(--trans);
        }
        
        .mobile-help-panel.show {
            opacity: 1;
            visibility: visible;
        }
        
        .help-content {
            background: white;
            padding: var(--sp-xl);
            border-radius: var(--rd-lg);
            max-width: 90%;
            max-height: 80%;
            overflow-y: auto;
            box-shadow: var(--shadow-card);
        }
        
        .help-content h3 {
            color: var(--text);
            margin-bottom: var(--sp-md);
            display: flex;
            align-items: center;
            gap: var(--sp-sm);
        }
        
        .help-content ul {
            list-style: none;
            padding: 0;
            margin-bottom: var(--sp-lg);
        }
        
        .help-content li {
            padding: var(--sp-sm) 0;
            border-bottom: 1px solid #eee;
        }
        
        .help-content li strong {
            color: var(--accent);
        }
        
        .close-help-btn {
            width: 100%;
            padding: var(--sp-md);
            background: var(--text);
            color: white;
            border: none;
            border-radius: var(--rd-md);
            font-weight: bold;
            cursor: pointer;
        }
        
        /* 形状设置 */
        .shape-square .cell { border-radius: 0; }
        .shape-square .star { border-radius: 2px; }
        .shape-square .gong-daxian { border-radius: 2px; }
        .shape-square .star-sihua { border-radius: 2px; }
        
        .shape-round .cell { border-radius: 0; }
        .shape-round .star { border-radius: 6px; }
        .shape-round .gong-daxian { border-radius: 10px; }
        .shape-round .star-sihua { border-radius: 50%; }
        
        .mobile-menu-btn {
            display: none; 
            position: fixed; 
            top: var(--sp-md); 
            right: var(--sp-md);
            width: 50px; 
            height: 50px; 
            background: var(--text); 
            color: white;
            border-radius: var(--rd-full); 
            z-index: 999; 
            justify-content: center; 
            align-items: center;
            box-shadow: var(--shadow-card);
            -webkit-tap-highlight-color: transparent;
        }
        
        .mobile-menu-btn .menu-text { 
            display: none; 
            font-size: 14px; 
            margin-left: 5px; 
        }
        
        .info-tip {
            font-size: 13px; 
            color: #666; 
            background: #f5f5f5;
            padding: 8px 12px; 
            border-radius: 6px; 
            margin: 15px 0;
            border-left: 3px solid var(--accent);
        }
        
        .form-note {
            font-size: 12px; 
            color: #888; 
            margin-top: 3px;
            font-style: italic;
        }
        
        .loading {
            text-align: center; 
            padding: 100px 0; 
            color: #8d6e63;
        }
        
        /* 四化标签动画 */
        @keyframes pulse { 
            0%, 100% { transform: translateX(-50%) scale(1); } 
            50% { transform: translateX(-50%) scale(1.1); } 
        }
        
        /* =========================================
           响应式设计 - 移动端优化，无缩放，完全自适应
           ========================================= */
        
        @media (max-width: 1024px) {
            body { 
                padding: 10px; 
                padding-top: 80px; 
            }
            
            .wrapper { 
                flex-direction: column; 
                gap: var(--sp-md); 
            }
            
            .mobile-menu-btn { 
                display: flex; 
                width: auto; 
                padding: 0 15px; 
                border-radius: 22px; 
            }
            
            .mobile-menu-btn .menu-text { 
                display: inline; 
            }
        
            .sidebar {
                width: 100%; 
                max-width: 100%;
                position: fixed; 
                top: 0; 
                left: 0; 
                height: 100vh; 
                z-index: 1000;
                transform: translateX(-100%); 
                transition: transform var(--trans); 
                border-radius: 0;
                padding: 0;
                max-height: 100vh; 
                box-shadow: none;
                border-right: 1px solid #eee;
            }
            
            .sidebar.open { 
                transform: translateX(0); 
            }
            
            .close-sidebar-btn {
                display: flex;
            }
            
            .main-content { 
                width: 100%; 
                margin-top: 0; 
                padding: var(--sp-md);
            }
        
            /* 移动端网格：宽度100%，无transform，高度自适应 */
            .pan-grid {
                min-width: auto;
                transform: none !important; /* 确保无缩放 */
                width: 100%;
                grid-template-rows: repeat(4, auto);
            }
            
            .cell {
                min-height: 160px;
                padding-bottom: 38px;
            }
            
            .center-cell {
                min-height: 180px;
                padding: var(--sp-md);
            }
            
            .gong-name { 
                font-size: 1.025rem; 
            }
            
            .star { 
                font-size: 0.75rem; 
                min-width: 16px; 
                width: 16px;
                margin-bottom: 10px;
            }
            
            .star-sihua { 
                width: 16px; 
                height: 16px; 
                font-size: 0.7rem; 
                line-height: 16px; 
                bottom: -14px; 
            }
            
            .star-brightness { 
                font-size: 0.6rem; 
            }
        
            .gong-shensha {
                bottom: 32px; 
                height: 20px;
            }
            
            .shensha-item {
                font-size: 0.625rem; 
            }
            
            .stars-container { 
                min-height: 70px; 
                max-height: none; /* 允许扩展 */
            }
            
            .button-container {
                margin-top: auto; 
                padding-top: var(--sp-lg); 
                position: sticky; 
                bottom: 0;
                background: #fff; 
                padding-bottom: var(--sp-sm);
            }
        }
        
        @media (max-width: 768px) {
            body { 
                padding-top: 70px; 
                padding-left: 5px;
                padding-right: 5px;
            }
            
            .pan-grid { 
                width: 100%;
                grid-template-rows: repeat(4, auto);
                transform: none !important;
            }
            
            .gong-name { 
                font-size: 1rem; 
            }
            
            .star { 
                font-size: 0.6875rem; 
                min-width: 14px; 
                width: 14px; 
                margin-bottom: 8px;
            }
            
            .star-sihua { 
                width: 14px; 
                height: 14px; 
                font-size: 0.65rem; 
                line-height: 14px; 
                bottom: -12px; 
            }
            
            .star-brightness { 
                font-size: 0.55rem; 
            }
            
            .gong-gz { 
                font-size: 0.8125rem; 
            }
            
            .center-cell h2 { 
                font-size: 1.05rem; 
            }
            
            .gong-shensha {
                bottom: 30px; 
                height: 18px;
            }
            
            .shensha-item { 
                font-size: 0.5625rem; 
            }
            
            .stars-container { 
                min-height: 60px; 
            }
            
            .cell { 
                padding-bottom: 32px; 
            }
        }
        
        @media (max-width: 480px) {
            body { 
                padding-top: 60px; 
                padding-left: 0;
                padding-right: 0;
            }
            
            .pan-grid { 
                border-width: 4px;
                transform: none !important;
            }
            
            .pan-hint {
                font-size: 11px;
                padding: 8px;
            }
            
            .gong-name { 
                font-size: 0.9375rem; 
            }
            
            .star { 
                font-size: 0.625rem; 
                min-width: 12px; 
                width: 12px; 
                margin-bottom: 6px;
            }
            
            .star-sihua { 
                width: 12px; 
                height: 12px; 
                font-size: 0.6rem; 
                line-height: 12px; 
                bottom: -10px; 
            }
            
            .star-brightness { 
                font-size: 0.5rem; 
            }
            
            .gong-shensha { 
                bottom: 28px; 
                height: 16px;
            }
            
            .shensha-item { 
                font-size: 0.5rem; 
            }
            
            .stars-container { 
                min-height: 50px; 
            }
            
            .cell { 
                padding-bottom: 28px; 
            }
            
            .mobile-menu-btn {
                top: 10px;
                right: 10px;
                width: 45px;
                height: 45px;
            }
        }
        
        /* 横屏模式优化 */
        @media (max-height: 600px) and (orientation: landscape) {
            .sidebar { 
                padding-top: 70px; 
            }
            
            .button-container { 
                padding-bottom: 5px; 
            }
            
            .pan-grid {
                grid-template-rows: repeat(4, auto);
            }
        }

        /* =========================================
           AI 报告面板专属样式 (PC与移动端自适应)
           ========================================= */
        .ai-report-card { background: #fff; padding: var(--sp-lg); border-radius: var(--rd-lg); max-width: 900px; margin: 0 auto; box-shadow: var(--shadow-card); }
        .ai-report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px; gap: 12px; flex-wrap: wrap; }
        .ai-title-area h3 { margin: 0; color: #3e2723; display: flex; align-items: center; gap: 8px; font-size: 1.05rem; }
        .ai-title-area h3 i { color: #1565c0; }
        .ai-title-area p { margin: 4px 0 0 0; color: #666; font-size: 0.8125rem; }
        .ai-action-area { display: flex; gap: 8px; }
        
        /* 通用精致按钮 */
        .ai-btn { border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 0.875rem; font-weight: 600; transition: all 0.2s; white-space: nowrap; -webkit-tap-highlight-color: transparent; }
        
        /* 顶部两个小按钮（清新扁平风） */
        .ai-btn-copy { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .ai-btn-copy:hover { background: #c8e6c9; }
        .ai-btn-back { background: #f3e5f5; color: #6a1b9a; border: 1px solid #e1bee7; }
        .ai-btn-back:hover { background: #e1bee7; }
        
        .ai-hint-box { background: #f8f9fa; border-radius: 8px; padding: 12px; margin-bottom: 16px; border-left: 4px solid #1565c0; display: flex; align-items: flex-start; gap: 8px; color: #555; font-size: 0.875rem; line-height: 1.5; }
        .ai-hint-box i { color: #1565c0; margin-top: 3px; }
        .ai-textarea { width: 100%; height: 350px; padding: 16px; border: 1px solid #ddd; border-radius: 8px; font-family: "Courier New", Consolas, monospace; line-height: 1.6; font-size: 0.875rem; resize: vertical; background: #fafafa; color: #333; }
        .ai-textarea:focus { outline: none; border-color: #1565c0; box-shadow: 0 0 0 3px rgba(21,101,192,0.1); }
        .ai-report-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; gap: 12px; flex-wrap: wrap; }
        .ai-footer-hint { font-size: 0.8125rem; color: #666; margin: 0; display: flex; align-items: center; gap: 4px; }
        
        /* 底部主复制按钮（突出的胶囊风） */
        .ai-btn-copy-main { background: #2e7d32; color: #fff; padding: 10px 20px; box-shadow: 0 2px 8px rgba(46,125,50,0.3); border-radius: 8px; }
        .ai-btn-copy-main:hover { background: #1b5e20; transform: translateY(-1px); }

        /* 手机端精细化排版 */
        @media (max-width: 600px) {
            .ai-report-card { padding: 16px; border-radius: 12px; }
            .ai-report-header { flex-direction: column; align-items: flex-start; gap: 16px; }
            .ai-action-area { width: 100%; justify-content: flex-end; }
            .ai-action-area .ai-btn { flex: 1; padding: 10px; /* 顶部按钮等分拉宽，更好按 */ }
            .ai-textarea { height: 280px; font-size: 0.8125rem; }
            .ai-report-footer { flex-direction: column; align-items: stretch; gap: 16px; }
            .ai-btn-copy-main { width: 100%; padding: 14px; font-size: 1rem; /* 底部主按钮变成横贯满宽的大按钮 */ }
        }

        /* =========================================
           新增：移动端强制横向滑动模式优化
           ========================================= */
        @media (max-width: 1024px) {
            /* 1. 开启容器横向滚动，并在底部留出一点空间显示滑动提示 */
            .pan-grid-container {
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 25px !important; 
                /* 纯CSS实现底部滑动文字提示 */
                background-image: url("data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="200" height="20"><text x="50%" y="15" font-family="sans-serif" font-size="12" fill="%23888888" text-anchor="middle">← 左右滑动查看完整命盘 →</text></svg>");
                background-repeat: no-repeat;
                background-position: center bottom 4px;
            }
            
            /* 2. 强制盘面最小宽度，防止被挤压变高 (860px能保证宫位比例刚好) */
            .pan-grid {
                min-width: 860px !important; 
            }
            
            /* 3. 适配大屏字号，并为底部新增的【流年】与【小限】分配充足纵向空间 */
            .cell { 
                min-height: 210px !important; /* 整体高度稍微拉长一点，留出星曜显示空间 */
                padding-bottom: 88px !important; /* 关键修复：强行撑开底部，给4行文字留足空间 */
            }
            
            .gong-name { font-size: 1.125rem !important; }
            .star { font-size: 0.8125rem !important; min-width: 18px !important; width: 18px !important; margin-bottom: 8px !important; }
            .star-sihua { width: 20px !important; height: 20px !important; font-size: 0.7rem !important; line-height: 20px !important; bottom: -22px !important; }
            .star-brightness { font-size: 0.625rem !important; }
            .gong-gz { font-size: 0.9375rem !important; }
            
            /* 重新梳理底部 4 层信息的绝对高度，像叠汉堡一样错开，坚决不重叠 */
            .gong-liunian { bottom: 70px !important; font-size: 0.65rem !important; } /* 第1层(最上)：流年 */
            .gong-ages { bottom: 54px !important; font-size: 0.65rem !important; }    /* 第2层：小限 */
            .gong-shensha { bottom: 32px !important; height: 20px !important; }       /* 第3层：神煞 */
                                                                                      /* 第4层(最下)：干支与大限(由gong-footer自带bottom:8px控制) */
            
            .shensha-item { font-size: 0.6875rem !important; }
            .center-cell h2 { font-size: 1.05rem !important; } /* 姓名在横滑宽屏里放大一点更协调 */
        }

        /* =========================================
           极致优化：星曜过多时自动收紧，强制单行显示不撑高宫位 (已修复高度拉伸问题)
           ========================================= */
        .stars-container {
            flex-wrap: nowrap !important; /* 绝对不允许换行 */
            overflow: visible !important; /* 【修复】允许四化星溢出显示，防止被裁切挡住 */
            gap: 0 !important; /* 取消硬性间距，交由弹性盒子管理 */
            align-items: flex-start !important; /* 【核心修复】强制顶部对齐，绝对禁止星曜被垂直拉伸！ */
        }
        
        .star {
            flex: 0 1 20px !important; /* 弹性收缩：平时宽度20px，空间不够时允许缩小 */
            width: auto !important; /* 取消写死的宽度 */
            min-width: 14px !important; /* 极限压缩：刚好包住13px的汉字，绝不压碎汉字 */
            padding-left: 0 !important; /* 极限挤压时抽干左右内边距 */
            padding-right: 0 !important;
            margin-left: 0 !important;
            margin-right: 1px !important; /* 星曜之间保留1px的最后尊严 */
            height: max-content !important; /* 【双重保险】确保高度仅由文字内容撑开 */
        }

        /* 覆盖移动端里写死宽度的历史设置 */
        @media (max-width: 1024px) {
            .star {
                flex: 0 1 18px !important; 
                min-width: 14px !important; 
                width: auto !important;
            }
        }

        /* =========================================
           新增：宫位底部流年、小限与空间布局调整
           ========================================= */
        /* 1. 撑高宫位的底部内边距，给两排岁数留出充足空间 */
        .cell { padding-bottom: 84px !important; }
        
        /* 2. 流年和小限的通用样式 */
        .gong-liunian, .gong-ages {
            position: absolute; 
            left: 2px; 
            right: 2px;
            text-align: center;
            font-size: 0.75rem; 
            font-family: "Courier New", Consolas, monospace;
            letter-spacing: -0.5px;
            white-space: nowrap;
            z-index: 2;
        }
        
        /* 流年在最上面 */
        .gong-liunian {
            bottom: 70px; 
            color: #d84315; /* 流年用一点暗橘色凸显 */
            border-top: 1px dashed #eee;
            padding-top: 2px;
        }

        /* 小限在中间 */
        .gong-ages {
            bottom: 56px; 
            color: #607d8b; /* 小限用蓝灰色 */
        }
        
        /* 神煞在最下面（大限之上） */
        .gong-shensha {
            bottom: 36px !important; 
            border-top: none !important; 
        }

        /* 移动端细微适配 */
        @media (max-width: 1024px) {
            .gong-liunian, .gong-ages { font-size: 0.6rem !important; }
            .gong-liunian { bottom: 70px !important; }
            .gong-ages { bottom: 54px !important; }
            .gong-shensha { bottom: 32px !important; }
        }
        @media (max-width: 768px) {
            .cell { padding-bottom: 76px !important; }
            .gong-liunian, .gong-ages { font-size: 0.55rem !important; }
            .gong-liunian { bottom: 70px !important; }
            .gong-ages { bottom: 54px !important; }
            .gong-shensha { bottom: 32px !important; }
        }
        @media (max-width: 480px) {
            .cell { padding-bottom: 76px !important; }
            .gong-liunian, .gong-ages { font-size: 0.5rem !important; }
            .gong-liunian { bottom: 70px !important; }
            .gong-ages { bottom: 54px !important; }
            .gong-shensha { bottom: 32px !important; }
        }
        
    ';
}

function getJavaScript() {
    return '
        // 分离事件绑定与布局更新，修复横竖屏切换菜单失效问题
        function setupMobileMenuEvents() {
            const menuBtn = document.getElementById("mobileMenuBtn");
            const sidebar = document.getElementById("sidebar");
            const closeSidebarBtn = document.getElementById("closeSidebarBtn");
            
            if (!menuBtn || !sidebar) return;
            
            menuBtn.addEventListener("click", function(e) {
                e.stopPropagation();
                sidebar.classList.toggle("open");
                document.body.style.overflow = sidebar.classList.contains("open") ? "hidden" : "";
            });
            
            if (closeSidebarBtn) {
                closeSidebarBtn.addEventListener("click", function(e) {
                    e.stopPropagation();
                    sidebar.classList.remove("open");
                    document.body.style.overflow = "";
                });
            }
            
            document.addEventListener("click", function(e) {
                if (window.innerWidth <= 1024 && !sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
                    sidebar.classList.remove("open");
                    document.body.style.overflow = "";
                }
            });
            
            sidebar.addEventListener("click", function(e) {
                e.stopPropagation();
            });
        }

        function updateMobileMenuLayout() {
            const menuBtn = document.getElementById("mobileMenuBtn");
            const sidebar = document.getElementById("sidebar");
            const closeSidebarBtn = document.getElementById("closeSidebarBtn");
            
            if (!menuBtn || !sidebar) return;
            
            if (window.innerWidth <= 1024) {
                menuBtn.style.display = "flex";
                if (closeSidebarBtn) closeSidebarBtn.style.display = "flex";
            } else {
                sidebar.classList.add("open");
                menuBtn.style.display = "none";
                if (closeSidebarBtn) closeSidebarBtn.style.display = "none";
                document.body.style.overflow = "";
            }
        }
        
        document.addEventListener("DOMContentLoaded", function() {
            setupMobileMenuEvents();
            updateMobileMenuLayout();
            initMobileFeatures();
        });
        
        window.addEventListener("resize", updateMobileMenuLayout);
        
        // 初始化移动端功能（移除缩放相关）
        function initMobileFeatures() {
            // 帮助面板
            const helpBtn = document.getElementById("mobileHelpBtn");
            const helpPanel = document.getElementById("mobileHelpPanel");
            
            if (helpBtn && helpPanel) {
                helpBtn.addEventListener("click", function() {
                    helpPanel.classList.add("show");
                });
                
                const closeHelpBtn = helpPanel.querySelector(".close-help-btn");
                if (closeHelpBtn) {
                    closeHelpBtn.addEventListener("click", function() {
                        helpPanel.classList.remove("show");
                    });
                }
                
                helpPanel.addEventListener("click", function(e) {
                    if (e.target === helpPanel) {
                        helpPanel.classList.remove("show");
                    }
                });
            }
            
            // 长按复制星曜名称（保留）
            document.addEventListener("touchstart", function(e) {
                const star = e.target.closest(".star");
                if (star) {
                    const touchTimer = setTimeout(function() {
                        const starName = star.querySelector(".star-name")?.textContent || "";
                        if (starName && navigator.clipboard) {
                            navigator.clipboard.writeText(starName).then(function() {
                                const toast = document.createElement("div");
                                toast.textContent = "已复制: " + starName;
                                toast.style.cssText = `
                                    position: fixed;
                                    bottom: 100px;
                                    left: 50%;
                                    transform: translateX(-50%);
                                    background: rgba(0,0,0,0.8);
                                    color: white;
                                    padding: 12px 24px;
                                    border-radius: 25px;
                                    z-index: 1000;
                                    font-size: 14px;
                                    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                                `;
                                document.body.appendChild(toast);
                                setTimeout(function() {
                                    document.body.removeChild(toast);
                                }, 2000);
                            });
                        }
                    }, 800);
                    
                    const cancelTimer = function() {
                        clearTimeout(touchTimer);
                        document.removeEventListener("touchend", cancelTimer);
                        document.removeEventListener("touchmove", cancelTimer);
                    };
                    
                    document.addEventListener("touchend", cancelTimer);
                    document.addEventListener("touchmove", cancelTimer);
                }
            });
        }
        
        // 切换日期类型
        function toggleDateType() {
            const type = document.getElementById("dateType").value;
            document.getElementById("leapMonthContainer").style.display = (type === "lunar") ? "block" : "none";
			// 新增：切换类型时自动重置闰月状态
			if (type === "solar") {
				document.getElementById("isLeapMonth").checked = false;
			}
        }
        
        // 五鼠遁定时干
        function getHourGan(dayGan, hourIdx) {
            const map = {
                "甲": 0, "己": 0,
                "乙": 2, "庚": 2,
                "丙": 4, "辛": 4,
                "丁": 6, "壬": 6,
                "戊": 8, "癸": 8
            };
            const gans = ["甲","乙","丙","丁","戊","己","庚","辛","壬","癸"];
            const start = map[dayGan];
            return gans[(start + hourIdx) % 10];
        }
        
        // 准备数据
        function prepareData() {
            const dtStr = document.getElementById("birth_datetime").value;
            if (!dtStr) throw new Error("请选择日期");
        
            const dateType = document.getElementById("dateType").value;
            const ziMethod = document.getElementById("zi_shi_method").value;
            const isLeapCheck = document.getElementById("isLeapMonth").checked;
        
            const [dPart, tPart] = dtStr.split("T");
            const [Y, M, D] = dPart.split("-").map(Number);
            const [h, m] = tPart.split(":").map(Number);
        
            let solar, lunar, panLunar;
            let isLateZi = false;
        
            if (dateType === "solar") {
                solar = Solar.fromYmd(Y, M, D);
                lunar = solar.getLunar();
            } else {
                // 局部修复：不使用未暴露的 getLeapMonth 方法，改用直接创建及返回值比对校验
                try {
                    // 如果是闰月，传入负数月份给 lunar.js
                    lunar = Lunar.fromYmd(Y, isLeapCheck ? -M : M, D);
                } catch (err) {
                    alert(`【日期错误】该农历日期无效。可能是${Y}年不存在闰${M}月，或该月没有${D}日。`);
                    throw new Error("日期错误");
                }
                
                // 额外校验：如果勾选了闰月，但库生成的对象月份不是负数，说明该闰月被库强制容错纠正成了普通月，这意味着当年并无此闰月
                if (isLeapCheck && lunar.getMonth() !== -M) {
                    alert(`【日期错误】${Y}年农历不存在闰${M}月，请取消勾选闰月！`);
                    throw new Error("日期错误");
                }
                
                solar = lunar.getSolar();
            }
        
            if (h === 23 && ziMethod === "auto") {
                isLateZi = true;
                const nextSolar = solar.next(1);
                panLunar = nextSolar.getLunar();
            } else {
                panLunar = lunar;
            }
        
            const bazi = panLunar.getBaZi();
            const yearGZ = bazi[0];
            
            const zhiIdx = (h >= 23 || h < 1) ? 0 : Math.floor((h + 1) / 2);
            const zhis = ["子","丑","寅","卯","辰","巳","午","未","申","酉","戌","亥"];
            const hourZhi = zhis[zhiIdx];
            
            const dayGZ = panLunar.getDayInGanZhi();
            const dayGan = dayGZ.charAt(0);
            const hourGan = getHourGan(dayGan, zhiIdx);
        
            document.getElementById("year_gan").value = yearGZ.charAt(0);
            document.getElementById("year_zhi").value = yearGZ.charAt(1);
            document.getElementById("hour_gan").value = hourGan;
            document.getElementById("hour_zhi").value = hourZhi;
            
            const isLeapNow = panLunar.getMonth() < 0;
			document.getElementById("isLeapMonth").checked = isLeapNow;   // ← 关键：永远同步状态
			let mVal = Math.abs(panLunar.getMonth());
            
            document.getElementById("lunar_month").value = mVal;
            document.getElementById("lunar_day").value = panLunar.getDay();
            
            const fullBazi = yearGZ + "年 " + panLunar.getMonthInGanZhi() + "月 " + dayGZ + "日 " + hourGan + hourZhi + "时";
            document.getElementById("bazi_str").value = fullBazi;
            
            document.getElementById("birth_date").value = solar.toYmd();
            document.getElementById("is_late_zi").value = isLateZi ? 1 : 0;
            
            return true;
        }

        
        // 生成命盘
        async function generateChart() {
            try {
                prepareData();
                
                const btn = document.getElementById("submitBtn");
                const resultDiv = document.getElementById("panResult");
                btn.disabled = true;
                btn.innerHTML = "<i class=\"fas fa-spinner fa-spin\"></i> 计算中...";
                
                const formData = new FormData(document.getElementById("mainForm"));
                
                const response = await fetch("", {
                    method: "POST",
                    body: formData
                });
                
                const html = await response.text();
                resultDiv.innerHTML = html;
                
                if (window.innerWidth <= 1024) {
                    setTimeout(() => {
                        const sidebar = document.getElementById("sidebar");
                        if (sidebar) sidebar.classList.remove("open");
                        document.body.style.overflow = "";
                    }, 100);
                }
                
                updateShape();
                
                setTimeout(() => {
                    setupPalaceClickHandlers();
                    initMobileFeatures();
                }, 100);
                
                if(window.innerWidth < 1024) {
                    resultDiv.scrollIntoView({behavior: "smooth"});
                    document.getElementById("sidebar").classList.remove("open");
                    document.body.style.overflow = "";
                }
                
            } catch (e) {
                if(!e.message.includes("日期错误")) {
                    alert("排盘出错：" + e.message);
                }
                console.error(e);
            } finally {
                document.getElementById("submitBtn").disabled = false;
                document.getElementById("submitBtn").innerHTML = "<i class=\"fas fa-calculator\"></i> 生成命盘";
            }
        }
        
        // 样式切换
        function updateShape() {
            const val = document.querySelector("input[name=\"shape_setting\"]:checked").value;
            const grid = document.getElementById("panGrid");
            if (grid) {
                grid.className = "pan-grid " + (val === "square" ? "shape-square" : "shape-round");
            }
        }
        
        // 计算三方四正
        function calculateSanFangSiZheng(index) {
            const indices = [];
            indices.push(index);
            indices.push((index + 4) % 12);
            indices.push((index + 8) % 12);
            indices.push((index + 6) % 12);
            return [...new Set(indices)];
        }
        
        // 获取宫位信息
        function getPalaceInfo(index) {
            const cell = document.querySelector(".cell[data-index=\"" + index + "\"]");
            if (!cell) {
                const centerCell = document.querySelector(".center-cell");
                if (centerCell && centerCell.dataset.index == index) {
                    return {
                        index: index,
                        name: "中宫",
                        pos: "",
                        daxian: "",
                        is_center: true
                    };
                }
                return null;
            }
            
            return {
                index: index,
                name: cell.dataset.gongName,
                pos: cell.dataset.pos,
                daxian: cell.dataset.daxian,
                is_center: false
            };
        }
        
        // 设置宫位点击事件（简化触摸处理）
        function setupPalaceClickHandlers() {
            const cells = document.querySelectorAll(".cell, .center-cell");
            
            cells.forEach(cell => {
                cell.removeEventListener("click", handleCellClick);
                cell.style.cursor = "pointer";
            });
            
            cells.forEach(cell => {
                cell.addEventListener("click", handleCellClick);
            });
            
            function handleCellClick(e) {
                e.stopPropagation();
                
                document.querySelectorAll(".cell, .center-cell").forEach(el => {
                    el.classList.remove("highlight", "current-highlight");
                });
                
                this.classList.add("current-highlight");
                
                const index = parseInt(this.dataset.index);
                if (isNaN(index)) return;
                
                const sanfangIndices = calculateSanFangSiZheng(index);
                const primaryPalace = getPalaceInfo(index);
                if (!primaryPalace) return;
                
                sanfangIndices.forEach(idx => {
                    const targetCell = document.querySelector("[data-index=\"" + idx + "\"]");
                    if (targetCell && idx !== index) {
                        targetCell.classList.add("highlight");
                    }
                });
            }
            
            document.addEventListener("click", function(e) {
                if (!e.target.closest(".cell") && !e.target.closest(".center-cell")) {
                    document.querySelectorAll(".cell, .center-cell").forEach(el => {
                        el.classList.remove("highlight", "current-highlight");
                    });
                }
            });
        }
        
        // ----- 新增：来因宫 & 暗合宫 AI 解读 -----
        async function getTextReport() {
            const resultDiv = document.getElementById("panResult");
            const btn = document.querySelector(".ai-report-btn");
            try {
                prepareData();
                
                if (window.innerWidth <= 1024) {
                    setTimeout(() => {
                        const sidebar = document.getElementById("sidebar");
                        if (sidebar) sidebar.classList.remove("open");
                        document.body.style.overflow = "";
                    }, 100);
                }
                btn.disabled = true;
                btn.innerHTML = "<i class=\"fas fa-spinner fa-spin\"></i> 生成报告中...";
                resultDiv.innerHTML = "<div class=\"loading\"><i class=\"fas fa-robot fa-spin\" style=\"font-size: 40px; margin-bottom: 20px;\"></i><p>正在连接排盘接口，生成详细解读数据...</p></div>";
        
                const formData = new FormData(document.getElementById("mainForm"));
                const response = await fetch("?action=api", {
                    method: "POST",
                    body: formData
                });
                
                const json = await response.json();
                
                if (!json.success) {
                    throw new Error(json.error || "接口返回错误");
                }
        
                const basic = json.basic;
                const palaces = json.palaces;
                const info = json.info;
                
                let r = "### 紫微斗数命理分析报告\\n\\n";
                
                const solarY = (basic.birth_info.solar_date || "").substring(0, 4);
                const isLeapStr = basic.birth_info.is_leap_month ? "闰" : "";

                r += "#### 🔮 基本信息\\n";
                r += "- **姓名**：" + basic.name + " (" + basic.gender + ")\\n";
                r += "- **出生年份**：" + basic.birth_info.year_gan + basic.birth_info.year_zhi + "年 " + (solarY ? "(" + solarY + "年)" : "") + "\\n";
                r += "- **出生时间**：公历 " + (basic.birth_info.solar_date || "") + " / 农历 " + (basic.birth_info.traditional_lunar_date || "") + " " + basic.birth_info.hour_zhi + "时\\n";
                r += "- **四柱八字**：" + basic.bazi + "\\n";
                r += "- **五行局数**：" + basic.ming_ju + "\\n";
                r += "- **命主/身主**：" + basic.ming_zhu + " / " + basic.shen_zhu + "\\n";
                r += "- **命宫/身宫**：" + basic.ming_gong + " / " + basic.shen_gong + "\\n";
                r += "- **生肖**：" + (basic.birth_info.zodiac || "") + "\\n";
                // 来因宫
                if (info.lai_yin && info.lai_yin.gong) {
                    r += "- **来因宫**：" + info.lai_yin.gong + " (" + info.lai_yin.gan + info.lai_yin.zhi + ")\\n";
                }
                r += "\\n";
                
                r += "#### 🏛️ 十二宫位星曜配置\\n";
                
                palaces.forEach(p => {
                    let tags = [];
                    if (p.is_ming) tags.push("命宫");
                    if (p.is_shen) tags.push("身宫");
                    const tagStr = tags.length > 0 ? "【" + tags.join("+") + "】" : "";
                    
                    r += "##### " + p.name + " (" + p.pos + ") " + tagStr + "\\n";
                    
                    let majorStars = [];
                    
                    if (p.stars.major && p.stars.major.length > 0) {
                        p.stars.major.forEach(s => {
                            let str = "**" + s.name + "**";
                            if(s.brightness && s.brightness !== "-") str += "[" + s.brightness + "]";
                            if(s.sihua) str += "(化" + s.sihua + ")";
                            majorStars.push(str);
                        });
                    } else if (p.stars.borrowed && p.stars.borrowed.length > 0) {
                        majorStars.push("*空宫，借对宫主星*：");
                        p.stars.borrowed.forEach(s => {
                            let str = s.name;
                            if(s.brightness && s.brightness !== "-") str += "[" + s.brightness + "]";
                            if(s.sihua) str += "(化" + s.sihua + ")";
                            majorStars.push(str);
                        });
                    } else {
                        majorStars.push("*无主星*");
                    }
                    r += "- **主星**：" + majorStars.join("、") + "\\n";
                    
                    let minorStars = [];
                    if (p.stars.minor && p.stars.minor.length > 0) {
                        p.stars.minor.forEach(s => {
                            let str = s.name;
                            if(s.brightness && s.brightness !== "-") str += "[" + s.brightness + "]";
                            if(s.sihua) str += "(化" + s.sihua + ")";
                            minorStars.push(str);
                        });
                    }
                    if (minorStars.length > 0) r += "- **辅佐煞曜**：" + minorStars.join("、") + "\\n";
                    
                    let extraStars = [];
                    if (p.stars.extra && p.stars.extra.length > 0) {
                        extraStars = p.stars.extra.map(s => s.name);
                    }
                    if (extraStars.length > 0) r += "- **杂曜**：" + extraStars.join("、") + "\\n";
                    
                    const g = p.gods;
                    let shensha = [];
        
                    if (g.cs && g.cs.trim()) {
                        r += "- **长生十二神**：" + g.cs + "\\n";
                    }
        
                    let otherShensha = [];
                    if (g.boshi && g.boshi.trim()) otherShensha.push("博士:" + g.boshi);
                    if (g.suijian && g.suijian.trim()) otherShensha.push("岁建:" + g.suijian);
                    if (g.jiangxing && g.jiangxing.trim()) otherShensha.push("将星:" + g.jiangxing);
        
                    if (otherShensha.length > 0) {
                        r += "- **其他神煞**：" + otherShensha.join(" | ") + "\\n";
                    }
					if (p.liu_nian_ages) r += "- **流年**：" + p.liu_nian_ages + "\\n";
                    if (p.ages) r += "- **小限**：" + p.ages + "\\n";
                    if (p.daxian) r += "- **大限**：" + p.daxian + "岁\\n";
                    
                    r += "\\n";
                });
                
                // ----- 新增：来因宫与暗合宫分析 -----
                r += "#### 🌿 来因宫与暗合宫分析\\n";
                if (info.lai_yin && info.lai_yin.gong) {
                    const laiGong = info.lai_yin.gong;
                    r += "**来因宫**：位于 **" + laiGong + "**。\\n";
                    r += "> 这是你今生携带的“人生课题”与“先天业力”所在。";
                    const laiMeanings = {
                        "命宫": "自我实现、个人意志是核心功课，需学会与他人协作。",
                        "兄弟宫": "手足情谊、竞争合作、母亲缘分为今生重点。",
                        "夫妻宫": "情感模式、婚姻关系是主要修行，择偶往往反映深层需求。",
                        "子女宫": "家庭传承、合伙、桃花缘分，子女或晚辈是重要镜子。",
                        "财帛宫": "金钱观、赚钱方式带来成长，但也易患得患失。",
                        "疾厄宫": "身体是最大资本，劳碌命格，需注意健康与压力平衡。",
                        "迁移宫": "异地发展、外界评价、机遇把握，驿马动中求成。",
                        "交友宫": "人际关系、部属缘，易成为他人的依靠或顾问。",
                        "官禄宫": "事业成就、社会地位是价值感来源，但也易过劳。",
                        "田宅宫": "家庭责任、房产祖荫，你为家人撑起一片天。",
                        "福德宫": "精神世界、因果福报，内心富足比物质更重要。",
                        "父母宫": "父母缘、长辈关系，孝顺与自我独立需要平衡。"
                    };
                    let laiDesc = laiMeanings[laiGong] || "此宫位需结合主星四化深入分析。";
                    r += " " + laiDesc + "\\n\\n";
                }

                // 命宫暗合解读
                const mingPalace = palaces.find(p => p.is_ming === true);
                if (mingPalace && mingPalace.an_he_gong) {
                    const anHeGong = mingPalace.an_he_gong;
                    r += "**命宫暗合**：命宫暗合 **" + anHeGong + "**。\\n";
                    r += "> 这是你“嘴上不说、心里放不下”的宫位。无论表面上是否疏离，内心与 " + anHeGong + " 总有割舍不断的牵连。";
                    if (anHeGong.includes("父母")) {
                        r += " 你对父母的牵挂深入骨髓，孝顺是你的本能。";
                    } else if (anHeGong.includes("夫妻")) {
                        r += " 亲密关系是你内心的柔软之处，容易被情感牵动。";
                    } else if (anHeGong.includes("田宅")) {
                        r += " 家庭、房子是你安全感的最后防线，你愿意为此付出一切。";
                    } else if (anHeGong.includes("财帛")) {
                        r += " 金钱不仅是工具，更是你给予家人保障的方式。";
                    }
                    r += "\\n\\n";
                }
                
                r += "#### ⭐ 重点提示\\n";
                r += "1. **命宫三方四正**：分析 " + palaces[info.ming_gong_index].name + " 宫的三方四正组合\\n";
                r += "2. **四化星分布**：重点关注化禄、化权、化科、化忌所在宫位\\n";
                r += "3. **特殊格局**：检查是否有特殊成格（如紫府同宫、日月并明、火贪格等）\\n";
                r += "4. **空宫情况**：注意无主星宫位，需借对宫星曜分析\\n";
                if (mingPalace && mingPalace.an_he_gong) {
                    r += "5. **暗合宫提醒**：命宫暗合" + mingPalace.an_he_gong + "，此宫位易成为你潜意识里的执念。\\n";
                }
                r += "\\n";
                
                r += "---\n";
                r += "#### 🤖 AI 分析指令 \n";
                r += "```\n";
                r += "你是一位深谙紫微斗数精髓，并能将古典命理与现代心理学、职业规划相结合的国学大师。请严格基于上方提供的完整排盘数据（包括十二宫干支、主星、辅星、杂曜、四化、大限、流年、小限、神煞、来因宫、暗合宫等），为命主进行深度、精准、个性化、具备实操指导意义的命理分析。\n\n";
                r += "【分析核心原则】\n";
                r += "1. 坚守数据：严格基于提供的数据分析，绝不自行脑补、捏造星曜或格局。所有论断必须有数据支撑。\n";
                r += "2. 拒绝套话：避免放之四海皆准的表述，必须结合具体宫位、星曜、四化进行针对性解读。\n";
                r += "3. 辩证解盘：吉星需看庙旺与煞忌制化（如化禄在闲宫可能为慵懒，煞星得制化可成权），凶星需看有无吉星调和（如擎羊入庙可成将星）。空宫必须借对宫主星，并结合本宫煞曜综合论断。\n\n";
                r += "请严格按以下七大模块，输出字数不少于 2500 字的专业报告，每个模块需包含具体宫位与星曜分析：\n\n";
                r += "【模块一：命盘总纲】\n";
                r += "- 简要总结命盘最核心的三大特质（如：杀破狼动荡格局、紫微帝星坐命的领导气质、机月同梁的文职潜质等）。\n";
                r += "- 点出命盘中能量最强的宫位（如双禄交流、权忌交战、煞星汇聚等），以及最弱的宫位（如空劫夹、羊陀夹等）。\n\n";
                r += "【模块二：先天命格与人格底色】\n";
                r += "- 命宫/身宫深度解析：详细解读命宫主星（若空宫则借对宫）的庙旺、四化、辅星组合，描述命主的核心性格、天赋、外在表现。\n";
                r += "- 三方四正影响：分析财帛、官禄、迁移宫对命宫的影响，揭示命主在社会中的行为模式与发展潜力。\n";
                r += "- 四化能量引擎：逐一分析化禄、权、科、忌所在宫位，解读其对命主的福报、能力、名声、业力的具体影响，尤其关注禄忌交错的宫位连线。\n";
                r += "- 来因宫与暗合宫：结合来因宫揭示今生核心课题；解析命宫暗合宫位，挖掘潜意识中放不下的执念或软肋。\n\n";
                r += "【模块三：十二宫深度穿透】\n";
                r += "对以下核心宫位进行深度剖析（每个宫位需包含：主星庙旺、四化、辅星、杂曜、三方四正、夹宫影响）：\n";
                r += "1. 兄弟宫：手足缘分、母亲影响、早期人际模式、现金流状况。\n";
                r += "2. 夫妻宫：情感模式、正缘特征（若为空宫，需借对宫并结合暗合宫）、婚姻质量、配偶助力/压力。\n";
                r += "3. 子女宫：子女缘分、生育状况、桃花质量、合伙运、晚年生活。\n";
                r += "4. 财帛宫：求财方式（正财/偏财/专业技能/人脉变现）、财运起伏、最易破财的领域。\n";
                r += "5. 疾厄宫：先天体质强弱、易感疾病、心理健康关注点、意外血光隐患。\n";
                r += "6. 迁移宫：外出发展机遇、贵人类型、人际应对模式、意外灾厄。\n";
                r += "7. 交友宫：人际圈子、部属缘、合作伙伴特质、潜在小人。\n";
                r += "8. 官禄宫：事业方向、职场表现、适合的行业类型（如技术、管理、创意、公职）、事业天花板。\n";
                r += "9. 田宅宫：家庭环境、房产运、家族传承、晚年居所、内心安全感来源。\n";
                r += "10. 福德宫：精神世界、内心快乐来源、因果福报、晚年心境。\n";
                r += "11. 父母宫：父母关系、遗传倾向、长辈助力、文书契约运。\n\n";
                r += "【模块四：格局定性与煞星制化】\n";
                r += "- 格局检测：严格比对“附录中的60种核心格局”，列出命盘中完全符合或高度相似的格局（如紫府同宫、杀破狼、阳梁昌禄等），并解读其对现实命运的投射。若无经典格局，则分析是否有特殊偏格或破格。\n";
                r += "- 煞星淬炼：列出六煞星（擎羊、陀罗、火星、铃星、地空、地劫）所在的宫位，分析它们对宫位带来的具体破坏力（如冲动、拖延、突发变故、空忙等），并结合该宫主星指出如何将煞气转化为锐气（例如：火星+贪狼可激发爆发力，空劫+天机可转向创新思维）。\n\n";
                r += "【模块五：大限流年点拨】\n";
                r += "- 当前大限剖析：根据命主当前年龄（已在基本信息中提供），锁定当前所在大限宫位，分析该宫主星、四化、三方四正对本十年运程的核心影响，指出这十年的发力点（如创业、进修、置业）与潜藏危机（如官司、破财、健康）。\n";
                r += "- 近期流年提示：结合当前年份（系统时间）的流年四化，指出本年度最需要注意的宫位与事件（如流年禄入财帛宜求财，流年忌入疾厄需防病）。\n";
                r += "- 小限与流年互动：简要说明本年度小限所在宫位与流年太岁的互动关系，指出情绪波动或人际焦点。\n\n";
                r += "【模块六：定制化破局之道】\n";
                r += "基于以上分析，给出3-5条高度定制化的实操建议，每条建议需具体、可执行：\n";
                r += "1. 职业赛道选择：结合官禄、财帛、迁移、福德，推荐最匹配的行业领域（如“适合从事流动性强的贸易行业，利用迁移宫天马+禄存的优势”）。\n";
                r += "2. 心智模式升维：针对命主性格短板（如巨门坐命易口舌，太阴陷地易多虑），给出心理调整方法（如“练习非暴力沟通，避免直来直去得罪人”）。\n";
                r += "3. 人际断舍离：根据交友宫、兄弟宫、夫妻宫，建议哪些人需要深交，哪些人应保持距离。\n";
                r += "4. 健康调养方向：针对疾厄宫、福德宫，指出身体薄弱环节及养生重点（如“火星入疾厄，需防心血管疾病，建议少熬夜”）。\n";
                r += "5. 风水/方位建议：结合迁移、田宅、财帛，推荐有利方位（如“往南方发展有利，家中东方保持明亮”）。\n\n";
                r += "【模块七：结语】\n";
                r += "- 用温暖有力的语言总结命主一生的大致轨迹，给予信心与鼓励，同时提醒可能的风险，体现“知命而不认命”的智慧。\n\n";
                r += "【输出格式要求】\n";
                r += "- 使用Markdown格式，层级分明，重点内容加粗。\n";
                r += "- 每个宫位分析时，先列出该宫位的所有星曜（包括四化、庙旺），再写解读。\n";
                r += "- 语言风格需兼具专业性与亲和力，既要有命理术语的精准，又要让不懂紫微的命主能听懂，做到“批命如知己，点拨如导师”。\n";
                r += "- 严格基于提供的数据，若数据缺失某星曜，切勿自行添加。\n";
                r += "```\n";
                
                r += "【附录：紫微斗数 60 种核心格局速查字典（请严格比对星盘，若高度符合请重点深度解析）】\n";
                r += "--- 👑 皇权贵胄/统帅格 (主大贵、掌权、领袖) (8条) ---\n";
                r += "01.[紫府同宫格] 紫微与天府同在寅或申宫。主大富大贵，福寿双全，但易感精神孤独。\n";
                r += "02.[极向离明格] 紫微在午宫坐命，无煞星同宫。主格局宏大，极具领导力，非富即贵。\n";
                r += "03.[君臣庆会格] 紫微坐命，三方有左辅右弼、天魁天钺、文昌文曲会照。得鼎力相助，天生领袖。\n";
                r += "04.[紫府朝垣格] 紫微、天府在三方合照命宫。主食禄万钟，多得贵人提拔。\n";
                r += "05.[府相朝垣格] 天府与天相在三方合照命宫。为人稳重，事业平顺，多为高管或体制内要员。\n";
                r += "06.[七杀朝斗/仰斗格] 七杀在子/午/寅/申坐命，且有吉星。主将帅之才，统领全局，白手起家。\n";
                r += "07.[雄宿朝元格] 廉贞在申宫坐命。主气魄宏大，擅长交际与权谋，宜政界或大企业高管。\n";
                r += "08.[辅弼夹帝格] 紫微坐命，左辅右弼分居左右邻宫。极强的贵人相助格，一生逢凶化吉，众星捧月。\n";
                r += "--- 💰 巨富/暴发/生财格 (主大富、吸金、横财) (8条) ---\n";
                r += "09.[火贪格/铃贪格] 贪狼与火星或铃星同宫。最强暴发格！主突发横财，或异路功名，极具爆发力。\n";
                r += "10.[武贪同行格] 武曲与贪狼同在丑或未宫。主“武贪不发少年人”，三十岁后方发，多为大富商。\n";
                r += "11.[双禄交流格] 化禄与禄存同宫或在三方合照。主财源滚滚，吸金能力极强。\n";
                r += "12.[禄马交驰格] 禄存（或化禄）与天马同宫或三方相会。主在奔波、异地、国际贸易中大发其财。\n";
                r += "13.[三奇嘉会格] 化禄、权、科在命宫三方四正齐会。主一生多逢奇遇，逢凶化吉，名利双收。\n";
                r += "14.[日月夹财格] 武曲在命，太阳与太阴在左右相邻宫位夹持。主一生财源不断，非富即贵。\n";
                r += "15.[日月照壁格] 破军在戌坐命，辰宫(田宅)有太阳太阴同宫。主天生自带极佳的房产运、遗产或祖荫。\n";
                r += "16.[禄马佩印格] 禄存（或化禄）与天马、天相同宫守命。主奔波中得权，因财获贵，食太仓之米，宜贸易、外务、管理。\n";
                r += "--- 🧠 文翰/才华/专业格 (主名望、考试、技术、幕僚) (11条) ---\n";
                r += "17.[机月同梁格] 三方四正凑齐天机、太阴、天同、天梁。极佳的幕僚、企划、技术或公职人才。\n";
                r += "18.[阳梁昌禄格] 太阳、天梁、文昌、禄存（或化禄）同会。考运极佳，易在学术、国考、大企业拔得头筹。\n";
                r += "19.[日月并明格] 命宫在丑或未，太阳在辰（庙）、太阴在戌（庙）三合拱照；或日月同在庙旺之地同宫守命。主光明磊落，少年得志。\n";
                r += "20.[明珠出海格] 未宫无主星坐命（借对宫天同），迁移宫天同，三合方会见卯宫太阳（庙）、亥宫太阴（庙）。主才华洋溢，名声远播。\n";
                r += "21.[石中隐玉格] 巨门在子或午宫坐命，有化权/禄/科，且三方不见煞星，有昌曲左右等吉星会照。早年艰辛，中晚年一鸣惊人，需低调。\n";
                r += "22.[巨日同宫格] 巨门与太阳同在寅或申宫。主口才极佳，宜律师、教育、外交、外贸，异族生财。\n";
                r += "23.[机巨同临格] 天机巨门同在卯或酉宫。主心思极其敏捷，研究能力强，恃才傲物，白手成家。\n";
                r += "24.[文星拱命格] 文昌、文曲在三方合照命宫。主举止温文尔雅，极具文艺才华与书卷气。\n";
                r += "25.[寿星入庙格] 天梁（寿星）在午宫坐命。主正直无私，清贵高寿，多逢凶化吉，适合监察/医疗体系。\n";
                r += "26.[昌曲夹命格] 文昌与文曲夹命宫。主天资聪颖，气质高雅，必定在学业或文艺上有过人之处。\n";
                r += "27.[文梁振纪格] 文曲与天梁在旺地守身命（如午宫天梁、子宫文曲）。主监察、纪检、功名之臣，宜法律、审计、清贵之职。\n";
                r += "--- ⚔️ 刚毅/动荡/异路格 (主波动、险中求胜、大起大落) (6条) ---\n";
                r += "28.[杀破狼格] 七杀、破军、贪狼分别落于命宫、财帛宫、官禄宫。一生大起大落，变动极强，乱世英雄或创业悍将。\n";
                r += "29.[马头带剑格] 擎羊在午宫坐命（或贪狼/同巨在午碰擎羊）。主威镇边疆，富贵险中求，极多惊险。\n";
                r += "30.[擎羊入庙格] 擎羊在辰、戌、丑、未坐命。化煞为权，主刚毅果决，能开创局面，属大将之才。\n";
                r += "31.[刑囚夹印格] 廉贞(囚)与天相(印)同宫，被擎羊(刑)夹击。主有魄力但极为险恶，易惹官司刑伤。\n";
                r += "32.[化星返贵格] 星辰落陷地却得四化引动，如巨门在辰化禄、天同在戌化忌、太阴在辰化禄、太阳在戌化权等，反成佳局。\n";
                r += "33.[英星入庙格] 破军在子午宫坐命，庙旺无煞。主英气勃发，果敢有为，宜军警、创业、开拓性行业。\n";
                r += "--- ⚠️ 凶险/破败/内耗格 (需重点点拨避坑与化解) (13条) ---\n";
                r += "34.[羊陀夹忌格] 化忌星被擎羊、陀罗左右夹击。主极为受制，施展不开，暗箭难防，重大挫折。\n";
                r += "35.[刑忌夹印格] 天相被化忌与天刑（或擎羊）夹击。主官非、文书纠纷、替人背黑锅、受牵连。\n";
                r += "36.[空劫夹命格] 地空、地劫在命宫的左右两邻。主一生起伏极大，理想宏大但多成空，宜修行或技术。\n";
                r += "37.[命里逢空格] 命宫坐地空或地劫，无吉星化解。主一生如同浪里行舟，聚散无常，看淡物质方能得救。\n";
                r += "38.[巨逢四煞格] 巨门落陷，又逢羊陀火铃。主口舌是非极重，人际关系恶劣，甚至惹来血光灾祸。\n";
                r += "39.[铃昌陀武格] 武曲、文昌、铃星、陀罗齐会（辰戌宫最严重）。主面临绝境，重大财务破败或倾覆。\n";
                r += "40.[泛水桃花格] 贪狼在子或亥宫遇煞星或桃花星。主情欲过重，易因色破财或身败名裂。\n";
                r += "41.[风流彩杖格] 贪狼在寅宫逢陀罗。主贪图享乐，感情纠葛极其复杂，易因情色吃官司。\n";
                r += "42.[日月反背格] 命身宫在太阳夜宫（戌亥子）、太阴昼宫（辰巳午）之地，或日月反背夹命。主六亲缘薄，披星戴月，极为劳碌。\n";
                r += "43.[天机巳亥格] 天机在巳或亥落陷坐命。主思绪多变，神经衰弱，多学少精，感情和事业常有变动。\n";
                r += "44.[因财操刀格] 武曲、七杀同宫，逢擎羊。主为求财不择手段，易因金钱引发严重冲突或刑伤。\n";
                r += "45.[禄逢冲破格] 本有禄存或化禄，但在同宫或对宫遭到空劫、化忌冲破。主吉处藏凶，得而复失。\n";
                r += "46.[巨机化酉格] 天机巨门在酉宫坐命，化忌更验。主奔波劳碌，口舌是非，多学少成，易遭人非议。\n";
                r += "--- 🌟 特殊吉格/杂格 (增补) (6条) ---\n";
                r += "47.[文桂文华格] 文昌、文曲分别在命宫的两邻宫夹命。主文采风流，才华过人，考试运极佳。\n";
                r += "48.[月朗天门格] 太阴在亥宫（庙）守命。主为人清白，温文儒雅，男命得贤妻，女命贵为命妇。\n";
                r += "49.[日丽中天格] 太阳在午宫（庙）守命。主光明磊落，心胸开阔，大富大贵，但需防目中无人。\n";
                r += "50.[武曲守垣格] 武曲在戌、辰、丑、未宫（庙旺）坐命。主刚毅果决，适合军警、金融、技术行业，女命则刚强。\n";
                r += "51.[廉贞清白格] 廉贞在亥、子、寅、申宫守命，无煞星冲破。主廉洁自守，清高不群，适合文职、监察、司法工作。\n";
                r += "52.[天乙拱命格] 天魁、天钺在三方四正会照命宫或夹命。主一生多逢贵人，逢凶化吉，尤其利于考试、求职。\n";
                r += "--- ⚡ 特殊凶格/破格 (增补) (8条) ---\n";
                r += "53.[火铃夹命格] 火星、铃星在命宫左右两邻相夹。主突发状况、暴躁、血光，人生起伏极大，需看主星吉凶以定祸福。\n";
                r += "54.[羊陀夹命格] 擎羊、陀罗在命宫左右两邻相夹。主压力大、是非多、行事掣肘，若命宫星弱，则一生劳碌奔波。\n";
                r += "55.[空劫夹命格] 地空、地劫在命宫左右两邻相夹。主理想与现实落差大，钱财难守，需看命宫主星若强，则可转向技术、艺术、哲学等领域突破。\n";
                r += "56.[劫空夹忌格] 地空、地劫夹住化忌（通常指夹命宫或田宅宫）。主钱财破败极为严重，且精神痛苦，多成空。\n";
                r += "57.[极居卯酉格] 紫微贪狼在卯酉坐命。主才艺卓绝或桃花旺盛，加吉星则艺术成名，加煞则易沉迷酒色、感情纠葛。\n";
                r += "58.[科星巡逢格] 化科在命、财、官三合方会齐。主名声显扬，考试高中，适合学术、文化、传播行业。\n";
                r += "59.[权煞化禄格] 煞星（如擎羊、七杀）与化权、化禄同宫。主以杀伐、技术、极端手段获取成功，为“异路功名”之一种。\n";
                r += "60.[财印坐垣格] 武曲（财）与天相（印）在寅、申、巳、亥宫守命。主精于理财，善于谋划，适合企业财务、管理、法务等。\n";
                r += "\\n";
                r += "请你在分析时，自动扫描上述格局，若排盘数据中精准吻合某项（或高度相似），请务必在【模块四：格局定性】中深度解读其对命主现实命运的投射！\\n";
                r += "```\n";

                const html = `
                    <div class="ai-report-card">
                        <div class="ai-report-header">
                            <div class="ai-title-area">
                                <h3><i class="fas fa-file-alt"></i> 紫微斗数文本报告</h3>
                                <p>完整排盘数据，可直接用于深度解析</p>
                            </div>
                            <div class="ai-action-area">
                                <button class="ai-btn ai-btn-back" onclick="generateChart()">
                                    <i class="fas fa-redo"></i> 返回排盘
                                </button>
                                <button class="ai-btn ai-btn-copy" onclick="copyReport()">
                                    <i class="fas fa-copy"></i> 复制全部
                                </button>
                            </div>
                        </div>
                        
                        <div class="ai-hint-box">
                            <i class="fas fa-info-circle"></i>
                            <span>一键复制下方完整文本，粘贴到 DeepSeek、Kimi、豆包 等大模型中，即可获取大师级解析。</span>
                        </div>
                        
                        <textarea id="reportTextarea" class="ai-textarea" spellcheck="false">${r}</textarea>
                        
                        <div class="ai-report-footer">
                            <p class="ai-footer-hint">
                                <i class="fas fa-lightbulb"></i> 提示：尽量避免手动删减文本，以免影响AI准确度
                            </p>
                            <button class="ai-btn ai-btn-copy-main" onclick="copyReport()">
                                <i class="fas fa-copy"></i> 一键复制报告
                            </button>
                        </div>
                    </div>
                `;
                
                resultDiv.innerHTML = html;
                resultDiv.scrollIntoView({behavior: "smooth"});
        
            } catch (e) {
                console.error(e);
                resultDiv.innerHTML = `
                    <div class="loading" style="color:var(--accent)">
                        <i class="fas fa-exclamation-triangle" style="font-size:40px; margin-bottom:20px;"></i>
                        <h3>生成失败</h3>
                        <p>${e.message}</p>
                        <button onclick="generateChart()" style="margin-top:20px; padding:10px 20px; background:var(--accent); color:white; border:none; border-radius:6px; cursor:pointer;">
                            返回排盘
                        </button>
                    </div>
                `;
            } finally {
                btn.disabled = false;
                btn.innerHTML = "<i class=\"fas fa-robot\"></i> 生成AI解读文本";
            }
        }
        
        // 复制功能 (移动端终极防御级兼容版 - 彻底修复排版换行丢失问题)
        async function copyReport() {
            const textarea = document.getElementById("reportTextarea");
            if (!textarea) return;
            const textToCopy = textarea.value.trim();
            // 成功反馈
            const showSuccess = (msg = "✅ 已复制成功！") => {
                const btns = document.querySelectorAll(".ai-btn-copy, .ai-btn-copy-main");
                btns.forEach(btn => {
                    const oldHTML = btn.innerHTML;
                    btn.innerHTML = `<i class="fas fa-check"></i> ${msg}`;
                    btn.style.background = "#1b5e20";
                    btn.style.color = "#fff";
                    setTimeout(() => {
                        btn.innerHTML = oldHTML;
                        btn.style.background = "";
                        btn.style.color = "";
                    }, 2200);
                });
            };
        
            // 第一优先：现代 Clipboard API（电脑 + 大多数手机）
            if (navigator.clipboard && window.isSecureContext) {
                try {
                    await navigator.clipboard.writeText(textToCopy);
                    console.log("✅ 复制成功 - Clipboard API");
                    showSuccess();
                    return;
                } catch (e) {
                    console.warn("Clipboard API 失败，进入经典兼容模式");
                }
            }
        
            // 第二优先：经典隐藏 textarea + execCommand（小米/360/国产浏览器最有效的方法）
            try {
                const tempTextarea = document.createElement("textarea");
                tempTextarea.value = textToCopy;
                tempTextarea.style.position = "fixed";
                tempTextarea.style.top = "0";
                tempTextarea.style.left = "0";
                tempTextarea.style.opacity = "0";
                tempTextarea.style.width = "1px";
                tempTextarea.style.height = "1px";
                document.body.appendChild(tempTextarea);
        
                tempTextarea.focus();
                tempTextarea.select();
                if (tempTextarea.setSelectionRange) {
                    tempTextarea.setSelectionRange(0, textToCopy.length); // iOS + 小米必备
                }

                const success = document.execCommand("copy");
                document.body.removeChild(tempTextarea);
        
                if (success) {
                    console.log("✅ 复制成功 - 经典 execCommand 模式（小米专用）");
                    showSuccess("已复制成功！");
                    return;
                }
            } catch (err) {
                console.warn("execCommand 失败", err);
            }

            // 极少数极端情况才提示手动复制
            console.log("⚠️ 自动复制失败 → 手动全选模式");
            textarea.focus();
            textarea.select();
            if (textarea.setSelectionRange) textarea.setSelectionRange(0, textToCopy.length);

            const toast = document.createElement("div");
            toast.style.cssText = "position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.92);color:#fff;padding:24px 32px;border-radius:18px;font-size:15px;line-height:1.65;text-align:center;z-index:99999;max-width:92%;box-shadow:0 20px 50px rgba(0,0,0,0.5);";
            toast.innerHTML = `✅ 已帮你<strong>自动全选</strong><br><br>请 <span style="color:#ffeb3b">长按文字区域</span><br>→ 点击【复制】`;
            document.body.appendChild(toast);

            setTimeout(() => { toast.style.opacity = "0"; setTimeout(() => toast.remove(), 500); }, 3500);
        }
    ';
}
?>
