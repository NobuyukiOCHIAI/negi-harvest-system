<?php
/**
 * 下部ナビ（モバイル・アイコン付き）
 * @param string $active today|monitor|inventory|capacity|actual|settings
 * @param string $base ルートプレフィックス（data_entry からは '../'）
 */
function forecast_nav(string $active = '', string $base = ''): void
{
    $icons = [
        'today' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>',
        'monitor' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c4-4 8-7.5 8-12a8 8 0 1 0-16 0c0 4.5 4 8 8 12z"/><circle cx="12" cy="10" r="3"/></svg>',
        'inventory' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V5M4 19h16M8 15v4M12 11v8M16 7v12"/></svg>',
        'capacity' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>',
        'actual' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v10"/><path d="M8 8c0 4 1.5 7 4 9 2.5-2 4-5 4-9"/><path d="M5 21h14"/><path d="M9 21c0-2 1.5-3.5 3-3.5S15 19 15 21"/></svg>',
        'plan' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>',
    ];
    // 経営常時: 実収穫量は1タップ（メニュー経由不要）
    $items = [
        'today' => ['href' => 'today.php', 'label' => '今日'],
        'monitor' => ['href' => 'monitor.php', 'label' => '栽培'],
        'inventory' => ['href' => 'inventory.php', 'label' => '予測'],
        'capacity' => ['href' => 'capacity.php', 'label' => '需給'],
        'actual' => ['href' => 'actual.php', 'label' => '収量'],
        'settings' => ['href' => 'settings.php', 'label' => 'メニュー'],
    ];
    echo '<nav class="gf-bottom-nav gf-bottom-nav--6" aria-label="メインメニュー"><div class="nav-inner">';    foreach ($items as $key => $it) {
        $cls = $key === $active ? 'active' : '';
        $aria = $key === $active ? ' aria-current="page"' : '';
        $href = htmlspecialchars($base . $it['href'], ENT_QUOTES, 'UTF-8');
        echo '<a href="' . $href . '" class="' . $cls . '"' . $aria . '>';
        echo $icons[$key] ?? '';
        echo '<span>' . htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') . '</span></a>';
    }
    echo '</div></nav>';
    echo <<<'JS'
<script>
(function () {
  if (window.__gfNegPainted) return;
  window.__gfNegPainted = 1;
    var skip = { SCRIPT: 1, STYLE: 1, SVG: 1, TEXTAREA: 1, INPUT: 1 };
    var skipCls = { 'num-neg': 1, 'job-name': 1, 'chip': 1 };
    var re = /(^|[\s(（\[「『:：·・,，])(-(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?)/g;
    function walk(n) {
      if (n.nodeType === 1) {
        if (skip[n.nodeName]) return;
        if (n.classList) {
          for (var c = 0; c < n.classList.length; c++) {
            if (skipCls[n.classList[c]]) return;
          }
        }
        Array.prototype.slice.call(n.childNodes).forEach(walk);
        return;
      }
    if (n.nodeType !== 3 || !n.nodeValue || n.nodeValue.indexOf('-') < 0) return;
    re.lastIndex = 0;
    if (!re.test(n.nodeValue)) return;
    re.lastIndex = 0;
    var t = n.nodeValue, last = 0, frag = document.createDocumentFragment(), m;
    while ((m = re.exec(t))) {
      var start = m.index + m[1].length;
      if (start > last) frag.appendChild(document.createTextNode(t.slice(last, start)));
      var sp = document.createElement('span');
      sp.className = 'num-neg';
      sp.style.color = '#c62828';
      sp.style.fontWeight = '800';
      sp.textContent = m[2];
      frag.appendChild(sp);
      last = start + m[2].length;
    }
    if (last < t.length) frag.appendChild(document.createTextNode(t.slice(last)));
    n.parentNode.replaceChild(frag, n);
  }
  if (document.body) walk(document.body);
})();
</script>
JS;
}

/** インラインSVGヘルパ（ページ内アイコン） */
function gf_icon(string $name, string $class = 'ico'): string
{
    $map = [
        'plant' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22V10"/><path d="M12 10c-4-2-6-5-6-8 4 0 6 2 6 5"/><path d="M12 10c4-2 6-5 6-8-4 0-6 2-6 5"/><path d="M7 22h10"/></svg>',
        'harvest' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v10"/><path d="M8 8c0 4 1.5 7 4 9 2.5-2 4-5 4-9"/><path d="M5 21h14"/><path d="M9 21c0-2 1.5-3.5 3-3.5S15 19 15 21"/></svg>',
        'alert' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
        'chart' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V5M4 19h16M8 15v4M12 11v8M16 7v12"/></svg>',
        'bed' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="10" width="18" height="8" rx="1"/><path d="M3 10V8a2 2 0 0 1 2-2h4"/><path d="M7 18v2M17 18v2"/></svg>',
        'check' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>',
        'check-circle' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8 12.5 11 15.5 16.5 9"/></svg>',
        'arrow' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>',
        'calendar' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
        'empty' => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 12h6"/></svg>',
    ];
    return $map[$name] ?? '';
}

/** サイクル状態（アイコン＋ラベル） */
function gf_cycle_status(string $status, string $label = ''): string
{
    $map = [
        'closed' => ['check-circle', '完了'],
        'growing' => ['plant', '栽培中'],
        'harvesting' => ['harvest', '収穫中'],
        'empty' => ['empty', '空き'],
    ];
    [$icon, $default] = $map[$status] ?? ['empty', $label !== '' ? $label : $status];
    $text = $label !== '' ? $label : $default;
    return '<span class="rc-status status-' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '">'
        . gf_icon($icon, 'rc-status-ico')
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        . '</span>';
}
