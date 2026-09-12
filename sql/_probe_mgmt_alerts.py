# -*- coding: utf-8 -*-
from pathlib import Path
import paramiko

ROOT = Path(r"c:\Users\n00218\my-workspace")
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
    php = f"""<?php
require '{REMOTE}/db.php';
require '{REMOTE}/lib/mgmt_alerts.php';
$b = gf_mgmt_alerts_bundle($link);
echo 'ok total=' . $b['counts']['total'] . ' crit=' . $b['counts']['critical'] . PHP_EOL;
foreach ($b['items'] as $it) {{
    echo $it['severity'] . '|' . $it['category'] . '|' . $it['title'] . PHP_EOL;
}}
"""
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
    remote_php = f"{REMOTE}/sql/_probe_mgmt_alerts_tmp.php"
    with sftp.file(remote_php, "w") as f:
        f.write(php)
    sftp.close()
    _i, o, e = ssh.exec_command(f"php {remote_php}", timeout=180)
    print(o.read().decode("utf-8", "replace"))
    err = e.read().decode("utf-8", "replace")
    if err.strip():
        print(err)
    ssh.exec_command(f"rm -f {remote_php}")
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
