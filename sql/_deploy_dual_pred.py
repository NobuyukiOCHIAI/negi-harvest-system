# -*- coding: utf-8 -*-
from pathlib import Path
import paramiko

ROOT = Path(r"c:\Users\n00218\my-workspace")
LOCAL = ROOT / "栽培予測システム"
REMOTE = "/home/love-media/www/greenfarm/forecast"
FILES = [
    "docs/合意仕様.md",
    "lib/supply_ops.php",
    "lib/plant_calib.php",
    "lib/break_sim.php",
    "sql/_assert_inventory_canon.php",
    "inventory.php",
    "capacity.php",
    "agent.php",
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
    cmd = (
        f"php -l {REMOTE}/lib/supply_ops.php; "
        f"php -l {REMOTE}/lib/break_sim.php; "
        f"php -l {REMOTE}/lib/plant_calib.php; "
        f"php -l {REMOTE}/agent.php; "
        f"php {REMOTE}/sql/_assert_inventory_canon.php"
    )
    _i, o, e = ssh.exec_command(cmd, timeout=300)
    print(o.read().decode("utf-8", "replace"))
    err = e.read().decode("utf-8", "replace")
    if err.strip():
        print("ERR", err[:3000])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
