<?php

class Cleantalk_Antispam_Model_Api //?? extends Mage_Core_Model_Abstract
{

    protected function _construct()
    {
        $this->_init('antispam/api');
    }
    
    /**
     * Universal method for error message
     * @return error template
     */
     
     static function CleantalkDie($message)
	{
		$error_tpl=file_get_contents(dirname(__FILE__)."/error.html");
		print str_replace('%ERROR_TEXT%',$message,$error_tpl);
		die();
	}
     

    /**
     * Universal method for page addon
     * Needed for correct JavaScript detection, for example.
     * @return string Template addon text
     */
    static function PageAddon() {

	$field_name = 'ct_checkjs';	// todo - move this to class constant
	$ct_check_def = '0';
	if (!isset($_COOKIE[$field_name])) setcookie($field_name, $ct_check_def, 0, '/');

	$ct_check_value = self::GetCheckJSValue();
	$js_template = '<script type="text/javascript">
// <![CDATA[
function ctSetCookie(c_name, value) {
 document.cookie = c_name + "=" + escape(value) + "; path=/";
}
ctSetCookie("%s", "%s");
// ]]>
</script>
';
	$ct_template_addon_body = sprintf($js_template, $field_name, $ct_check_value);
	return $ct_template_addon_body;
    }

    /**
     * Universal method for checking comment or new user for spam
     * It makes checking itself
     * @param &array Entity to check (comment or new user)
     * @param boolean Notify admin about errors by email or not (default FALSE)
     * @return array|null Checking result or NULL when bad params
     */
    static function CheckSpam(&$arEntity, $bSendEmail = FALSE) {
      if(!is_array($arEntity) || !array_key_exists('type', $arEntity)) return;
        $type = $arEntity['type'];
        if($type != 'comment' && $type != 'register') return;

		$ct_key = Mage::getStoreConfig('general/cleantalk/api_key');
        $ct_ws = self::GetWorkServer();
        if (isset($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT'] == 'CodistoConnect/1.0') 
            return;
		if(!(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'))
			if (!session_id())
				session_start(); //This one is causing errors with ajax

		if (!isset($_COOKIE['ct_checkjs'])) {
			$checkjs = NULL;
		}
		elseif ($_COOKIE['ct_checkjs'] == self::GetCheckJSValue()) {
			$checkjs = 1;
		}
		else {
			$checkjs = 0;
		}

        if(isset($_SERVER['HTTP_USER_AGENT']))
            $user_agent = htmlspecialchars((string) $_SERVER['HTTP_USER_AGENT']);
        else
            $user_agent = NULL;

        if(isset($_SERVER['HTTP_REFERER']))
            $refferrer = htmlspecialchars((string) $_SERVER['HTTP_REFERER']);
        else
            $refferrer = NULL;

	$ct_language = 'en';

        $sender_info = array(
            'cms_lang' => $ct_language,
            'REFFERRER' => $refferrer,
            'post_url' => $refferrer,
            'USER_AGENT' => $user_agent,
            'REFFERRER_PREVIOUS' => isset($_COOKIE['apbct_prev_referer']) ? $_COOKIE['apbct_prev_referer'] : null,
        );
        $sender_info = json_encode($sender_info);

        require_once __DIR__  . '/lib/cleantalk.class.php';
        
        $ct = new Cleantalk\Antispam\Api\lib\Cleantalk();
        $ct->work_url = $ct_ws['work_url'];
        $ct->server_url = $ct_ws['server_url'];
        $ct->server_ttl = $ct_ws['server_ttl'];
        $ct->server_changed = $ct_ws['server_changed'];
		$sender_ip = $ct->cleantalk_get_real_ip();
        $ct_request = new Cleantalk\Antispam\Api\lib\CleantalkRequest();
        $ct_request->auth_key = $ct_key;
        $ct_request->sender_email = isset($arEntity['sender_email']) ? $arEntity['sender_email'] : '';
        $ct_request->sender_nickname = isset($arEntity['sender_nickname']) ? $arEntity['sender_nickname'] : '';
        $ct_request->sender_ip = isset($arEntity['sender_ip']) ? $arEntity['sender_ip'] : $sender_ip;
        $ct_request->agent = 'magento2-14';
        $ct_request->js_on = $checkjs;
        $ct_request->sender_info = $sender_info;
        $ct_request->submit_time = isset($_COOKIE['ct_ps_timestamp']) ? time() - intval($_COOKIE['ct_ps_timestamp']) : 0;

        switch ($type) {
            case 'comment':
                $timelabels_key = 'mail_error_comment';

                $message_title = isset($arEntity['message_title']) ? $arEntity['message_title'] : '';
                $message_body = isset($arEntity['message_body']) ? $arEntity['message_body'] : '';
                $ct_request->message = $message_title . " \n\n" . $message_body;

                $example = '';
                $a_example['title'] = isset($arEntity['example_title']) ? $arEntity['example_title'] : '';
                $a_example['body'] =  isset($arEntity['example_body']) ? $arEntity['example_body'] : '';
                $a_example['comments'] = isset($arEntity['example_comments']) ? $arEntity['example_comments'] : '';

                // Additional info.
                $post_info = '';
                $a_post_info['comment_type'] = 'comment';

                // JSON format.
                $example = json_encode($a_example);
                $post_info = json_encode($a_post_info);

                // Plain text format.
                if($example === FALSE){
                    $example = '';
                    $example .= $a_example['title'] . " \n\n";
                    $example .= $a_example['body'] . " \n\n";
                    $example .= $a_example['comments'];
                }
                if($post_info === FALSE)
                    $post_info = '';

                // Example text + last N comments in json or plain text format.
                $ct_request->example = $example;
                $ct_request->post_info = $post_info;
                $ct_result = $ct->isAllowMessage($ct_request);
                break;
            case 'register':
                $timelabels_key = 'mail_error_reg';
                $ct_request->tz = isset($arEntity['user_timezone']) ? $arEntity['user_timezone'] : NULL;
                $ct_result = $ct->isAllowUser($ct_request);
        }

        $ret_val = array();
        $ret_val['ct_request_id'] = $ct_result->id;

        if($ct->server_change)
            self::SetWorkServer(
                $ct->work_url, $ct->server_url, $ct->server_ttl, time()
            );

        // First check errstr flag.
        if(!empty($ct_result->errstr)
            || (!empty($ct_result->inactive) && $ct_result->inactive == 1)
        ){
            // Cleantalk error so we go default way (no action at all).
            $ret_val['errno'] = 1;
            $err_title = $_SERVER['SERVER_NAME'] . ' - CleanTalk module error';

            if(!empty($ct_result->errstr)){
		    if (preg_match('//u', $ct_result->errstr)){
            		    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $ct_result->errstr);
		    }else{
            		    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $ct_result->errstr);
		    }
            }else{
		    if (preg_match('//u', $ct_result->comment)){
			    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $ct_result->comment);
		    }else{
			    $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $ct_result->comment);
		    }
	    }
            $ret_val['errstr'] = $err_str;

            return $ret_val;
        }

        $ret_val['errno'] = 0;
        if ($ct_result->allow == 1) {
            // Not spammer.
            $ret_val['allow'] = 1;
        }else{
            $ret_val['allow'] = 0;
            $ret_val['ct_result_comment'] = $ct_result->comment;
            // Spammer.
            // Check stop_queue flag.
            if($type == 'comment' && $ct_result->stop_queue == 0) {
                // Spammer and stop_queue == 0 - to manual approvement.
                $ret_val['stop_queue'] = 0;
            }else{
                // New user or Spammer and stop_queue == 1 - display message and exit.
                $ret_val['stop_queue'] = 1;
            }
        }
        return $ret_val;
    }


    /**
     * CleanTalk inner function - gets working server.
     */
    private static function GetWorkServer() {
        $data = false;
        try{
            $server = Mage::getModel('antispam/server');
            $server->load(1);
            $data = $server->getData();
        }catch(Exception $e){
            Mage::log('Cannot read from with "cleantalk_server" table.');
        }

        if($data && !empty($data))
            return array(
                'work_url' => $data['work_url'],
                'server_url' => $data['server_url'],
                'server_ttl' => $data['server_ttl'],
                'server_changed' => $data['server_changed'],
            );
        else
            return array(
                'work_url' => 'https://moderate.cleantalk.org',
                'server_url' => 'https://moderate.cleantalk.org',
                'server_ttl' => 0,
                'server_changed' => 0,
            );
    }

    /**
     * CleanTalk inner function - sets working server.
     */
    private static function SetWorkServer($work_url = 'https://moderate.cleantalk.org', $server_url = 'https://moderate.cleantalk.org', $server_ttl = 0, $server_changed = 0) {
        try{
            $server = Mage::getModel('antispam/server');
            $server->load(1);
            $data = $server->getData();

            if($data && !empty($data))
                $server->setData('server_id', 1);
        
            $server->setData('work_url', $work_url);
            $server->setData('server_url', $server_url);
            $server->setData('server_ttl', $server_ttl);
            $server->setData('server_changed', $server_changed);
            $server->save();
        }catch(Exception $e){
            Mage::log('Cannot write to "cleantalk_server" table.');
        }
    }

    /**
     * CleanTalk inner function - JavaScript checking value, depends on system variables
     * @return string System depending md5 hash
     */
    static function GetCheckJSValue() {
	return md5(Mage::getStoreConfig('general/cleantalk/api_key') . '_' . Mage::getStoreConfig('trans_email/ident_general/email'));
    }

}// class Cleantalk_Antispam_Model_Api
