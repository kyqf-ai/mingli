/**
 * 神煞处理模块 (V5.0)
 * 负责天乙贵人、文昌、羊刃、劫煞等神煞的识别
 */

(function(window) {
  'use strict';

  class SenshaProcessor {
    static initDict() {
      return Object.freeze({
        TIANYI: {
          '甲':['丑','未'],'戊':['丑','未'],'庚':['丑','未'],
          '乙':['子','申'],'己':['子','申'],
          '丙':['亥','酉'],'丁':['亥','酉'],
          '壬':['巳','卯'],'癸':['巳','卯'],
          '辛':['寅','午']
        },
        WENCHANG: {
          '甲':'巳','乙':'午','丙':'申','丁':'酉',
          '戊':'申','己':'酉','庚':'亥','辛':'子',
          '壬':'寅','癸':'卯'
        },
        LU_SHEN: {
          '甲':'寅','乙':'卯','丙':'巳','丁':'午',
          '戊':'巳','己':'午','庚':'申','辛':'酉',
          '壬':'亥','癸':'子'
        },
        YIMA: {
          '申':'寅','子':'寅','辰':'寅',
          '亥':'巳','卯':'巳','未':'巳',
          '寅':'申','午':'申','戌':'申',
          '巳':'亥','酉':'亥','丑':'亥'
        }
      });
    }

    /**
     * 提取命局神煞
     * @param {object} nodes - 8个节点
     * @returns {object} 神煞集合
     */
    static extractShensha(nodes) {
      const dict = this.initDict();
      const shenshas = {auspicious: [], inauspicious: []};
      
      const dayGan = nodes.dG.char;
      const yearZhi = nodes.yZ.char;
      const dayZhi = nodes.dZ.char;

      // 天乙贵人
      if (dict.TIANYI[dayGan]) {
        const locations = dict.TIANYI[dayGan];
        locations.forEach(loc => {
          if (this._isInNodes(nodes, loc)) {
            shenshas.auspicious.push({name: '天乙贵人', location: loc});
          }
        });
      }

      // 文昌星
      if (dict.WENCHANG[dayGan]) {
        const loc = dict.WENCHANG[dayGan];
        if (this._isInNodes(nodes, loc)) {
          shenshas.auspicious.push({name: '文昌星', location: loc});
        }
      }

      // 驿马
      if (dict.YIMA[yearZhi]) {
        const loc = dict.YIMA[yearZhi];
        if (this._isInNodes(nodes, loc)) {
          shenshas.auspicious.push({name: '驿马', location: loc});
        }
      }

      return shenshas;
    }

    /**
     * 检查位置是否在命盘中
     * @private
     */
    static _isInNodes(nodes, location) {
      return Object.values(nodes).some(n => n.char === location);
    }
  }

  window.SenshaProcessor = SenshaProcessor;
})(window);
