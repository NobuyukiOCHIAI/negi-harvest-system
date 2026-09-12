# -*- coding: utf-8 -*-
"""Deploy 経営アラート一覧."""
from pathlib import Path
import paramiko

ROOT = Path(r"c:\Users\n00218\my-workspace")
LOCAL = ROOT / "栽培予測システム"
REMOTE = "/home/love-media/www/greenfarm/forecast"
FILES = [
    "lib/mgmt_alerts.php",
    "alerts.php",
    "settings.php",
    "index.php",
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
        lp = LOCAL.joinpath(*rel.split("/"))
        sftp.put(str(lp), f"{REMOTE}/{rel}")
        print("PUT", rel, lp.stat().st_size)
    sftp.close()
    lint = " ; ".join(f"php -l {REMOTE}/{rel}" for rel in FILES if rel.endswith(".php"))
    _i, o, e = ssh.exec_command(lint, timeout=90)
    print(o.read().decode("utf-8", "replace"))
    err = e.read().decode("utf-8", "replace")
    if err.strip():
        print(err)
    # smoke: load alerts page via php CLI include path check
    _i, o, e = ssh.exec_command(
        f"php -r \"require '{REMOTE}/db.php'; require '{REMOTE}/lib/mgmt_alerts.php'; "
        f"\\$b=gf_mgmt_alerts_bundle(\\$link); echo 'alerts='.\\$b['counts']['total'].PHP_EOL;\"",
        timeout=120,
    )
    print(o.read().decode("utf-8", "replace"))
    err2 = e.read().decode("utf-8", "replace")
    if err2.strip():
        print(err2)
    ssh.close()
    print("DONE")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
