/**
 * 应用主入口 (V5.0)
 * 协调各模块，处理用户交互
 */

(function(window) {
  'use strict';

  // 全局应用对象
  const App = {
    engine: null,
    currentBazi: null,
    currentNodes: null,
    isAnalyzing: false,

    /**
     * 初始化应用
     */
    init() {
      console.log('[App] 初始化 V5.0');
      this.engine = new window.BaziEngine();
      this._bindEvents();
    },

    /**
     * 绑定事件监听
     * @private
     */
    _bindEvents() {
      const btn = document.getElementById('analyzeBtn');
      if (btn) {
        btn.addEventListener('click', () => this.analyze());
      }
    },

    /**
     * 主分析流程
     */
    async analyze() {
      if (this.isAnalyzing) return;
      this.isAnalyzing = true;
      
      try {
        window.UIRenderer.showLoading(true);

        // 获取用户输入
        const dateInput = document.getElementById('date')?.value;
        const timeInput = document.getElementById('time')?.value;
        const genderRadios = document.querySelectorAll('input[name="gender"]');
        
        if (!dateInput || !timeInput) {
          throw new Error('请输入完整的出生日期和时间');
        }

        const [year, month, day] = dateInput.split('-').map(Number);
        const [hour, minute] = timeInput.split(':').map(Number);
        const gender = Array.from(genderRadios).find(r => r.checked)?.value === '1' ? 1 : 0;

        // 验证日期
        if (!window.LunarAdapter.validateDate(year, month, day)) {
          throw new Error('日期无效或不支持');
        }

        console.log(`[App] 开始推演: ${year}-${month}-${day} ${hour}:${minute}`);

        // Step 1: Lunar转换
        this.currentBazi = window.LunarAdapter.createBaziFromSolar(year, month, day, hour, minute);
        console.log('[App] 八字创建成功', this.currentBazi.eightChar);

        // Step 2: 初始化节点
        this.currentNodes = this.engine.initializeNodes(this.currentBazi);
        console.log('[App] 节点初始化完成');

        // Step 3: 提取旬空
        this._applyXunKong();
        
        // Step 4: 提取神煞
        const shenshas = window.SenshaProcessor.extractShensha(this.currentNodes);
        console.log('[App] 神煞提取完成', shenshas);

        // Step 5: 格局判定
        const pattern = window.PatternDetector.detectMainPattern(
          this.currentNodes,
          this.engine
        );
        console.log('[App] 格局判定完成', pattern);

        // Step 6: 破格救应检测
        const breakStatus = window.PatternDetector.checkPatternBreakStatus(
          this.currentNodes,
          pattern.pattern
        );
        console.log('[App] 破格检测完成', breakStatus);

        // Step 7: 生成大运
        const dayun = window.LunarAdapter.generateDayun(this.currentBazi, gender, 2);
        console.log('[App] 大运生成完成', dayun.length);

        // Step 8: 渲染UI
        this._renderResults(shenshas, pattern, dayun);

        window.UIRenderer.showOutput();
        console.log('[App] 推演完成 ✅');

      } catch (error) {
        console.error('[App] 推演错误:', error);
        window.UIRenderer.showError(error.message);
      } finally {
        window.UIRenderer.showLoading(false);
        this.isAnalyzing = false;
      }
    },

    /**
     * 应用旬空信息
     * @private
     */
    _applyXunKong() {
      const ec = this.currentBazi.eightChar;
      const xunKongMap = {
        'yearXunKong': ['yG', 'yZ'],
        'monthXunKong': ['mG', 'mZ'],
        'dayXunKong': ['dG', 'dZ'],
        'timeXunKong': ['hG', 'hZ']
      };

      for (const [key, [ganId, zhiId]] of Object.entries(xunKongMap)) {
        const xkStr = ec[key];
        if (xkStr && xkStr.length === 2) {
          const [xkGan, xkZhi] = Array.from(xkStr);
          if (this.currentNodes[ganId].char === xkGan) {
            this.currentNodes[ganId].isVoid = true;
          }
          if (this.currentNodes[zhiId].char === xkZhi) {
            this.currentNodes[zhiId].isVoid = true;
          }
        }
      }
    },

    /**
     * 渲染所有结果
     * @private
     */
    _renderResults(shenshas, pattern, dayun) {
      // 1. 渲染命盘
      window.UIRenderer.renderNodeGrid(this.currentNodes);

      // 2. 渲染五行能量
      const {wxE} = this.engine.getWxEnergyMap(this.currentNodes);
      const wxRatio = {};
      const totalE = Object.values(wxE).reduce((a, b) => a + b, 0);
      for (const wx in wxE) {
        wxRatio[wx] = (wxE[wx] / totalE * 100).toFixed(1);
      }
      window.UIRenderer.renderWuxingBar(wxRatio);

      // 3. 渲染格局信息面板
      const dashBoard = document.getElementById('mainDashboard');
      if (dashBoard) {
        dashBoard.innerHTML = `
          <div class="panel-left">
            <div class="panel-title">格局分析</div>
            <div class="pattern-name">${pattern.pattern}</div>
            <div class="pattern-desc">${pattern.reasoning}</div>
            <div class="break-status-box ${pattern.status || 'intact'}"> 
              ${pattern.summary}
            </div>
          </div>
          <div class="panel-right">
            <div class="panel-title">神煞概览</div>
            <div class="system-tags">
              ${(shenshas.auspicious || []).map(s => 
                `<span class="sys-tag tag-info">✨ ${s.name}</span>`
              ).join('')}
            </div>
          </div>
        `;
      }
    }
  };

  // 页面加载完成时初始化
  document.addEventListener('DOMContentLoaded', () => App.init());

  // 导出全局函数
  window.handleAnalyze = () => App.analyze();
})(window);
