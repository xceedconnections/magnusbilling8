Ext.define('MBilling.view.stirShaken.List', {
    extend: 'Ext.ux.grid.Panel',
    alias: 'widget.stirshakenlist',
    store: 'StirShaken',
    initComponent: function() {
        var me = this;
        me.buttonCsv = false;
        me.buttonUpdateLot = false;
        me.allowPrint = false;
        me.allowCreate = false;
        me.allowDelete = false;
        me.columns = [{
            header: t('ID'),
            dataIndex: 'id',
            flex: 1,
            hidden: true
        }, {
            header: t('SipProxy'),
            dataIndex: 'proxy_host',
            flex: 2
        }, {
            header: t('Sign enabled'),
            dataIndex: 'sign_enabled',
            flex: 1,
            renderer: me.booleanRenderer
        }, {
            header: t('Verify enabled'),
            dataIndex: 'verify_enabled',
            flex: 1,
            renderer: me.booleanRenderer
        }, {
            header: t('Attestation'),
            dataIndex: 'attestation',
            flex: 1
        }, {
            header: t('x5u'),
            dataIndex: 'x5u',
            flex: 3
        }];
        me.callParent(arguments);
    },
    booleanRenderer: function(value) {
        return parseInt(value, 10) === 1 ? t('Yes') : t('No');
    }
});
