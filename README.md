# mingli

紫微斗数排盘系统 — 单文件部署（index.php）的一体化命理工具，集排盘计算、移动端友好展示与 AI 报告生成功能于一体。

## 主要特性

- 单文件核心：index.php 集成了排盘引擎、前端界面、样式与脚本（仅依赖 lunar.min.js）
- 完整紫微斗数规则实现：主星、辅星、神煞、长生十二神、三方四正、流年/小限/大限计算
- 新增功能：来因宫（Lai Yin）与暗合宫（An He）支持，并在 AI 报告中输出解读文本模版
- 响应式与移动端优化：侧边输入栏、移动端滑动与长按复制星曜支持
- 内置 AI 报告生成器：将排盘数据格式化为可供大模型（如 DeepSeek/Kimi）使用的完整文本提示与数据块
- 无需外部后台依赖（可将 index.php 部署在支持 PHP 的任意服务器）

## 快速开始

### 要求

- PHP 7.4+（推荐 8.x）
- Web 服务器（Apache、Nginx 等）
- lunar.min.js（已包含或可从仓库/CDN 引入）

### 本地部署

1. 克隆或下载仓库到 web 根目录：

   ```bash
   git clone https://github.com/kyqf-ai/mingli.git
   ```

2. 确保 web 服务器可访问 index.php（例如访问 http://localhost/mingli/index.php）
3. 打开页面，在左侧表单输入出生信息，点击「生成命盘」或「生成AI解读文本」

### API 使用

- 返回 JSON 的排盘接口：
  - URL: index.php?action=api
  - 方法: POST 或 GET
  - 必需参数（示例字段名）：year_gan, year_zhi, hour_gan, hour_zhi, lunar_month, lunar_day
  - 返回示例字段：basic（含姓名、性别、八字等）、palaces（12 宫的星曜结构）、info（来因宫、命宫索引等）

示例（伪代码）：

```bash
POST /index.php?action=api
Content-Type: application/x-www-form-urlencoded

year_gan=甲&year_zhi=子&hour_gan=甲&hour_zhi=子&lunar_month=1&lunar_day=1
```

响应：JSON 包含 palaces 与 info 字段（详见源码 index.php 中的 handleApiRequest）

## 文件结构

- index.php —— 核心文件，包含前端界面、CSS、JS 与 PHP 排盘引擎
- lunar.min.js —— 农历与干支计算库（页面依赖）
- BaziZJ_V2_8.html, hehun_v56.html —— 辅助演示/参考页面
- README.md —— 项目说明（本文件）

## 使用说明（界面）

1. 在左侧表单输入姓名、性别、日期类型（公历/农历）与出生时间。
2. 对于农历且为闰月的情况，请勾选「是闰月��或确保正确选择闰月。
3. 点击「生成命盘」即可在右侧看到可交互的十二宫排盘。点击宫位会高亮三方四正。
4. 点击「生成AI解读文本」可生成用于大模型的完整报告文本并支持一键复制。

## 开发者说明

- 核心排盘类：ZiWei、ZiWeiData、DateTimeHandler（均在 index.php 中）
- 主要扩展：来因宫（LAI_YIN_POSITION）与暗合宫映射（AN_HE_MAP），并在渲染与 API 中暴露
- 前端使用 lunar.min.js 提供的 Lunar/Solar API 将日期转换为四柱与时支，前端将标准化字段通过隐藏输入传给后端

## 已知事项与注意

- lunar.min.js 的版本差异可能影响闰月判断，请保证使用仓库内的同版本或兼容版本
- 部署至 HTTPS 时，浏览器剪贴板 API 与某些功能体验更佳
- 若需要将 AI 报告直接接入在线模型，请注意将敏感信息脱敏后再调用第三方服务

## 贡献

欢迎通过 Issue 或 Pull Request 提交错误修复、功能增强或界面优化。请在贡献前先开启 Issue 简述变更意图。

## 许可证

MIT License —— 详见仓库 LICENSE（如无此文件请在合并前补充）

---  
（自动生成于 2026-04-23，基于 index.php 源码分析生成 README）