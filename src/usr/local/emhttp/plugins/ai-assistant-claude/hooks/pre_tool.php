#!/usr/bin/php
<?php
/*
 * pre_tool.php - Claude Code PreToolUse hook (matcher: Bash|Write|Edit|MultiEdit).
 *
 * Contract (verified against https://code.claude.com/docs/en/hooks):
 *   stdin  = JSON {session_id, cwd, hook_event_name:"PreToolUse", tool_name, tool_input:{...}, ...}
 *   exit 2 = block the tool call; stderr text is shown to Claude as the reason.
 *   exit 0 = allow (no output needed).
 * Fail-safe: any internal error / unparsable input => allow (and log). A matched destructive
 * pattern or a detected secret always blocks.
 *
 * Self-test:  php pre_tool.php --selftest
 */
// Never let a PHP notice/warning reach stdout/stderr: on exit 0 any output may be shown as a "hook error".
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
@error_reporting(0);
if (PHP_SAPI === 'cli' && !(isset($argv[1]) && $argv[1] === '--selftest')) {
    // fatal error (parse/memory/etc.) => fail-safe allow with a clean exit code instead of PHP's 255
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) exit(0);
    });
}
require_once __DIR__ . '/hooklib.php';

function aia_pre_decide($in, $extra) {
    // returns array(reason|null, kind)
    $tool = isset($in['tool_name']) ? $in['tool_name'] : '';
    $ti = isset($in['tool_input']) && is_array($in['tool_input']) ? $in['tool_input'] : array();
    $cwd = isset($in['cwd']) && is_string($in['cwd']) ? $in['cwd'] : '/';

    if ($tool === 'Bash') {
        $cmd = isset($ti['command']) ? $ti['command'] : '';
        $r = aia_check_command($cmd, $cwd, $extra);
        if ($r !== null) return array('Destructive command blocked by AI Assistant policy: ' . $r . '. Do not try to work around this; ask the user to run it themselves if it is really intended.', 'destructive');
        $disc = array_key_exists('aia_discovery', $in) ? (bool)$in['aia_discovery'] : aia_discovery_mode();   // AIA_DISCOVERY=1 from discover.sh
        if ($disc) {
            $r = aia_check_discovery_command($cmd);
            if ($r !== null) return array('Blocked during discovery (read-only mode): ' . $r . '. Use a simple read-only command instead (no redirection to files, no python/perl/php, no for-loops, nothing that changes docker/virsh/network/disk state).', 'discovery');
        }
        // shell writes into the memory dir: scan the command text for secrets
        foreach (aia_memory_dirs() as $d) {
            if ($d !== '' && strpos($cmd, $d) !== false) {
                $s = aia_find_secret($cmd);
                if ($s !== null) return array('Secret-looking content (' . $s . ') must not be written to the memory directory. Store only the NAME of the variable/setting, never its value.', 'secret');
                break;
            }
        }
        return array(null, '');
    }
    if ($tool === 'Write' || $tool === 'Edit' || $tool === 'MultiEdit' || $tool === 'NotebookEdit') {
        $path = isset($ti['file_path']) ? $ti['file_path'] : (isset($ti['notebook_path']) ? $ti['notebook_path'] : '');
        if (!aia_is_memory_path($path, $cwd)) {
            $disc = array_key_exists('aia_discovery', $in) ? (bool)$in['aia_discovery'] : aia_discovery_mode();
            if ($disc) return array('Blocked during discovery: files may only be written inside the memory directory (' . aia_ram() . '/memory).', 'discovery');
            return array(null, '');
        }
        $texts = array();
        foreach (array('content', 'new_string', 'new_source') as $k) if (isset($ti[$k]) && is_string($ti[$k])) $texts[] = $ti[$k];
        if (isset($ti['edits']) && is_array($ti['edits'])) {
            foreach ($ti['edits'] as $e) if (is_array($e) && isset($e['new_string']) && is_string($e['new_string'])) $texts[] = $e['new_string'];
        }
        foreach ($texts as $t) {
            $s = aia_find_secret($t);
            if ($s !== null) return array('Secret-looking content (' . $s . ') must not be written to the memory directory. Store only the NAME of the variable/setting (e.g. "DB_PASSWORD is set in the container env"), never its value, then retry.', 'secret');
        }
    }
    return array(null, '');
}

/* ---------------------------------------------------------------- selftest */
function aia_pre_selftest() {
    $mem = aia_ram() . '/memory';
    $block = array(
        'mkfs.ext4 /dev/sdb1', 'sudo mkfs -t xfs /dev/nvme0n1p1', '/sbin/mkfs.btrfs -f /dev/sdc', 'wipefs -a /dev/sdb',
        'dd if=/dev/zero of=/dev/sdb bs=1M', 'dd of=/dev/nvme0n1 if=img', 'sgdisk -o /dev/sdb', 'sgdisk --zap-all /dev/sdb',
        'sgdisk -n 1:0:0 /dev/sdb', 'sfdisk /dev/sdb < layout', 'parted -s /dev/sdb mklabel gpt', 'fdisk /dev/sdb', 'blkdiscard /dev/nvme0n1',
        'shred -n1 /dev/sdb', 'rm -rf /mnt/user/appdata', 'rm -r /mnt/disk1', 'rm -rf /boot/config', 'rm -rf /', 'rm -rf /*', 'rm -fr /mnt/*',
        'sudo rm -rf --no-preserve-root /', 'cd /mnt/user && rm -rf *', 'bash -c "rm -rf /mnt/cache"', 'echo $(rm -rf /boot)', 'ls; rm -rf /etc',
        'find /mnt/user -name x -delete', 'find /mnt -exec rm -rf {} +', 'xargs rm -rf /mnt/disk2',
        'mdcmd set md_write_method 1', 'mdcmd stop', '/root/mdcmd start', 'mdcmd new', 'mdcmd clear', 'emcmd cmdStop=Stop', '/usr/local/sbin/emcmd cmdStart=Start',
        'emcmd cmdNewConfig=Apply', 'emcmd changeDevice=Apply', 'zpool destroy tank', 'zpool labelclear -f /dev/sdb1', 'zfs destroy -r tank/data',
        'btrfs device delete /dev/sdb /mnt/cache', 'btrfs device remove /dev/sdb /mnt/cache', 'docker system prune -a', 'docker system prune --all --volumes', 'docker system prune -af',
        'docker volume rm myvol', 'docker volume prune -f', 'echo x > /dev/sdb', 'cat img >> /dev/nvme0n1', 'echo 1 | tee /dev/md1', 'echo foo > /proc/mdcmd',
        'curl "http://localhost/update.htm?cmdStop=Stop"', 'timeout 5 mkfs.xfs /dev/sdx',
    );
    $allow = array(
        'rm /tmp/foo.txt', 'rm -rf /tmp/build', 'rm -f /var/lib/ai-assistant-claude/work/x', 'rm -rf ./node_modules', 'ls /mnt/user', 'df -h',
        'mdcmd status', '/root/mdcmd status', 'cat /var/local/emhttp/var.ini', 'zpool status', 'zpool list', 'btrfs filesystem show', 'btrfs device stats /mnt/cache',
        'docker ps -a', 'docker system df', 'docker volume ls', 'docker system prune', 'docker image prune', 'docker logs --tail 50 plex',
        'echo mkfs is dangerous', 'grep -r wipefs /etc', 'grep "mkfs.ext4" /var/log/syslog', 'smartctl -a /dev/sdb', 'fdisk -l', 'sfdisk -d /dev/sdb', 'sgdisk -p /dev/sdb',
        'parted -l', 'parted /dev/sdb print', 'dd if=/dev/sda of=/tmp/mbr.bin bs=512 count=1', 'dd if=/dev/zero of=/dev/null count=1', 'ls > /dev/null 2>&1',
        'find /mnt/user -name "*.nfo"', 'find /tmp -name x -delete', 'echo hi > /tmp/x', 'lsblk', 'cat /proc/mdstat', 'virsh list --all', 'emcmd', 'mdcmd',
        'tail -n 100 /var/log/syslog', 'rm /mnt/user/appdata/foo/old.log', 'ps aux | grep mdcmd',
    );
    $fail = 0;
    foreach ($block as $c) {
        $r = aia_check_command($c, '/mnt/user', '');
        if ($r === null) { echo "FAIL (should block): $c\n"; $fail++; }
    }
    foreach ($allow as $c) {
        $r = aia_check_command($c, '/tmp', '');
        if ($r !== null) { echo "FAIL (should allow): $c  -> $r\n"; $fail++; }
    }
    // cwd-relative rm
    if (aia_check_command('rm -rf *', '/mnt/user/foo', '') === null) { echo "FAIL (rm -rf * in /mnt)\n"; $fail++; }
    if (aia_check_command('rm -rf *', '/tmp/work', '') !== null) { echo "FAIL (rm -rf * in /tmp)\n"; $fail++; }
    // extra patterns
    if (aia_check_command('reboot now', '/', 'reboot|poweroff') === null) { echo "FAIL (extra pattern reboot)\n"; $fail++; }
    if (aia_check_command('shutdown -h now', '/', "reboot\nshutdown( |$)") === null) { echo "FAIL (extra pattern shutdown)\n"; $fail++; }
    if (aia_check_command('uptime', '/', '(reboot|poweroff)|halt') !== null) { echo "FAIL (extra false positive)\n"; $fail++; }
    if (aia_check_command('halt', '/', '(reboot|poweroff)|halt') === null) { echo "FAIL (extra alternation group)\n"; $fail++; }

    // secrets
    $sec_block = array(
        "DB_PASSWORD=hunter2hunter2", "api_token: abcd1234efgh5678", "SECRET_KEY=\"s3cr3t-value-xyz\"", "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----",
        "token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U", "key sk-ant-api03-abcdefghijklmnopqrstuvwx",
        "ghp_abcdefghijklmnopqrstuvwxyz0123456789", "redis://user:s3cretpw@redis:6379", "id 0123456789abcdef0123456789abcdef01234567", "blob QWxhZGRpbjpvcGVuIHNlc2FtZTEyMzQ1Njc4OWFiY2RlZmdoaWo=",
        "MYSQL_ROOT_PASSWORD = Tr0ub4dor&3xyz", "curl --password hunter22x host", "AWS AKIAABCDEFGHIJKLMNOP",
    );
    $sec_allow = array(
        "DB_PASSWORD=***", "The DB password is set via DB_PASSWORD (value not recorded)", "API_TOKEN=<redacted>", "PASSWORD: set", "Authentication: required",
        "AUTH_KEY=${AUTH_KEY}", "image sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef", "Path /mnt/user/appdata/some-very-long-directory-name-for-something-1234",
        "Plex token stored in Preferences.xml (PlexOnlineToken), not recorded", "ssh key: ed25519 in /root/.ssh", "uuid 123e4567-e89b-12d3-a456-426614174000", "Kernel 6.12.54-Unraid, Docker 27.5.1",
        "KEYBOARD=us", "TOKEN_FILE=/run/secrets/token",
    );
    foreach ($sec_block as $t) { if (aia_find_secret($t) === null) { echo "FAIL (secret not detected): " . str_replace("\n", '\\n', $t) . "\n"; $fail++; } }
    foreach ($sec_allow as $t) { $r = aia_find_secret($t); if ($r !== null) { echo "FAIL (secret false positive): $t -> $r\n"; $fail++; } }

    // end-to-end decision on hook JSON
    $d = aia_pre_decide(array('tool_name' => 'Write', 'cwd' => '/tmp', 'tool_input' => array('file_path' => "$mem/docker.md", 'content' => "MYSQL_PASSWORD=abcdefgh123")), '');
    if ($d[0] === null) { echo "FAIL (Write secret to memory)\n"; $fail++; }
    $d = aia_pre_decide(array('tool_name' => 'Write', 'cwd' => '/tmp', 'tool_input' => array('file_path' => "$mem/docker.md", 'content' => "MYSQL_PASSWORD is set (value not recorded)")), '');
    if ($d[0] !== null) { echo "FAIL (clean memory write blocked)\n"; $fail++; }
    $d = aia_pre_decide(array('tool_name' => 'Write', 'cwd' => '/tmp', 'tool_input' => array('file_path' => "/tmp/other.txt", 'content' => "MYSQL_PASSWORD=abcdefgh123")), '');
    if ($d[0] !== null) { echo "FAIL (non-memory write blocked)\n"; $fail++; }
    $d = aia_pre_decide(array('tool_name' => 'MultiEdit', 'cwd' => '/tmp', 'tool_input' => array('file_path' => "$mem/../memory/x.md", 'edits' => array(array('old_string' => 'a', 'new_string' => 'api_key=abcdef123456XYZ')))), '');
    if ($d[0] === null) { echo "FAIL (MultiEdit secret)\n"; $fail++; }
    $d = aia_pre_decide(array('tool_name' => 'Bash', 'cwd' => '/tmp', 'tool_input' => array('command' => "echo 'TOKEN=abcdefgh1234' >> $mem/x.md")), '');
    if ($d[0] === null) { echo "FAIL (Bash secret to memory)\n"; $fail++; }

    // masking
    $mk = array(
        array('"MYSQL_PASSWORD=pa ss w0rd","TZ=Europe/Brussels"', 'pa ss'),
        array('postgres://bob:hunter2@db:5432/x', 'hunter2'),
        array('"Password": "abc123"', 'abc123'),
        array("environment:\n  - API_KEY=zzz999\n  secret_token: yyy888\n  other: fine", 'zzz999'),
        array('TOKEN=abc curl -H "Authorization: Bearer abcdefghijkl" http://x', 'abcdefghijkl'),
    );
    foreach ($mk as $p) { $o = aia_mask($p[0]); if (strpos($o, $p[1]) !== false) { echo "FAIL (mask leaked '{$p[1]}'): $o\n"; $fail++; } }
    $o = aia_mask("environment:\n  - API_KEY=zzz999\n  secret_token: yyy888\n  other: fine");
    if (strpos($o, 'yyy888') !== false || strpos($o, 'other: fine') === false) { echo "FAIL (mask yaml): $o\n"; $fail++; }
    $o = aia_mask('"TZ=Europe/Brussels"');
    if ($o !== '"TZ=Europe/Brussels"') { echo "FAIL (mask overreach): $o\n"; $fail++; }
    if (aia_mask(aia_mask('KEY=abc')) !== aia_mask('KEY=abc')) { echo "FAIL (mask idempotence)\n"; $fail++; }

    // discovery mode (read-only) checks
    $d_block = array(
        'echo x > /tmp/x', 'cat /etc/hosts >> /tmp/x', 'ls 2> /tmp/err', 'ls &> /tmp/o', 'tee /tmp/x', 'echo a | tee /tmp/x', 'cp a b', 'mv a b', 'rm a', 'touch /tmp/x', 'mkdir /tmp/d', 'chmod 600 x',
        'ln -s a b', 'sed -i s/a/b/ f', 'sed -ni s/a/b/p f', 'sed --in-place s/a/b/ f', 'sort -o out in', 'find / -delete', 'find / -exec rm {} +', 'find . -fprint /tmp/x', 'python3 -c 1', 'perl -e 1', 'php -r 1',
        'awk \'BEGIN{system("id")}\' x', 'awk \'{print > "/tmp/x"}\' f', 'for f in a; do cat $f > /tmp/x; done', 'docker restart x', 'docker rm x', 'docker exec x ls', 'docker run busybox', 'docker system prune',
        'docker compose up -d', 'docker compose -f x.yml down', 'docker volume rm x', 'docker network create x', 'virsh start vm', 'virsh destroy vm', 'virsh -c qemu:///system shutdown vm', 'virsh define x.xml', 'virsh snapshot-create vm',
        'smartctl -t short /dev/sda', 'smartctl -a -s off /dev/sda', 'smartctl --set=standby,off /dev/sda', 'smartctl -o on /dev/sda', 'ip addr add 1.2.3.4/24 dev lo', 'ip link set lo down', 'ip -6 route add ::1/128 dev lo', 'ip netns add x',
        'iptables -A INPUT -j DROP', 'iptables -F', 'iptables -t nat -A POSTROUTING -j MASQUERADE', 'wg set wg0 peer x', 'tailscale up', 'tailscale down', 'ethtool -s eth0 speed 100', 'ethtool -K eth0 tso off',
        'zpool destroy t', 'zpool scrub t', 'zfs destroy t/x', 'zfs snapshot t@x', 'btrfs device delete /dev/sdb /mnt/x', 'btrfs scrub start /mnt/x', 'btrfs device stats -z /mnt/x', 'nft flush ruleset', 'sysctl -w a=1', 'crontab -r', 'crontab -e',
        'date -s 2020-01-01', 'dmesg -C', 'dmesg -c', 'journalctl --vacuum-size=1M', 'mount /dev/sda1 /mnt/x', 'nvidia-smi -pm 1', 'nvidia-smi -r', 'hdparm -S 1 /dev/sda', 'mdcmd stop', 'curl http://x', 'wget http://x', 'kill 1', 'reboot', 'bash script.sh', 'eval ls',
        'source /etc/profile', 'ssh host ls', 'git pull', 'tar xf a.tar', 'rc.docker restart', 'notify -e x', 'sensors -s', 'xxd -r in out', 'tree -o out', 'cat x | sh -c "rm y"',
    );
    $d_allow = array(
        'cat /etc/unraid-version', 'cat /proc/mdstat 2>&1', 'ls -la /dev/dri 2>/dev/null', 'ls -1 /boot/config/shares/ 2>/dev/null | head -20', 'ls /sys/kernel/iommu_groups | wc -l', 'ls -l /etc/localtime && date', 'cat /etc/hosts > /dev/null',
        'virsh version', 'virsh list --all', 'virsh dominfo vm', 'virsh domblklist vm --details', 'virsh -c qemu:///system dumpxml vm', 'virsh net-list --all', 'smartctl -n standby -H -A -i /dev/sda', 'smartctl -a -d sat /dev/sda', 'smartctl --scan', 'smartctl -x /dev/nvme0',
        'lscpu', 'bridge link', 'wg show', 'ethtool eth0', 'ethtool -i eth0', 'ethtool -S eth0', 'ethtool -k eth0', 'testparm -s', 'iptables -S', 'iptables -L -n -v', 'iptables -t nat -S', 'tailscale status', 'tailscale ip -4', 'ip -6 route', 'ip -br addr', 'ip addr show', 'ip -s link', 'ip route show table all',
        'docker ps -a --format \'{{json .}}\'', 'docker inspect plex', 'docker logs --tail 50 plex', 'docker stats --no-stream', 'docker images', 'docker network ls', 'docker network inspect bridge', 'docker volume ls', 'docker system df', 'docker compose ls', 'docker compose -f /x/docker-compose.yml ps',
        'docker image ls', 'docker container ls -a', 'docker version', 'docker info', 'zpool status', 'zpool list', 'zfs list -t all', 'btrfs filesystem show', 'btrfs device stats /mnt/cache', 'mdcmd status', 'nvidia-smi', 'nvidia-smi -L', 'nvidia-smi -q', 'nvidia-smi --query-gpu=name --format=csv',
        'sensors', 'dmesg | tail -50', 'dmesg -T', 'journalctl -n 50', 'date', 'date +%F', 'uname -a', 'hostname', 'id', 'which docker', 'stat /etc/hosts', 'file /bin/ls', 'du -sh /mnt/cache', 'df -h', 'free -m', 'uptime', 'ps aux', 'top -bn1', 'ss -tulpn', 'netstat -tulpn', 'lsof -i :80',
        'findmnt', 'mount', 'lsblk', 'crontab -l', 'nvme list', 'lspci -nn', 'sysctl -a', 'sysctl -n vm.swappiness', 'cat /etc/hosts | awk \'{print $1}\' | sort | uniq', 'awk \'$1>3\' /etc/hosts', 'grep -r foo /etc | cut -d: -f1 | tr a-z A-Z', 'find /boot/config -maxdepth 1 -name "*.cfg"', 'sed -n 1,5p /etc/hosts',
        'echo hi', 'sort /etc/hosts', 'php /usr/local/emhttp/plugins/ai-assistant-claude/scripts/ua-sanitize docker inspect plex', '/usr/local/emhttp/plugins/ai-assistant-claude/scripts/ua-sanitize cat /boot/config/go', 'xargs echo', 'cat /boot/config/shares/*.cfg', 'hdparm -I /dev/sda', 'sh -c "cat /etc/hosts"',
    );
    $d_block = array_merge($d_block, array('swapon /dev/sdb2', 'swapon -a', 'swapon --all', 'swapon /swapfile', 'swapoff -a', 'swapoff /dev/sdb2', 'crontab -r', 'crontab -e', 'crontab /tmp/cron.txt', 'crontab -', 'crontab -l -r', 'crontab', 'crontab -i -r', 'crontab < /tmp/c',
        'mount /dev/sdb1 /mnt/x', 'mount -t ext4 /dev/sdb1 /mnt/x', 'mount -o remount,rw /boot', 'mount -o remount /', 'mount --bind /a /b', 'mount -a', 'mount -t cifs //srv/share /mnt/x', 'mount -t', 'mount -l /mnt/x', 'mount -t cifs 2>&1 > /tmp/o', 'umount /mnt/x'));
    $d_allow = array_merge($d_allow, array('swapon --show', 'swapon -s', 'swapon --summary', 'swapon --show --noheadings --bytes', 'swapon --show=NAME,SIZE', 'swapon --show 2>&1', 'crontab -l 2>&1', 'crontab -l 2>/dev/null', 'crontab -l -u root', 'crontab -u root -l', 'mount -l', 'mount -t cifs,nfs,nfs4', 'mount -t nfs4 2>&1', 'mount -l -t ext4', 'mount 2>/dev/null | head', 'mount -t cifs,nfs,nfs4 2>&1 | cut -d\' \' -f1-5; ls -d /mnt/HomeAssistant /mnt/remotes/* 2>&1; grep -c . /boot/config/plugins/unassigned.devices/samba_mount.cfg 2>&1', 'cat /proc/swaps 2>&1'));
    foreach ($d_block as $c) { if (aia_check_discovery_command($c) === null) { echo "FAIL (discovery should block): $c\n"; $fail++; } }
    foreach ($d_allow as $c) { $r = aia_check_discovery_command($c); if ($r !== null) { echo "FAIL (discovery should allow): $c -> $r\n"; $fail++; } }
    $d = aia_pre_decide(array('aia_discovery' => true, 'tool_name' => 'Bash', 'cwd' => '/tmp', 'tool_input' => array('command' => 'echo x > /tmp/f')), '');
    if ($d[0] === null || $d[1] !== 'discovery') { echo "FAIL (discovery hook decision)\n"; $fail++; }
    $d = aia_pre_decide(array('aia_discovery' => false, 'tool_name' => 'Bash', 'cwd' => '/tmp', 'tool_input' => array('command' => 'echo x > /tmp/f')), '');
    if ($d[0] !== null) { echo "FAIL (non-discovery redirect must not be blocked by the hook)\n"; $fail++; }
    $d = aia_pre_decide(array('aia_discovery' => true, 'tool_name' => 'Write', 'cwd' => '/tmp', 'tool_input' => array('file_path' => '/etc/x', 'content' => 'a')), '');
    if ($d[0] === null) { echo "FAIL (discovery Write outside memory)\n"; $fail++; }
    $d = aia_pre_decide(array('aia_discovery' => true, 'tool_name' => 'Write', 'cwd' => '/tmp', 'tool_input' => array('file_path' => "$mem/system.md", 'content' => 'ok')), '');
    if ($d[0] !== null) { echo "FAIL (discovery Write inside memory blocked)\n"; $fail++; }
    $total_extra = count($d_block) + count($d_allow) + 4;

    $total = count($block) + count($allow) + count($sec_block) + count($sec_allow) + 11 + $total_extra;
    echo $fail === 0 ? "pre_tool selftest OK ($total checks)\n" : "pre_tool selftest FAILED: $fail failure(s)\n";
    return $fail === 0 ? 0 : 1;
}

/* -------------------------------------------------------------------- main */
if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--selftest') {
    putenv('AIA_NO_SYSLOG=1');
    exit(aia_pre_selftest());
}

function aia_pre_main() {
    @set_error_handler(function () { return true; });
    $raw = @stream_get_contents(STDIN);
    $in = json_decode((string)$raw, true);
    if (!is_array($in)) {
        aia_log_line('pre_tool: unparsable hook input, allowing');
        return 0;
    }
    $extra = (string)aia_cfg_get('EXTRA_BLOCKED_PATTERNS', '');
    list($reason, $kind) = aia_pre_decide($in, $extra);
    if ($reason === null) return 0;

    $tool = isset($in['tool_name']) ? $in['tool_name'] : '';
    $ti = isset($in['tool_input']) && is_array($in['tool_input']) ? $in['tool_input'] : array();
    $sum = $tool === 'Bash' ? (isset($ti['command']) ? $ti['command'] : '') : (isset($ti['file_path']) ? $ti['file_path'] : '');
    $sum = aia_trunc(aia_mask($sum), 300);
    aia_append_activity(array(
        't' => time(), 'session_id' => isset($in['session_id']) ? $in['session_id'] : '', 'session' => isset($in['session_id']) ? $in['session_id'] : '',
        'tool' => $tool, 'summary' => $sum, 'cwd' => isset($in['cwd']) ? $in['cwd'] : '', 'blocked' => true, 'reason' => $kind,
    ));
    aia_syslog('BLOCKED ' . $kind . ' ' . $tool . ': ' . $sum);
    fwrite(STDERR, $reason . "\n");
    return 2;
}

try {
    exit(aia_pre_main());
} catch (Throwable $e) {
    @aia_log_line('pre_tool internal error, allowing: ' . $e->getMessage());
    exit(0);
}
