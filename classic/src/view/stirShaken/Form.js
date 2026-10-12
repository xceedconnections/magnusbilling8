Ext.define('MBilling.view.stirShaken.Form', {
    extend: 'Ext.ux.form.Panel',
    alias: 'widget.stirshakenform',
    initComponent: function() {
        var me = this;
        me.items = [{
            xtype: 'displayfield',
            name: 'proxy_host',
            fieldLabel: t('SipProxy')
        }, {
            xtype: 'noyescombo',
            name: 'sign_enabled',
            fieldLabel: t('Sign enabled'),
            value: 0
        }, {
            xtype: 'noyescombo',
            name: 'verify_enabled',
            fieldLabel: t('Verify enabled'),
            value: 1
        }, {
            xtype: 'noyescombo',
            name: 'verify_reject',
            fieldLabel: t('Reject invalid Identity'),
            value: 0
        }, {
            xtype: 'combobox',
            name: 'attestation',
            fieldLabel: t('Attestation'),
            forceSelection: true,
            editable: false,
            value: 'C',
            store: [
                ['A', 'A'],
                ['B', 'B'],
                ['C', 'C']
            ]
        }, {
            name: 'x5u',
            fieldLabel: t('x5u HTTPS URL'),
            allowBlank: true,
            emptyText: 'https://certificates.example.com/stir/certificate.pem'
        }, {
            xtype: 'textareafield',
            name: 'certificate',
            fieldLabel: t('Certificate PEM'),
            height: 140,
            allowBlank: true
        }, {
            xtype: 'textareafield',
            name: 'private_key',
            fieldLabel: t('Private key PEM'),
            height: 140,
            allowBlank: true
        }];
        me.callParent(arguments);
    }
});
