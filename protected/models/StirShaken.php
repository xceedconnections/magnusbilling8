<?php
/**
 * Virtual model for OpenSIPS stir_shaken_config (single-row policy).
 *
 * =======================================
 * MagnusBilling open cluster
 * =======================================
 */
class StirShaken extends Model
{
    protected $_module = 'stirShaken';

    public $id              = 1;
    public $sign_enabled    = 0;
    public $verify_enabled  = 1;
    public $verify_reject   = 0;
    public $attestation     = 'C';
    public $x5u             = '';
    public $certificate     = '';
    public $private_key     = '';
    public $proxy_host      = '';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_servers';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        return [
            ['sign_enabled, verify_enabled, verify_reject', 'numerical', 'integerOnly' => true],
            ['attestation', 'in', 'range' => ['A', 'B', 'C']],
            ['x5u, proxy_host', 'length', 'max' => 1024],
            ['certificate, private_key', 'safe'],
        ];
    }
}
