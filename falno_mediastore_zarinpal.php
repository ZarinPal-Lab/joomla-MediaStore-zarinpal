<?php defined('_JEXEC') or die; header("Content-type: text/html; charset=UTF-8"); jimport('joomla.plugin.plugin');
class plgMediaStoreFalno_Mediastore_Zarinpal extends JPlugin
{
	protected $info;
	protected $url;
	protected $data;
	protected $config;
	protected $response;
	protected $response_status;
	function __construct(&$subject, $config = array())
	{
		parent::__construct($subject, $config);
		$this->loadLanguage();
	}
	protected function validateData($data)
	{
		if($_GET['Status'] != 'OK')
		{
			header('location: '.$this->getUrl('index.php?option=com_mediastore&task=order.paymentCancel&payment_method=zarinpal&id='.intval($_GET['id'])));
			exit;
			return false;
		}
		else
		{
			$price = intval(ceil($_GET['price']));
			$client = new SoapClient('https://de.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8'));
			$parameters = array(
					'MerchantID' => $this->params->get('zarinpal_id'),
					'Authority'  => $_GET['Authority'],
					'Amount'     => $price
				);
			$result = $client->PaymentVerification($parameters);
			if($result->Status == 100)
			{
				$this->response_status = $result->RefID;
				return true;
			}
			else
			{
				throw new Exception(JText::sprintf('PLG_MEDIASTORE_FALNO_MEDIASTORE_ZARINPAL_ERROR_RESPONSE_STATUS', $result->Status));
			}
		}
	}
	protected function getUrl($link)
	{
		$uri = JUri::getInstance();
		$base = $uri->toString(array('scheme', 'user', 'pass', 'host', 'port'));
		return $base.JRoute::_($link, false);
	}
	protected function getInfo()
	{
		if (!isset($this->info))
		{
			$info = new stdClass();
			$info->code = 'zarinpal';
			$info->name = JText::_('PLG_MEDIASTORE_FALNO_MEDIASTORE_ZARINPAL_INFO_NAME');
			$info->label = $this->params->get('label', JText::_('PLG_MEDIASTORE_FALNO_MEDIASTORE_ZARINPAL_INFO_LABEL'));
			$info->description = $this->params->get('description', JText::_('PLG_MEDIASTORE_FALNO_MEDIASTORE_ZARINPAL_INFO_DESC'));
			$info->credit_card = 0;
			$info->available = 1;
			$this->info = $info;
		}
		return $this->info;
	}
	function onMSGetPaymentMethod()
	{
		return $this->getInfo();
	}
	function onMSProcessPayment($order)
	{
		$info = $this->getInfo();
		if ($order->payment_method != $info->code) return false;
		$price = $order->subtotal + $order->tax_total - $order->discount_total;
		if ($this->params->get('cur')) $price /= 10;
		$price = intval(ceil($price));
		date_default_timezone_set("Asia/Tehran");
		$client = new SoapClient('https://de.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8'));
		$parameters = array(
				'MerchantID'  => $this->params->get('zarinpal_id'),
				'Amount'      => $price,
				'Description' => $order->title,
				'Email'       => '',
				'Mobile'      => '',
				'CallbackURL' => $this->getUrl('index.php?option=com_mediastore&task=order.paymentNotify&payment_method=zarinpal&id='.$order->id.'&price='.$price)
			);
		$result = $client->PaymentRequest($parameters);
		if($result->Status == 100)
		{
			$url = 'https://www.zarinpal.com/pg/StartPay/'.$result->Authority;
			if ($this->params->get('mode')) $url .= '/ZarinGate';
			echo ('<p>'.JText::_('PLG_MEDIASTORE_FALNO_MEDIASTORE_ZARINPAL_REDIRECT_MSG').'</p>
					<form id="zarinpal_standard_checkout" name="zarinpal_standard_checkout" action="'.$url.'" method="get">
						<input class="btn" name="" value="'.JText::_('PLG_MEDIASTORE_FALNO_MEDIASTORE_ZARINPAL_SUBMIT_LABEL').'" type="submit" />
						</form><script type="text/javascript">document.getElementById("zarinpal_standard_checkout").submit();</script>');
		}
		else
		{
			echo (JText::sprintf('PLG_MEDIASTORE_FALNO_MEDIASTORE_ZARINPAL_ERROR_RESPONSE_STATUS', $result->Status));
		}
	}
	function onMSPaymentNotify($payment_method, $data, $model)
	{
		$info = $this->getInfo();
		if ($info->code != $payment_method) return false;
		if ($this->validateData($data))
		{
			$price = intval(ceil($_GET['price']));
			$pinfo = array();
			$pinfo['au'] = $_GET['Authority'];
			$pinfo['refnum'] = $this->response_status;
			$pinfo['price'] = $price;
			$pinfo['currency'] = $this->params->get('cur') ? 'RLS' : 'TMN';
			$result = new stdClass();
			$result->id = (int) $_GET['id'];
			$result->transaction_id = $this->response_status;
			$result->status = MediaStoreHelper::PAYMENTSTATUS_COMPLETED;
			$table = JTable::getInstance('Order', 'MediaStoreTable');
			$table->load($result->id);
			$result->grand_total = $table->grand_total;
			$result->currency_code = $table->currency_code;
			$result->fee = 0;
			$result->info = new JRegistry($pinfo);
			$result->info = $result->info->toString();
			return $result;
		}
		return false;
	}
}
