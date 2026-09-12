# -*- coding: utf-8 -*-
from pathlib import Path

p = Path(r"c:\Users\n00218\my-workspace\栽培予測システム\capacity.php")
text = p.read_text(encoding="utf-8")
start = text.find("  <?php\n    $bs = $breakSim;")
end = text.find("  <div class=\"chart-card primary\">\n    <div class=\"chart-title\">定植済累計")
if start < 0 or end < 0:
    raise SystemExit(f"markers missing {start} {end}")

new = r'''  <?php
    $bs = $breakSim;
    $baseRun = (int)$bs['baseline']['runway_weeks'];
    $scRun = (int)$bs['scenario']['runway_weeks'];
    $delta = (int)$bs['delta_runway'];
    $afterCls = $delta > 0 ? 'ok' : ($delta < 0 ? 'warn' : '');
    $effNow = (int)$bs['eff_yield_kg'];
    $sug = (int)$bs['suggested_kg'];
    $recentN = (int)($bs['recent']['n'] ?? 0);
    $baseBreak = $bs['baseline']['first_break_week'] ?? null;
    $scBreak = $bs['scenario']['first_break_week'] ?? null;
    $shipDeltaV = (float)$bs['ship_delta'];
    $shipWeeksV = (int)$bs['ship_weeks'];
    $plantNV = (int)$bs['plant_n'];
    $emptyN = (int)$bs['empty_beds'];
    $plantedN = (int)($bs['plant_extra']['planted'] ?? 0);
    $useEff = !empty($bs['use_eff']);
    $qBase = 'sim=' . rawurlencode($simParam);
  ?>

  <section class="sim-hero" id="sec-break-sim">
    <h2>割れ回避シミュレーション（組み合わせ可）</h2>
    <p class="page-sub mb-2">
      現状の定植済ベースに対し、実効収量・出荷加減・空き定植を試し、割れ週を先延ばしできるか見る。DBは書き換えない。
    </p>
    <form class="sim-form" method="get" action="capacity.php">
      <input type="hidden" name="sim" value="<?= htmlspecialchars($simParam, ENT_QUOTES, 'UTF-8') ?>">
      <div>
        <label for="eff">① 実効収量 kg/床</label>
        <input id="eff" type="number" name="eff" min="40" max="400" step="5"
               value="<?= $useEff ? $effNow : $sug ?>" placeholder="<?= $sug ?>">
      </div>
      <div>
        <label for="ship_delta">② 出荷加減 kg/週</label>
        <input id="ship_delta" type="number" name="ship_delta" min="-500" max="500" step="10" value="<?= (int)$shipDeltaV ?>">
      </div>
      <div>
        <label for="ship_weeks">② 対象週数</label>
        <input id="ship_weeks" type="number" name="ship_weeks" min="1" max="12" step="1" value="<?= max(1, $shipWeeksV) ?>">
      </div>
      <div>
        <label for="plant_n">③ 明日定植する空き床</label>
        <input id="plant_n" type="number" name="plant_n" min="0" max="999" step="1" value="<?= $plantNV >= 999 ? $emptyN : max(0, $plantNV) ?>">
      </div>
      <button type="submit" class="btn btn-success btn-sm">試す</button>
    </form>
    <div class="d-flex flex-wrap gap-2 mt-2 mb-2">
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?>&eff=<?= $sug ?>">①平均<?= $sug ?>kg</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?>&eff=160">①160kg</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?>&eff=<?= $useEff ? $effNow : $sug ?>&ship_delta=-50&ship_weeks=4">②出荷−50×4週</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?>&eff=<?= $useEff ? $effNow : $sug ?>&plant_n=999">③空き全床を明日定植(<?= $emptyN ?>)</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?>&eff=<?= $sug ?>&ship_delta=-50&ship_weeks=4&plant_n=999">①②③まとめて</a>
    </div>
    <p class="page-sub mb-2">
      直近平均≈<?= $sug ?>kg<?= $recentN ? "（n={$recentN}）" : '' ?> · 空き床 <?= $emptyN ?> ·
      適用中: <strong><?= htmlspecialchars((string)$bs['lever_label'], ENT_QUOTES, 'UTF-8') ?></strong>
      <?php if ($plantedN > 0): ?>
        · 明日定植<?= $plantedN ?>床→収穫週 <?= h_sunday_week($bs['plant_extra']['harvest_week'] ?? null) ?>
        （約<?= (int)$bs['plant_extra']['days'] ?>日・<?= (int)$bs['plant_extra']['yield_kg'] ?>kg/床）
      <?php endif; ?>
    </p>

    <div class="sim-compare">
      <div class="sim-box">
        <div class="s-lab">現状（モデル残）</div>
        <div class="s-val"><?= $baseRun ?><span style="font-size:0.85rem">週</span></div>
        <div class="s-sub">割れまで · 残 <?= number_format($bs['open_remain_baseline'], 0) ?>kg
          <?php if ($baseBreak): ?> · 初回 <?= h_sunday_week($baseBreak) ?><?php endif; ?></div>
      </div>
      <div class="sim-arrow"><?= $delta > 0 ? '+' . $delta : (string)$delta ?>週</div>
      <div class="sim-box after <?= $afterCls ?>">
        <div class="s-lab">シナリオ</div>
        <div class="s-val"><?= $scRun ?><span style="font-size:0.85rem">週</span></div>
        <div class="s-sub">割れまで · 残 <?= number_format($bs['open_remain_scenario'], 0) ?>kg
          <?php if ($scBreak): ?> · 初回 <?= h_sunday_week($scBreak) ?><?php endif; ?></div>
      </div>
    </div>
    <p class="page-sub mb-0">
      出荷減は営業依頼の試算。定植は現場の <a href="today.php#sec-plant">今日</a> で実行。異常は <a href="alerts.php">経営アラート</a>。
    </p>
  </section>

'''

p.write_text(text[:start] + new + text[end:], encoding="utf-8")
print("ui patched", start, end)
