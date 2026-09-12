# -*- coding: utf-8 -*-
"""Deploy promise≤capacity summary + chart theme."""
from pathlib import Path
import paramiko

ROOT = Path(r"c:\Users\n00218\my-workspace")
LOCAL = ROOT / "栽培予測システム"
REMOTE = "/home/love-media/www/greenfarm/forecast"
FILES = [
    "lib/promise_capacity.php",
    "lib/mgmt_alerts.php",
    "js/gf-chart-theme.js",
    "css/mobile-ui.css",
    "inventory.php",
    "capacity.php",
    "alerts.php",
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
    # ensure js dir
    ssh.exec_command(f"mkdir -p {REMOTE}/js", timeout=30)
    sftp = ssh.open_sftp()
    for rel in FILES:
        lp = LOCAL.joinpath(*rel.split("/"))
        sftp.put(str(lp), f"{REMOTE}/{rel}")
        print("PUT", rel, lp.stat().st_size)
    sftp.close()
    php_files = [f for f in FILES if f.endswith(".php")]
    lint = " ; ".join(f"php -l {REMOTE}/{rel}" for rel in php_files)
    _i, o, e = ssh.exec_command(lint, timeout=90)
    print(o.read().decode("utf-8", "replace"))
    err = e.read().decode("utf-8", "replace")
    if err.strip():
        print(err)

    probe = f"""<?php
require '{REMOTE}/db.php';
require '{REMOTE}/lib/promise_capacity.php';
$s = gf_promise_vs_capacity_summary($link, 8);
echo $s['status'].'|'.$s['headline'].PHP_EOL;
"""
    remote_php = f"{REMOTE}/sql/_probe_promise_tmp.php"
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
