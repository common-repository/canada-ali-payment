<?php
if (! defined ( 'ABSPATH' ))
	exit (); // Exit if accessed directly

class CAliWCPaymentGateway extends WC_Payment_Gateway {
    private $config;
    
	public function __construct() {
		//支持退款
		array_push($this->supports,'refunds');

		$this->id = C_WC_ALI_ID;
		$this->icon =C_WC_AlI_URL. '/images/alipay.png';
		$this->has_fields = false;
		
		$this->method_title = 'Canada Ali Payment'; // checkout option title
	    $this->method_description='Canada Ali Payment   <a href="https://www.paybuzz.ca" >Provided by Paybuzz</a>';
	   
		$this->init_form_fields ();
		$this->init_settings ();
		
		$this->title = $this->get_option ( 'title' );
		$this->description = $this->get_option ( 'description' );
		
		$lib = C_WC_ALI_DIR.'/lib';


	}
	function init_form_fields() {
	    $this->form_fields = array (
	        'enabled' => array (
	            'title' => __ ( 'Enable/Disable 启用/禁用', 'alipay' ),
	            'type' => 'checkbox',
	            'label' => __ ( 'Enable ALI Payment 启用支付宝支付', 'alipay' ),
	            'default' => 'no'
	        ),
	        'title' => array (
	            'title' => __ ( 'Title 标题', 'alipay' ),
	            'type' => 'text',
	            'description' => __ ( 'Payment Method Name, shown when check out. 支付网关名称，用户结账时显示', 'alipay' ),
	            'default' => __ ( 'ALIPay', 'alipay' ),
	            'css' => 'width:400px'
	        ),
	        'description' => array (
	            'title' => __ ( 'Description 描述', 'alipay' ),
	            'type' => 'textarea',
	            'description' => __ ( 'Describe the payment method. 描述一下支付网关，用户结账时会看到', 'alipay' ),
	            'default' => __ ( "Pay via ALIPay, if you don't have an ALIPay account, you can also pay with your debit card or credit card", 'alipay' ),
	            //'desc_tip' => true ,
	            'css' => 'width:400px'
	        ),
				'CID' => array (
						'title' => __ ( 'CID 商户ID', 'alipay' ),
						'type' => 'text',
						'description' => __ ( '商户ID Contact Paybuzz.ca for ID id@paybuzz.ca' ),
						'css' => 'width:400px',
						'default' => __ ( '', 'alipay' ),
				),
	    );
	
	}
	
	public function process_payment($order_id) {
	    $order = new WC_Order ( $order_id );
	    return array (
	        'result' => 'success',
	        'redirect' => $order->get_checkout_payment_url ( true )
	    );
	}
	
	public  function woocommerce_alipay_add_gateway( $methods ) {
	    $methods[] = $this;
	    return $methods;
	}
	
	/**
	 * 
	 * @param WC_Order $order
	 * @param number $limit
	 * @param string $trimmarker
	 */
	public  function get_order_title($order,$limit=32,$trimmarker='...'){
	    $id = method_exists($order, 'get_id')?$order->get_id():$order->id;
		$title="#{$id}|".get_option('blogname');
		
		$order_items =$order->get_items();
		if($order_items&&count($order_items)>0){
		    $title="#{$id}|";
		    $index=0;
		    foreach ($order_items as $item_id =>$item){
		        $title.= $item['name'];
		        if($index++>0){
		            $title.='...';
		            break;
		        }
		    }    
		}
		
		return apply_filters('xh_ali_wc_get_order_title', mb_strimwidth ( $title, 0,32, '...','utf-8'));
	}
	
	public function get_order_status() {
		$order_id = isset($_POST ['orderId'])?$_POST ['orderId']:'';
		$order = new WC_Order ( $order_id );
		$isPaid = ! $order->needs_payment ();
	
		echo json_encode ( array (
		    'status' =>$isPaid? 'paid':'unpaid',
		    'url' => $this->get_return_url ( $order )
		));
		
		exit;
	}
	
	function wp_enqueue_scripts() {
		$orderId = get_query_var ( 'order-pay' );
		$order = new WC_Order ( $orderId );
		$payment_method = method_exists($order, 'get_payment_method')?$order->get_payment_method():$order->payment_method;
		if ($this->id == $payment_method) {
			if (is_checkout_pay_page () && ! isset ( $_GET ['pay_for_order'] )) {
			    
			    wp_enqueue_script ( 'XH_WECHAT_JS_QRCODE', C_WC_AlI_URL. '/js/qrcode.js', array (), C_ALI_VERSION );
				wp_enqueue_script ( 'XH_WECHAT_JS_CHECKOUT', C_WC_AlI_URL. '/js/checkout.js', array ('jquery','XH_WECHAT_JS_QRCODE' ), C_ALI_VERSION );
				
			}
		}
	}

// Log信息输出
	public function wplog( $str = '', $tag = '' ) {
		$split = ( $tag=='' ) ? '' : ":t";
		file_put_contents( C_WC_ALI_DIR.'/wp.log', $tag . $split . $str . "\n", FILE_APPEND );
	}
	public function check_alipay_response() {
	    if(defined('WP_USE_THEMES')&&!WP_USE_THEMES){
	        return;
		}



		$json =  file_get_contents("php://input");//isset($GLOBALS ['HTTP_RAW_POST_DATA'])?$GLOBALS ['HTTP_RAW_POST_DATA']:'';
		date_default_timezone_set("Asia/Shanghai");

		if(empty($json)){
			return;
		}
		// 如果返回成功则验证签名
		try {
			$result = json_decode($json,true);
		    if (!$result ||! isset($result['OID'])) {
				return;
		    }
			$this->wplog(date("Y-m-d h:i:s").'  pay callback:'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'   json:'.$json);

			$order_id = $this->wc_get_order_id_by_ott_id($result['OID']);



            if(!(isset($result['STATUS'])&& $result['STATUS']=='SUCCESS')){
                throw new Exception("order not paid!");
            }

			if($result['STATUS']=='TIMEOUT'){
				throw new Exception("TIMEOUT!");
			}

		    $order = new WC_Order ( $order_id );
		    if($order->needs_payment()){
		          $order->payment_complete ();//$transaction_id);
		    }
			else{
				$resp['R_status'] = 0;
				$resp['R_MSG'] = 'Already paid';
				echo json_encode($resp);
				exit;
			}

			//返回
			$resp['R_status'] = 1;
			echo json_encode($resp);
		    exit;
		} catch ( Exception $e ) {
			$resp['R_status'] = 0;
			$resp['R_MSG'] = $e->errorMessage();
			echo json_encode($resp);
			return;
			exit;
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = ''){		
		$order = new WC_Order ($order_id );
		if(!$order){
			return new WP_Error( 'invalid_order','错误的订单' );
		}
		$oldoid = $this->get_ott_id($order_id);
		$cid = $this->get_option('CID');
		$post_data = array(
				'CID' => $cid,
				'NEWOID' => 'ACT'.strtoupper(dechex(date('m'))) . date('d') . substr(time(), -5) . substr(microtime(), 2, 5) ,
				'OLDOID'=>$oldoid,
				'RE_AMT'=>(string)(int)($order->get_total() *100),
		);
		$data_json =  json_encode($post_data);
		$json = $this->do_post_request('https://service.paybuzz.ca/refund.jsp',$data_json );
		$ret = json_decode($json['body'],true);
		if($ret && $ret['R_status'] == 1)
			return true;
		else
			return new WP_Error( 'invalid_order',$ret?'':$ret['R_MSG']);
	}


	/**
	 * 
	 * @param WC_Order $order
	 */
	function receipt_page($order_id) {
	    $order = new WC_Order($order_id);
	    if(!$order||!$order->needs_payment()){
	        wp_redirect($this->get_return_url($order));
	        exit;
	    }
	    
        echo '<p>' . __ ( 'Please scan the QR code with ALI to finish the payment.', 'alipay' ) . '</p>';
		$oid =  'ACT'.strtoupper(dechex(date('m'))) . date('d') . substr(time(), -5) . substr(microtime(), 2, 5) ;//$this->create_uuid($order->get_id()."-");
		//$this->wplog('oid:'.$oid.'   key:'.$order->get_order_key());
		$cid = $this->get_option('CID');
		$buyer_country = $order->get_billing_country();
		$buyer_firstname = $order->get_billing_first_name();
		$buyer_lastname = $order->get_billing_last_name();
		$buyer_phone = $order->get_billing_phone();
		$buyer_postcode = $order->get_billing_postcode();
		$buyer_email = $order->get_billing_email();
		$buyer_city = $order->get_billing_city();
		$buyer_address = $order->get_billing_address_1();
		$buyer_province = '';
		$buyer_company = $order->get_billing_company();
		$items = $order->get_items();
		$oi = [];
		foreach ( $items as $item ) {
			$product = [];
			$product['PRODUCT'] = $item->get_name();
			$product['QUANTITY'] = $item->get_quantity();
			$product['SUBTOTAL'] = (string)($item->get_total());
			$product['PRICE'] = (string)($item->get_subtotal());
			$oi[] = $product;
		}
		$post_data = array(
				'CID' => $cid,
				'OID' =>$oid,
				'TYPE'=>'ALIPAY',
				'TOTAL'=>(string)(int)($order->get_total() *100),
				'CBU'=>home_url(),
				'HASCI'=>'Y',
				'HASOI'=>'Y',
				'FN'=>$buyer_firstname,
				'LN' => $buyer_lastname,
				'CN' =>$buyer_company,
				'COUNTRY' => $buyer_country,
				'ADDR' =>$buyer_address,
				'CITY' => $buyer_city,
				'PROVINCE' =>$buyer_province,
				'POSTCODE' => $buyer_postcode,
				'PHONE' =>$buyer_phone,
				'EMAIL' => $buyer_email,
				'OI' =>$oi,
		);

		$data_json =  json_encode($post_data);
		$json = $this->do_post_request('https://service.paybuzz.ca/pay.jsp',$data_json );
		$ret = json_decode($json['body'],true);
		if($ret['R_status'] == 1) {
			//echo ("<input type='hidden' id='out_trade_no' value='A010101' />");
			update_post_meta( $order_id, '_ott_order_id', wc_clean( $ret['Order_ID']) );

			echo '<input type="hidden" id="ali-payment-pay-url" value="'.$ret['QR'].'"/>';
			echo '<div style="width:200px;height:200px" id="ali-payment-pay-img" data-oid="' . $order->get_id() . '"></div>';
		}
		else{
			echo '<div  data-oid="' . $order_id . '">'.$ret['R_MSG'].'</div>';
		}
	}

	function do_post_request($url, $data)
	{
//		$curl = curl_init(); // 启动一个CURL会话
//		curl_setopt($curl, CURLOPT_URL, $url); // 要访问的地址
//		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // 对认证证书来源的检查
//		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 1); // 从证书中检查SSL加密算法是否存在
//		curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']); // 模拟用户使用的浏览器
//		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1); // 使用自动跳转
//		curl_setopt($curl, CURLOPT_AUTOREFERER, 1); // 自动设置Referer
//		curl_setopt($curl, CURLOPT_POST, 1); // 发送一个常规的Post请求
//		curl_setopt($curl, CURLOPT_POSTFIELDS, $data); // Post提交的数据包
//		curl_setopt($curl, CURLOPT_TIMEOUT, 30); // 设置超时限制防止死循环
//		curl_setopt($curl, CURLOPT_HEADER, 0); // 显示返回的Header区域内容
//		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // 获取的信息以文件流的形式返回
//		$tmpInfo = curl_exec($curl); // 执行操作
//		if (curl_errno($curl)) {
//			echo 'Errno'.curl_error($curl);//捕抓异常
//		}
//		curl_close($curl); // 关闭CURL会话
//		return $tmpInfo; // 返回数据
		$request = new WP_Http;
		$result = $request->request( $url, array( 'method' => 'POST', 'body' => $data) );
		return $result;
	}

	public static function create_uuid($prefix = ""){    //可以指定前缀
		$str = md5(uniqid(mt_rand(), true));
		$uuid  = substr($str,0,8) . '-';
		$uuid .= substr($str,8,4) . '-';
		$uuid .= substr($str,12,5);

		return $prefix . $uuid;
	}
	function wc_get_order_id_by_ott_id( $oid ) {
		global $wpdb;

		// Faster than get_posts()
		$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_ott_order_id' AND meta_value = %s", $oid ) );

		return $order_id;
	}

	function get_ott_id($order_id)
	{
		return get_post_meta($order_id, '_ott_order_id', true );
	}
}

?>
