/**
 * Class to define combo of "groupUser"
 *
 * Adilson L. Magnus <info@magnussolution.com> 
 * 15/04/2013
 */
Ext.define('MBilling.view.servers.Combo', {
    extend: 'Ext.form.field.ComboBox',
    alias: 'widget.serverscombo',
    name: 'id_server',
    fieldLabel: t('Server'),
    displayField: 'name',
    forceSelection: true,
    editable: false,
    valueField: 'id',
    // When true, only Asterisk / MagnusBilling call-processing nodes are listed
    // (used to pin a SIP account to a slave).
    asteriskOnly: false,
    initComponent: function() {
        var me = this;
        me.store = Ext.create('MBilling.store.Servers', {
            proxy: {
                type: 'uxproxy',
                module: 'servers',
                limitParam: undefined
            }
        });
        if (me.asteriskOnly) {
            me.store.on('load', function(store) {
                store.filterBy(function(rec) {
                    var type = (rec.get('type') || '').toLowerCase();
                    return type === 'asterisk' || type === 'mbilling';
                });
            });
        }
        me.callParent(arguments);
    }
});