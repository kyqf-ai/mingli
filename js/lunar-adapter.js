/**
 * Lunar框架适配层 (V5.0)
 * 将 Lunar.js 的数据结构转换为命盘推演所需的统一格式
 * 负责日期转换、八字提取、大运计算等
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
        // 转换为农历
        const lunar = Lunar.fromYmd(year, month, day);
        if (!lunar) throw new Error('日期转换失败');

        const eightChar = lunar.getEightChar();
        if (!eightChar) throw new Error('八字提取失败');

        return {
          solar: { year, month, day, hour, minute },
          lunar: {
            year: lunar.getYear(),
            month: lunar.getMonth(),
            day: lunar.getDay(),
            isLeap: lunar.isLeap()
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
            version: 'V5.0'
          }
        };
      } catch (error) {
        console.error('[LunarAdapter] 八字创建错误:', error);
        throw new Error(`八字转换失败: ${error.message}`);
      }
    }

    /**
     * 生成大运表
     * @param {object} baziData - 八字数据
     * @param {number} gender - 1=男, 0=女
     * @param {number} sect - 流派 1 或 2
     * @returns {array} 大运对象数组
     */
    static generateDayun(baziData, gender = 1, sect = 2) {
      try {
        const lunar = Lunar.fromYmd(
          baziData.solar.year,
          baziData.solar.month,
          baziData.solar.day
        );
        const yun = lunar.getYun(gender, sect);
        const dayunList = yun.getDaYun();

        return dayunList.map((item, idx) => ({
          index: idx,
          startYear: item.getStartYear(),
          endYear: item.getEndYear(),
          startAge: item.getStartAge(),
          endAge: item.getEndAge(),
          ganZhi: item.getGanZhi(),
          xunKong: item.getXunKong(),
          liuNian: this._extractLiuNian(item),
          xiaoYun: this._extractXiaoYun(item)
        }));
      } catch (error) {
        console.error('[LunarAdapter] 大运生成错误:', error);
        return [];
      }
    }

    /**
     * 从大运对象提取流年数组
     * @private
     */
    static _extractLiuNian(dayunItem) {
      const liuNianList = dayunItem.getLiuNian() || [];
      return liuNianList.map(ln => ({
        year: ln.getYear(),
        age: ln.getAge(),
        ganZhi: ln.getGanZhi(),
        xunKong: ln.getXunKong()
      }));
    }

    /**
     * 从大运对象提取小运数组
     * @private
     */
    static _extractXiaoYun(dayunItem) {
      const xiaoYunList = dayunItem.getXiaoYun() || [];
      return xiaoYunList.map(xy => ({
        year: xy.getYear(),
        age: xy.getAge(),
        ganZhi: xy.getGanZhi(),
        xunKong: xy.getXunKong()
      }));
    }

    /**
     * 解析旬空信息
     * @param {string} xunKongStr - 旬空字符串,如 "子丑"
     * @returns {array} [干,支] 旬空对
     */
    static parseXunKong(xunKongStr) {
      if (!xunKongStr || xunKongStr.length !== 2) return [null, null];
      return [xunKongStr[0], xunKongStr[1]];
    }

    /**
     * 验证输入日期的有效性
     */
    static validateDate(year, month, day) {
      if (year < 1900 || year > 2100) return false;
      if (month < 1 || month > 12) return false;
      if (day < 1 || day > 31) return false;
      try {
        const lunar = Lunar.fromYmd(year, month, day);
        return lunar !== null;
      } catch {
        return false;
      }
    }
  }

  // 导出到全局
  window.LunarAdapter = LunarAdapter;
})(window);
