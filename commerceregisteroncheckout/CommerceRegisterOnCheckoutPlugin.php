<?php
/**
 * Commerce Register on Checkout plugin for Craft CMS
 *
 * Register customers on checkout with Craft Commerce
 *
 * @author    Jeremy Daalder
 * @copyright Copyright (c) 2016 Jeremy Daalder
 * @link      https://github.com/bossanova808
 * @package   CommerceRegisterOnCheckout
 * @since     0.0.1
 */

namespace Craft;

class CommerceRegisterOnCheckoutPlugin extends BasePlugin
{

    protected static $settings;

    /**
     * Static log functions for this plugin
     *
     * @param mixed $msg
     * @param string $level
     * @param bool $force
     *
     * @return null
     */
    public static function logError($msg){
        CommerceRegisterOnCheckoutPlugin::log($msg, LogLevel::Error, $force = true);
    }
    public static function logWarning($msg){
        CommerceRegisterOnCheckoutPlugin::log($msg, LogLevel::Warning, $force = true);
    }
    // If debugging is set to true in this plugin's settings, then log every message, devMode or not.
    public static function log($msg, $level = LogLevel::Info, $force = false)
    {
        if(self::$settings['debug']) $force=true;

        if (is_string($msg))
        {
            $msg = "\n\n" . $msg . "\n";
        }
        else
        {
            $msg = "\n\n" . print_r($msg, true) . "\n";
        }

        parent::log($msg, $level, $force);
    }

    /**
     * @return mixed
     */
    public function getName()
    {
         return Craft::t('Commerce Register on Checkout');
    }

    /**
     * @return mixed
     */
    public function getDescription()
    {
        return Craft::t("Commerce Register on Checkout lets you offer user registration during checkout with Craft Commerce.");
    }

    /**
     * @return string
     */
    public function getDocumentationUrl()
    {
        return 'https://github.com/bossanova808/commerceregisteroncheckout/blob/master/README.md';
    }

    /**
     * @return string
     */
    public function getReleaseFeedUrl()
    {
        return 'https://raw.githubusercontent.com/bossanova808/commerceregisteroncheckout/master/releases.json';
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return '0.0.2';
    }

    /**
     * @return string
     */
    public function getSchemaVersion()
    {
        return '0.0.1';
    }

    /**
     * @return string
     */
    public function getDeveloper()
    {
        return 'Jeremy Daalder';
    }

    /**
     * @return string
     */
    public function getDeveloperUrl()
    {
        return 'https://github.com/bossanova808';
    }

    public function getSettingsHtml()
    {

        $settings = self::$settings;

        $variables = array(
            'name'     => $this->getName(true),
            'version'  => $this->getVersion(),
            'settings' => $settings,
            'description' => $this->getDescription(),
        );

        return craft()->templates->render('commerceregisteroncheckout/_settings', $variables);

   }

    public function defineSettings()
    {
        return array(
            'debug' => AttributeType::Bool,
        );
    }

    /**
     * @return bool
     */
    public function hasCpSection()
    {
        return false;
    }

    /**
     */
    public function onBeforeInstall()
    {
    }

    /**
     */
    public function onAfterInstall()
    {
    }

    /**
     */
    public function onBeforeUninstall()
    {
    }

    /**
     */
    public function onAfterUninstall()
    {
    }

    /* 
     * Clean up the registration records in the DB - for the current order, and for any incomplete carts older than the purge duration
    */
    private function cleanUp($order){

        // Delete the DB record for this order
        craft()->db->createCommand()->setText("delete from craft_commerceregisteroncheckout where orderNumber='" . $order->number . "'")->execute();

        // Also take the chance to clean out any old order records that are associated with incomplete carts older than the purge duration
        // Code from getCartsToPurge in Commerce_CartService.php

        $configInterval = craft()->config->get('purgeInactiveCartsDuration', 'commerce');
        $edge = new DateTime();
        $interval = new DateInterval($configInterval);
        $interval->invert = 1;
        $edge->add($interval);
        
        // Added this...
        $mysqlEdge = $edge->format('Y-m-d H:i:s');
        craft()->db->createCommand()->setText("delete from craft_commerceregisteroncheckout where dateUpdated<='" . $mysqlEdge . "'")->execute();

    }


    /**
     * @return mixed
     */
    public function init(){

        // Listen to onOrderComplete (not onBefore...) as we definitely don't want to make submitting orders have more potential issues...
        // We check our DB for a registration record, if there is one, we complete registration & for security delete the record
        craft()->on('commerce_orders.onOrderComplete', function($event){

            $order = $event->params['order'];

            $result = craft()->db->createCommand()->setText("select * from craft_commerceregisteroncheckout where orderNumber='" . $order->number ."'")->queryAll();

            // Short circuit if we don't have registration details for this order
            if (!$result){
                CommerceRegisterOnCheckoutPlugin::log("Register on checkout record not found for order: " . $order->number);
                return true;
            }
                
            CommerceRegisterOnCheckoutPlugin::log("Register on checkout record FOUND for order: " . $order->number);

            // Clean up the DB so we're not keeping evem encrypted passwords around for nay longer than is necessary
            $this->cleanup($order);

            // Retrieve and decrypt the stored password, short circuit if we can't get it...           
            try {
                $encryptedPassword = $result[0]['EPW'];
                $password = craft()->security->decrypt(base64_decode($encryptedPassword));
            }
            catch (Exception $e) {
                CommerceRegisterOnCheckoutPlugin::logError("Couldn't retrieve registration password for order: " . $order->number);
                CommerceRegisterOnCheckoutPlugin::logError($e);
                return false;                   
            }
            
            $firstName = "";
            $lastName = "";     

            //Is there a billing address?  If so by default use that
            $address = $order->getBillingAddress();
            if($address){
                $firstName = $address->firstName;
                $lastName = $address->lastName;
            }

            //Overrule with POST data if that's supplied instead (this won't work with offiste gateways like PayPal though)
            if(craft()->request->getParam('firstName')){
                $firstName = craft()->request->getParam('firstName');
            }
            if(craft()->request->getParam('lastName')){
                $lastName = craft()->request->getParam('lastName');
            }                


            //@TODO - we offer only username = email support currently - since in Commerce everything is keyed by emails...
            $user = new UserModel();
            $user->username         = $order->email;
            $user->email            = $order->email;
            $user->firstName        = $firstName;
            $user->lastName         = $lastName;
            $user->newPassword      = $password;
 
            $success = craft()->users->saveUser($user);

            if ($success) {
                CommerceRegisterOnCheckoutPlugin::log("Registered new user $address->firstName $address->lastName [$order->email] on checkout");

                // Assign them to the default user group (customers)
                craft()->userGroups->assignUserToDefaultGroup($user);
                // & Log them in
                craft()->userSession->loginByUserId($user->id);
                // & record we've done this so the template variable can be set
                craft()->httpSession->add("registered", true);

                return true;
            }

            //If we haven't returned already, registration failed....
            CommerceRegisterOnCheckoutPlugin::logError("Failed to register new user $address->firstName $address->lastName [$order->email] on checkout");
            CommerceRegisterOnCheckoutPlugin::log($user->getErrors());

            craft()->httpSession->add("registered", false);
            craft()->httpSession->add("account", $user);

            return false;
        }); 

    }

    // @TODO - delete below if a no controller method can't be found...

    // Listen to onOrderSave and if there is field set on the order fields[registerOnCheckout] AND there's a password in POST
    // Then save an encrypted record to the DB for retrieval later onOrderComplete
    // I used onBeforeSaveOrder here as I saw some issues with transactions when using onSaveOrder and I can't see 
    // any reason on to use onBeforeSaveOrder
    
    // craft()->on('commerce_orders.onBeforeSaveOrder', function($event){

    //     $order = $event->params['order'];
        
    //     if($order->registerOnCheckout=="true"){
            
    //         $order = $event->params['order'];

    //         $password = craft()->request->getParam('password');
    //         if($password){

    //             CommerceRegisterOnCheckoutPlugin::log("Saving registration record for order: " . $order->number . " (it's normal to see this more than once)");

    //             // delete any old records (saveOrder gets called quite a bit)
    //             $result = craft()->db->createCommand()->setText("delete from craft_commerceregisteroncheckout where orderNumber='" . $order->number ."'")->execute();
    //             // save the new record 
    //             $encryptedPassword = base64_encode(craft()->security->encrypt($password));
    //             craft()->db->createCommand()->insert("commerceregisteroncheckout",["orderNumber"=>$order->number, "encryptedPassword"=>$encryptedPassword]);
    //         }
    //     }

    // });

}