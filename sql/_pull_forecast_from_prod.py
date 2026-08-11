# -*- coding: utf-8 -*-
"""Pull live forecast web tree into local workspace (overwrite stubs)."""
from __future__ import annotations

import tarfile
import tempfile
from pathlib import Path

import paramiko

ROOT = Path(r"c:\Users\n00218\.claude\my-workspace")
LOCAL = ROOT / "栽培予測システム"
REMOTE = "/home/love-media/www/greenfarm/forecast"


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
    remote_tgz = "/tmp/forecast_pull_local.tgz"
    cmd = (
        f"cd {REMOTE} && tar czf {remote_tgz} "
        "--exclude='./db.local.php' --exclude='./.env' "
        "--exclude='./models' --exclude='./_tmp*' "
        "--exclude='./lightgbm*.whl' "
        "."
    )
    _i, o, e = ssh.exec_command(cmd, timeout=120)
    err = e.read().decode("utf-8", "replace")
    out = o.read().decode("utf-8", "replace")
    if o.channel.recv_exit_status() != 0:
        print("tar failed", err or out)
        ssh.close()
        return 1

    sftp = ssh.open_sftp()
    with tempfile.TemporaryDirectory() as td:
        local_tgz = Path(td) / "pull.tgz"
        sftp.get(remote_tgz, str(local_tgz))
        LOCAL.mkdir(parents=True, exist_ok=True)
        with tarfile.open(local_tgz, "r:gz") as tf:
            tf.extractall(LOCAL)
    sftp.close()
    ssh.exec_command(f"rm -f {remote_tgz}")
    ssh.close()

    for p in [
        LOCAL / "capacity.php",
        LOCAL / "inventory.php",
        LOCAL / "lib" / "supply_ops.php",
        LOCAL / "lib" / "capacity_outlook.php",
    ]:
        print(f"{'OK' if p.exists() else 'MISS'} {p.name} {p.stat().st_size if p.exists() else 0}")
    print("DONE", LOCAL)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
