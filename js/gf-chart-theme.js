/**
 * GreenFarm chart visual theme — 本線(緑)強調・系列の役割固定
 * 計画=緑実線 / 定植済=灰破線 / GCAL=紫 / 営業現実・SIM=青 / 昨対=薄い灰
 */
(function (global) {
  const C = {
    plan: '#1b7a4a',
    planFill: 'rgba(27,122,74,0.12)',
    planted: '#9e9e9e',
    gcal: '#6a1b9a',
    sales: '#1565c0',
    yoy: '#cfd8dc',
    pos: 'rgba(27,122,74,0.55)',
    neg: 'rgba(196,70,40,0.55)',
    zero: 'rgba(0,0,0,0.28)',
  };

  const legend = {
    position: 'bottom',
    labels: { boxWidth: 10, font: { size: 10 }, padding: 10 },
  };

  function yKg(beginAtZero) {
    return {
      beginAtZero: !!beginAtZero,
      ticks: { callback: (v) => v + 'kg', font: { size: 10 } },
      grid: {
        color: (ctx) => (ctx.tick && ctx.tick.value === 0 ? C.zero : 'rgba(0,0,0,0.06)'),
        lineWidth: (ctx) => (ctx.tick && ctx.tick.value === 0 ? 1.5 : 1),
      },
    };
  }

  /** 0kg 水平線 */
  const zeroPlugin = {
    id: 'gfZeroLine',
    afterDraw(chart) {
      const y = chart.scales.y;
      const area = chart.chartArea;
      if (!y || !area) return;
      if (y.min > 0 || y.max < 0) return;
      const yp = y.getPixelForValue(0);
      const ctx = chart.ctx;
      ctx.save();
      ctx.beginPath();
      ctx.moveTo(area.left, yp);
      ctx.lineTo(area.right, yp);
      ctx.strokeStyle = C.zero;
      ctx.lineWidth = 1.25;
      ctx.setLineDash([4, 3]);
      ctx.stroke();
      ctx.restore();
    },
  };

  function dsPlan(label, data, extra) {
    return Object.assign(
      {
        label,
        data,
        borderColor: C.plan,
        backgroundColor: C.planFill,
        borderWidth: 3,
        tension: 0.25,
        fill: false,
        pointRadius: 0,
        pointHoverRadius: 4,
      },
      extra || {}
    );
  }

  function dsPlanted(label, data, extra) {
    return Object.assign(
      {
        label,
        data,
        borderColor: C.planted,
        borderDash: [5, 4],
        borderWidth: 1.5,
        tension: 0.25,
        fill: false,
        pointRadius: 0,
        pointHoverRadius: 3,
      },
      extra || {}
    );
  }

  function dsGcal(label, data, extra) {
    return Object.assign(
      {
        label,
        data,
        borderColor: C.gcal,
        borderDash: [4, 3],
        borderWidth: 2,
        tension: 0.25,
        fill: false,
        pointRadius: 0,
        pointHoverRadius: 3,
      },
      extra || {}
    );
  }

  function dsSales(label, data, extra) {
    return Object.assign(
      {
        label,
        data,
        borderColor: C.sales,
        borderWidth: 2.5,
        tension: 0.25,
        fill: false,
        pointRadius: 0,
        pointHoverRadius: 4,
      },
      extra || {}
    );
  }

  function dsYoy(label, data, extra) {
    return Object.assign(
      {
        label,
        data,
        borderColor: C.yoy,
        borderWidth: 1,
        tension: 0.25,
        fill: false,
        pointRadius: 0,
        pointHoverRadius: 0,
      },
      extra || {}
    );
  }

  global.GF_CHART = {
    C,
    legend,
    yKg,
    zeroPlugin,
    dsPlan,
    dsPlanted,
    dsGcal,
    dsSales,
    dsYoy,
  };
})(window);
