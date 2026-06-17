/**
 * 八字推演核心引擎 (V5.0 模块化版)
 * 包含五行能量计算、格局判定、旺衰评估等核心逻辑
 */

(function(window) {
  'use strict';

  /**
   * BaziEngine - 核心推演引擎
   */
  class BaziEngine {
    constructor() {
      this.DICT = this._initDict();
      this.cache = new Map();
    }

    /**
     * 初始化命理字典
     * @private
     */
    _initDict() {
      return Object.freeze({
        WX: {
          '甲':'木','乙':'木','丙':'火','丁':'火','戊':'土','己':'土','庚':'金','辛':'金','壬':'水','癸':'水',
          '寅':'木','卯':'木','巳':'火','午':'火','申':'金','酉':'金','亥':'水','子':'水','辰':'土','戌':'土','丑':'土','未':'土'
        },
        SHISHEN: {
          '木':{self:'木', res:'水', off:'金', wea:'土', out:'火'},
          '火':{self:'火', res:'木', off:'水', wea:'金', out:'土'},
          '土':{self:'土', res:'火', off:'木', wea:'水', out:'金'},
          '金':{self:'金', res:'土', off:'火', wea:'木', out:'水'},
          '水':{self:'水', res:'金', off:'土', wea:'火', out:'木'}
        },
        HIDDEN: {
          '子':['癸'], '丑':['己','癸','辛'], '寅':['甲','丙','戊'], '卯':['乙'],
          '辰':['戊','乙','癸'], '巳':['丙','戊','庚'], '午':['丁','己'], '未':['己','丁','乙'],
          '申':['庚','壬','戊'], '酉':['辛'], '戌':['戊','辛','丁'], '亥':['壬','甲']
        },
        HIDDEN_WEIGHT: [0.65, 0.25, 0.10],
        HIDDEN_WEIGHT_MAP: {
          '子': [1.00], '丑': [0.60, 0.32, 0.07], '寅': [0.60, 0.30, 0.10], '卯': [1.00],
          '辰': [0.60, 0.30, 0.10], '巳': [0.60, 0.30, 0.10], '午': [0.70, 0.30], '未': [0.60, 0.30, 0.10],
          '申': [0.60, 0.30, 0.10], '酉': [1.00], '戌': [0.60, 0.32, 0.07], '亥': [0.70, 0.30]
        },
        HE_GAN: {'甲':'己','己':'甲','乙':'庚','庚':'乙','丙':'辛','辛':'丙','丁':'壬','壬':'丁','戊':'癸','癸':'戊'},
        CHONG_ZHI: {'子':'午','午':'子','丑':'未','未':'丑','寅':'申','申':'寅','卯':'酉','酉':'卯','辰':'戌','戌':'辰','巳':'亥','亥':'巳'},
        LIU_HE_TRANSFORM: {'子丑':'土', '寅亥':'木', '卯戌':'火', '辰酉':'金', '巳申':'水', '午未':'土'}
      });
    }

    /**
     * 初始化命盘节点
     * @param {object} baziData - Lunar适配器返回的八字数据
     * @returns {object} 8个节点对象
     */
    initializeNodes(baziData) {
      const ec = baziData.eightChar;
      return {
        yG: this._createNode('yG', '年干', ec.yearGan, '干'),
        yZ: this._createNode('yZ', '年支', ec.yearZhi, '支'),
        mG: this._createNode('mG', '月干', ec.monthGan, '干'),
        mZ: this._createNode('mZ', '月令', ec.monthZhi, '支'),
        dG: this._createNode('dG', '日主', ec.dayGan, '干'),
        dZ: this._createNode('dZ', '日支', ec.dayZhi, '支'),
        hG: this._createNode('hG', '时干', ec.timeGan, '干'),
        hZ: this._createNode('hZ', '时支', ec.timeZhi, '支')
      };
    }

    /**
     * 创建单个节点对象
     * @private
     */
    _createNode(id, label, char, type) {
      return {
        id, label, char, type,
        originalWx: this.DICT.WX[char],
        currentWx: this.DICT.WX[char],
        energy: 1.0,
        states: new Set(),
        shenshas: [],
        qiPhase: '',
        isVoid: false,
        isTransformed: false,
        transformedWx: null,
        transformRatio: 0.0,
        targetWx: null,
        _inactive: false,
        
        // 方法
        addState(stateStr, energyMult = 1.0) {
          if (!this.states.has(stateStr)) {
            this.states.add(stateStr);
            this.energy *= energyMult;
            if (['重伤','被反噬','蚍蜉撼树','被抽干'].includes(stateStr)) {
              this._inactive = true;
            }
          }
        },
        hasState(stateStr) {
          for (const s of this.states) { if (s.includes(stateStr)) return true; }
          return false;
        },
        get effectiveWx() { return this.isTransformed ? this.transformedWx : this.currentWx; },
        isActive() {
          if (this._inactive) return false;
          if (this.states.has('受冲') && this.energy < 0.35) return false;
          return true;
        }
      };
    }

    /**
     * 计算五行能量分布
     * @param {object} nodes - 8个节点
     * @param {boolean} excludeDm - 是否排除日主
     * @returns {object} {wxE: {木,火,土,金,水}, totalE}
     */
    getWxEnergyMap(nodes, excludeDm = false) {
      const cacheKey = `wxEnergy_${excludeDm}`;
      if (this.cache.has(cacheKey)) return this.cache.get(cacheKey);

      let wxE = {'木':0,'火':0,'土':0,'金':0,'水':0}, totalE = 0;
      
      Object.values(nodes).forEach(n => {
        if (!n.isActive()) return;
        if (excludeDm && n.id === 'dG') return;

        const weights = { 'mZ': 2.5, other_zhi: 1.5, other_gan: 1.0 };
        let w = n.type === '支' ? (n.id === 'mZ' ? weights.mZ : weights.other_zhi) : weights.other_gan;
        if (n.isVoid) w *= 0.2;

        const finalEnergy = n.energy * w;
        wxE[n.effectiveWx] = (wxE[n.effectiveWx] || 0) + finalEnergy;
        totalE += finalEnergy;
      });

      const result = {wxE, totalE};
      this.cache.set(cacheKey, result);
      return result;
    }

    /**
     * 获取目标五行在四地支中的通根
     * @param {object} nodes
     * @param {string} targetWx
     * @returns {object} {mainCount, subCount, rootNodes}
     */
    getRootsData(nodes, targetWx) {
      let result = {mainCount: 0, subCount: 0, rootNodes: []};
      const branches = [nodes.yZ, nodes.mZ, nodes.dZ, nodes.hZ];

      branches.forEach(z => {
        if (!z || z.hasState('重伤') || (z.hasState('受冲') && z.energy < 0.35)) return;
        const hidden = this.DICT.HIDDEN[z.char] || [];
        hidden.forEach((h, idx) => {
          if (h && this.DICT.WX[h] === targetWx) {
            if (idx === 0) result.mainCount++;
            else result.subCount++;
            if (!result.rootNodes.includes(z)) result.rootNodes.push(z);
          }
        });
      });
      return result;
    }

    /**
     * 清除缓存
     */
    clearCache() {
      this.cache.clear();
    }
  }

  window.BaziEngine = BaziEngine;
})(window);
