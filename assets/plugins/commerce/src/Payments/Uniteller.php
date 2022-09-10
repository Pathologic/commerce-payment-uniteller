<?php

namespace Commerce\Payments;

class Uniteller extends Payment
{
    protected $debug = false;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('uniteller');
        $this->debug = $this->getSetting('debug') == 1;
    }

    public function getMarkup()
    {
        $credentials = ['shop_id', 'shop_password'];
        foreach ($credentials as $item) {
            if (empty($this->getSetting($item))) {
                return '<span class="error" style="color: red;">' . $this->lang['uniteller.error.empty_client_credentials'] . '</span>';
            }
        }

        return '';
    }

    public function getPaymentMarkup()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $currency  = ci()->currency->getCurrency($order['currency']);
        $payment   = $this->createPayment($order['id'], $order['amount']);
        $fields = [
            'Shop_IDP' => $this->getSetting('shop_id'),
            'Order_IDP' => $order['id'] . '-' . $payment['hash'],
            'Subtotal_P' => $payment['amount'],
            'URL_RETURN_OK' => MODX_SITE_URL . 'commerce/uniteller/payment-success',
            'URL_RETURN_NO' => MODX_SITE_URL . 'commerce/uniteller/payment-failed',
            'Currency' => $currency['code'],
            'Email' => $order['email'],
            'Phone' => $order['phone'],
        ];
        $fields['Signature'] = $this->getSignature([
            $fields['Shop_IDP'],
            $fields['Order_IDP'],
            $fields['Subtotal_P'],
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $this->getSetting('shop_password')
        ]);

        if ($this->debug) {
            $this->modx->logEvent(0, 1, '<pre>' . print_r($fields, true) . '</pre>', 'Start Commerce Uniteller Payment');
        }

        $view = new \Commerce\Module\Renderer($this->modx, null, [
            'path' => 'assets/plugins/commerce/templates/front/',
        ]);

        return $view->render('payment_form.tpl', [
            'url'    => 'https://wpay.uniteller.ru/pay/',
            'method' => 'post',
            'data'   => $fields,
        ]);
    }

    public function handleCallback()
    {
        if ($this->debug) {
            $this->modx->logEvent(0, 1, '<pre>' . print_r($_POST, true) . '</pre>',
                'Process Callback Commerce Uniteller Payment');
        }
        if (!empty($_POST['Order_ID']) && !empty($_POST['Signature']) && !empty($_POST['Status']) && in_array($_POST['Status'], ['authorized', 'paid']) && $_POST['Signature'] === $this->getSignature([$_POST['Order_ID'], $_POST['Status'], $this->getSetting('shop_password')], 'callback')) {
            $order = explode('-', $_POST['Order_ID']);
            $paymentHash = $order[1];
            $processor = $this->modx->commerce->loadProcessor();
            try {
                $payment = $processor->loadPaymentByHash($paymentHash);

                if (!$payment) {
                    throw new Exception('Payment "' . htmlentities(print_r($paymentHash, true)) . '" . not found!');
                }

                return $processor->processPayment($payment['id'], $payment['amount']);
            } catch (Exception $e) {
                $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce Uniteller Payment');
                return false;
            }
        }

        return false;
    }

    protected function getSignature(array $data, $type = 'order')
    {
        if ($type == 'order') {
            foreach ($data as &$item) {
                $item = md5($item);
            }
            $separator = '&';
        } else {
            $separator = '';
        }

        return strtoupper(md5(implode($separator, $data)));
    }

    public function getRequestPaymentHash()
    {
        if (!empty($_GET['Order_ID'])) {
            $order = explode('-', $_POST['Order_ID']);
            $paymentHash = $order[1];
            $orderId = $order[0];
            $processor = $this->modx->commerce->loadProcessor();
            $payment = $processor->loadPaymentByHash($paymentHash);
            if ($payment && $payment['order_id'] == $orderId) {
                return $paymentHash;
            }
        }

        return null;
    }


}
