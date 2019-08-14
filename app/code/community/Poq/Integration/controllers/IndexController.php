<?php

/**
 * Poq Integration model
 *
 * @author Poq Studio
 */
class Poq_Integration_IndexController extends Mage_Core_Controller_Front_Action {

    /**
     * Info page for checking extension status
     */
    public function indexAction() {

        // Get layout
        $this->loadLayout();

        // Create static block dynmacially
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'Integration Settings', array('template' => 'poqintegration/index.phtml'));

        // Push block to layout
        $this->getLayout()->getBlock('content')->append($block);

        // Show settings
        //Set CSV content type
        if ($_GET['debug'] == '1') {
            // Get extension settings
            $settings = Mage::helper('poq_integration')->getSettings();
            var_dump($settings);
        }


        // Render page
        $this->renderLayout();
    }

    /**
     * Create csv feed file with respecto extension settings
     */
    public function feedAction() {

        // Get extension settings
        $settings = Mage::helper('poq_integration')->getSettings();

        //Configuration values
        $store_id = $settings->store_id;
        $image_base_url = $settings->image_base_url;
        $image_ignore_strings = $settings->image_ignore_string;
        $tax_rates_enabled = $settings->tax_enabled;

        //Trying to get default values
        if ($store_id == 0) {
            $store_id = Mage::app()->getStore()->getStoreId();
        }

        //Set CSV content type
        if ($_GET['debug'] == '1') {
            header("Content-type: text/plain");
        } else {
            header("Content-type: text/csv");
            header("Content-Disposition: attachment; filename=feed.csv");
        }
        header("Pragma: no-cache");
        header("Expires: 0");
        $productSizeDescriptions = array();
        $parentProductIds = array();

        $conn = Mage::getSingleton('core/resource')->getConnection('core_setup');
        $collection = Mage::getModel('catalog/product')->getCollection()
                ->joinField(
                        'qty', 'cataloginventory/stock_item', 'qty', 'product_id=entity_id', '{{table}}.stock_id=1', 'left'
                )
                ->addAttributeToFilter('status', 1) // enabled
                ->addUrlRewrite()
                ->addPriceData()
                ->addStoreFilter($store_id)
                ->addAttributeToSelect('*');
        Mage::getSingleton('catalog/product_status')->addSaleableFilterToCollection($collection);
        Mage::getSingleton('cataloginventory/stock')->addInStockFilterToCollection($collection);
        $collection->setOrder('sku', 'desc');

        if ($_GET['debug'] == '1') {
            //only get the first hundred rows, useful for faster testing. Also enable profiler, so we can see performance data.
            $collection->setPageSize(200)->setCurPage(1);
            $conn->getProfiler()->setEnabled(true);
            echo $collection->getSelect()->__toString() . "\n\n";
        }

        //Read the dataset into memory
        $collection->load();

        //Preload the media_gallery image data into memory
        //For use with Option #1 below
        $_mediaGalleryAttributeId = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'media_gallery')->getAttributeId();
        $_read = Mage::getSingleton('core/resource')->getConnection('catalog_read');
        $_mediaGalleryData = $_read->fetchAll('SELECT
                main.entity_id, `main`.`value_id`, `main`.`value` AS `file`,
                `value`.`label`, `value`.`position`, `value`.`disabled`, `default_value`.`label` AS `label_default`,
                `default_value`.`position` AS `position_default`,
                `default_value`.`disabled` AS `disabled_default`
            FROM `catalog_product_entity_media_gallery` AS `main`
                LEFT JOIN `catalog_product_entity_media_gallery_value` AS `value`
                    ON main.value_id=value.value_id AND value.store_id=' . $store_id . '
                LEFT JOIN `catalog_product_entity_media_gallery_value` AS `default_value`
                    ON main.value_id=default_value.value_id AND default_value.store_id=0
            WHERE (
                main.attribute_id = ' . $_read->quote($_mediaGalleryAttributeId) . ')
                AND (main.entity_id IN (' . $_read->quote($collection->getAllIds()) . '))
            ORDER BY IF(value.position IS NULL, default_value.position, value.position) ASC
        ');
        $_mediaGalleryByProductId = array();
        foreach ($_mediaGalleryData as $_galleryImage) {
            $k = $_galleryImage['entity_id'];
            unset($_galleryImage['entity_id']);
            if (!isset($_mediaGalleryByProductId[$k])) {
                $_mediaGalleryByProductId[$k] = array();
            }
            $_mediaGalleryByProductId[$k][] = $_galleryImage;
        }
        unset($_mediaGalleryData);
        foreach ($collection as &$_product) {
            $_productId = $_product->getData('entity_id');
            if (isset($_mediaGalleryByProductId[$_productId])) {
                $_product->setData('media_gallery', array('images' => $_mediaGalleryByProductId[$_productId]));
            }
        }
        unset($_mediaGalleryByProductId);
        // End of media_gallery queries for Option #1
        //Go through configurable products, and preload data into $parentProductIds and $productSizeDescriptions
        foreach ($collection as $product) {
            if ($product->getTypeId() == "configurable") {

                //Get list of related sub-products
                $productids = $product->getTypeInstance()->getUsedProductIds();
                foreach ($productids as $productid) {
                    $parentProductIds[$productid] = $product->getId(); //Add to array of related products, so we can look up when we loop through the main products later.
                }

                //Look up the relationship's attribute, so we can describe the relationship (such as the size)
                $code = "";
                $confAttributes = $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product); //var_dump($confAttributes);
                if (sizeof($confAttributes) > 0) {
                    $code = $confAttributes[0]["attribute_code"]; //Read the attribute code of the configured product, so we can look up the right name
                }
                $conf = Mage::getModel('catalog/product_type_configurable')->setProduct($product);
                $col = $conf->getUsedProductCollection()->addAttributeToSelect($code)->addFilterByRequiredOptions();
                foreach ($col as $simple_product) {
                    if ($code != "") { //if we know which code to look up, find the attribute value and put it on the list.
                        $productSizeDescriptions[$simple_product->getId()] = $simple_product->getAttributeText($code);
                    }
                }
            }
        }

        //Add one line of headers above the actual content.
        echo "\"id\"";
        echo ",\"name\"";
        echo ",\"description\"";
        echo ",\"price\"";
        echo ",\"specialprice\"";
        echo ",\"parentproductid\"";
        echo ",\"sku\"";
        echo ",\"isinstock\"";
        echo ",\"quantity\"";
        echo ",\"size\"";
        echo ",\"productURL\"";
        echo ",\"pictureurls\"";
        echo ",\"categoryid\"";
        echo ",\"categoryname\"";
        echo "\n";

        // Get tax rates if enabled
        $tax_classes;

        if ($tax_rates_enabled) {

            // Get all tax classes with their values
            $tax_classes = Mage::helper('tax')->getAllRatesByProductClass();
        }

        //Loop through the products, get the values and write them in CSV format
        foreach ($collection as $product) {

            $description = strip_tags($product->getDescription()); //Remove HTML tags from the description
            $description = addslashes($description); //Escape quotes etc from the text
            $description = preg_replace('#\s{2,}#', '\\n', $description); //Remove line breaks, "\\n" will be put back in as line breaks when importing.
            //$description = preg_replace('/(?<!,)"(?!,)/', '""', $description); //double up any double quote that is not immediately preceded or followed by a comma
            $description = preg_replace('/\"/', '""', $description); //double up any double quote

            echo "\"" . $product->getId() . "\"";
            echo ",\"" . addslashes($product->getName()) . "\"";
            echo ",\"" . $description . "\"";

            // Original price might have been tax rates applied on live site
            // Fix by macromania
            //echo ",\"" . $product->getPrice() . "\"";  )
            try {

                // Original price without tax
                $product_price = $product->getPrice();
                $special_price = $product->getFinalPrice();

                if ($tax_rates_enabled) {

                    // Get product tax class id
                    $tax_class_id = $_product->getData('tax_class_id');

                    // $tax_classes is returned as string. So parsing is needed to get the value
                    // an example $tax_classes could be like {"value_2":20,"value_4":20,"value_5":20,"value_6":0,"value_7":5}
                    $tax_classes = str_replace('{', '', $tax_classes);
                    $tax_classes = str_replace('}', '', $tax_classes);
                    $tax_class_value_array = explode(',', $tax_classes);

                    // Tax value
                    $product_tax_value = 0;

                    foreach ($tax_class_value_array as $tax_class) {

                        $values = explode(':', $tax_class);
                        if ($values[0] == '"value_' . $tax_class_id . '"') {
                            // Get the rate
                            $product_tax_value = $values[1];
                        }
                    }

                    // Apply tax rate
                    if ($product_tax_value > 0) {
                        $product_price += $product_price * $product_tax_value / 100;
                        $special_price += $special_price * $product_tax_value / 100;
                    }

                    echo ",\"" . ceil($product_price) . "\"";
                    echo ",\"" . ceil($special_price) . "\"";
                } else {
                    echo ",\"" . $product_price . "\"";
                    echo ",\"" . $special_price . "\"";
                }



                //echo '<p style="color:red">------<br/>Caught exception: ',  ceil($product_price),'----',$product->getPrice(), '<br/>-----</p>';
            } catch (Exception $eproductprice) {
                echo '<p style="color:red">------<br/>Caught exception: ', $e2->getMessage(), '<br/>-----</p>';
            }



            echo ",\"" . $parentProductIds[$product->getId()] . "\"";
            echo ",\"" . $product->getSku() . "\"";
            echo ",\"" . $product->getStockItem()->getIsInStock() . "\"";
            echo ",\"" . $product->getQty() . "\"";
            echo ",\"" . $productSizeDescriptions[$product->getId()] . "\"";

            if ($product->getVisibility() == 4) { //Only products that are individually visible need URLs, pictures, categories.
                //Get product URL
                echo ",\"" . $product->getProductUrl() . "\"";

                //Get main image
                $imageString = $product->getMediaConfig()->getMediaUrl($product->getData('image'));

                //attempt to audo-detect base image url, if necessary
                if ($image_base_url == '') {
                    $image_base_url = substr($imageString, 0, strrpos($imageString, 'media/catalog/product')) . 'media/catalog/product';
                }

                //Option #1 - Fast way to get all media gallery images from preloaded array
                $_images = $product->getData('media_gallery');
                foreach ($_images as $imagegallery) {
                    foreach ($imagegallery as $add_image) {

                        //Check if image should be filtered.
                        $image_should_be_added = true;
                        if ($image_ignore_strings != '') {
                            //echo "\n image_ignore_strings: " . $image_ignore_strings;
                            $image_ignore_string_array = explode(",", $image_ignore_strings);
                            foreach ($image_ignore_string_array as $image_ignore_string) {
                                //echo "\n image_ignore_string: " . $image_ignore_string;
                                if (strpos($add_image['file'], $image_ignore_string) !== false) {
                                    //echo "\n IGNORING: " . $add_image['file'];
                                    $image_should_be_added = false;
                                }
                            }
                        }
                        if ($image_should_be_added) {
                            $imageString .= ',' . $image_base_url . $add_image['file'];
                        }
                    }
                }

                //Option #2 - Slower, but more reliable way to get all images. Try this is option #1 does not run
//                $_images = Mage::getModel('catalog/product')->load($product->getId())->getMediaGalleryImages();
//                foreach ($_images as $imagegallery) {
//                    $imageString .= ';' . $imagegallery['url'];
//                }
                echo ",\"" . $imageString . "\"";

                //List the categories this product should be listed in
                $cats = $product->getCategoryIds();
                $category_id_list = "";
                $category_name_list = "";
                foreach ($cats as $category_id) {


                    // $_cat = Mage::getModel('catalog/category')->load($category_id); // Throws error
                    // some php configurations might throw "Cannot send headers" error.
                    // To avoid those errors and continue to the process
                    // following try/catch blog has been added by macromania
                    try {
                        $cat = Mage::getModel('catalog/category');
                        $cat->load($category_id);
                        $cat_name = $cat->getName(); //Remove any semicolons from the category name.
                        $cat_name = str_replace(';', '', $cat_name);
                        $category_name_list .= $cat_name . ";";
                        $category_id_list .= $category_id . ";"; //only append the id if we can successfully get the category name. -OH
                    } catch (Exception $e2) {
                        //echo '<p style="color:red">------<br/>Caught exception: ',  $e2->getMessage(), '<br/>-----</p>';
                    }
                }
                $category_id_list = substr($category_id_list, 0, -1);
                $category_name_list = substr($category_name_list, 0, -1);
                echo ",\"" . $category_id_list . "\"";
                echo ",\"" . $category_name_list . "\"";
            }
            echo "\n";
        }
        if ($_GET['debug'] == '1') { //Show performance data
            echo "\n\n\n" . Varien_Profiler::getSqlProfiler($conn);
        }
    }

    public function cartAction() {

        //Configuration values
        $next_url = '/checkout/';      //After the product are added, redirect to this url.
        $require_https = false;             //SSL-encrypted requests cannot be read by anyone else, and will not be stored in history etc.
        $require_signed_request = false;    //If enabled, will require the request to be signed, we can verify that the URL has not been tampered with.
        $signed_request_secret = ''; //Get this configuration value from Poq Studio
        $limit_referer = false;             //Enable this to require the request to come from our trusted source. Also makes it difficult to URL hack and investigate
        $safe_referer_list = array("poqstudio.com", "cloudapp.net"); //add mobile website domains here, leave cloudapp.net and poqstudio.com.
        $tracking_code = "utm_source=mobile&utm_campaign=poq"; //If set, will be added to the checkout page URL.
        //End of configuration values

        header("Pragma: no-cache");
        header("Expires: 0");
        $session = Mage::getSingleton('core/session', array('name' => 'frontend'));

        //Security check #1 - make sure reference id is set and has a decent format
        $reference_id = $_GET['reference_id'];
        if (empty($reference_id)) {
            die("Invalid request. Error 1001.");
        } else if (!strpos($reference_id, '-')) {
            die("Invalid request. Error 1002.");
        }
        $session->setPoqReferenceId($reference_id); //Save reference ID in session, for cross-tracking
        //Security Check #2 - is the request SSL-encrypted?
        if ($require_https) {
            $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443);
            if (!$is_https) {
                die("Invalid request. Error 2001.");
            }
        }

        //Security Check #3 - is the referer from poqstudio.com?
        if ($limit_referer) {
            $http_referer = $_SERVER['HTTP_REFERER'];
            $is_from_safe_referer = false;
            foreach ($safe_referer_list as $referer) {
                if (strpos($http_referer, $referer) > -1) {
                    $is_from_safe_referer = true;
                }
            }
            if (!$is_from_safe_referer) {
                if ($_GET['debug'] == '1') {
                    echo "Invalid Referer:" . $http_referer;
                }
                die("Invalid request. Error 3001.");
            }
        }


        //Security Check #4 - is the request SSL-encrypted?
        if ($require_signed_request) {
            $hv = $reference_id = $_GET['hv'];
            $qs = $_SERVER['QUERY_STRING'];
            $toBeHashed = substr($qs, strrpos($qs, 'reference_id='));
            $toBeHashed = substr($toBeHashed, 0, strrpos($toBeHashed, '&hv='));
            $hash = md5($signed_request_secret . $toBeHashed . $signed_request_secret);
            if ($hash != $hv) {
                if ($_GET['debug'] == '1') {
                    echo "<br>Preparing: " . $toBeHashed;
                    echo "<br>Computed: " . $hash;
                    echo "<br>HV: " . $hv;
                }
                die("Invalid request. Error 4001.");
            }
        }

        //Get access to the cart
        $cart = Mage::helper('checkout/cart')->getCart();

        //Empty out cart first, in case the user clicked back and then tried again.
        $items = $cart->getItems();
        foreach ($items as $item) {
            $cart->removeItem($item->getId());
        }

        //Loop through the querystring, find items and add them to bag.
        for ($i = 1; $i <= 99; $i++) {
            $item_quantity = $_GET['item_quantity_' . $i];
            $item_sku = $_GET['item_sku_' . $i];

            if (!empty($item_sku) && !empty($item_quantity)) {
                $product_id = Mage::getModel('catalog/product')->getIdBySku($item_sku);
                $product = Mage::getModel('catalog/product')->load($product_id);
                if (!$product) {
                    die("Invalid product added. Error 6002.");
                } else if (!$product->isSalable()) {
                    //The product has sold out since the customer added it to cart on mobile, show friendly error message.
                    echo "<html><body style='font-family:Tahoma;color:#444444;text-align:center;padding-top:1em;'><h1>Out of stock</h1>";
                    echo $product->getName() . "<br/>";
                    echo $product->getSku() . "<br/><br/>";
                    echo "Please go back and try again.";
                    echo "</body></html>";
                    die();
                } else {
                    //Seems legit, let's try and add it to the cart. Note that stock level control might stop it here, products often sell out.
                    try {
                        $visibility = $product->getVisibility();
                        if ($visibility != 4) {
                            //If the product is not individually visible, add its parent with the selected SKU as the configured attribute.
                            $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($product_id);
                            $parent_product_id = $parentIds[0];
                            $parent_product = Mage::getModel('catalog/product')->load($parent_product_id);
                            $confAttributes = $parent_product->getTypeInstance(true)->getConfigurableAttributesAsArray($parent_product); //var_dump($confAttributes);
                            //Get the required attributes, and set them on the cart.
                            if (sizeof($confAttributes) > 0) {
                                $configurable_attribute_id = $confAttributes[0]["attribute_id"];
                                $configurable_attribute_code = $confAttributes[0]["attribute_code"];
                                $attribute_id = $product->getData($configurable_attribute_code);

                                //Construct attributes so the correct size will be selected in the cart.
                                $params = array(
                                    'product' => $parent_product_id,
                                    'super_attribute' => array(
                                        $configurable_attribute_id => $attribute_id,
                                    ),
                                    'qty' => $item_quantity,
                                );


                                $cart->addProduct($parent_product, $params);
                                $session->setLastAddedProductId($parent_product->getId());
                            } else {
                                //if the parent product has no configurable attributes, just add the simple product.
                                $cart->addProduct($product, $item_quantity);
                                $session->setLastAddedProductId($product->getId());
                            }
                        } else {
                            //If the simple product is visible directly, simpley add it to the bag.
                            $cart->addProduct($product, $item_quantity);
                            $session->setLastAddedProductId($product->getId());
                        }
                    } catch (Exception $e) {
                        //An error occurred when adding the product to the cart, such as ordering 2 of a product when only 1 is left in stock.
                        echo "<html><body style='font-family:Tahoma;color:#444444;text-align:center;padding-top:1em;'><h1>Out of stock</h1>";
                        echo "Please go back and try again.<br><br><em>";
                        echo $product->getName() . "<br/>";
                        echo $product->getSku() . "</em><br/><br/>";
                        echo $e->getMessage() . "<br/>";
                        echo "</body></html>";
                        die();
                    }
                }
            }
        }

        //Save changes
        $session->setCartWasUpdated(true);
        $cart->save();

        if ($tracking_code != "") {
            $tracking_code .= "&utm_medium=" . $_GET['channel'];
            $tracking_code .= "&reference_id=" . $reference_id; //Add unique id to tracking code, so it's stored in analytics
            if (strpos($next_url, "?") > -1) {
                $next_url .= "&" . $tracking_code;
            } else {
                $next_url .= "?" . $tracking_code;
            }
        }

        //Redirect to checkout page.
        header("Location: " . $next_url);
        die(); //stop execution of further scripts.
    }

}
