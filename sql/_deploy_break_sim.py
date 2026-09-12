# -*- coding: utf-8 -*-
from pathlib import Path
import paramiko

ROOT = Path(r"c:\Users\n00218\my-workspace")
LOCAL = ROOT / "栽培予測システム"
REMOTE = "/home/love-media/www/greenfarm/forecast"
FILES = [
    "lib/break_sim.php",
    "capacity.php",
    "docs/合意仕様.md",
]


def read_env(name: str) -> dict[str, str]:
    vals: dict[str, str] = {}
    for line in (ROOT / ".secrets" / name).read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        vals[k.strip()] = v.strip().strip('"').strip("'")
    return vals


def main() -> int:
    ssh_e = read_env("sakura_ssh.env")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        ssh_e.get("SAKURA_SSH_HOST", "love-media.sakura.ne.jp"),
        username=ssh_e.get("SAKURA_SSH_USER", "love-media"),
        password=ssh_e["SAKURA_SSH_PASS"],
        timeout=45,
    )
    sftp = ssh.open_sftp()
    for rel in FILES:
        if rel.startswith("docs/"):
            continue  # docs stay in git only
        lp = LOCAL.joinpath(*rel.split("/"))
        sftp.put(str(lp), f"{REMOTE}/{rel}")
        print("PUT", rel, lp.stat().st_size)
    sftp.close()
    lint = f"php -l {REMOTE}/lib/break_sim.php ; php -l {REMOTE}/capacity.php"
    _i, o, e = ssh.exec_command(lint, timeout=90)
    print(o.read().decode("utf-8", "replace"))
    err = e.read().decode("utf-8", "replace")
    if err.strip():
        print(err)

    probe = f"""<?php
require '{REMOTE}/db.php';
require '{REMOTE}/lib/break_sim.php';
$b0 = gf_break_sim_combo($link, [], 16);
$b1 = gf_break_sim_combo($link, ['eff' => 160], 16);
$b2 = gf_break_sim_combo($link, ['ship_delta' => -50], 16);
$b3 = gf_break_sim_combo($link, ['plant_n' => 999], 16);
$b4 = gf_break_sim_combo($link, ['early_days' => 7], 16);
$b5 = gf_break_sim_combo($link, ['early_days' => 14], 16);
$b6 = gf_break_sim_combo($link, ['eff' => 160, 'ship_delta' => -50, 'plant_n' => 999, 'early_days' => 7], 16);
foreach (['none'=>$b0,'eff160'=>$b1,'ship-50'=>$b2,'plant_all'=>$b3,'early7'=>$b4,'early14'=>$b5,'combo'=>$b6] as $k=>$b) {{
  $lab = str_replace(['·','×'], ['|','x'], $b['lever_label']);
  echo $k.' base='.$b['baseline']['runway_weeks'].' sc='.$b['scenario']['runway_weeks'].' d='.$b['delta_runway'].' | '.$lab.PHP_EOL;
}}
"""
    remote_php = f"{REMOTE}/sql/_probe_break_sim_tmp.php"
    sftp = ssh.open_sftp()
    with sftp.file(remote_php, "w") as f:
        f.write(probe)
    sftp.close()
    _i, o, e = ssh.exec_command(f"php {remote_php}", timeout=180)
    print(o.read().decode("utf-8", "replace"))
    err2 = e.read().decode("utf-8", "replace")
    if err2.strip():
        print(err2)
    ssh.exec_command(f"rm -f {remote_php}")
    ssh.close()
    print("DONE")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
