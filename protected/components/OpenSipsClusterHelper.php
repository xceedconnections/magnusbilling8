<?php
/**
 * Helpers for MagnusBilling open cluster (OpenSIPS + multi-Asterisk).
 *
 * =======================================
 * MagnusBilling
 * =======================================
 */

class OpenSipsClusterHelper
{
    /**
     * Build host:port used by OpenSIPS to pin or dispatch to an Asterisk server.
     *
     * @param Servers $server
     * @param bool    $preferPrivate when proxy is on a private network
     * @return string
     */
    public static function asteriskDestination(Servers $server, $preferPrivate = false)
    {
        $host = '';
        if ($preferPrivate && strlen((string) $server->host)) {
            $host = $server->host;
        } elseif (filter_var($server->public_ip, FILTER_VALIDATE_IP)) {
            $host = $server->public_ip;
        } elseif (strlen((string) $server->host)) {
            $host = $server->host;
        }

        $port = strlen((string) $server->sip_port) ? $server->sip_port : '5060';

        return $host . ':' . $port;
    }

    /**
     * Resolve pin destination for a SIP account (empty = weight-based balance).
     *
     * @param Sip  $sip
     * @param bool $preferPrivate
     * @return string
     */
    public static function sipPinDestination(Sip $sip, $preferPrivate = false)
    {
        if (! isset($sip->id_server) || (int) $sip->id_server <= 0) {
            return '';
        }

        $server = isset($sip->idServer) ? $sip->idServer : Servers::model()->findByPk((int) $sip->id_server);
        if (! isset($server->id) || ! in_array(strtolower((string) $server->type), ['asterisk', 'mbilling'], true)) {
            return '';
        }
        if ((int) $server->status === 0) {
            return '';
        }

        return self::asteriskDestination($server, $preferPrivate);
    }

    /**
     * Normalize directmedia for OpenSIPS media-bypass flag (yes/no).
     *
     * @param string $directmedia
     * @return string
     */
    public static function mediaBypassFlag($directmedia)
    {
        $value = strtolower(trim((string) $directmedia));
        if (in_array($value, ['yes', 'update', 'nonat', 'outgoing', 'incoming'], true)) {
            return 'yes';
        }
        return 'no';
    }

    /**
     * Open a Yii DB connection to an active SipProxy opensips database.
     *
     * @param Servers|null $proxy
     * @return CDbConnection|null
     */
    public static function connectProxy(Servers $proxy = null)
    {
        if ($proxy === null) {
            $proxy = Servers::model()->find('type = :type AND status = 1', [':type' => 'sipproxy']);
        }
        if (! isset($proxy->id)) {
            return null;
        }

        $dsn = 'mysql:host=' . $proxy->host . ';dbname=opensips';
        try {
            $con         = new CDbConnection($dsn, $proxy->username, $proxy->password);
            $con->active = true;
            return $con;
        } catch (Exception $e) {
            return null;
        }
    }
}
