<?php
if (!defined('DIR_APPLICATION')) {
    die();
}
require_once DIR_SYSTEM . 'library/Px/Px.php';

class ControllerPaymentBankdebit extends Controller
{
    protected $_module_name = 'bankdebit';

    protected static $_px;

    /**
     * Index Action
     */
    protected function index()
    {
        $this->language->load('payment/bankdebit');

        $this->data['text_title'] = $this->language->get('text_title');
        $this->data['text_description'] = $this->language->get('text_description');
        $this->data['text_select_bank'] = $this->language->get('text_select_bank');
        $this->data['button_confirm'] = $this->language->get('button_confirm');
        $this->data['continue'] = $this->url->link('checkout/success');
        $this->data['action'] = $this->url->link('payment/' . $this->_module_name . '/confirm');
        $this->data['available_banks'] = array(
            'NB' => 'Nordea Bank',
            'FSPA' => 'Swedbank',
            'SEB' => 'Svenska Enskilda Bank',
            'SHB' => 'Handelsbanken',
            'NB:DK' => 'Nordea Bank DK',
            'DDB' => 'Den Danske Bank',
            'BAX' => 'BankAxess',
            'SAMPO' => 'Sampo',
            'AKTIA' => 'Aktia, Säästöpankki',
            'OP' => 'Osuuspanki, Pohjola, Oko',
            'NB:FI' => 'Nordea Bank Finland',
            'SHB:FI' => 'SHB:FI',
            'SPANKKI' => 'SPANKKI',
            'TAPIOLA' => 'TAPIOLA',
            'AALAND' => 'Ålandsbanken'
        );
        $this->data['bankdebit_banks'] = $this->config->get('bankdebit_banks');

        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/bankdebit.tpl')) {
            $this->template = $this->config->get('config_template') . '/template/payment/bankdebit.tpl';
        } else {
            $this->template = 'default/template/payment/bankdebit.tpl';
        }

        $this->render();
    }

    /**
     * Confirm Action
     */
    public function confirm()
    {
        $this->load->language('payment/payex_error');
        $this->load->model('checkout/order');
        $this->load->model('module/bankdebit');

        $order_id = $this->session->data['order_id'];
        if (empty($order_id)) {
            $this->session->data['payex_error'] = $this->language->get('error_invalid_order');
            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }

        $bank_id = $this->request->post['bank_id'];
        if (empty($bank_id)) {
            $this->session->data['payex_error'] = $this->language->get('error_invalid_bank');
            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }

        $order = $this->model_checkout_order->getOrder($order_id);

        // Call PxOrder.Initialize8
        $params = array(
            'accountNumber' => '',
            'purchaseOperation' => 'SALE',
            'price' => 0,
            'priceArgList' => $bank_id . '=' . round($order['total'] * 100),
            'currency' => $order['currency_code'],
            'vat' => 0,
            'orderID' => $order['order_id'],
            'productNumber' => $order['customer_id'],
            'description' => html_entity_decode($order['store_name'], ENT_QUOTES, 'UTF-8'),
            'clientIPAddress' => $order['ip'],
            'clientIdentifier' => 'USERAGENT=' . $order['user_agent'],
            'additionalValues' => $this->config->get('bankdebit_responsive') ? 'USECSS=RESPONSIVEDESIGN' : '',
            'externalID' => '',
            'returnUrl' => $this->url->link('payment/' . $this->_module_name . '/success', '', 'SSL'),
            'view' => 'DIRECTDEBIT',
            'agreementRef' => '',
            'cancelUrl' => $this->url->link('payment/' . $this->_module_name . '/cancel', '', 'SSL'),
            'clientLanguage' => $this->getLocale($this->language->get('code'))
        );
        $result = $this->getPx()->Initialize8($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            $this->session->data['payex_error'] = $result['errorCode'] . ' (' . $result['description'] . ')';
            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }
        $orderRef = $result['orderRef'];

        // add Order Lines
        $i = 1;
        foreach ($this->cart->getProducts() as $product) {
            $qty = $product['quantity'];
            $price = $product['price'] * $qty;
            $priceWithTax = $this->tax->calculate($price, $product['tax_class_id'], 1);
            $taxPrice = $priceWithTax - $price;
            $taxPercent = ($taxPrice > 0) ? round(100 / (($priceWithTax - $taxPrice) / $taxPrice)) : 0;

            // Call PxOrder.AddSingleOrderLine2
            $params = array(
                'accountNumber' => '',
                'orderRef' => $orderRef,
                'itemNumber' => $i,
                'itemDescription1' => $product['name'],
                'itemDescription2' => '',
                'itemDescription3' => '',
                'itemDescription4' => '',
                'itemDescription5' => '',
                'quantity' => $qty,
                'amount' => (int)(100 * $priceWithTax), //must include tax
                'vatPrice' => (int)(100 * round($taxPrice, 2)),
                'vatPercent' => (int)(100 * $taxPercent)
            );
            $result = $this->getPx()->AddSingleOrderLine2($params);
            if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
                $this->session->data['payex_error'] = $result['errorCode'] . ' (' . $result['description'] . ')';
                $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
            }

            $i++;
        }

        // Add Shipping Line
        $shipping_method = $this->session->data['shipping_method'];
        if (isset($shipping_method['cost']) && $shipping_method['cost'] > 0) {
            $shipping = $shipping_method['cost'];
            $shippingWithTax = $this->tax->calculate($shipping, $shipping_method['tax_class_id'], 1);
            $shippingTax = $shippingWithTax - $shipping;
            $shippingTaxPercent = $shipping != 0 ? (int)((100 * ($shippingTax) / $shipping)) : 0;

            // Call PxOrder.AddSingleOrderLine2
            $params = array(
                'accountNumber' => '',
                'orderRef' => $orderRef,
                'itemNumber' => $i,
                'itemDescription1' => $shipping_method['title'],
                'itemDescription2' => '',
                'itemDescription3' => '',
                'itemDescription4' => '',
                'itemDescription5' => '',
                'quantity' => 1,
                'amount' => (int)(100 * $shippingWithTax), //must include tax
                'vatPrice' => (int)(100 * round($shippingTax, 2)),
                'vatPercent' => (int)(100 * $shippingTaxPercent)
            );
            $result = $this->getPx()->AddSingleOrderLine2($params);
            if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
                $this->session->data['payex_error'] = $result['errorCode'] . ' (' . $result['description'] . ')';
                $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
            }

            $i++;
        }

        // Add Order Address
        // Call PxOrder.AddOrderAddress2
        $params = array(
            'accountNumber' => '',
            'orderRef' => $orderRef,
            'billingFirstName' => $order['payment_firstname'],
            'billingLastName' => $order['payment_lastname'],
            'billingAddress1' => $order['payment_address_1'],
            'billingAddress2' => $order['payment_address_2'],
            'billingAddress3' => '',
            'billingPostNumber' => $order['payment_postcode'],
            'billingCity' => $order['payment_city'],
            'billingState' => $order['payment_zone'],
            'billingCountry' => $order['payment_country'],
            'billingCountryCode' => $order['payment_iso_code_2'],
            'billingEmail' => $order['email'],
            'billingPhone' => $order['telephone'],
            'billingGsm' => '',
        );

        $shipping_params = array(
            'deliveryFirstName' => '',
            'deliveryLastName' => '',
            'deliveryAddress1' => '',
            'deliveryAddress2' => '',
            'deliveryAddress3' => '',
            'deliveryPostNumber' => '',
            'deliveryCity' => '',
            'deliveryState' => '',
            'deliveryCountry' => '',
            'deliveryCountryCode' => '',
            'deliveryEmail' => '',
            'deliveryPhone' => '',
            'deliveryGsm' => '',
        );

        if (isset($shipping_method['cost']) && $shipping_method['cost'] > 0) {
            $shipping_params = array(
                'deliveryFirstName' => $order['shipping_firstname'],
                'deliveryLastName' => $order['shipping_lastname'],
                'deliveryAddress1' => $order['shipping_address_1'],
                'deliveryAddress2' => $order['shipping_address_2'],
                'deliveryAddress3' => '',
                'deliveryPostNumber' => $order['shipping_postcode'],
                'deliveryCity' => $order['shipping_city'],
                'deliveryState' => $order['shipping_zone'],
                'deliveryCountry' => $order['shipping_country'],
                'deliveryCountryCode' => $order['shipping_iso_code_2'],
                'deliveryEmail' => $order['email'],
                'deliveryPhone' => $order['telephone'],
                'deliveryGsm' => '',
            );
        }

        $params += $shipping_params;

        $result = $this->getPx()->AddOrderAddress2($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            // @todo Error $result['errorCode'] . ' (' . $result['description'] . ')'
            exit('Error: ' . $result['errorCode'] . ' (' . $result['description'] . ')');
        }

        // Call PxOrder.PrepareSaleDD2
        $params = array(
            'accountNumber' => '',
            'orderRef' => $orderRef,
            'userType' => 0, // Anonymous purchase
            'userRef' => '',
            'bankName' => $bank_id
        );
        $result = $this->getPx()->PrepareSaleDD2($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            exit('Error: ' . $result['errorCode'] . ' (' . $result['description'] . ')');
        }

        $this->redirect($result['redirectUrl']);
    }

    /**
     * Success Action
     */
    public function success()
    {
        $this->load->language('payment/payex_error');
        $this->load->model('checkout/order');
        $this->load->model('module/bankdebit');

        $order_id = $this->session->data['order_id'];
        if (empty($order_id)) {
            $this->session->data['payex_error'] = $this->language->get('error_invalid_order');
            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }

        $orderRef = $this->request->get['orderRef'];
        if (empty($orderRef)) {
            $this->session->data['payex_error'] = $this->language->get('error_invalid_order_reference');
            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }

        // Call PxOrder.Complete
        $params = array(
            'accountNumber' => '',
            'orderRef' => $orderRef
        );
        $result = $this->getPx()->Complete($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            $this->session->data['payex_error'] = $result['errorCode'] . ' (' . $result['description'] . ')';
            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }

        if (!isset($result['transactionNumber'])) {
            $result['transactionNumber'] = '';
        }

        // Get Transaction status
        $transaction_status = (int)$result['transactionStatus'];

        // Save Transaction
        $this->model_module_bankdebit->addTransaction($order_id, $result['transactionNumber'], $transaction_status, $result, isset($result['date']) ? strtotime($result['date']) : time());

        /* Transaction statuses:
        0=Sale, 1=Initialize, 2=Credit, 3=Authorize, 4=Cancel, 5=Failure, 6=Capture */
        switch ($transaction_status) {
            case 0:
            case 6:
                $this->model_checkout_order->confirm($this->session->data['order_id'], $this->config->get('payex_completed_status_id'), '', true);
                $this->redirect($this->url->link('checkout/success', '', 'SSL'));
                break;
            case 1:
            case 3:
                $this->model_checkout_order->confirm($this->session->data['order_id'], $this->config->get('payex_pending_status_id'), '', true);
                $this->redirect($this->url->link('checkout/success', '', 'SSL'));
                break;
            case 4:
                // Cancel
                $this->model_checkout_order->confirm($this->session->data['order_id'], $this->config->get('payex_canceled_status_id'), '', true);
                $this->redirect($this->url->link('checkout/cart', '', 'SSL'));
                break;
            case 5:
            default:
                // Error
                $this->model_checkout_order->confirm($this->session->data['order_id'], $this->config->get('payex_failed_status_id'), '', true);
                $this->session->data['payex_error'] = $result['errorCode'] . ' (' . $result['description'] . ')';
                $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }
    }

    /**
     * Cancel Action
     */
    public function cancel()
    {
        $this->redirect($this->url->link('checkout/cart', '', 'SSL'));
    }

    /**
     * Error Action
     */
    public function error()
    {
        $this->load->language('payment/payex_error');

        $this->data['heading_title'] = $this->language->get('heading_title');
        if (!empty($this->session->data['payex_error'])) {
            $this->data['description'] = $this->session->data['payex_error'];
        } else {
            $this->data['description'] = $this->language->get('text_error');
        }
        $this->data['link_text'] = $this->language->get('link_text');
        $this->data['link'] = $this->url->link('checkout/checkout', '', 'SSL');

        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/payex_error.tpl')) {
            $this->template = $this->config->get('config_template') . '/template/payment/payex_error.tpl';
        } else {
            $this->template = 'default/template/payment/payex_error.tpl';
        }

        $this->children = array(
            'common/column_left',
            'common/column_right',
            'common/content_top',
            'common/content_bottom',
            'common/footer',
            'common/header'
        );

        $this->response->setOutput($this->render());
    }

    /**
     * Get PayEx Handler
     * @return Px
     */
    protected function getPx()
    {
        if (is_null(self::$_px)) {
            $account_number = $this->config->get('payex_account_number');
            $encryption_key = $this->config->get('payex_encryption_key');
            $mode = $this->config->get('payex_mode');
            self::$_px = new Px();
            self::$_px->setEnvironment($account_number, $encryption_key, ($mode !== 'LIVE'));
        }

        return self::$_px;
    }

    /**
     * Get Locale for PayEx
     * @param $lang
     * @return string
     */
    protected function getLocale($lang)
    {
        $allowedLangs = array(
            'en' => 'en-US',
            'sv' => 'sv-SE',
            'nb' => 'nb-NO',
            'da' => 'da-DK',
            'es' => 'es-ES',
            'de' => 'de-DE',
            'fi' => 'fi-FI',
            'fr' => 'fr-FR',
            'pl' => 'pl-PL',
            'cs' => 'cs-CZ',
            'hu' => 'hu-HU'
        );

        if (isset($allowedLangs[$lang])) {
            return $allowedLangs[$lang];
        }

        return 'en-US';
    }

    /**
     * Add Message to Log
     * @param $message
     */
    protected function log($message)
    {
        // @todo Debug log
    }
}