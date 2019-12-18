<?php


namespace Paynow\Payment\Model\Payment;

class Paynow extends \Magento\Payment\Model\Method\AbstractMethod
{

    const CODE = 'paynow';

    protected $_code = self::CODE;
    protected $_isOffline = true;

    public function isAvailable(
        \Magento\Quote\Api\Data\CartInterface $quote = null
    ) {
        return parent::isAvailable($quote);
    }
}
