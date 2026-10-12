<?php
/**
 * Portal UI for OpenSIPS STIR/SHAKEN policy (opensips.stir_shaken_config).
 *
 * =======================================
 * MagnusBilling open cluster
 * =======================================
 */
class StirShakenController extends Controller
{
    public $attributeOrder = 't.id';

    public function init()
    {
        if (! Yii::app()->session['isAdmin']) {
            exit();
        }
        $this->instanceModel = new StirShaken;
        $this->abstractModel = StirShaken::model();
        $this->titleReport   = Yii::t('zii', 'STIR/SHAKEN');
        parent::init();
    }

    public function actionRead($asJson = true, $condition = null)
    {
        $row = $this->loadConfigRow();
        echo json_encode([
            $this->nameSuccess => true,
            $this->nameRoot    => [$row],
            $this->nameCount   => 1,
        ]);
    }

    public function actionSave()
    {
        $this->persistFromRequest();
    }

    public function actionUpdate()
    {
        $this->persistFromRequest();
    }

    public function actionCreate()
    {
        $this->persistFromRequest();
    }

    public function actionDestroy()
    {
        echo json_encode([
            $this->nameSuccess => false,
            $this->nameMsgErrors => Yii::t('zii', 'Not allowed'),
        ]);
    }

    protected function persistFromRequest()
    {
        $values = $this->getAttributesRequest();
        if (isset($values[0]) && is_array($values[0])) {
            $values = $values[0];
        }
        $proxy  = Servers::model()->find('type = :type AND status = 1', [':type' => 'sipproxy']);
        if (! isset($proxy->id)) {
            echo json_encode([
                $this->nameSuccess    => false,
                $this->nameMsgErrors  => Yii::t('zii', 'No active SipProxy server found'),
            ]);
            return;
        }

        $con = OpenSipsClusterHelper::connectProxy($proxy);
        if ($con === null) {
            echo json_encode([
                $this->nameSuccess   => false,
                $this->nameMsgErrors => Yii::t('zii', 'Unable to connect to SipProxy database'),
            ]);
            return;
        }

        $this->ensureStirTable($con);

        $signEnabled   = isset($values['sign_enabled']) ? (int) $values['sign_enabled'] : 0;
        $verifyEnabled = isset($values['verify_enabled']) ? (int) $values['verify_enabled'] : 1;
        $verifyReject  = isset($values['verify_reject']) ? (int) $values['verify_reject'] : 0;
        $attestation   = isset($values['attestation']) ? strtoupper($values['attestation']) : 'C';
        if (! in_array($attestation, ['A', 'B', 'C'], true)) {
            $attestation = 'C';
        }
        $x5u         = isset($values['x5u']) ? trim($values['x5u']) : '';
        $certificate = isset($values['certificate']) ? (string) $values['certificate'] : '';
        $privateKey  = isset($values['private_key']) ? (string) $values['private_key'] : '';

        if ($signEnabled === 1) {
            if ($x5u === '' || ! preg_match('#^https://#i', $x5u)) {
                echo json_encode([
                    $this->nameSuccess   => false,
                    $this->nameMsgErrors => Yii::t('zii', 'x5u must be an HTTPS URL when signing is enabled'),
                ]);
                return;
            }
            if (trim($certificate) === '' || trim($privateKey) === '') {
                echo json_encode([
                    $this->nameSuccess   => false,
                    $this->nameMsgErrors => Yii::t('zii', 'Certificate and private key are required when signing is enabled'),
                ]);
                return;
            }
        }

        $sql = 'INSERT INTO stir_shaken_config
            (id, sign_enabled, verify_enabled, verify_reject, attestation, x5u, certificate, private_key)
            VALUES (1, :sign, :verify, :reject, :attest, :x5u, :cert, :pkey)
            ON DUPLICATE KEY UPDATE
            sign_enabled=VALUES(sign_enabled),
            verify_enabled=VALUES(verify_enabled),
            verify_reject=VALUES(verify_reject),
            attestation=VALUES(attestation),
            x5u=VALUES(x5u),
            certificate=VALUES(certificate),
            private_key=VALUES(private_key)';

        try {
            $cmd = $con->createCommand($sql);
            $cmd->bindValue(':sign', $signEnabled, PDO::PARAM_INT);
            $cmd->bindValue(':verify', $verifyEnabled, PDO::PARAM_INT);
            $cmd->bindValue(':reject', $verifyReject, PDO::PARAM_INT);
            $cmd->bindValue(':attest', $attestation, PDO::PARAM_STR);
            $cmd->bindValue(':x5u', $x5u, PDO::PARAM_STR);
            $cmd->bindValue(':cert', $certificate, PDO::PARAM_STR);
            $cmd->bindValue(':pkey', $privateKey, PDO::PARAM_STR);
            $cmd->execute();
        } catch (Exception $e) {
            echo json_encode([
                $this->nameSuccess   => false,
                $this->nameMsgErrors => $e->getMessage(),
            ]);
            return;
        }

        $row = $this->loadConfigRow($proxy, $con);
        echo json_encode([
            $this->nameSuccess => true,
            $this->nameRoot    => [$row],
            $this->nameMsg     => $this->msgSuccess,
        ]);
    }

    protected function loadConfigRow(Servers $proxy = null, CDbConnection $con = null)
    {
        if ($proxy === null) {
            $proxy = Servers::model()->find('type = :type AND status = 1', [':type' => 'sipproxy']);
        }
        if ($con === null && isset($proxy->id)) {
            $con = OpenSipsClusterHelper::connectProxy($proxy);
        }

        $row = [
            'id'             => 1,
            'sign_enabled'   => 0,
            'verify_enabled' => 1,
            'verify_reject'  => 0,
            'attestation'    => 'C',
            'x5u'            => '',
            'certificate'    => '',
            'private_key'    => '',
            'proxy_host'     => isset($proxy->host) ? $proxy->host : '',
        ];

        if ($con === null) {
            return $row;
        }

        $this->ensureStirTable($con);
        try {
            $data = $con->createCommand('SELECT * FROM stir_shaken_config WHERE id = 1')->queryRow();
            if (is_array($data)) {
                $row['sign_enabled']   = (int) $data['sign_enabled'];
                $row['verify_enabled'] = (int) $data['verify_enabled'];
                $row['verify_reject']  = (int) $data['verify_reject'];
                $row['attestation']    = $data['attestation'];
                $row['x5u']            = $data['x5u'];
                $row['certificate']    = $data['certificate'];
                $row['private_key']    = $data['private_key'];
            }
        } catch (Exception $e) {
            // return defaults
        }

        return $row;
    }

    protected function ensureStirTable(CDbConnection $con)
    {
        $sql = "CREATE TABLE IF NOT EXISTS stir_shaken_config (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            sign_enabled TINYINT(1) NOT NULL DEFAULT 0,
            verify_enabled TINYINT(1) NOT NULL DEFAULT 1,
            verify_reject TINYINT(1) NOT NULL DEFAULT 0,
            attestation ENUM('A','B','C') NOT NULL DEFAULT 'C',
            x5u VARCHAR(1024) NOT NULL DEFAULT '',
            certificate LONGTEXT NULL,
            private_key LONGTEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try {
            $con->createCommand($sql)->execute();
            $con->createCommand('INSERT IGNORE INTO stir_shaken_config (id) VALUES (1)')->execute();
        } catch (Exception $e) {
            // already present
        }
    }
}
