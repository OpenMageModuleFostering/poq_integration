<?php

/**
 * Poq Integration Order Management
 *
 * @author Poq Studio
 */
class Poq_Integration_OrderController extends Mage_Core_Controller_Front_Action
{
    /*
     * Creates an order as completed
     */

    public function createAction()
    {
        try
        {

            // Get extension settings
            $settings = Mage::helper('poq_integration')->getSettings();

            // Get store id
            $storeId = $settings->store_id;
            
            // Check if extension configured with signed request string
            if (empty($settings -> signed_request_string))
            {
                die("A security protocol error occured.\n Please try again later.");
            }

            // Check if the store id is valid
            if (is_null($storeId))
            {
                // Set store id as default store id
                $storeId = Mage::app()->getWebsite()
                        ->getDefaultGroup()
                        ->getDefaultStoreId();
            }
            else
            if (!is_numeric($storeId))
            {
                // Set store id as default store id
                $storeId = Mage::app()->getWebsite()
                        ->getDefaultGroup()
                        ->getDefaultStoreId();
            }

            // Get HTTP-POST data
            $post_data = Mage::app()->getRequest()->getPost();

            // Check if data is sent
            if (count($post_data) == 0)
            {
                Mage::throwException(Mage::helper('core')->__('Please send data via HTTP POST.'));
            }
            
            // Check if security phrase is sent
            if (empty($post_data['integration_key']))
            {
                die("Integration security settings couldn't be received.\nPlease try again later.");
            }
            
            if($post_data['integration_key'] != $settings -> signed_request_string)
            {
                die("Integration security settings couldn't be veried.\nPlease try again later.");
            }

            // Check if customer details is sent
            if (empty($post_data['email']) || empty($post_data['firstname']) || empty($post_data['lastname']) || empty($post_data['phone']))
            {
                Mage::throwException(Mage::helper('core')->__('Please set customer details: e-mail, firstname, lastname, phone'));
            }

            // Get customer details
            $customer = array(
                'email' => $post_data['email'],
                'firstname' => $post_data['firstname'],
                'lastname' => $post_data['lastname'],
                'phone' => $post_data['phone']
            );

            // Check if billing details is sent
            if (empty($post_data['address']) || empty($post_data['city']) || empty($post_data['country']) || empty($post_data['state']) ||
                    empty($post_data['postcode']))
            {
                Mage::throwException(Mage::helper('core')->__('Please set billing details: address, city, country, state, postcode'));
            }

            // Get billing address details
            $billing = array(
                'firstname' => $customer['firstname'],
                'lastname' => $customer['lastname'],
                'phone' => $customer['phone'],
                'street' => $post_data['address'] . ' ' . $post_data['address2'],
                'city' => $post_data['city'],
                'country' => $post_data['country'],
                'region' => $post_data['state'],
                'postcode' => $post_data['postcode']
            );

            // Check if shipping details is sent
            if (empty($post_data['deliveryfirstname']) || empty($post_data['deliverylastname']) || empty($post_data['deliveryaddress']) ||
                    empty($post_data['deliverycity']) || empty($post_data['deliverystate']) || empty($post_data['deliverypostcode']) ||
                    empty($post_data['deliverycountry']))
            {
                Mage::throwException(
                        Mage::helper('core')->__(
                                'Please set shipping details: deliveryfirstname, deliverylastname, deliveryaddress, deliverycity, deliverypostcode, deliverystate, deliverycountry'));
            }

            // Get shipping address details
            $shipping = array(
                'firstname' => $post_data['deliveryfirstname'],
                'lastname' => $post_data['deliverylastname'],
                'street' => $post_data['deliveryaddress'] . ' ' . $post_data['deliveryaddress2'],
                'city' => $post_data['deliverycity'],
                'country' => $post_data['deliverycountry'],
                'region' => $post_data['deliverystate'],
                'postcode' => $post_data['deliverypostcode']
            );

            // Create transaction
            $transaction = Mage::getModel('core/resource_transaction');

            // Reserve the order increment id
            $reservedOrderId = Mage::getSingleton('eav/config')->getEntityType('order')->fetchNewIncrementId($storeId);

            // Check if currenct is set
            if (empty($post_data['currency']))
            {
                Mage::throwException(Mage::helper('core')->__('Please set currency details: currency'));
            }

            // Create order
            $order = Mage::getModel('sales/order')->setIncrementId($reservedOrderId)
                    ->setStoreId($storeId)
                    ->setQuoteId(0)
                    ->setGlobal_currency_code($post_data['currency'])
                    ->setBase_currency_code($post_data['currency'])
                    ->setStore_currency_code($post_data['currency'])
                    ->setOrder_currency_code($post_data['currency']);

            // Set Customer data
            $order->setCustomer_email($customer['email'])
                    ->setCustomerFirstname($customer['firstname'])
                    ->setCustomerLastname($customer['lastname'])
                    ->setCustomer_is_guest(1);

            // Set Billing detaials
            $billingAddress = Mage::getModel('sales/order_address')->setStoreId($storeId)
                    ->setAddressType(Mage_Sales_Model_Quote_Address::TYPE_BILLING)
                    ->setFirstname($billing['firstname'])
                    ->setLastname($billing['lastname'])
                    ->setStreet($billing['street'])
                    ->setCity($billing['city'])
                    ->setCountry($billing['country'])
                    ->setRegion($billing['state'])
                    ->setPostcode($billing['postcode'])
                    ->setTelephone($billing['phone']);
            $order->setBillingAddress($billingAddress);

            // Set Shippong details
            $shippingAddress = Mage::getModel('sales/order_address')->setStoreId($storeId)
                    ->setAddressType(Mage_Sales_Model_Quote_Address::TYPE_SHIPPING)
                    ->setFirstname($shipping['firstname'])
                    ->setLastname($shipping['lastname'])
                    ->setStreet($shipping['street'])
                    ->setCity($shipping['city'])
                    ->setCountry($shipping['country'])
                    ->setRegion($shipping['state'])
                    ->setPostcode($shipping['postcode'])
                    ->setTelephone($shipping['phone']);
            $order->setShippingAddress($shippingAddress);

            // Set order payment type
            $orderPayment = Mage::getModel('sales/order_payment')->setStoreId($storeId)->setMethod('purchaseorder');
            $order->setPayment($orderPayment);

            // Get shipping price
            $shipping_price = $post_data['deliverycost'];

            // Set shipping description
            $order->setShippingDescription($post_data['deliveryoptiontitle']);

            // Subtotal
            $subTotal = 0;

            // Get products
            $items = $post_data['items'];

            // Set order products
            foreach ($items as $item)
            {

                // Get product details by id
                $product = Mage::getModel('catalog/product')->load($item['id']);

                if (!is_null($product->getId()))
                {

                    // Get total price per product by quantity
                    $rowTotal = $product->getPrice() * $item['quantity'];

                    // Set order item
                    $orderItem = Mage::getModel('sales/order_item')->setStoreId($storeId)
                            ->setQuoteItemId(0)
                            ->setQuoteParentItemId(NULL)
                            ->setProductId($item['id'])
                            ->setProductType($product->getTypeId())
                            ->setQtyBackordered(NULL)
                            ->setQtyOrdered($item['quantity'])
                            ->setName($product->getName())
                            ->setSku($product->getSku())
                            ->setPrice($product->getPrice())
                            ->setBasePrice($product->getPrice())
                            ->setOriginalPrice($product->getPrice())
                            ->setRowTotal($rowTotal)
                            ->setBaseRowTotal($rowTotal);

                    // Add order item total to subtotal
                    $subTotal += $rowTotal;

                    // Add order item to order
                    $order->addItem($orderItem);
                }
                else
                {

                    // Product not found
                    Mage::throwException(Mage::helper('core')->__('Cannot add product ' . $item['id'] . ' to order. Please check product id.'));
                }
            }

            // Get voucer details
            $voucher = array(
                'code' => $post_data['vouchercode'],
                'amount' => $post_data['voucheramount']
            );

            // Check voucher
            if ($voucher['amount'] > 0)
            {

                // Apply discount
                $order->setDiscountAmount($voucher['amount']);
                $subTotal -= $voucher['amount'];

                // Add coupon code
                $order->addStatusHistoryComment('Voucher coupon ' . $voucher['code'] . ' applied.', Mage_Sales_Model_Order::STATE_COMPLETE);
            }

            // Add Poq order data
            $order->addStatusHistoryComment('Poq Data:  Payment Transaction ID:' . $post_data['payment_transaction_id'] . ' Ref ID:' . $post_data['reference_id'], Mage_Sales_Model_Order::STATE_COMPLETE);

            // Add Poq channel data
            $order->addStatusHistoryComment('Poq Data:  Channel:' . $post_data['channel'] . ' Platform:' . $post_data['platform'], Mage_Sales_Model_Order::STATE_COMPLETE);

            // Set order total
            $order->setSubtotal($subTotal)
                    ->setBaseSubtotal($subTotal)
                    ->setGrandTotal($subTotal + $shipping_price)
                    ->setBaseGrandTotal($subTotal + $shipping_price)
                    ->setShippingAmount($shipping_price)
                    ->setBaseShippingAmount($shipping_price);

            // Add order to transaction
            $transaction->addObject($order);
            $transaction->addCommitCallback(array(
                $order,
                'place'
            ));
            $transaction->addCommitCallback(array(
                $order,
                'save'
            ));
            $transaction->save();


            if ($order->getTotalPaid() == 0)
            {

                // Generate invoice
                // Check if order invoicable
                if (!$order->canInvoice())
                {
                    Mage::throwException(Mage::helper('core')->__('Cannot create an invoice for this order.'));
                }

                // Prepare invoice
                $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

                // Check if order has products
                if (!$invoice->getTotalQty())
                {
                    Mage::throwException(
                            Mage::helper('core')->__('Cannot create an invoice without products. Check product ids before creating the order.'));
                }

                // Update invoice as captured online
                $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                $invoice->register();

                // Add update history comment
                $order->addStatusHistoryComment('Order paid via native payment with transaction id ' . $post_data['payment_transaction_id'], Mage_Sales_Model_Order::STATE_COMPLETE);

                // Update order status
                $order->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE);

                // Save transcation and update order
                $transactionSave = Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder());
                $transactionSave->save();

                try
                {

                    if($settings->send_order_emails){
                        
                        $order->sendNewOrderEmail();
                    }
                    
                }
                catch (Exception $ex)
                {
                    
                }

                echo "Success";
            }
        }
        catch (Mage_Core_Exception $e)
        {

            // Output error message
            echo $e->getMessage();
        }
    }

    /*
     * Updates order status and payment info
     */

    public function saveAction()
    {
        try
        {

            // Get HTTP-POST data
            $post_data = Mage::app()->getRequest()->getPost();

            if (count($post_data) == 0)
            {
                Mage::throwException(Mage::helper('core')->__('Please send data via HTTP POST.'));
            }

            // Get order id
            $orderId = $post_data['orderId'];

            // Check order id
            if (is_null($orderId))
            {
                Mage::throwException(Mage::helper('core')->__('Order id is required to complete this order.'));
            }

            // Get transaction id
            $transactionId = $post_data['transactionId'];

            // Check transaction id
            if (is_null($transactionId))
            {
                Mage::throwException(Mage::helper('core')->__('Transaction id is required to complete this order.'));
            }

            // Get order
            $order = Mage::getModel('sales/order')->load($orderId);

            if (!is_null($order->getId()))
            {

                if ($order->getTotalPaid() == 0)
                {

                    // Generate invoice
                    // Check if order invoicable
                    if (!$order->canInvoice())
                    {
                        Mage::throwException(Mage::helper('core')->__('Cannot create an invoice for this order.'));
                    }

                    // Prepare invoice
                    $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

                    // Check if order has products
                    if (!$invoice->getTotalQty())
                    {
                        Mage::throwException(
                                Mage::helper('core')->__('Cannot create an invoice without products. Check product ids before creating the order.'));
                    }

                    // Update invoice as captured online
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                    $invoice->register();

                    // Add update history comment
                    $order->addStatusHistoryComment('Order paid via native payment with transaction id ' . $transactionId, Mage_Sales_Model_Order::STATE_COMPLETE);

                    // Update order status
                    $order->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE);

                    // Save transcation and update order
                    $transactionSave = Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder());
                    $transactionSave->save();



                    echo "Success";
                }
                else
                {
                    Mage::throwException(Mage::helper('core')->__('Order has been already updated.'));
                }
            }
            else
            {
                Mage::throwException(Mage::helper('core')->__('Order id ' . $orderId . ' is not valid. Please check order id.'));
            }
        }
        catch (Mage_Core_Exception $e)
        {

            // Output error message
            echo $e->getMessage();
        }
    }

    /*
     * Checks product's stock status
     */

    public function checkAction()
    {
        try
        {
            // Get HTTP-POST data
            $post_data = Mage::app()->getRequest()->getPost();

            // Check if data is sent
            if (count($post_data) == 0)
            {
                die('Please send data via HTTP POST.');
            }

            // Get products
            $items = $post_data['items'];

            // Out of stock product names for error message
            $outOfStockItems = array();


            // Set order products
            foreach ($items as $item)
            {
                if (!empty($item['sku']))
                {
                    // Get product id by sku
                    $productId = Mage::getModel('catalog/product')->getIdBySku($item['sku']);

                    if (!empty($productId))
                    {
                        // Get product details by id
                        $product = Mage::getModel('catalog/product')->load($productId);

                        // Die if the product is not found
                        if (!$product)
                        {
                            die("Products in your shopping cart are out of stock:\n".$item['productTitle']);
                        }

                        // The product has sold out 
                        if (!$product->isSalable())
                        {
                            array_push($outOfStockItems, $product->getName());
                        }
                    }
                    else
                    {
                        die("Products in your shopping cart are out of stock:\n".$item['productTitle']);
                    }
                }
                else
                {
                    die("SKU field is empty for item index :" . array_search($item, $items));
                }
            }



            // Return out of stock items if any
            if (count($outOfStockItems) > 0)
            {
                $errorMessage = "The following products in your cart are out of stock:\n";

                foreach ($outOfStockItems as $outOfStockItem)
                {
                    $errorMessage .= $outOfStockItem . "\n";
                }

                echo $errorMessage;
            }
            else
            {
                echo "Success";
            }
        }
        catch (Exception $e)
        {
            // Output error message
            echo $e->getMessage(), "\n";
        }
    }

}

