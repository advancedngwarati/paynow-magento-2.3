<?php

namespace Paynow\Payment\Model\Payment;


class PaynowConfig implements \Magento\Checkout\Model\ConfigProviderInterface
{

    protected $methodCode = \Paynow\Payment\Model\Payment\Paynow::CODE;

    protected $escaper;

    protected $customerSession;

    public function __construct(
        \Magento\Payment\Helper\Data $paymentHelper,
        \Magento\Framework\Escaper $escaper,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Customer\Model\Session $customerSession
    ) {
        $this->escaper = $escaper;
        $this->cart = $cart;
        $this->method = $paymentHelper->getMethodInstance($this->methodCode);
        $this->customerSession = $customerSession;
    }

    public function getConfig()
    {
        return $this->method->isAvailable() ? [
            'payment' => [
                $this->methodCode => [
                    'paybillValidate' => 'paynow/index/index'
                ],
            ],
        ] : [];
    }
}