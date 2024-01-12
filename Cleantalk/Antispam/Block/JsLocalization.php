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
    public function getExternalFormsEnabled()
    {
        $external_forms_option = 'general/cleantalkantispam/ct_external_forms';
        $external_forms = $this->_scopeConfig->getValue($external_forms_option);

        $params = [
            'externalForms' => $external_forms,
            'ajaxUrl' => $this->getUrl('cleantalkajax/ajaxhandler'),
        ];
        return json_encode($params);
    }
}
