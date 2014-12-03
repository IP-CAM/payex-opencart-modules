<?php
if (!defined('DIR_APPLICATION')) {
    die();
}
require_once DIR_SYSTEM . 'library/Px/Px.php';

class ControllerPaymentFactoring extends Controller
{
    protected $_module_name = 'factoring';

    protected static $_px;

    /**
     * Index Action
     */
    protected function index()
    {

        $this->language->load('payment/factoring');

        $this->data['text_title'] = $this->language->get('text_title');
        $this->data['text_description'] = $this->language->get('text_description');
        $this->data['text_social_security_number'] = $this->language->get('text_social_security_number');
        $this->data['text_select_payment_method'] = $this->language->get('text_select_payment_method');
        $this->data['text_factoring'] = $this->language->get('text_factoring');
        $this->data['text_part_payment'] = $this->language->get('text_part_payment');
        $this->data['button_confirm'] = $this->language->get('button_confirm');
        $this->data['continue'] = $this->url->link('checkout/success');
        $this->data['action'] = $this->url->link('payment/factoring/validate');
        $this->data['type'] = $this->config->get('factoring_type');

        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/factoring.tpl')) {
            $this->template = $this->config->get('config_template') . '/template/payment/factoring.tpl';
        } else {
            $this->template = 'default/template/payment/factoring.tpl';
        }

        $this->render();
    }

    /**
     * Validate Action
     */
    public function validate()
    {
        $this->load->model('checkout/order');
        $this->load->language('payment/factoring');
        $this->load->language('payment/payex_error');

        $order_id = $this->session->data['order_id'];
        $ssn = $this->request->post['social-security-number'];

        $order = $this->model_checkout_order->getOrder($order_id);

        // Call PxVerification.GetConsumerLegalAddress
        $params = array(
            'accountNumber' => '',
            'countryCode' => $order['payment_iso_code_2'], // Supported only "SE"
            'socialSecurityNumber' => $ssn
        );
        $result = $this->getPx()->GetConsumerLegalAddress($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            if (preg_match('/\bInvalid parameter:SocialSecurityNumber\b/i', $result['description'])) {
                $json = array(
                    'status' => 'error',
                    'message' => $this->language->get('error_invalid_ssn')
                );
                $this->response->setOutput(json_encode($json));
                return;
            }

            $json = array(
                'status' => 'error',
                'message' => $result['errorCode'] . ' (' . $result['description'] . ')'
            );
            $this->response->setOutput(json_encode($json));
            return;
        }

        $json = array(
            'status' => 'ok',
            'redirect' => $this->url->link('payment/factoring/confirm'),
        );
        $this->response->setOutput(json_encode($json));
        return;
    }

    /**
     * Confirm Action
     */
    public function confirm()
    {
        $this->language->load('payment/payex_error');
        $this->load->model('checkout/order');
        $this->load->model('module/factoring');

        $order_id = $this->session->data['order_id'];
        if (empty($order_id)) {
            $this->session->data['payex_error'] = $this->language->get('error_invalid_order');
            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }

        $ssn = $this->request->post['social-security-number'];
        if (empty($ssn)) {
            $this->session->data['payex_error'] = $this->language->get('error_invalid_ssn');
            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }

        // Selected Payment Mode
        $view = $this->config->get('factoring_type') ? $this->config->get('factoring_type') : 'FACTORING';
        if ($view === 'SELECT') {
            $view = $this->request->post['factoring-menu'];
        }

        $order = $this->model_checkout_order->getOrder($order_id);

        // Call PxOrder.Initialize8
        $params = array(
            'accountNumber' => '',
            'purchaseOperation' => 'AUTHORIZATION',
            'price' => round($order['total'] * 100),
            'priceArgList' => '',
            'currency' => $order['currency_code'],
            'vat' => 0,
            'orderID' => $order['order_id'],
            'productNumber' => $order['customer_id'],
            'description' => html_entity_decode($order['store_name'], ENT_QUOTES, 'UTF-8'),
            'clientIPAddress' => $order['ip'],
            'clientIdentifier' => '',
            'additionalValues' => '',
            'externalID' => '',
            'returnUrl' => 'http://localhost.no/return',
            'view' => $view,
            'agreementRef' => '',
            'cancelUrl' => 'http://localhost.no/cancel',
            'clientLanguage' => 'en-US'
        );
        $result = $this->getPx()->Initialize8($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            $this->session->data['payex_error'] = $result['errorCode'] . ' (' . $result['description'] . ')';
            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }
        $orderRef = $result['orderRef'];

        // Call PxOrder.PurchaseInvoiceSale / PxOrder.PurchasePartPaymentSale
        $params = array(
            'accountNumber' => '',
            'orderRef' => $orderRef,
            'socialSecurityNumber' => $ssn,
            'legalFirstName' => $order['payment_firstname'],
            'legalLastName' => $order['payment_lastname'],
            'legalStreetAddress' => trim($order['payment_address_1'] . ' ' . $order['payment_address_2']),
            'legalCoAddress' => '',
            'legalPostNumber' => $order['payment_postcode'],
            'legalCity' => $order['payment_city'],
            'legalCountryCode' => $order['payment_iso_code_2'],
            'email' => $order['email'],
            'msisdn' => (substr($order['telephone'], 0, 1) === '+') ? $order['telephone'] : '+' . $order['telephone'],
            'ipAddress' => $order['ip'],
        );

        if ($view === 'FACTORING') {
            $result = $this->getPx()->PurchaseInvoiceSale($params);
        } else {
            $result = $this->getPx()->PurchasePartPaymentSale($params);
        }

        if ($result['code'] !== 'OK' || $result['description'] !== 'OK') {
            $this->session->data['payex_error'] = $result['errorCode'] . ' (' . $result['description'] . ')';
            if (preg_match('/\bInvalid parameter:msisdn\b/i', $result['description'])) {
                $this->session->data['payex_error'] = $this->language->get('error_invalid_msisdn');
            }

            $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }

        // Save Transaction
        $this->model_module_factoring->addTransaction($order_id, $result['transactionNumber'], $result['transactionStatus'], $result, isset($result['date']) ? strtotime($result['date']) : time());

        $transaction_status = (int)$result['transactionStatus'];
        switch ($transaction_status) {
            case 0:
            case 6:
                $this->model_checkout_order->confirm($this->session->data['order_id'], $this->config->get('factoring_completed_status_id'), '', true);
                $this->redirect($this->url->link('checkout/success', '', 'SSL'));
                break;
            case 3:
                $this->model_checkout_order->confirm($this->session->data['order_id'], $this->config->get('factoring_pending_status_id'), '', true);
                $this->redirect($this->url->link('checkout/success', '', 'SSL'));
                break;
            case 5;
            default:
                $error_message = '';
                if (!empty($message['thirdPartyError'])) {
                    $error_message .= $this->language->get('error_third_party') . ': ' . $message['thirdPartyError'];
                }

                if (!empty($message['transactionErrorCode']) && !empty($message['transactionErrorDescription'])) {
                    $error_message .= $this->language->get('error_transaction') . ': ' . $message['transactionErrorCode'] . ' (' . $message['transactionErrorDescription'] . ')';
                }

                if (empty($error_message)) {
                    $error_message = $this->language->get('error_unknown');
                }

                $this->session->data['payex_error'] = $error_message;
                $this->redirect($this->url->link('payment/' . $this->_module_name . '/error', '', 'SSL'));
        }
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
            $account_number = $this->config->get('factoring_account_number');
            $encryption_key = $this->config->get('factoring_encryption_key');
            $mode = $this->config->get('factoring_mode');
            self::$_px = new Px();
            self::$_px->setEnvironment($account_number, $encryption_key, ($mode !== 'LIVE'));
        }

        return self::$_px;
    }

    /**
     * Generate Invoice Print XML
     * @param $products
     * @param $shipping_method
     * @return mixed
     */
    protected function getInvoiceExtraPrintBlocksXML($products, $shipping_method)
    {
        $dom = new DOMDocument('1.0', 'utf-8');
        $OnlineInvoice = $dom->createElement('OnlineInvoice');
        $dom->appendChild($OnlineInvoice);
        $OnlineInvoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $OnlineInvoice->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsd', 'http://www.w3.org/2001/XMLSchema');

        $OrderLines = $dom->createElement('OrderLines');
        $OnlineInvoice->appendChild($OrderLines);

        foreach ($products as $key => $product) {
            $qty = $product['quantity'];
            $price = $product['price'] * $qty;
            $priceWithTax = $this->tax->calculate($price, $product['tax_class_id'], 1);
            $taxPrice = $priceWithTax - $price;
            $taxPercent = ($taxPrice > 0) ? round(100 / (($priceWithTax - $taxPrice) / $taxPrice)) : 0;

            $OrderLine = $dom->createElement('OrderLine');
            $OrderLine->appendChild($dom->createElement('Product', $product['name']));
            $OrderLine->appendChild($dom->createElement('Qty', $qty));
            $OrderLine->appendChild($dom->createElement('UnitPrice', $product['price']));
            $OrderLine->appendChild($dom->createElement('VatRate', $taxPercent));
            $OrderLine->appendChild($dom->createElement('VatAmount', $taxPrice));
            $OrderLine->appendChild($dom->createElement('Amount', $priceWithTax));
            $OrderLines->appendChild($OrderLine);
        }

        // Add Shipping Line
        if (isset($shipping_method['cost'])) {
            $shipping = $shipping_method['cost'];
            $shippingWithTax = $this->tax->calculate($shipping, $shipping_method['tax_class_id'], 1);
            $shippingTax = $shippingWithTax - $shipping;
            $shippingTaxPercent = $shipping != 0 ? (int)((100 * ($shippingTax) / $shipping)) : 0;

            $OrderLine = $dom->createElement('OrderLine');
            $OrderLine->appendChild($dom->createElement('Product', $shipping_method['title']));
            $OrderLine->appendChild($dom->createElement('Qty', 1));
            $OrderLine->appendChild($dom->createElement('UnitPrice', $shipping));
            $OrderLine->appendChild($dom->createElement('VatRate', $shippingTaxPercent));
            $OrderLine->appendChild($dom->createElement('VatAmount', $shippingTax));
            $OrderLine->appendChild($dom->createElement('Amount', $shipping + $shippingTax));
            $OrderLines->appendChild($OrderLine);
        }

        // Add Factoring fee
        if ($this->config->get('factoring_fee_fee') > 0) {
            $fee = (float)$this->config->get('factoring_fee_fee');
            $fee_tax_class_id = (int)$this->config->get('factoring_fee_tax_class_id');
            $feeWithTax = $this->tax->calculate($fee, $fee_tax_class_id, 1);
            $feeTax = $feeWithTax - $fee;
            $feeTaxPercent = $fee != 0 ? (int)((100 * ($feeTax) / $fee)) : 0;

            $OrderLine = $dom->createElement('OrderLine');
            $OrderLine->appendChild($dom->createElement('Product', $this->language->get('text_factoring_fee')));
            $OrderLine->appendChild($dom->createElement('Qty', 1));
            $OrderLine->appendChild($dom->createElement('UnitPrice', $fee));
            $OrderLine->appendChild($dom->createElement('VatRate', $feeTaxPercent));
            $OrderLine->appendChild($dom->createElement('VatAmount', $feeTax));
            $OrderLine->appendChild($dom->createElement('Amount', $fee + $feeTax));
            $OrderLines->appendChild($OrderLine);
        }

        return str_replace("\n", '', $dom->saveXML());
    }
}