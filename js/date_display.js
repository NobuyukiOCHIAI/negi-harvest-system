/**
 * 管理画面日付表記: ●月●週（週開始月曜 Y-m-d）
 * 例: formatMonthWeek('2026-03-12') → '3月2週（2026-03-09）'
 */
(function (global) {
  function weekMonday(dateStr) {
    if (!dateStr || dateStr === '-') return null;
    const d = new Date(String(dateStr).slice(0, 10) + 'T00:00:00');
    if (isNaN(d.getTime())) return null;
    const day = d.getDay(); // 0=Sun
    const offset = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + offset);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + dd;
  }

  function formatMonthWeek(dateStr, empty) {
    empty = empty == null ? '—' : empty;
    const mon = weekMonday(dateStr);
    if (!mon) return empty;
    const d = new Date(mon + 'T00:00:00');
    const month = d.getMonth() + 1;
    const wom = Math.ceil(d.getDate() / 7);
    return month + '月' + wom + '週（' + mon + '）';
  }

  global.formatMonthWeek = formatMonthWeek;
  global.weekMondayDate = weekMonday;
})(typeof window !== 'undefined' ? window : globalThis);
