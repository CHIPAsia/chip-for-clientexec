<?php
require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/billing/models/class.gateway.plugin.php';

class PluginChipCallback extends PluginCallback
{

    public function processCallback()
    {
        $pluginName = 'chip';
        $cPlugin = new Plugin('', $pluginName, $this->user);
    }

}

