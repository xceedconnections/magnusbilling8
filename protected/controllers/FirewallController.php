<?php

/**
 * Actions of module "Firewall".
 *
 * MagnusBilling <info@magnusbilling.com>
 * Instant unban: removes Fail2ban ban immediately and deletes the DB row.
 * The IP stays free until the next real attack matches a jail again.
 */

class FirewallController extends Controller
{

    public $attributeOrder = 'date DESC';
    public $extraValues    = ['idServer' => 'name'];

    public function init()
    {
        $this->instanceModel = new Firewall;
        $this->abstractModel = Firewall::model();
        $this->titleReport   = Yii::t('zii', 'Firewall');

        parent::init();
    }

    public function actionDestroy()
    {
        if (! AccessManager::getInstance($this->instanceModel->getModule())->canDelete()) {
            header('HTTP/1.0 401 Unauthorized');
            $this->sendError('Access denied for module "{module}".', ['{module}' => $this->instanceModel->getModule()]);
        }

        $values = $this->getAttributesRequest();
        $namePk = 'id';
        $ids    = [];

        if (array_key_exists(0, $values)) {
            foreach ($values as $value) {
                array_push($ids, $value[$namePk]);
            }
        } else {
            array_push($ids, $values[$namePk]);
        }

        $unbanned = [];
        foreach ($ids as $value) {
            $model = Firewall::model()->findByPk((int) $value);
            if (! isset($model->id)) {
                continue;
            }

            $ip = trim($model->ip);
            FailtwobanipCommand::unbanIpLocal($ip);

            // Mark remote slaves/proxies for next sync as well (instant on master already done).
            $model->action = 3;
            $model->save();

            // Delete immediately so the Firewall list clears now.
            $ipCopy = $model->ip;
            $model->delete();
            LogUsers::model()->deleteAll('ip = :key', [':key' => $ipCopy]);
            $unbanned[] = $ipCopy;
        }

        // Also process any leftover action=3 rows on this host right away.
        try {
            Yii::app()->db->createCommand('DELETE FROM pkg_firewall WHERE action = 3')->execute();
        } catch (Exception $e) {
        }

        echo json_encode([
            $this->nameSuccess => true,
            $this->nameMsg     => count($unbanned)
                ? ('IP unbanned instantly: ' . implode(', ', $unbanned))
                : 'IP unbanned instantly',
        ]);
    }
}
