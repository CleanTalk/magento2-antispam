<?php

namespace Cleantalk\Antispam\Block;

class JsLocalization extends \Magento\Framework\View\Element\Template
{
    public function getScriptOptions()
    {
        $path = 'general/cleantalkantispam/ct_access_key';
        $config = $this->_scopeConfig->getValue($path);

        $params = [
            // @ToDo we can make it stronger - add a salt
            'jsKey' => hash('sha256', $config)
        ];
        return json_encode($params);
    }
}
