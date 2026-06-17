/**
 * Lunar框架适配层 (V5.0 修复版)
 * 修复 lunar.isLeap() 不存在的问题，改用官方API
 */

(function(window) {
  'use strict';

  /**
   * LunarAdapter 类 - Lunar框架与八字引擎的桥接
   */
  class LunarAdapter {
    /**
     * 从公历日期和时间创建八字数据
     * @param {number} year - 公历年
     * @param {number} month - 公历月
     * @param {number} day - 公历日
     * @param {number} hour - 时辰数字 (0-23)
     * @param {number} minute - 分钟
     * @returns {object} 八字数据对象
     */
    static createBaziFromSolar(year, month, day, hour, minute) {
      try {
        // 使用Solar.fromYmd而非deprecated的Lunar.fromYmd
        let lunar = null;
        
        // 尝试使用Solar API（推荐）
        if (window.Lunar && window.Lunar.Solar) {
          const solar = window.Lunar.Solar.fromYmd(year, month, day);
          if (solar) {
            lunar = solar.getLunar();
          }
        }
        
        // 回退到旧API
        if (!lunar && window.Lunar && window.Lunar.fromYmd) {
          lunar = window.Lunar.fromYmd(year, month, day);
        }
        
        if (!lunar) {
          throw new Error('日期转换失败：无法创建农历对象');
        }

        const eightChar = lunar.getEightChar();
        if (!eightChar) {
          throw new Error('八字提取失败');
        }

        // 获取闰月标志（兼容多种API版本）
        let isLeapMonth = false;
        if (typeof lunar.isLeap === 'function') {
          try {
            isLeapMonth = lunar.isLeap();
          } catch (e) {
            isLeapMonth = lunar.leap === 1;
          }
        } else if (lunar.leap !== undefined) {
          isLeapMonth = lunar.leap === 1;
        }

        return {
          solar: { year, month, day, hour, minute },
          lunar: {
            year: lunar.getYear(),
            month: lunar.getMonth(),
            day: lunar.getDay(),
            leap: isLeapMonth
          },
          eightChar: {
            year: eightChar.getYear(),
            month: eightChar.getMonth(),
            day: eightChar.getDay(),
            time: eightChar.getTime(),
            // 天干地支细节
            yearGan: eightChar.getYearGan(),
            yearZhi: eightChar.getYearZhi(),
            monthGan: eightChar.getMonthGan(),
            monthZhi: eightChar.getMonthZhi(),
            dayGan: eightChar.getDayGan(),
            dayZhi: eightChar.getDayZhi(),
            timeGan: eightChar.getTimeGan(),
            timeZhi: eightChar.getTimeZhi(),
            // 旬空信息
            yearXunKong: eightChar.getYearXunKong(),
            monthXunKong: eightChar.getMonthXunKong(),
            dayXunKong: eightChar.getDayXunKong(),
            timeXunKong: eightChar.getTimeXunKong(),
            // 十二长生
            yearDiShi: eightChar.getYearDiShi(),
            monthDiShi: eightChar.getMonthDiShi(),
            dayDiShi: eightChar.getDayDiShi(),
            timeDiShi: eightChar.getTimeDiShi()
          },
          meta: {
            createdAt: new Date().toISOString(),
            version: 'V5.0-fixed'
          }
        };
      } catch (error) {
        console.error('[LunarAdapter] 八字创建错误:', error.message);
        throw new Error(`八字转换失败: ${error.message}`);\n      }\n    }\n\n    /**\n     * 生成大运表\n     * @param {object} baziData - 八字数据\n     * @param {number} gender - 1=男, 0=女\n     * @param {number} sect - 流派 1 或 2\n     * @returns {array} 大运对象数组\n     */\n    static generateDayun(baziData, gender = 1, sect = 2) {\n      try {\n        let lunar = null;\n        \n        // 使用Solar API\n        if (window.Lunar && window.Lunar.Solar) {\n          const solar = window.Lunar.Solar.fromYmd(\n            baziData.solar.year,\n            baziData.solar.month,\n            baziData.solar.day\n          );\n          if (solar) lunar = solar.getLunar();\n        }\n        \n        // 回退到旧API\n        if (!lunar && window.Lunar && window.Lunar.fromYmd) {\n          lunar = window.Lunar.fromYmd(\n            baziData.solar.year,\n            baziData.solar.month,\n            baziData.solar.day\n          );\n        }\n        \n        if (!lunar) return [];\n        \n        const yun = lunar.getYun(gender, sect);\n        const dayunList = yun.getDaYun();\n\n        return dayunList.map((item, idx) => ({\n          index: idx,\n          startYear: item.getStartYear(),\n          endYear: item.getEndYear(),\n          startAge: item.getStartAge(),\n          endAge: item.getEndAge(),\n          ganZhi: item.getGanZhi(),\n          xunKong: item.getXunKong(),\n          liuNian: this._extractLiuNian(item),\n          xiaoYun: this._extractXiaoYun(item)\n        }));\n      } catch (error) {\n        console.error('[LunarAdapter] 大运生成错误:', error);\n        return [];\n      }\n    }\n\n    /**\n     * 从大运对象提取流年数组\n     * @private\n     */\n    static _extractLiuNian(dayunItem) {\n      try {\n        const liuNianList = dayunItem.getLiuNian() || [];\n        return liuNianList.map(ln => ({\n          year: ln.getYear(),\n          age: ln.getAge(),\n          ganZhi: ln.getGanZhi(),\n          xunKong: ln.getXunKong ? ln.getXunKong() : null\n        }));\n      } catch (error) {\n        return [];\n      }\n    }\n\n    /**\n     * 从大运对象提取小运数组\n     * @private\n     */\n    static _extractXiaoYun(dayunItem) {\n      try {\n        const xiaoYunList = dayunItem.getXiaoYun() || [];\n        return xiaoYunList.map(xy => ({\n          year: xy.getYear(),\n          age: xy.getAge(),\n          ganZhi: xy.getGanZhi(),\n          xunKong: xy.getXunKong ? xy.getXunKong() : null\n        }));\n      } catch (error) {\n        return [];\n      }\n    }\n\n    /**\n     * 解析旬空信息\n     * @param {string} xunKongStr - 旬空字符串,如 \"子丑\"\n     * @returns {array} [干,支] 旬空对\n     */\n    static parseXunKong(xunKongStr) {\n      if (!xunKongStr || xunKongStr.length !== 2) return [null, null];\n      return [xunKongStr[0], xunKongStr[1]];\n    }\n\n    /**\n     * 验证输入日期的有效性\n     */\n    static validateDate(year, month, day) {\n      if (year < 1900 || year > 2100) return false;\n      if (month < 1 || month > 12) return false;\n      if (day < 1 || day > 31) return false;\n      try {\n        let lunar = null;\n        \n        // 尝试Solar API\n        if (window.Lunar && window.Lunar.Solar) {\n          const solar = window.Lunar.Solar.fromYmd(year, month, day);\n          lunar = solar ? solar.getLunar() : null;\n        }\n        \n        // 回退到旧API\n        if (!lunar && window.Lunar && window.Lunar.fromYmd) {\n          lunar = window.Lunar.fromYmd(year, month, day);\n        }\n        \n        return lunar !== null && lunar !== undefined;\n      } catch (error) {\n        return false;\n      }\n    }\n  }\n\n  // 导出到全局\n  window.LunarAdapter = LunarAdapter;\n})(window);\n