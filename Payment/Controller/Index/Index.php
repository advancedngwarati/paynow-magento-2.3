<?php

namespace Paynow\Payment\Controller\Index;
 
use Magento\Framework\UrlInterface;

/**
* Main Controller
*/
class Index extends \Magento\Framework\App\Action\Action
{
	protected $_paymentPlugin;
	protected $_scopeConfig;
	protected $_session;
	protected $_order;
	protected $messageManager;
	protected $_redirect;
	protected $_orderId;
	protected $_storeManager;
	protected $_orderManagement;
	protected $_url;
	protected $_resource;
	protected $_orderSender;

	public function __construct(
		\Magento\Sales\Model\Order $order,
		\Magento\Framework\App\Action\Context $context,
        \Paynow\Payment\Model\Payment\Paynow $paymentPlugin,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Checkout\Model\Session $session,
		\Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
		\Magento\Sales\Api\OrderManagementInterface $orderManagement,
		\Magento\Framework\App\ResourceConnection $resource
    ){
        $this->_paymentPlugin   = $paymentPlugin;
        $this->_scopeConfig     = $scopeConfig;
        $this->_session         = $session;
		$this->_order 		    = $order;
		$this->_orderSender		= $orderSender;
        $this->_storeManager    = $storeManager;
		$this->_orderManagement = $orderManagement;
        $this->messageManager   = $context->getMessageManager();
		$this->_url             = $context->getUrl();
		$this->_resource		= $resource;
		parent::__construct($context);
	}
	
	public function execute()
	{
		/** check if isset success from vPayment */
		$success = filter_input(INPUT_GET, 'order_id',FILTER_SANITIZE_STRING);
		$cancel  = filter_input(INPUT_GET, 'cancel',FILTER_SANITIZE_STRING);

		

		if (isset($success) && !empty($success)){
			
			$orderId = rtrim($success, '/');
			$this->responseAction($orderId);

		}else{

			/** @var \Magento\Checkout\Model\Session  $session*/
			$order   = $this->_session->getLastRealOrder();
			$orderId = $order->getId();	
			$lastOrderId = $this->_session->getLastOrderId();
			
			if (!isset($orderId) || !$orderId) {

				$message = 'Invalid order ID, please try again later';
				/** @var  \Magento\Framework\Message\ManagerInterface $messageManager */
				$this->messageManager->addError($message);
				return $this->_redirect('checkout/cart');
			}


			$comment = 'Payment has not been processed yet';

			$this->setCommentToOrder($orderId, $comment);

			
			$this->_orderId  = $orderId;

			$billingDetails = $this->getBillingDetailsByOrderId($orderId);
			$configDetails	= $this->getPaymentConfig();
			
			$message = implode('',array(
				'resulturl' 	 =>$billingDetails["confirmURL"],
				'returnurl' 	 =>$billingDetails["returnURL"],
				'reference'    => $billingDetails["order_id"],
				'amount'		=>$billingDetails['amount'],
				'id'	=>$configDetails["integration_id"],
				'additionalinfo'	=>"Halsteds",
				'status'		=>"Message"
			));

			
			$message .= $configDetails["integration_key"];
			$utf8_message = utf8_encode($message);
			$hash = strtoupper(hash('sha512',$utf8_message));

			$inputEncoded = array(
				'resulturl' 	 =>strtolower(urlencode($billingDetails["confirmURL"])),
				'returnurl' 	 =>strtolower(urlencode($billingDetails["returnURL"])),
				'reference'    => urlencode($billingDetails["order_id"]),
				'amount'		=>urlencode($billingDetails['amount']),
				'id'	=>urlencode($configDetails["integration_id"]),
				'additionalinfo'	=>urlencode("Halsteds"),
				'status'		=>urlencode("Message"),
				'hash'			=>$hash

			);

			$encoded_string = '';
			
			foreach($inputEncoded as $key=>$value) { 
				$encoded_string .= $key.'='.$value.'&'; 
			}

			$encoded_string = rtrim($encoded_string, '&');
			
			$url = $configDetails['resource_url'];
			$response = $this->createCURL($url,$encoded_string);

			if ($response) {
				$parameters = $this->getParams($response);
							
				switch($parameters['status']) {
					case "Ok": { $this->handleSuccessfulInitiation($lastOrderId, $parameters, $billingDetails['amount']); break;}
					case "Error": { $this->handleFailedInitiation($lastOrderId, $parameters, $billingDetails['amount']); break;}
				}
			}
			else {
				
				$this->cancelAction();
			}
		}
	}


	public function setCommentToOrder($orderId, $comment)
	{
		$order = $this->_order->load($orderId);
		$order->addStatusHistoryComment($comment);
		$order->save();
	}

	// The cancel action is triggered when an order is to be cancelled
	public function cancelAction() {
        if ($this->_session->getLastRealOrderId()) {
            $order = $this->_order->loadByIncrementId($this->_session->getLastRealOrderId());
            if($order->getId()) {
				// Flag the order as 'cancelled' and save it
				$order->cancel()->setState(\Magento\Sales\Model\Order::STATE_CANCELED, true, 'Gateway has declined the payment.')->save();
			}
        }
	}



	// The response action is triggered when your gateway sends back a response after processing the customer's payment
	public function responseAction($order_id) {

		// here I should check the transaction status and update database
		
		if (isset($order_id) && !empty($order_id)){
			
			$order_id = $order_id;
		}
		else {
			die("Order ID is not set");
		}
		
		
		// getting the check url
		$q = "SELECT `poll_url` FROM `paynow` WHERE `order_id` = ". $order_id;
		$db = $this->_resource->getConnection();				
		if($r = $db->fetchAll($q)) {
			$statusMsg = false;
			if (count($r)) {
				$statusMsg = $this->createCURL($r[0]['poll_url'], "");
				echo $statusMsg;
			}

			if ($statusMsg) {
				$parameters = $this->getParams($statusMsg);
			}
		}
		
		if (isset($parameters['status'])) {
			switch($parameters['status']) {
				case "Cancelled": { $this->handleCancelledTransaction($order_id, $parameters); break;}
				case "Created": { $this->handleUnpaidTransaction($order_id, $parameters); break;}
				case "Awaiting Delivery": { $this->handleUnpaidTransaction($order_id, $parameters);break;}
				case "Delivered": { $this->handleUnpaidTransaction($order_id, $parameters); break;}
				case "Sent": { $this->handleUnpaidTransaction($order_id, $parameters); break;}
				case "Disputed": { $this->handleFailedTransaction($order_id, $parameters); break;}
				case "Paid": { $this->handlePaidTransaction($order_id, $parameters); break;}
				case "Refunded": { $this->handleFailedTransaction($order_id, $parameters); break;}
				default: { echo "Unknown status!"; }
			}
		}
	}

	private function handleSuccessfulInitiation($order_id, $params, $amount) {
		$params['browserurl'] = urldecode($params['browserurl']);
		$params['pollurl'] = urldecode($params['pollurl']);

		$this->createNewTransaction($order_id, $params, $amount);
		$this->_redirect($params['browserurl']);
	}
	
	private function handleFailedInitiation($order_id, $params, $amount) {
		var_dump($params);
		die("Couldn't initiate transactions!");
	}

	private function handleCancelledTransaction($order_id, $params) {
		$this->updateTransactionStatus($order_id, $params['status']);
		$this->updateTransactionInfo($order_id, $params);
		$order = $this->_order->load($order_id);
		$order->cancel()->setState(\Magento\Sales\Model\Order::STATE_CANCELED, true, 'Customer has canceled transaction.')->save();
		$message = 'Customer has canceled transaction.';
		$this->restoreOrderToCart($message, $order_id);
	}
	
	private function handlePaidTransaction($order_id, $params) {
		$this->updateTransactionStatus($order_id,$params['status']);
		$this->updateTransactionInfo($order_id, $params);
		$order = $this->_order->load($order_id);
		$order->load($order_id);
		$order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING, true, 'Payment received.');
		$order->setStatus("Processing", true);
		$order->save();
		$this->_redirect('checkout/onepage/success', array('_secure'=>true));
	}
	
	private function handleFailedTransaction($order_id, $params) {
		$this->updateTransactionStatus($order_id, $params['status']);
		$this->updateTransactionInfo($order_id, $params);
		$order = $this->_order->load($order_id);

		if($params['status'] == "Disputed"){

			$order->cancel()->setState(\Magento\Sales\Model\Order::TATE_PENDING_PAYMENT, true, 'Desputed.')->save();
			$message = 'Transaction has been disputed by the Customer and funds are being held in suspense until the dispute has been resolved.';
			$this->messageManager->addError($message);
			$this->_redirect('checkout/onepage/failure', array('_secure'=>true));


		}else{

			$order->cancel()->setState(\Magento\Sales\Model\Order::STATE_CANCELED, true, 'Refunded.')->save();
			$message = 'Funds were refunded back to the customer.';
			$this->messageManager->addError($message);
			$this->_redirect('checkout/onepage/failure', array('_secure'=>true));
		}
	}
	
	private function handleUnpaidTransaction($order_id, $params) {

		$this->updateTransactionStatus($order_id, $params['status']);
		$this->updateTransactionInfo($order_id, $params);
		$order = $this->_order->load($order_id);

		if($params['status'] == "Awaiting Delivery"){
			
			$order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, true, "Awaiting Delivery.");
			$order->save();
			$this->messageManager->addError("Customer is waiting for a delivery.");
			$this->_redirect('checkout/onepage/failure', array('_secure'=>true));
		}elseif($params['status'] == "Created"){
			
			$order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, true, "Created.");
			$order->save();
			$this->messageManager->addError("Transaction has been created in Paynow, but has not yet been paid by the customer.");
			$this->_redirect('checkout/onepage/failure', array('_secure'=>true));
		
		}elseif($params['status'] == "Created"){
			
			$order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, true, "Sent.");
			$order->save();
			$this->messageManager->addError("Transaction has been created in Paynow and an up stream system, the customer has been referred to that upstream system but has not yet made payment.");
			$this->_redirect('checkout/onepage/failure', array('_secure'=>true));
		}else{
			$this->_redirect('checkout/onepage/failure', array('_secure'=>true));
		}
	}
		
	// generate Curl and return response
	public function createCURL($url,$encoded_string)
	{	
		

		echo $encoded_string;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSLVERSION,6);
		curl_setopt($ch, CURLOPT_POST,1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded_string);
		
		$response = curl_exec($ch);

		$error = curl_error($ch);
		if($error){
			return $error;
			
		}else{
			return $response;
		}
		curl_close($ch);
	}

	private function getParams($response) {
		$vars = explode("&", $response);
		
		$parameters = array();
		if (count($vars)) {
			foreach($vars as $v) {
				list($key, $value) = explode("=",$v);
				$parameters[$key] = urldecode($value);
			}
		}
		return ($parameters);
	}

	public function getBillingDetailsByOrderId($orderId)
	{
		/** @var Magento\Sales\Model\Order $order */
		$order_information = $this->_order->loadByIncrementId($orderId);


		$param = [
			'order_id'     => $orderId,
			'amount' 	   => number_format($order_information->getGrandTotal(), 2, '.', ''),
			'confirmURL'  => $this->_url->getBaseUrl(),
			'returnURL'      => $this->_url->getUrl('paynow/index/index?order_id='.$orderId)
		];

		return $param;
	}

	public function getPaymentConfig()
	{
		/** get types of configuration */
		$param = $this->configArr();
		/** create new array */
		$paramArr = [];

		foreach ($param as $single_param){
			/** get config values */
			$paramArr[$single_param] = $this->_scopeConfig->getValue('payment/paynow/'.$single_param ,\Magento\Store\Model\ScopeInterface::SCOPE_STORE);
		}

		return $paramArr;
	}

	public function configArr()
	{
        $param  = ['active','integration_id','integration_key','resource_url'];
        return $param;
	}

	private function updateTransactionStatus($order_id, $status) {
		$db = $this->_resource->getConnection();
		$q = "UPDATE `paynow` SET status = '" . $status . "' WHERE `order_id` = " . $order_id;
		if(!$db->query($q)) {
			die("Couldn't insert transaction into database!");
		}		
	}
	
	private function updateTransactionInfo($order_id, $params) {
		$db = $this->_resource->getConnection();
		$q = "
			UPDATE `paynow` SET 
			`poll_url` = '".$params['pollurl']."',
			`amount` = '".$params['amount']."',
			`paynow_reference` = '".$params['paynowreference']."',
			`reference` = '".$params['reference']."'
			WHERE `order_id` = ". $order_id
		;
		if(!$db->query($q)) {
			die("Couldn't update transaction info!");
		}		
	}

	public function restoreOrderToCart($errorMessage, $orderId)
	{


		$this->_orderManagement->cancel($orderId); //cancel the order
		/** add msg to cancel */
		$this->setCommentToOrder($orderId, $errorMessage);
	
		/** @var \Magento\Checkout\Model\Session $session */
		$this->_session->restoreQuote(); //Restore quote
		
		/** show error message on checkout/cart */
		$this->messageManager->addError($errorMessage);

		/** and redirect to chechout /cart*/ 
		return $this->_redirect('checkout/cart', array('_secure'=>true));
	}

	private function createNewTransaction($order_id, $params, $amount) {
		$db = $this->_resource->getConnection();
		$q = "
			INSERT INTO paynow
			(`order_id`, `browser_url`, `poll_url`, `amount`, `status`) 
			VALUES 
			('".$order_id."', '".$params['browserurl']."', '".$params['pollurl']."', '".$amount."', 'Sent to paynow')
		";
				
		if(!$db->query($q)) {
			die("Couldn't insert transaction into database!");
		}		
	}
}
