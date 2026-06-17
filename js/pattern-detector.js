/**
 * 格局判定模块 (V5.0)
 * 负责八字格局的识别与分类
 */

(function(window) {
  'use strict';

  class PatternDetector {
    /**
     * 判定主格
     * @param {object} nodes - 8个节点
     * @param {object} engine - BaziEngine 实例
     * @returns {object} 格局信息
     */
    static detectMainPattern(nodes, engine) {
      const dmWx = engine.DICT.WX[nodes.dG.char];
      const monthZhi = nodes.mZ.char;
      const {wxE, totalE} = engine.getWxEnergyMap(nodes);

      if (totalE <= 0) return {pattern: '数据异常', grade: '未知'};

      // 计算五行比例
      const wxRatio = {};
      for (const wx in wxE) {
        wxRatio[wx] = (wxE[wx] / totalE * 100).toFixed(1);
      }

      // 判定逻辑（简化版示例）
      let pattern = '杂气局';
      let grade = '中';

      // 月令占大头时为传统格局
      if (wxE[engine.DICT.WX[monthZhi]] > totalE * 0.35) {
        pattern = this._detectTraditionalPattern(monthZhi, dmWx, wxRatio);
        grade = this._evaluateGrade(pattern, wxRatio, dmWx);
      }

      return {
        pattern,
        grade,
        wxRatio,
        summary: `${pattern} (${grade}格)`,
        reasoning: `月令${monthZhi}占比${wxRatio[engine.DICT.WX[monthZhi]]}%`
      };
    }

    /**
     * 识别传统格局
     * @private
     */
    static _detectTraditionalPattern(monthZhi, dmWx, wxRatio) {
      const patterns = {
        '寅': '木旺格', '卯': '木旺格',
        '巳': '火旺格', '午': '火旺格',
        '申': '金旺格', '酉': '金旺格',
        '亥': '水旺格', '子': '水旺格',
        '辰': '湿土格', '未': '燥土格', '丑': '湿土格', '戌': '燥土格'
      };
      return patterns[monthZhi] || '杂气局';
    }

    /**
     * 评估格局等级
     * @private
     */
    static _evaluateGrade(pattern, wxRatio, dmWx) {
      const maxRatio = Math.max(...Object.values(wxRatio).map(Number));
      if (maxRatio > 50) return '上';
      if (maxRatio > 35) return '中';
      return '下';
    }

    /**
     * 判断破格与救应
     */
    static checkPatternBreakStatus(nodes, pattern) {
      const dmChar = nodes.dG.char;
      const dmWx = window.BaziEngine?.prototype?.DICT?.WX?.[dmChar] || '未知';
      
      // 简化判定
      let status = 'intact';
      let detail = '格局完整';

      // 检查日主是否被克制过度
      const officialEnergy = nodes.yG.energy + nodes.mG.energy + nodes.hG.energy;
      if (officialEnergy > nodes.dG.energy * 3) {
        status = 'broken';
        detail = '官杀过旺，日主被克';
      }

      return {status, detail, canRescue: status === 'broken'};
    }
  }

  window.PatternDetector = PatternDetector;
})(window);
