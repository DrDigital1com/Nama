<?php
/**
 * Plugin Name: Nama Checkout Logger — אבחון הזמנות לא מושלמות
 * Description: מתעד את מסלול החיים המלא של כל ניסיון רכישה — צפייה בצ׳קאאוט, ולידציה, יצירת הזמנה, קריאה לשער הסליקה, חזרת ה-callback, ושינויי סטטוס — כולל שגיאות JS בצד הלקוח ושגיאות PHP פטאליות. מיועד לזהות בדיוק היכן נשברת ההזמנה.
 * Version: 1.1.0
 * Requires PHP: 7.4
 *
 * התקנה: wp-content/mu-plugins/nama-checkout-logger.php
 * צפייה:  WooCommerce → סטטוס → יומנים → קובץ "nama-checkout-*"
 *         ובמסך עריכת ההזמנה: תיבת "Nama — מסלול הצ׳קאאוט".
 *
 * הגנת פרטיות: לא נרשמים שמות, מיילים, כתובות, מספרי כרטיס או טוקנים.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Nama_Checkout_Logger' ) ) {
	return;
}

class Nama_Checkout_Logger {

	const SOURCE      = 'nama-checkout';
	const TRACE_META  = '_nama_checkout_trace';
	const MAX_TRACE   = 60;
	const JS_ACTION   = 'nama_checkout_js_error';

	/** @var array מארחים חיצוניים שנקראו במהלך הבקשה הנוכחית */
	private $outbound = array();

	/** @var int|null מזהה ההזמנה שנוצרה בבקשה הנוכחית */
	private $current_order_id = null;

	public static function boot() {
		$self = new self();

		// מסלול הצ׳קאאוט הקלאסי.
		add_action( 'template_redirect', array( $self, 'on_checkout_view' ), 5 );
		add_action( 'woocommerce_before_checkout_process', array( $self, 'on_checkout_start' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $self, 'on_validation' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $self, 'on_order_created' ), 10, 3 );

		// צ׳קאאוט בלוקים (Store API).
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $self, 'on_blocks_order_created' ), 10, 1 );

		// תשלום וסטטוסים.
		add_action( 'woocommerce_payment_complete', array( $self, 'on_payment_complete' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $self, 'on_status_changed' ), 10, 4 );

		// קריאות נכנסות משערי סליקה (IPN / callback / webhook).
		add_action( 'woocommerce_api_request', array( $self, 'on_gateway_callback' ), 1, 1 );
		add_action( 'init', array( $self, 'sniff_inbound_callback' ), 1 );

		// קריאות יוצאות אל שערי סליקה.
		add_action( 'http_api_debug', array( $self, 'on_http_request' ), 10, 5 );

		// שגיאות פטאליות בזמן צ׳קאאוט.
		add_action( 'init', array( $self, 'register_fatal_watch' ), 0 );

		// איסוף שגיאות JS מהדפדפן.
		add_action( 'wp_enqueue_scripts', array( $self, 'enqueue_js_watch' ), 99 );
		add_action( 'wp_ajax_' . self::JS_ACTION, array( $self, 'receive_js_error' ) );
		add_action( 'wp_ajax_nopriv_' . self::JS_ACTION, array( $self, 'receive_js_error' ) );

		// תיבת מסלול במסך ההזמנה.
		add_action( 'add_meta_boxes', array( $self, 'add_trace_metabox' ) );
	}

	/* ------------------------------------------------------------------ */

	private function log( $level, $message, array $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context['source'] = self::SOURCE;
		$context['req']    = $this->request_id();
		wc_get_logger()->log( $level, $message . ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), array( 'source' => self::SOURCE ) );
	}

	private function request_id() {
		static $id = null;
		if ( null === $id ) {
			$id = substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		return $id;
	}

	/**
	 * מוסיף אירוע למסלול השמור על ההזמנה.
	 */
	private function trace( $order, $event, array $data = array() ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$trace = $order->get_meta( self::TRACE_META );
		$trace = is_array( $trace ) ? $trace : array();

		$trace[] = array(
			't'    => gmdate( 'Y-m-d H:i:s' ),
			'e'    => $event,
			'ctx'  => 'cli' === PHP_SAPI ? 'cli' : ( wp_doing_ajax() ? 'ajax' : ( is_admin() ? 'admin' : 'web' ) ),
			'ip'   => $this->client_ip_hash(),
			'data' => $data,
		);

		if ( count( $trace ) > self::MAX_TRACE ) {
			$trace = array_slice( $trace, -self::MAX_TRACE );
		}

		$order->update_meta_data( self::TRACE_META, $trace );
		$order->save_meta_data();
	}

	/**
	 * גיבוב של כתובת ה-IP — מאפשר לזהות שהקריאה הגיעה ממקור חיצוני, בלי לשמור IP.
	 */
	private function client_ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip ? substr( md5( $ip . wp_salt( 'nonce' ) ), 0, 8 ) : '-';
	}

	private function is_checkout_request() {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false !== strpos( $uri, 'wc-ajax=checkout' ) || false !== strpos( $uri, 'wc-ajax=update_order_review' ) ) {
			return true;
		}
		if ( false !== strpos( $uri, '/wp-json/wc/store/' ) && false !== strpos( $uri, 'checkout' ) ) {
			return true;
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * אירועי מסלול
	 * --------------------------------------------------------------- */

	public function on_checkout_view() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		$cart_count = ( WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
		$session_id = ( WC()->session ) ? WC()->session->get_customer_id() : null;

		$this->log(
			'info',
			'CHECKOUT_VIEW',
			array(
				'cart_items' => $cart_count,
				'logged_in'  => is_user_logged_in() ? 1 : 0,
				'session'    => $session_id ? substr( md5( (string) $session_id ), 0, 8 ) : null,
				'cached'     => $this->cache_suspicion(),
			)
		);

		if ( 0 === $cart_count ) {
			$this->log( 'warning', 'CHECKOUT_VIEW_EMPTY_CART', array( 'note' => 'הלקוח הגיע לעמוד התשלום עם עגלה ריקה — סימן לאובדן סשן או להגשת עמוד מהמטמון.' ) );
		}
	}

	/**
	 * מנסה לזהות אם עמוד התשלום עלול להיות מוגש ממטמון.
	 */
	private function cache_suspicion() {
		$hints = array();
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			$hints[] = 'WP_CACHE';
		}
		foreach ( array( 'HTTP_X_CACHE', 'HTTP_CF_CACHE_STATUS', 'HTTP_X_LITESPEED_CACHE' ) as $h ) {
			if ( isset( $_SERVER[ $h ] ) ) {
				$hints[] = $h . '=' . sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
			}
		}
		if ( ! isset( $_COOKIE ) || empty( $_COOKIE ) ) {
			$hints[] = 'NO_COOKIES';
		}
		return $hints ? implode( ',', $hints ) : 'none';
	}

	public function on_checkout_start() {
		$this->log(
			'info',
			'CHECKOUT_SUBMIT',
			array(
				'gateway'    => isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '(none)',
				'nonce_ok'   => isset( $_POST['woocommerce-process-checkout-nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ), 'woocommerce-process_checkout' ) ? 1 : 0,
				'cart_items' => ( WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0,
				'cart_total' => ( WC()->cart ) ? WC()->cart->get_total( 'edit' ) : null,
			)
		);

		if ( isset( $_POST['woocommerce-process-checkout-nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ), 'woocommerce-process_checkout' ) ) {
			$this->log(
				'critical',
				'CHECKOUT_NONCE_INVALID',
				array( 'note' => 'ה-nonce של הצ׳קאאוט אינו תקין. כמעט תמיד: עמוד התשלום הוגש ממטמון, או שהסשן פג. ההזמנה לא תושלם.' )
			);
		}
	}

	public function on_validation( $data, $errors ) {
		if ( ! $errors instanceof WP_Error || ! $errors->get_error_codes() ) {
			return;
		}
		$list = array();
		foreach ( $errors->get_error_codes() as $code ) {
			$list[] = array(
				'code' => $code,
				'msg'  => wp_strip_all_tags( (string) $errors->get_error_message( $code ) ),
			);
		}
		$this->log(
			'error',
			'CHECKOUT_VALIDATION_FAILED',
			array(
				'gateway' => isset( $data['payment_method'] ) ? $data['payment_method'] : '(none)',
				'errors'  => $list,
			)
		);
	}

	public function on_order_created( $order_id, $posted_data, $order = null ) {
		$this->current_order_id = $order_id;
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$gateway = $order->get_payment_method();
		$this->log(
			'info',
			'ORDER_CREATED',
			array(
				'order'   => $order_id,
				'gateway' => $gateway ? $gateway : '(empty)',
				'total'   => $order->get_total(),
				'status'  => $order->get_status(),
				'items'   => count( $order->get_items() ),
			)
		);

		$this->trace(
			$order,
			'ORDER_CREATED',
			array(
				'gateway' => $gateway ? $gateway : '(empty)',
				'total'   => $order->get_total(),
				'status'  => $order->get_status(),
			)
		);

		if ( ! $gateway ) {
			$this->log( 'critical', 'ORDER_CREATED_WITHOUT_GATEWAY', array( 'order' => $order_id, 'note' => 'ההזמנה נוצרה ללא שיטת תשלום. הלקוח לא יופנה לסליקה וההזמנה תישאר pending.' ) );
		}
	}

	public function on_blocks_order_created( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->current_order_id = $order->get_id();
		$this->log(
			'info',
			'ORDER_CREATED_BLOCKS',
			array(
				'order'   => $order->get_id(),
				'gateway' => $order->get_payment_method(),
				'total'   => $order->get_total(),
			)
		);
		$this->trace( $order, 'ORDER_CREATED_BLOCKS', array( 'gateway' => $order->get_payment_method() ) );
	}

	public function on_payment_complete( $order_id ) {
		$this->log( 'info', 'PAYMENT_COMPLETE', array( 'order' => $order_id ) );
		$this->trace( $order_id, 'PAYMENT_COMPLETE' );
	}

	public function on_status_changed( $order_id, $from, $to, $order ) {
		$this->log(
			'failed' === $to ? 'error' : 'info',
			'STATUS_CHANGED',
			array(
				'order' => $order_id,
				'from'  => $from,
				'to'    => $to,
			)
		);
		$this->trace(
			$order,
			'STATUS_' . strtoupper( $to ),
			array(
				'from' => $from,
				'to'   => $to,
			)
		);
	}

	/**
	 * קריאה נכנסת דרך נקודת הקצה של ווקומרס (/wc-api/<gateway>).
	 * זו הדרך שבה רוב שערי הסליקה מודיעים על תשלום מוצלח.
	 */
	public function on_gateway_callback( $api_request ) {
		$this->log(
			'info',
			'GATEWAY_CALLBACK_RECEIVED',
			array(
				'endpoint' => $api_request,
				'method'   => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'ip_hash'  => $this->client_ip_hash(),
				'ua'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 120 ) : '',
				'keys'     => array_slice( array_keys( array_merge( (array) $_GET, (array) $_POST ) ), 0, 25 ),
			)
		);
	}

	/**
	 * מזהה קריאות callback שמגיעות בנתיבים אחרים (לא /wc-api/).
	 */
	public function sniff_inbound_callback() {
		if ( is_admin() || 'cli' === PHP_SAPI ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '';
		if ( '' === $uri ) {
			return;
		}
		$needles = array( 'ipn', 'callback', 'notify', 'webhook', 'payment-return', 'paymentresponse', 'indicator' );
		foreach ( $needles as $n ) {
			if ( false !== strpos( $uri, $n ) ) {
				$this->log(
					'info',
					'INBOUND_CALLBACK_LIKE_REQUEST',
					array(
						'uri'     => substr( $uri, 0, 200 ),
						'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
						'ip_hash' => $this->client_ip_hash(),
					)
				);
				return;
			}
		}
	}

	/**
	 * מתעד כל קריאת HTTP יוצאת בזמן צ׳קאאוט — כולל כשלים וזמני תגובה.
	 * כאן מתגלה "השרת לא מצליח לדבר עם חברת הסליקה".
	 */
	public function on_http_request( $response, $context, $class, $parsed_args, $url ) {
		if ( ! $this->is_checkout_request() && ! $this->current_order_id ) {
			return;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return;
		}

		// לא לתעד קריאות פנימיות ורעש רגיל.
		$skip = array( 'api.wordpress.org', 'downloads.wordpress.org', 'woocommerce.com', 'api.woocommerce.com' );
		foreach ( $skip as $s ) {
			if ( false !== strpos( $host, $s ) ) {
				return;
			}
		}

		$entry = array(
			'host'   => $host,
			'path'   => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'method' => isset( $parsed_args['method'] ) ? $parsed_args['method'] : 'GET',
			'order'  => $this->current_order_id,
		);

		if ( is_wp_error( $response ) ) {
			$entry['error'] = $response->get_error_message();
			$this->log( 'critical', 'OUTBOUND_HTTP_FAILED', $entry );
			if ( $this->current_order_id ) {
				$this->trace( $this->current_order_id, 'OUTBOUND_HTTP_FAILED', $entry );
			}
			return;
		}

		$code           = wp_remote_retrieve_response_code( $response );
		$entry['code']  = $code;

		if ( (int) $code >= 400 || 0 === (int) $code ) {
			$entry['body_head'] = substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 0, 300 );
			$this->log( 'error', 'OUTBOUND_HTTP_BAD_STATUS', $entry );
			if ( $this->current_order_id ) {
				$this->trace( $this->current_order_id, 'OUTBOUND_HTTP_BAD_STATUS', $entry );
			}
		} else {
			$this->log( 'info', 'OUTBOUND_HTTP_OK', $entry );
			if ( $this->current_order_id ) {
				$this->trace( $this->current_order_id, 'OUTBOUND_HTTP_OK', array( 'host' => $host, 'code' => $code ) );
			}
		}
	}

	/**
	 * תופס שגיאה פטאלית שקרתה באמצע בקשת צ׳קאאוט.
	 */
	public function register_fatal_watch() {
		if ( ! $this->is_checkout_request() ) {
			return;
		}
		register_shutdown_function(
			function () {
				$err = error_get_last();
				if ( ! $err || ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}
				$this->log(
					'critical',
					'FATAL_DURING_CHECKOUT',
					array(
						'order'   => $this->current_order_id,
						'message' => substr( (string) $err['message'], 0, 400 ),
						'file'    => str_replace( ABSPATH, '', (string) $err['file'] ),
						'line'    => $err['line'],
					)
				);
				if ( $this->current_order_id ) {
					$this->trace(
						$this->current_order_id,
						'FATAL_DURING_CHECKOUT',
						array(
							'message' => substr( (string) $err['message'], 0, 200 ),
							'file'    => str_replace( ABSPATH, '', (string) $err['file'] ),
							'line'    => $err['line'],
						)
					);
				}
			}
		);
	}

	/* ------------------------------------------------------------------
	 * צד לקוח — שגיאות JS ובקשות AJAX שנכשלו
	 * --------------------------------------------------------------- */

	public function enqueue_js_watch() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( ! is_checkout() && ! is_cart() ) {
			return;
		}

		$handle = 'nama-checkout-watch';
		wp_register_script( $handle, false, array(), '1.1.0', true );
		wp_enqueue_script( $handle );
		wp_add_inline_script(
			$handle,
			sprintf(
				'(function(){
	var U=%s, N=%s, sent=0;
	function report(kind, detail){
		if (sent >= 15) { return; }
		sent++;
		try {
			var fd = new FormData();
			fd.append("action", "%s");
			fd.append("nonce", N);
			fd.append("kind", kind);
			fd.append("detail", String(detail).slice(0, 900));
			fd.append("url", location.pathname + location.search);
			if (navigator.sendBeacon) { navigator.sendBeacon(U, fd); }
			else { fetch(U, {method:"POST", body:fd, credentials:"same-origin", keepalive:true}); }
		} catch(e){}
	}
	window.addEventListener("error", function(e){
		report("js_error", (e.message||"") + " @ " + (e.filename||"") + ":" + (e.lineno||0));
	}, true);
	window.addEventListener("unhandledrejection", function(e){
		report("js_promise", (e.reason && (e.reason.message||e.reason)) || "unhandled rejection");
	});
	if (window.jQuery) {
		jQuery(document).on("ajaxError", function(ev, xhr, settings){
			var u = (settings && settings.url) || "";
			if (u.indexOf("wc-ajax") === -1 && u.indexOf("wc/store") === -1) { return; }
			report("wc_ajax_error", u + " -> HTTP " + (xhr && xhr.status) + " " + ((xhr && xhr.responseText) || "").slice(0,300));
		});
		jQuery(document.body).on("checkout_error", function(){
			var t = jQuery(".woocommerce-error, .wc-block-components-notice-banner.is-error").text() || "";
			report("checkout_error_notice", t.replace(/\s+/g," ").trim().slice(0,500));
		});
	}
})();',
				wp_json_encode( admin_url( 'admin-ajax.php' ) ),
				wp_json_encode( wp_create_nonce( self::JS_ACTION ) ),
				esc_js( self::JS_ACTION )
			)
		);
	}

	public function receive_js_error() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::JS_ACTION ) ) {
			wp_send_json_error( 'bad nonce', 403 );
		}

		// הגבלת קצב: עד 40 דיווחים לשעה לכל IP.
		$key   = 'nama_js_rl_' . $this->client_ip_hash();
		$count = (int) get_transient( $key );
		if ( $count > 40 ) {
			wp_send_json_success( 'rate limited' );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$kind   = isset( $_POST['kind'] ) ? sanitize_text_field( wp_unslash( $_POST['kind'] ) ) : 'unknown';
		$detail = isset( $_POST['detail'] ) ? sanitize_textarea_field( wp_unslash( $_POST['detail'] ) ) : '';
		$url    = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';

		$this->log(
			'error',
			'CLIENT_' . strtoupper( $kind ),
			array(
				'url'    => substr( $url, 0, 200 ),
				'detail' => substr( $detail, 0, 700 ),
			)
		);

		wp_send_json_success( 'logged' );
	}

	/* ------------------------------------------------------------------
	 * תיבת מסלול במסך ההזמנה
	 * --------------------------------------------------------------- */

	public function add_trace_metabox() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'nama_checkout_trace',
				'Nama — מסלול הצ׳קאאוט',
				array( $this, 'render_trace_metabox' ),
				$screen,
				'normal',
				'low'
			);
		}
	}

	public function render_trace_metabox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );
		if ( ! $order ) {
			echo '<p>לא נמצאה הזמנה.</p>';
			return;
		}

		$trace = $order->get_meta( self::TRACE_META );
		if ( ! is_array( $trace ) || ! $trace ) {
			echo '<p>אין נתוני מסלול להזמנה זו. המסלול נרשם רק להזמנות שנוצרו לאחר התקנת התוסף.</p>';
			return;
		}

		echo '<table class="widefat striped" dir="rtl"><thead><tr><th>זמן (UTC)</th><th>אירוע</th><th>הקשר</th><th>נתונים</th></tr></thead><tbody>';
		foreach ( $trace as $t ) {
			$style = ( false !== strpos( $t['e'], 'FATAL' ) || false !== strpos( $t['e'], 'FAILED' ) ) ? ' style="color:#d63638;font-weight:700"' : '';
			printf(
				'<tr><td>%s</td><td%s>%s</td><td>%s</td><td><code style="direction:ltr;display:inline-block">%s</code></td></tr>',
				esc_html( $t['t'] ),
				$style,
				esc_html( $t['e'] ),
				esc_html( isset( $t['ctx'] ) ? $t['ctx'] : '' ),
				esc_html( wp_json_encode( isset( $t['data'] ) ? $t['data'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
			);
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:8px">אם המסלול מסתיים ב-<code>ORDER_CREATED</code> ואין אחריו <code>PAYMENT_COMPLETE</code> או <code>GATEWAY_CALLBACK_RECEIVED</code> — הלקוח לא חזר מהסליקה, או שה-callback נחסם לפני שהגיע לאתר.</p>';
	}
}

Nama_Checkout_Logger::boot();
