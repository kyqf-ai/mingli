/**
 * UI渲染器模块 (V5.0)
 * 负责命盘、旺衰图、格局等信息的页面展示
 */

(function(window) {
  'use strict';

  class UIRenderer {
    /**
     * 渲染命盘网格
     * @param {object} nodes - 8个节点
     */
    static renderNodeGrid(nodes) {
      const grid = document.getElementById('nodeGrid');
      if (!grid) return;
      grid.innerHTML = '';

      const pillars = [
        ['yG', 'yZ', '年柱'],
        ['mG', 'mZ', '月柱'],
        ['dG', 'dZ', '日柱'],
        ['hG', 'hZ', '时柱']
      ];

      pillars.forEach(([ganId, zhiId, title]) => {
        const col = document.createElement('div');
        col.className = 'pillar-col';
        col.innerHTML = `
          <div class="pillar-header">${title}</div>
          <div class="node-card ${ganId === 'dG' ? 'dm' : ''} ${nodes[ganId].isVoid ? 'is-void' : ''}">
            <div class="node-char">${nodes[ganId].char}</div>
            <div class="node-wx wx-${nodes[ganId].originalWx}">${nodes[ganId].originalWx}</div>
            <div class="node-phase">${nodes[ganId].qiPhase}</div>
          </div>
          <div class="node-card ${nodes[zhiId].isVoid ? 'is-void' : ''}">
            <div class="node-char">${nodes[zhiId].char}</div>
            <div class="node-wx wx-${nodes[zhiId].originalWx}">${nodes[zhiId].originalWx}</div>
            <div class="node-phase">${nodes[zhiId].qiPhase}</div>
          </div>
        `;
        grid.appendChild(col);
      });
    }

    /**
     * 渲染五行能量柱状图
     * @param {object} wxRatio - 五行比例
     */
    static renderWuxingBar(wxRatio) {
      const container = document.getElementById('wxingBar');
      if (!container) return;

      const wxOrder = ['木', '火', '土', '金', '水'];
      let html = '<div class="wuxing-bar">';
      wxOrder.forEach(wx => {
        const ratio = parseFloat(wxRatio[wx] || 0);
        html += `<div class="wuxing-bar-seg wxseg-${wx}" style="width:${ratio}%">${wx}${ratio.toFixed(0)}%</div>`;
      });
      html += '</div>';
      container.innerHTML = html;
    }

    /**
     * 显示错误信息
     */
    static showError(message) {
      const errorEl = document.getElementById('errorMessage');
      if (!errorEl) return;
      errorEl.textContent = `❌ ${message}`;
      errorEl.style.display = 'block';
      setTimeout(() => errorEl.style.display = 'none', 5000);
    }

    /**
     * 显示加载状态
     */
    static showLoading(isLoading) {
      const loader = document.getElementById('loadingIndicator');
      if (loader) loader.style.display = isLoading ? 'block' : 'none';
    }

    /**
     * 显示输出区域
     */
    static showOutput() {
      const output = document.getElementById('outputArea');
      if (output) output.style.display = 'block';
    }
  }

  window.UIRenderer = UIRenderer;
})(window);
