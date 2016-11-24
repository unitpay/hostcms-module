<?php

class Shop_Payment_System_HandlerXX extends Shop_Payment_System_Handler
{
    protected $public_key = '';
    protected $secret_key = '';

    protected $currency_name = 'RUB';

    // id валюты, в которой будет производиться рассчет суммы
    protected $currency_id = 1; // 1 - рубли (RUR), 2 - евро (EUR), 3 - доллары (USD), 4 - рубли (RUB)

    public function __construct(Shop_Payment_System_Model $oShop_Payment_System_Model)
    {
        parent::__construct($oShop_Payment_System_Model);
        $currency = Core_Entity::factory('Shop_Currency')->getByCode($this->currency_name);
        !is_null($currency) && $this->currency_id = $currency->id;
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

        $sum = $this->getSumWithCoeff();
        $account = $this->_shopOrder->id;
        $desc = 'Оплата по заказу №' . $this->_shopOrder->id;
        $currency = $this->currency_name;

        if ($currency == 'RUR') {
            $currency = 'RUB';
        }

        $form = '<form name="unitpay" action="https://unitpay.ru/pay/' . $this->public_key . '" method="get">';
        $form .= '<input type="hidden" name="sum" value="' . $sum . '" />';
        $form .= '<input type="hidden" name="account" value="' . $account . '" />';
        $form .= '<input type="hidden" name="desc" value="' . $desc . '" />';
        $form .= '<input type="hidden" name="currency" value="' . $currency . '" />';
        $form .= '<input class="button" type="submit" value="Оплатить">';
        $form .= '</form>';

        return $form;
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

            if (!isset($params['orderSum']) || ((float)$total != (float)$params['orderSum'])) {
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

            if (!isset($params['orderSum']) || ((float)$total != (float)$params['orderSum'])) {
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