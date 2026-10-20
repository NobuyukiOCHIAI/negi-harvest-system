# -*- coding: utf-8 -*-
"""週まとめを月曜始まりにして本番へ反映する。"""
from pathlib import Path
import paramiko

ROOT = Path(r"c:\Users\n00218\my-workspace")
LOCAL = ROOT / "栽培予測システム"
REMOTE = "/home/love-media/www/greenfarm/forecast"
FILES = [
    "lib/gcal_shipments.php",
    "lib/date_display.php",
    "lib/plant_schedule.php",
    "lib/rotation_capacity.php",
    "lib/supply_ops.php",
    "inventory.php",
    "loss.php",
    "weather.php",
    "sql/_migrate_week_monday.php",
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
        sftp.put(str(LOCAL.joinpath(*rel.split("/"))), f"{REMOTE}/{rel}")
        print("PUT", rel)
    sftp.close()

    checks = [
        f"php -l {REMOTE}/lib/gcal_shipments.php",
        f"php -l {REMOTE}/lib/supply_ops.php",
        f"php -l {REMOTE}/loss.php",
        f"php {REMOTE}/sql/_migrate_week_monday.php",
        f"rm -f {REMOTE}/sql/_migrate_week_monday.php {REMOTE}/sql/_probe_week_monday.php",
    ]
    for cmd in checks:
        _i, o, e = ssh.exec_command(cmd, timeout=180)
        out = o.read().decode("utf-8", "replace")
        err = e.read().decode("utf-8", "replace")
        code = o.channel.recv_exit_status()
        print(out)
        if err.strip():
            print("ERR", err[:2000])
        if code != 0 or "Parse error" in out or "Parse error" in err:
            print("exit", code, cmd)
            ssh.close()
            return 1
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
