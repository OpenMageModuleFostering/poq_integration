<?php

/**
 * Integration Data helper
 *
 * @author Poq Studio
 */
class Poq_Integration_Helper_Data extends Mage_Core_Helper_Data {

    /**
     * Store ID
     * @var int
     */
    public $store_id;

    /**
     * Image Base URL
     * @var string
     */
    public $image_base_url;

    /**
     * List of Images to Ignore
     * @var array
     */
    public $image_ignore_string = array();

    /**
     * Tax Rates
     * @var boolean
     */
    public $tax_rates_enabled;

    /**
     * Checkout URL
     * @var string
     */
    public $checkout_url;

    /**
     * Requires HTTPS
     * @var boolean
     */
    public $requires_https;

    /**
     * Requires Signed Request
     * @var boolean
     */
    public $requires_signed_request;

    /**
     * Signed Request String
     * @var string
     */
    public $signed_request_string;

    /**
     * Limit Referer
     * @var boolean
     */
    public $limit_referer;

    /**
     * Safe Referer List
     * @var array
     */
    public $safe_referer_list = array();

    /**
     * Tracking Code
     * @var string
     */
    public $tracking_code;

    /**
     * Get settings
     */
    public function getSettings() {
        // Get store id
        $this->store_id = Mage::getStoreConfig('integration/integration_feed_group/integration_feed_store_input', Mage::app()->getStore());

        // Get base image url
        $this->image_base_url = Mage::getStoreConfig('integration/integration_feed_group/integration_feed_image_url_input', Mage::app()->getStore());

        // Get ignored image list
        $this->image_ignore_string = Mage::getStoreConfig('integration/integration_feed_group/integration_feed_image_ignore_input', Mage::app()->getStore());
        
        // Parse tax enabled
        $this->tax_rates_enabled = filter_var(Mage::getStoreConfig('integration/integration_feed_group/integration_feed_taxrates_select', Mage::app()->getStore()), FILTER_VALIDATE_BOOLEAN);

        // Get checkout url
        $this->checkout_url = Mage::getStoreConfig('integration/integration_checkout_group/integration_checkout_checkout_input', Mage::app()->getStore());
        
        // Parse require https
        $this->requires_https = filter_var(Mage::getStoreConfig('integration/integration_checkoutsecurity_group/integration_checkout_requirehttps_select', Mage::app()->getStore()), FILTER_VALIDATE_BOOLEAN);
        
        // Parse signed request
        $this->requires_signed_request = filter_var(Mage::getStoreConfig('integration/integration_checkoutsecurity_group/integration_checkout_requiresignedrequest_select', Mage::app()->getStore()), FILTER_VALIDATE_BOOLEAN);
        
        // Get signed request
        $this->signed_request_string = Mage::getStoreConfig('integration/integration_checkoutsecurity_group/integration_checkout_signedrequeststring_input', Mage::app()->getStore());
        
        // Parse limit referer
        $this->limit_referer = filter_var(Mage::getStoreConfig('integration/integration_checkoutsecurity_group/integration_checkout_limitreferrrer_select', Mage::app()->getStore()), FILTER_VALIDATE_BOOLEAN);
        
        // Get safe referer list
        $this->safe_referer_list = Mage::getStoreConfig('integration/integration_checkoutsecurity_group/integration_checkout_safereferrerlist_input', Mage::app()->getStore());
        $this->safe_referer_list = split(',', $this->safe_referer_list);
        
        // Get tracking code
        $this->tracking_code = Mage::getStoreConfig('integration/integration_checkout_group/integration_checkout_trackingcode_input', Mage::app()->getStore());

        // return settings
        return $this;
    }

}
