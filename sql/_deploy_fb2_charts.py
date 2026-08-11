# -*- coding: utf-8 -*-
"""Upload selected forecast PHP files to Sakura."""
from __future__ import annotations

from pathlib import Path

import paramiko

ROOT = Path(r"c:\Users\n00218\.claude\my-workspace")
LOCAL = ROOT / "栽培予測システム"
REMOTE = "/home/love-media/www/greenfarm/forecast"
FILES = [
    "capacity.php",
    "inventory.php",
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
        lp = LOCAL / rel
        rp = f"{REMOTE}/{rel}"
        sftp.put(str(lp), rp)
        print("PUT", rel, lp.stat().st_size)
    sftp.close()
    checks = " ".join(f"{REMOTE}/{f}" for f in FILES)
    _i, o, e = ssh.exec_command(f"php -l {REMOTE}/capacity.php; php -l {REMOTE}/inventory.php")
    print(o.read().decode("utf-8", "replace"))
    print(e.read().decode("utf-8", "replace"))
    ssh.close()
    print("DONE")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
