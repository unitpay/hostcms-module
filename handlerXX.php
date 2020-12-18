<?php

class Shop_Payment_System_HandlerXX extends Shop_Payment_System_Handler
{
    protected $public_key = '';
    protected $secret_key = '';

    protected $currency_id = 1;

    protected $currency_code = "RUB";

    public function __construct(Shop_Payment_System_Model $oShop_Payment_System_Model)
    {
        parent::__construct($oShop_Payment_System_Model);

        $this->currency_id = $oShop_Payment_System_Model->shop_currency_id;

        $currency = Core_Entity::factory('Shop_Currency')->getById($this->currency_id);

        $this->currency_code = $currency->code;
    }

    /**
     * Метод, вызываемый в коде настроек ТДС через Shop_Payment_System_Handler::checkBeforeContent($oShop);
     */
    public function checkPaymentBeforeContent()
    {

        if (isset($_GET['params']))
        {
            // Получаем ID заказа
            $params = Core_Array::getGet('params');
            $order_id = intval($params['account']);

            $oShop_Order = Core_Entity::factory('Shop_Order')->find($order_id);

            if (!is_null($oShop_Order->id))
            {
                // Вызов обработчика платежной системы
                Shop_Payment_System_Handler::factory($oShop_Order->Shop_Payment_System)
                        ->shopOrder($oShop_Order)
                        ->paymentProcessing();
            }
            //для "зеленой лампочки в обработчике"
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
            $this->hardReturnJson($result);
        }

    }
    /*
     * Метод, запускающий выполнение обработчика
     */
    public function execute()
    {
        parent::execute();
        $this->printNotification();
        return $this;
    }
    /*
     * Вычисление суммы товаров заказа
     */
    public function getSumWithCoeff()
    {
        return Shop_Controller::instance()->round(($this->currency_id > 0
            && $this->_shopOrder->shop_currency_id > 0
                    ? Shop_Controller::instance()->getCurrencyCoefficientInShopCurrency(
                            $this->_shopOrder->Shop_Currency,
                            Core_Entity::factory('Shop_Currency', $this->currency_id)
                    )
                    : 0) * $this->_shopOrder->getAmount());
    }
    protected function _processOrder()
    {
        parent::_processOrder();
        // Установка XSL-шаблонов в соответствии с настройками в узле структуры
        $this->setXSLs();
        // Отправка писем клиенту и пользователю
        $this->send();
        return $this;
    }
    /*
     * Обработка ответа платёжного сирвиса
     */
    public function paymentProcessing()
    {
        $this->ProcessResult();
        return TRUE;
    }

    /*
     * Оплачивает заказ
     */
    function ProcessResult()
    {

        $data = $_GET;
        $method = '';
        $params = array();
        if ((isset($data['params'])) && (isset($data['method'])) && (isset($data['params']['signature']))){
            $params = $data['params'];
            $method = $data['method'];
            $signature = $params['signature'];
            if (empty($signature)){
                $status_sign = false;
            }else{
                $status_sign = $this->verifySignature($params, $method);
            }
        }else{
            $status_sign = false;
        }
//    $status_sign = true;
        if ($status_sign){
            switch ($method) {
                case 'check':
                    $result = $this->check( $params );
                    break;
                case 'pay':
                    $result = $this->pay( $params );
                    break;
                case 'error':
                    $result = $this->error( $params );
                    break;
                default:
                    $result = array('error' =>
                        array('message' => 'неверный метод')
                    );
                    break;
            }
        }else{
            $result = array('error' =>
                array('message' => 'неверная сигнатура')
            );
        }
        $this->hardReturnJson($result);

    }
    /*
     * Печатает форму отправки запроса на сайт платёжной сервиса
     */
    public function getNotification()
    {
        if (empty($this->public_key)) {
            throw new Exception('public_key is empty');
        }

        if (empty($this->secret_key)) {
            throw new Exception('secret_key is empty');
        }

        $orderParams = $this->_orderParams;
        $sum = number_format($this->getSumWithCoeff(), 2, '.', '');
        $account = $this->_shopOrder->id;
        $desc = 'Оплата по заказу №' . $this->_shopOrder->id;
        $currency = $this->currency_code;

        if ($currency == 'RUR') {
            $currency = 'RUB';
        }

        $signature = hash('sha256', join('{up}', array(
            $account,
            $currency,
            $desc,
            $sum,
            $this->secret_key
        )));

        $aShop_Order_Items = $this->_shopOrder->Shop_Order_Items->findAll(FALSE);

        // Расчет сумм скидок, чтобы потом вычесть из цены каждого товара
        $discount = $amount = 0;
        foreach ($aShop_Order_Items as $key => $oShop_Order_Item)
        {
            if ($oShop_Order_Item->price < 0)
            {
                $discount -= $oShop_Order_Item->getAmount();
                unset($aShop_Order_Items[$key]);
            }
            elseif ($oShop_Order_Item->shop_item_id)
            {
                $amount += $oShop_Order_Item->getAmount();
            }
        }

        $discount = $amount != 0
                ? abs($discount) / $amount
                : 0;

        $items = array();

        foreach ($aShop_Order_Items as $oShop_Order_Item)
        {
            if($oShop_Order_Item->getAmount() > 0) {
                $items[] = array(
                    'name' => mb_substr($oShop_Order_Item->name, 0, 128),
                    'count' => $oShop_Order_Item->quantity,
                    'nds' => $this->getTaxRates($oShop_Order_Item->rate),
                    'price' => (($oShop_Order_Item->getAmount()/$oShop_Order_Item->quantity) * ($oShop_Order_Item->shop_item_id ? 1 - $discount : 1)),
                    'currency' => $currency,
                    'type' => (strpos($oShop_Order_Item->name, 'Доставка') === false ? 'commodity' : 'service'),
                );
            }
        }

        $cashItems = base64_encode(json_encode($items));

        $form = '<form name="unitpay" action="https://unitpay.ru/pay/' . $this->public_key . '" method="get">';
        $form .= '<input type="hidden" name="sum" value="' . $sum . '" />';
        $form .= '<input type="hidden" name="account" value="' . $account . '" />';
        $form .= '<input type="hidden" name="desc" value="' . $desc . '" />';
        $form .= '<input type="hidden" name="currency" value="' . $currency . '" />';
        $form .= '<input type="hidden" name="signature" value="' . $signature . '" />';
        $form .= '<input type="hidden" name="customerEmail" value="' . $orderParams["email"]. '" />';
        $form .= '<input type="hidden" name="customerPhone" value="' . preg_replace('/\D/', '', $orderParams["phone"]) . '" />';
        $form .= '<input type="hidden" name="cashItems" value="' . $cashItems . '" />';
        $form .= '<input class="button" type="submit" value="Оплатить">';
        $form .= '</form>';

        return $form;
    }

    public function getTaxRates($rate){
        switch (intval($rate)){
            case 10:
                $vat = 'vat10';
                break;
            case 20:
                $vat = 'vat20';
                break;
            case 0:
                $vat = 'vat0';
                break;
            default:
                $vat = 'none';
        }

        return $vat;
    }

    public function getInvoice()
    {
        return $this->getNotification();
    }

    function check( $params )
    {
        // Получаем ID заказа
        $order_id = intval($params['account']);
        $order = Core_Entity::factory('Shop_Order')->find($order_id);

        if (is_null($order->id))
        {
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }else{

            $total = number_format($this->getSumWithCoeff(), 2, '.', '');
            $currency = Core_Entity::factory('Shop_Currency', $this->currency_id)->code;
            if ($currency == 'RUR') {
                $currency = 'RUB';
            }

            if (!isset($params['orderSum']) || ((float) number_format($total, 2, '.', '') != (float) number_format($params['orderSum'], 2, '.', ''))) {
                $result = array('error' =>
                    array('message' => 'не совпадает сумма заказа')
                );
            }elseif (!isset($params['orderCurrency']) || ($currency != $params['orderCurrency'])) {
                $result = array('error' =>
                    array('message' => 'не совпадает валюта заказа')
                );
            }
            else{
                $result = array('result' =>
                    array('message' => 'Запрос успешно обработан')
                );
            }
        }
        return $result;
    }
    function pay( $params )
    {
        // Получаем ID заказа
        $order_id = intval($params['account']);
        $order = Core_Entity::factory('Shop_Order')->find($order_id);

        if (is_null($order->id))
        {
            $result = array('error' =>
                    array('message' => 'заказа не существует')
            );
        }else{
            $total = number_format($this->getSumWithCoeff(), 2, '.', '');
            $currency = Core_Entity::factory('Shop_Currency', $this->currency_id)->code;
            if ($currency == 'RUR') {
                $currency = 'RUB';
            }

            if (!isset($params['orderSum']) || ((float) number_format($total, 2, '.', '') != (float) number_format($params['orderSum'], 2, '.', ''))) {
                $result = array('error' =>
                    array('message' => 'не совпадает сумма заказа')
                );
            }elseif (!isset($params['orderCurrency']) || ($currency != $params['orderCurrency'])) {
                $result = array('error' =>
                        array('message' => 'не совпадает валюта заказа')
                );
            }
            else{
                $order->system_information = "Товар оплачен через платежную систему Unitpay.\n";
                $order->paid();
                $this->setXSLs();
                $this->send();

                ob_start();
                $this->changedOrder('changeStatusPaid');
                ob_get_clean();

                $result = array('result' =>
                    array('message' => 'Запрос успешно обработан')
                );
            }
        }
        return $result;
    }
    function error( $params )
    {
        // Получаем ID заказа
        $order_id = intval($params['account']);
        $order = Core_Entity::factory('Shop_Order')->find($order_id);

        if (is_null($order->id))
        {
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }
        else{
            $order->system_information = 'ошибка платежа Uitpay';
            $order->save();
            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );
        }
        return $result;
    }
    function getSignature($method, array $params, $secretKey)
    {
        ksort($params);
        unset($params['sign']);
        unset($params['signature']);
        array_push($params, $secretKey);
        array_unshift($params, $method);
        return hash('sha256', join('{up}', $params));
    }
    function verifySignature($params, $method)
    {
        $secret = $this->secret_key;
        return $params['signature'] == $this->getSignature($method, $params, $secret);
    }
    function hardReturnJson( $arr )
    {
        header('Content-Type: application/json');
        $result = json_encode($arr);
        die($result);
    }

}