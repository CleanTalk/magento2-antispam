<?php
 
namespace Cleantalk\Antispam\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class Predispatch implements ObserverInterface
{
    const COOKIE_REFERRAL = 'referral';
    
    protected $_cookieManager;
    protected $_sessionManager;
        
    public $scopeConfig;
    
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager
    ){
        $this->scopeConfig = $scopeConfig;
        $this->_cookieManager = $cookieManager;
        $this->_sessionManager = $sessionManager;
    }
    
    // * Get a configuration value
    public function getConfigValue($settings){
        return $this->scopeConfig->getValue('general/cleantalkantispam/'.$settings, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
    //Get cookie
    public function getCookie($cookie_name){
        $result = $this->_cookieManager->getCookie($cookie_name, 'default value');
        return $result;
    }
    //Set cookie
    public function setCookie($value){
        // set public cookie (can be accessed by JS)
        $meta = new \Magento\Framework\Stdlib\Cookie\PublicCookieMetadata();
        $meta->setPath('/'); // use meta to define cookie props: domain, duration, security, ...
        $meta->setDurationOneYear();
        $this->_cookieManager->setPublicCookie(self::COOKIE_REFERRAL, $value, $meta);

        // or set HTTP only cookie (is not accessible from JavaScript )
        /** @var \Magento\Framework\Stdlib\Cookie\SensitiveCookieMetadata $meta */
        $meta = null; // use meta to define cookie props: domain, duration, security, ...
        $this->_cookieManager->setSensitiveCookie(self::COOKIE_REFERRAL, $value, $meta);
    }

    public function process(){
        // save variable with name 'referral_code' into the session (using 'magic' methods)
        $this->_sessionManager->setReferralCode('u54321');

        // restore saved value from the session
        $saved = $this->_sessionManager->getReferralCode();
    }
 
    
    public function execute(Observer $observer){
        
        //Go out if CleanTalk disabled
        if(!$this->getConfigValue('ct_enabled'))
            return;
                
        //Exeptions for spam protection
        if(isset($_POST) && count($_POST)){
            //Flag to disable custom contact form checking
            $ct_already_checked = false;
            
            //Registrations
            $registration_test_enabled = $this->getConfigValue('ct_registrations');
            if( 
                // $registration_test_enabled &&
                strpos($_SERVER['REQUEST_URI'],'account/createpost') && 
                isset($_POST['firstname'], $_POST['lastname'], $_POST['email'], $_POST['password'], $_POST['password_confirmation'])
            ){
                $ct_fields = $this -> cleantalkGetFields($_POST);
                $result_array = $this -> setArrayToSend($ct_fields, 'register');
                
                $ct_already_checked = true;
            }
            
            //Contacts          
            $contact_test_enabled = $this->getConfigValue('ct_contact_forms');
            if( 
                // $contact_test_enabled &&
                strpos($_SERVER['REQUEST_URI'],'contact/index') && 
                isset($_POST['name'], $_POST['email'], $_POST['telephone'], $_POST['comment'], $_POST['hideit'])
            ){
                $ct_fields = $this -> cleantalkGetFields($_POST);
                $result_array = $this -> setArrayToSend($ct_fields, 'comment');
                
                $ct_already_checked = true;
            }
            
            //Custom Forms
            $custom_forms_test_enabled = $this->getConfigValue('ct_custom_contact_forms');
            if( $custom_forms_test_enabled &&
                !$ct_already_checked &&
                strpos($_SERVER['REQUEST_URI'],'login')===false &&
                strpos($_SERVER['REQUEST_URI'],'forgotpassword')===false
            ){
                $ct_fields = $this -> cleantalkGetFields($_POST);
                $result_array = $this -> setArrayToSend($ct_fields, 'comment');
            }
            
            //Rrequest and block if needed
            if(isset($result_array)){
                                
                $aResult = $this -> CheckSpam($result_array);
            
                if(isset($aResult) && is_array($aResult)){
                    if($aResult['errno'] == 0){
                        if($aResult['allow'] == 0){
                            $this -> CleantalkDie($aResult['ct_result_comment']);
                        }
                    }
                }
            }
        }
        
    }
    
    //Prepares data to request
    static function setArrayToSend($arr, $type){
                
        $result_array = array();
        $result_array['type'] = $type;
        $result_array['sender_email'] = ($arr['email'] ? $arr['email'] : '');
        $result_array['sender_nickname'] = ($arr['nickname'] ? $arr['nickname'] : '');
        $result_array['message_title'] = '';
        $result_array['message_body'] = ($arr['message'] ? $arr['message'] : '');
        $result_array['example_title'] = '';
        $result_array['example_body'] = '';
        $result_array['example_comments'] = '';
        $result_array['send_request'] = ($arr['message'] || $arr['email']) ? true: false;
        return $result_array;
    }
    
    //Recursevely gets data from array
    static function cleantalkGetFields($arr, $message=array(), $email = null, $nickname = array('nick' => '', 'first' => '', 'last' => ''), $subject = null, $contact = true, $prev_name = ''){
        
        //Skip request if fields exists
        $skip_params = array(
            'ipn_track_id',     // PayPal IPN #
            'txn_type',         // PayPal transaction type
            'payment_status',   // PayPal payment status
            'ccbill_ipn',       // CCBill IPN 
            'ct_checkjs',       // skip ct_checkjs field
            'api_mode',         // DigiStore-API
            'loadLastCommentId' // Plugin: WP Discuz. ticket_id=5571
        );
        
        // Fields to replace with ****
        $obfuscate_params = array(
            'password',
            'password_confirmation',
            'pass',
            'pwd',
            'pswd'
        );
        
        // Skip feilds with these strings and known service fields
        $skip_fields_with_strings = array( 
            // Common
            'ct_checkjs', //Do not send ct_checkjs
            'nonce', //nonce for strings such as 'rsvp_nonce_name'
            'security',
            // 'action',
            'http_referer',
            'timestamp',
            'captcha',
            // Formidable Form
            'form_key',
            'submit_entry',
            // Custom Contact Forms
            'form_id',
            'ccf_form',
            'form_page',
            // Qu Forms
            'iphorm_uid',
            'form_url',
            'post_id',
            'iphorm_ajax',
            'iphorm_id',
            // Fast SecureContact Froms
            'fs_postonce_1',
            'fscf_submitted',
            'mailto_id',
            'si_contact_action',
            // Ninja Forms
            'formData_id',
            'formData_settings',
            'formData_fields_\d+_id',
            'formData_fields_\d+_files.*',      
            // E_signature
            'recipient_signature',
            'output_\d+_\w{0,2}',
            // Contact Form by Web-Settler protection
            '_formId',
            '_returnLink',
            // Social login and more
            '_save',
            '_facebook',
            '_social',
            'user_login-',
            'submit',
            'form_token',
            'creation_time',
            'uenc',
            'product',

        );
                
        foreach($skip_params as $value){
            if(array_key_exists($value,$_POST))
            {
                $contact = false;
            }
        } unset($value);
            
        if(count($arr)){
            foreach($arr as $key => $value){
                
                if(gettype($value)=='string'){
                    $decoded_json_value = json_decode($value, true);
                    if($decoded_json_value !== null)
                    {
                        $value = $decoded_json_value;
                    }
                }
                
                if(!is_array($value) && !is_object($value)){
                    
                    if (in_array($key, $skip_params, true) && $key != 0 && $key != '' || preg_match("/^ct_checkjs/", $key))
                    {
                        $contact = false;
                    }
                    
                    if($value === '')
                    {
                        continue;
                    }
                    
                    // Skipping fields names with strings from (array)skip_fields_with_strings
                    foreach($skip_fields_with_strings as $needle){
                        if (preg_match("/".$needle."/", $prev_name.$key) == 1){
                            continue(2);
                        }
                    }unset($needle);
                    // Obfuscating params
                    foreach($obfuscate_params as $needle){
                        if (strpos($key, $needle) !== false){
                            $value = self::obfuscate_param($value);
                        }
                    }unset($needle);
                    

                    // Decodes URL-encoded data to string.
                    $value = urldecode($value); 

                    // Email
                    if (!$email && preg_match("/^\S+@\S+\.\S+$/", $value)){
                        $email = $value;
                        
                    // Names
                    }elseif (preg_match("/name/i", $key)){
                        
                        preg_match("/(first.?name)?(name.?first)?(forename)?/", $key, $match_forename);
                        preg_match("/(last.?name)?(family.?name)?(second.?name)?(surname)?/", $key, $match_surname);
                        preg_match("/(nick.?name)?(user.?name)?(nick)?/", $key, $match_nickname);
                        
                        if(count($match_forename) > 1)
                        {
                            $nickname['first'] = $value;
                        }
                        elseif(count($match_surname) > 1)
                        {
                            $nickname['last'] = $value;
                        }
                        elseif(count($match_nickname) > 1)
                        {
                            $nickname['nick'] = $value;
                        }
                        else
                        {
                            $message[$prev_name.$key] = $value;
                        }
                    
                    // Subject
                    }elseif ($subject === null && preg_match("/subject/i", $key)){
                        $subject = $value;
                    
                    // Message
                    }else{
                        $message[$prev_name.$key] = $value;                 
                    }
                    
                }elseif(!is_object($value)){
                    
                    $prev_name_original = $prev_name;
                    $prev_name = ($prev_name === '' ? $key.'_' : $prev_name.$key.'_');
                    
                    $temp = self::cleantalkGetFields($value, $message, $email, $nickname, $subject, $contact, $prev_name);
                    
                    $message    = $temp['message'];
                    $email      = ($temp['email']       ? $temp['email'] : null);
                    $nickname   = ($temp['nickname']    ? $temp['nickname'] : null);                
                    $subject    = ($temp['subject']     ? $temp['subject'] : null);
                    if($contact === true)
                    {
                        $contact = ($temp['contact'] === false ? false : true);
                    }
                    $prev_name  = $prev_name_original;
                }
            } unset($key, $value);
        }
                
        //If top iteration, returns compiled name field. Example: "Nickname Firtsname Lastname".
        if($prev_name === ''){
            if(!empty($nickname)){
                $nickname_str = '';
                foreach($nickname as $value){
                    $nickname_str .= ($value ? $value." " : "");
                }unset($value);
            }
            $nickname = $nickname_str;
        }
        
        $return_param = array(
            'email'     => $email,
            'nickname'  => $nickname,
            'subject'   => $subject,
            'contact'   => $contact,
            'message'   => $message
        );  
        return $return_param;
    }
    /**
    * Masks a value with asterisks (*)
    * @return string
    */
    static function obfuscate_param($value = null) {
        if ($value && (!is_object($value) || !is_array($value))) {
            $length = strlen($value);
            $value = str_repeat('*', $length);
        }
        return $value;
    }   
    //Die function - Shows special die page
    static function CleantalkDie($message){
                
        $error_tpl=file_get_contents(dirname(__FILE__)."/error.html");
        print str_replace('%ERROR_TEXT%',$message,$error_tpl);
        die();
    }
    
    /**
     * Universal method for checking comment or new user for spam
     * It makes checking itself
     * @param &array Entity to check (comment or new user)
     * @param boolean Notify admin about errors by email or not (default FALSE)
     * @return array|null Checking result or NULL when bad params
     */
    function CheckSpam($arEntity){
    
        if(!is_array($arEntity) || !array_key_exists('type', $arEntity) || $arEntity['send_request'] === false) return;

        $type = $arEntity['type'];
        if($type != 'comment' && $type != 'register') return;

        $ct_key = $this->getConfigValue('ct_access_key');
        
        $ct_ws = array(
                'work_url' => 'http://moderate.cleantalk.ru',
                'server_url' => 'http://moderate.cleantalk.ru',
                'server_ttl' => 0,
                'server_changed' => 0,
            );
        if(!(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'))
            if (!session_id())
                session_start(); //This one is causing errors with ajax

        $checkjs = $this->getCookie('ct_checkjs') == '777b374af06bbb4f6fdbda40727b5c3b' ? 1 : 0;
        $timezone = $this->getCookie('ct_timezone');
        
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
            'js_timezone' => $timezone
        );
        $sender_info = json_encode($sender_info);
                
        require_once __DIR__  . '/../Api/lib/cleantalk.class.php';
        
        $ct = new Cleantalk();
        $ct->work_url = $ct_ws['work_url'];
        $ct->server_url = $ct_ws['server_url'];
        $ct->server_ttl = $ct_ws['server_ttl'];
        $ct->server_changed = $ct_ws['server_changed'];

        $sender_ip = $ct->cleantalk_get_real_ip();
        $ct_request = new CleantalkRequest();
        $ct_request->auth_key = $ct_key;
        $ct_request->sender_email = isset($arEntity['sender_email']) ? $arEntity['sender_email'] : '';
        $ct_request->sender_nickname = isset($arEntity['sender_nickname']) ? $arEntity['sender_nickname'] : '';
        $ct_request->sender_ip = isset($arEntity['sender_ip']) ? $arEntity['sender_ip'] : $sender_ip;
        $ct_request->agent = 'magento2-11';
        $ct_request->js_on = $checkjs;
        $ct_request->sender_info = $sender_info;
        $ct_submit_time = NULL;
        if(isset($_SESSION['ct_submit_time']))
            $ct_submit_time = time() - $_SESSION['ct_submit_time'];
        switch ($type) {
            case 'comment':
                $timelabels_key = 'mail_error_comment';
                $ct_request->submit_time = $ct_submit_time;

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
                $ct_request->submit_time = $ct_submit_time;
                $ct_request->tz = isset($arEntity['user_timezone']) ? $arEntity['user_timezone'] : NULL;
                $ct_result = $ct->isAllowUser($ct_request);
        }
        $ret_val = array();
        $ret_val['ct_request_id'] = $ct_result->id;

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

            $timedata = FALSE;
            $send_flag = FALSE;
            $insert_flag = FALSE;
            try{
                $timelabels = Mage::getModel('antispam/timelabels');
                $timelabels->load('mail_error');
                $time = $timelabels->getData();
                if(!$time || empty($time)){
                    $send_flag = TRUE;
                    $insert_flag = TRUE;
                }elseif(time()-900 > $time['ct_value']) {   // 15 minutes
                    $send_flag = TRUE;
                    $insert_flag = FALSE;
                }
            }catch(Exception $e){
                $send_flag = FALSE;
                Mage::log('Cannot operate with "cleantalk_timelabels" table.');
            }
            
            if($send_flag){
                Mage::log($err_str);
                if(!$insert_flag)
                    $timelabels->setData('ct_key', 'mail_error');
                $timelabels->setData('ct_value', time());
                $timelabels->save();
                $general_email = Mage::getStoreConfig('trans_email/ident_general/email');

                $mail = Mage::getModel('core/email');
                $mail->setToEmail($general_email);
                $mail->setFromEmail($general_email);
                $mail->setSubject($err_title);
                $mail->setBody($_SERVER['SERVER_NAME'] . "\n\n" . $err_str);
                $mail->setType('text');
                try{
                    $mail->send();
                }catch (Exception $e){
                    Mage::log('Cannot send CleanTalk module error message to ' . $general_email);
                }
            }

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
}