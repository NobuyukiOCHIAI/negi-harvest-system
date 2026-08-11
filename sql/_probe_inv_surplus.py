# -*- coding: utf-8 -*-
import re
import urllib.request

inv = urllib.request.urlopen(
    "https://love-media.sakura.ne.jp/greenfarm/forecast/inventory.php", timeout=120
).read().decode("utf-8", "replace")
m = re.search(r"label: '余剰',\s*data: (\[[^\]]+\])", inv)
print("inv surplus", m.group(1) if m else "none")
badges = re.findall(r"余剰\s*([-0-9,]+)", inv)
print("badges", badges[:15])
# labels for inv chart block
i = inv.find("getElementById('invChart')")
if i < 0:
    i = inv.find('getElementById("invChart")')
print("invChart js idx", i)
print(inv[i : i + 900] if i >= 0 else "missing")
