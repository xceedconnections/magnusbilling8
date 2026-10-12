Ext.define('MBilling.model.StirShaken', {
    extend: 'Ext.data.Model',
    fields: [{
        name: 'id',
        type: 'int'
    }, {
        name: 'sign_enabled',
        type: 'int'
    }, {
        name: 'verify_enabled',
        type: 'int'
    }, {
        name: 'verify_reject',
        type: 'int'
    }, {
        name: 'attestation',
        type: 'string'
    }, {
        name: 'x5u',
        type: 'string'
    }, {
        name: 'certificate',
        type: 'string'
    }, {
        name: 'private_key',
        type: 'string'
    }, {
        name: 'proxy_host',
        type: 'string'
    }],
    proxy: {
        type: 'uxproxy',
        module: 'stirShaken'
    }
});
