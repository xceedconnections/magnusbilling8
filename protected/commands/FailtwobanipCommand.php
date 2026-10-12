<?php
/**
 * Sync Fail2ban bans into pkg_firewall and process unbans.
 *
 * Shows SSH / Asterisk / login / DDoS / SQL-injection / blacklist bans in the
 * Firewall menu. Unban can also be done instantly from FirewallController;
 * this cron keeps the list fresh every 2 minutes.
 *
 * Usage: php cron.php failtwobanip
 */
class FailtwobanipCommand extends ConsoleCommand
{
    protected $resultBanIps   = [];
    protected $resultUnBanIps = [];
    protected $ignogeips      = 'ignoreip = 127.0.0.1 ';
    protected $ssh_port       = 22;

    /** Jails to list in the Firewall panel */
    protected $jailsLocal = [
        'ip-blacklist'      => 1,
        'sshd'              => 0,
        'asterisk-iptables' => 0,
        'ast-cli-attck'     => 1,
        'asterisk-manager'  => 1,
        'ast-hgc-200'       => 1,
        'mbilling_login'    => 0,
        'mbilling_ddos'     => 0,
        'mbilling_sql'      => 0,
    ];

    protected $jailsProxy = [
        'ip-blacklist'     => 1,
        'opensips-iptables'=> 0,
        'sshd'             => 0,
    ];

    public function run($args)
    {
        if (isset($args[0]) && ctype_digit((string) $args[0])) {
            $this->ssh_port = (int) $args[0];
        }

        // Process pending unbans first (action=3), then rebuild list from live fail2ban.
        $sql = 'SELECT id, ip, jail, id_server FROM pkg_firewall WHERE action = 3';
        $this->resultUnBanIps = Yii::app()->db->createCommand($sql)->queryAll();

        foreach ($this->resultUnBanIps as $unbanIP) {
            $this->unbanEverywhere(trim($unbanIP['ip']));
            Yii::app()->db->createCommand('DELETE FROM pkg_firewall WHERE ip = :ip')->execute([
                ':ip' => $unbanIP['ip'],
            ]);
            LogUsers::model()->deleteAll('ip = :key', [':key' => $unbanIP['ip']]);
            echo "Unbanned " . $unbanIP['ip'] . "\n";
        }

        $sql = 'SELECT ip FROM pkg_firewall WHERE action = 1';
        $this->resultBanIps = Yii::app()->db->createCommand($sql)->queryAll();

        $sql = 'SELECT ip FROM pkg_firewall WHERE action = 5';
        $ignoreRows = Yii::app()->db->createCommand($sql)->queryAll();
        foreach ($ignoreRows as $row) {
            $ip = trim($row['ip']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->ignogeips .= $ip . ' ';
            }
        }

        $sql = 'SELECT host FROM pkg_sip JOIN pkg_user ON pkg_sip.id_user = pkg_user.id
                WHERE pkg_user.active = 1 AND host != "dynamic"';
        foreach (Yii::app()->db->createCommand($sql)->queryAll() as $row) {
            $ip = trim($row['host']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->ignogeips .= $ip . ' ';
            }
        }

        $sql = 'SELECT host FROM pkg_trunk WHERE status = 1 AND host != "dynamic"';
        foreach (Yii::app()->db->createCommand($sql)->queryAll() as $row) {
            $ip = trim($row['host']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->ignogeips .= $ip . ' ';
            }
        }

        // Keep IgnoreIP rows; drop stale synced bans so we refresh from fail2ban.
        Yii::app()->db->createCommand('DELETE FROM pkg_firewall WHERE action IN (0,1)')->execute();

        foreach ($ignoreRows as $row) {
            if (strlen($row['ip']) > 5) {
                try {
                    Yii::app()->db->createCommand(
                        "INSERT INTO pkg_firewall (ip,action, date, description, jail, id_server)
                         VALUES (:ip,5, NOW(), '','IgnoreIP','1')"
                    )->execute([':ip' => $row['ip']]);
                } catch (Exception $e) {
                }
            }
        }

        $modelServers = Servers::model()->findAll('status IN (1,3,4)');
        if (! isset($modelServers[0])) {
            $server              = new Servers;
            $server->name        = 'Master';
            $server->host        = 'localhost';
            $server->type        = 'mbilling';
            $server->port        = '5038';
            $server->username    = 'magnus';
            $server->password    = 'magnussolution';
            $server->status      = '1';
            $server->description = '1';
            $server->save();
            $modelServers = Servers::model()->findAll('status IN (1,3,4)');
        }

        foreach ($modelServers as $server) {
            if ($server['host'] != 'localhost') {
                $ip = trim($server['host']);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $this->ignogeips .= $ip . ' ';
                }
            }
        }

        foreach ($modelServers as $server) {
            $jails = ($server['type'] == 'sipproxy') ? $this->jailsProxy : $this->jailsLocal;
            foreach ($jails as $jail => $permAction) {
                $this->syncJail($jail, $permAction, $server);
            }
        }
    }

    /**
     * Instant unban on local jails (also used by FirewallController).
     */
    public static function unbanIpLocal($ip)
    {
        $ip = trim($ip);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        @shell_exec('sudo -n fail2ban-client unban ' . escapeshellarg($ip) . ' 2>/dev/null');
        $jails = [
            'sshd', 'asterisk-iptables', 'ast-cli-attck', 'asterisk-manager',
            'ast-hgc-200', 'mbilling_login', 'mbilling_ddos', 'mbilling_sql',
            'ip-blacklist', 'opensips-iptables',
        ];
        foreach ($jails as $jail) {
            @shell_exec(
                'sudo -n fail2ban-client set ' . escapeshellarg($jail)
                . ' unbanip ' . escapeshellarg($ip) . ' 2>/dev/null'
            );
        }
        return true;
    }

    protected function unbanEverywhere($ip)
    {
        self::unbanIpLocal($ip);

        $modelServers = Servers::model()->findAll('status IN (1,3,4) AND type != "mbilling" AND host != "localhost"');
        foreach ($modelServers as $server) {
            $host = trim($server['host']);
            if (! filter_var($host, FILTER_VALIDATE_IP)) {
                continue;
            }
            $port = strlen($server['description']) ? (int) $server['description'] : $this->ssh_port;
            @shell_exec(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 root@' . escapeshellarg($host)
                . ' -p ' . (int) $port
                . ' ' . escapeshellarg('fail2ban-client unban ' . $ip) . ' 2>/dev/null'
            );
        }
    }

    protected function syncJail($jail, $action, $server)
    {
        $isMaster = ($server['type'] == 'mbilling' || $server['host'] == 'localhost');

        if ($isMaster) {
            @shell_exec("sed -i 's/^ignoreip = .*/" . $this->ignogeips . "/' /etc/fail2ban/jail.local");
            if ($jail === 'ip-blacklist') {
                foreach ($this->resultBanIps as $blocked) {
                    $ip = trim($blocked['ip']);
                    if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                        continue;
                    }
                    @shell_exec('fail2ban-client set ip-blacklist banip ' . escapeshellarg($ip) . ' 2>/dev/null');
                    $this->insertBanRow($ip, 1, $jail, $server);
                }
            }
            $status = @shell_exec('fail2ban-client status ' . escapeshellarg($jail) . ' 2>/dev/null');
        } else {
            $host = trim($server['host']);
            if (! filter_var($host, FILTER_VALIDATE_IP)) {
                return;
            }
            $port = strlen($server['description']) ? (int) $server['description'] : $this->ssh_port;
            @shell_exec(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 root@' . escapeshellarg($host)
                . ' -p ' . (int) $port
                . ' ' . escapeshellarg("sed -i 's/^ignoreip = .*/" . $this->ignogeips . "/' /etc/fail2ban/jail.local && systemctl reload fail2ban")
                . ' 2>/dev/null'
            );
            if ($jail === 'ip-blacklist') {
                foreach ($this->resultBanIps as $blocked) {
                    $ip = trim($blocked['ip']);
                    if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                        continue;
                    }
                    @shell_exec(
                        'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 root@' . escapeshellarg($host)
                        . ' -p ' . (int) $port
                        . ' ' . escapeshellarg('fail2ban-client set ip-blacklist banip ' . $ip)
                        . ' 2>/dev/null'
                    );
                    $this->insertBanRow($ip, 1, $jail, $server);
                }
            }
            $status = @shell_exec(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 root@' . escapeshellarg($host)
                . ' -p ' . (int) $port
                . ' ' . escapeshellarg('fail2ban-client status ' . $jail)
                . ' 2>/dev/null'
            );
        }

        if (! is_string($status) || $status === '') {
            return;
        }

        if (! preg_match('/Banned IP list:\s*(.*)/', $status, $ipMatches)) {
            return;
        }
        if (! isset($ipMatches[1]) || trim($ipMatches[1]) === '') {
            return;
        }

        $ips = array_filter(array_map('trim', explode(' ', $ipMatches[1])));
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $this->insertBanRow($ip, (int) $action, $jail, $server);
        }
    }

    protected function insertBanRow($ip, $action, $jail, $server)
    {
        $exists = (int) Yii::app()->db->createCommand(
            'SELECT COUNT(*) FROM pkg_firewall WHERE ip = :ip AND jail = :jail AND id_server = :sid'
        )->queryScalar([
            ':ip'   => $ip,
            ':jail' => $jail,
            ':sid'  => $server['id'],
        ]);
        if ($exists > 0) {
            return;
        }
        try {
            Yii::app()->db->createCommand(
                'INSERT INTO pkg_firewall (ip,action, date, description, jail, id_server)
                 VALUES (:ip,:action, NOW(), :desc,:jail,:sid)'
            )->execute([
                ':ip'     => $ip,
                ':action' => $action,
                ':desc'   => $server['name'],
                ':jail'   => $jail,
                ':sid'    => $server['id'],
            ]);
            echo "Synced ban {$ip} jail={$jail} server={$server['name']}\n";
        } catch (Exception $e) {
        }
    }
}
