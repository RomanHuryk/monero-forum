<?php namespace Eddieh\Monero;

use Config;

class Monero
{

	public $expire;
	public $wallet;
	public $address;
	public $alias;

	function __construct()
	{
		$this->expire = Config::get('monero::xmr_expire_payments');
		$this->wallet = Config::get('monero::xmr_wallter_addr');
		$this->address = Config::get('monero::xmr_my_addr');
		$this->alias = Config::get('monero::xmr_my_alias');
	}

	public function generatePaymentID($var = false)
	{
		if (!$var) {
			$payment_id = bin2hex(openssl_random_pseudo_bytes(32));
			$check = Payment::where('payment_id', $payment_id)->count();
			while ($check != 0) {
				$payment_id = bin2hex(openssl_random_pseudo_bytes(32));
				$check = Payment::where('payment_id', $payment_id)->count();
			}
		} else {
			$payment_id = hash('sha256', $var);
		}

		return $payment_id;
	}

	public function receive($xmr, $payment_id_var = false)
	{
		$amount = $xmr * 1000000000000;

		$payment_id = $this->generatePaymentID($payment_id_var);

		$expire = date("Y-m-d H:i", strtotime("now " . $this->expire));

		$payment = Payment::create([
			'type' => 'receive',
			'payment_id' => $payment_id,
			'amount' => $amount,
			'status' => 'pending',
			'expire' => $expire
		]);

		$payment->address = $this->address;
		$payment->openalias = $this->alias;

		return $payment;
	}

	public function transfer($address, $xmr = 0 /* amount in XMR*/)
	{
		$amount = $xmr * 1000000000000;

		if ($amount == 0 || !Monero::validateAddress($address)) {
			return false;
		}

		$payment = Payment::create([
			'type' => 'transfer',
			'address' => $address,
			'amount' => $amount,
			'status' => 'pending'
		]);

		return $payment;

	}

	function clientReceive()
	{
		$now = date("Y-m-d H:i", strtotime("now"));

		$pending = Payment::where('status', 'pending')->where('type', 'receive')->where('expire', '>', $now)->get();

		if (count($pending)) {

			$ch = curl_init();
			$data = [
				'jsonrpc'   => '2.0',
				'method'    => 'get_bulk_payments',
				'id'        => 'phpmonero',
				'params'    => ['payment_ids' => []]
			];

			foreach($pending as $payment)
			{
				$data['params']['payment_ids'][] = $payment->payment_id;
			}

			curl_setopt($ch, CURLOPT_URL, "http://".$this->wallet."/json_rpc");
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_ENCODING, 'Content-Type: application/json');
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$server_output = curl_exec($ch);
			$result = json_decode($server_output, true);

			$payments = array();
			usort($result["result"]["payments"], Monero::sort('block_height'));

			foreach ($result["result"]["payments"] AS $index => $val) {

				array_push($payments, [
					"block_height" => $val["block_height"],
					"payment_id" => $val["payment_id"],
					"unlock_time" => $val["unlock_time"],
					"amount" => $val["amount"],
					"tx_hash" => $val["tx_hash"]
				]);

				$check = Payment::where('payment_id', $val['payment_id'])->first();

				if ($check->block_height < $val['block_height']) {
					$check->block_height = $val['block_height'];
					$check->amount = $val['amount'];
					$check->save();
				}
			}
			curl_close($ch);
		}
	}

	function clientTransfer($payment_id_var = false, $mixin = 3)
	{
		$pending = Payment::where('status', 'pending')->where('type', 'transfer')->get();

		if (count($pending)) {

			$payment_id = $this->generatePaymentID($payment_id_var);

			$ch = curl_init();

			$data = [
				'jsonrpc' => '2.0',
				'method' => 'transfer',
				"id" => "phpmonero",
				"params" => [
					'destinations' => [],
					'payment_id' => $payment_id,
					'mixin' => $mixin,
					'unlock_time' => 0
				]
			];

			foreach ($pending as $payment) {
				$data["params"]["destinations"][] = [
					'amount' => $payment->amount,
					'address' => $payment->address
				];
			}

			curl_setopt($ch, CURLOPT_URL, "http://" . $this->wallet . "/json_rpc");
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_ENCODING, 'Content-Type: application/json');
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$server_output = curl_exec($ch);

			if (curl_error($ch) != "") {
				return false;
			} else {
				foreach($pending as $payment)
				{
					$payment->status = 'complete';
					$payment->save();
				}
				return $pending;
			}
		}
		else {
			return false;
		}
	}

	public static function sort($key)
	{
		return function ($a, $b) use ($key) {
			return strnatcmp($a[$key], $b[$key]);
		};
	}

	public static function validateAddress($address)
	{
		if (
			substr($address, 0) != '4' ||
			!preg_match('/([0-9]|[A-B])/', substr($address, 1)) ||
			strlen($address) != 95
		) {
			return false;
		} else {
			return true;
		}
	}

}