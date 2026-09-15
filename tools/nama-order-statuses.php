<?php
/**
 * Plugin Name: Nama — סטטוסי הזמנה מותאמים
 * Description: מוסיף ל-WooCommerce שני סטטוסי הזמנה: "מוכן למשלוח" ו-"בחברת המשלוחים". תומך ב-HPOS ובאחסון המסורתי, כולל פעולות קבוצתיות, צביעה במסך ההזמנות, וספירה כהכנסה בדוחות.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// שמירה מפני טעינה כפולה. לא להשתמש כאן ב-class_exists: PHP רושמת מחלקה
// שמוצהרת ברמת הקובץ כבר בזמן הקומפילציה, ולכן התנאי היה מתקיים תמיד.
if ( defined( 'NAMA_ORDER_STATUSES_LOADED' ) ) {
	return;
}
define( 'NAMA_ORDER_STATUSES_LOADED', true );

class Nama_Order_Statuses {

	/**
	 * הסטטוסים להוספה, לפי סדר הופעתם בתהליך.
	 *
	 * מפתח הסטטוס מוגבל ל-20 תווים כולל הקידומת wc-.
	 *
	 * @var array<string,array{label:string,color:string,bg:string}>
	 */
	private static function statuses() {
		return array(
			'wc-ready-to-ship' => array(
				'label' => 'מוכן למשלוח',
				'color' => '#5b3d00',
				'bg'    => '#fcf0d1',
			),
			'wc-at-courier'    => array(
				'label' => 'בחברת המשלוחים',
				'color' => '#003f6b',
				'bg'    => '#d6e9f7',
			),
		);
	}

	/** הסטטוס שאחריו הם ישובצו ברשימה. */
	const INSERT_AFTER = 'wc-processing';

	public static function boot() {
		$self = new self();

		add_action( 'init', array( $self, 'register' ), 9 );
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( $self, 'register_hpos' ) );
		add_filter( 'wc_order_statuses', array( $self, 'add_to_dropdown' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( $self, 'mark_as_paid' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( $self, 'include_in_reports' ) );

		// פעולות קבוצתיות — גם במסך ההזמנות של HPOS וגם במסך המסורתי.
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $self, 'bulk_actions' ), 20 );
		add_filter( 'bulk_actions-edit-shop_order', array( $self, 'bulk_actions' ), 20 );

		add_action( 'admin_head', array( $self, 'status_styles' ) );
	}

	/**
	 * רישום הסטטוסים כ-post statuses. נדרש גם כש-HPOS פעיל.
	 */
	public function register() {
		foreach ( self::statuses() as $key => $data ) {
			register_post_status(
				$key,
				array(
					'label'                     => $data['label'],
					'public'                    => false,
					'internal'                  => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: מספר ההזמנות */
					'label_count'               => _n_noop(
						$data['label'] . ' <span class="count">(%s)</span>',
						$data['label'] . ' <span class="count">(%s)</span>'
					),
				)
			);
		}
	}

	/**
	 * רישום מקביל עבור טבלת ההזמנות של HPOS.
	 */
	public function register_hpos( $statuses ) {
		foreach ( self::statuses() as $key => $data ) {
			$statuses[ $key ] = array(
				'label'                     => $data['label'],
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					$data['label'] . ' <span class="count">(%s)</span>',
					$data['label'] . ' <span class="count">(%s)</span>'
				),
			);
		}
		return $statuses;
	}

	/**
	 * הוספה לרשימת הסטטוסים של ווקומרס, מיד אחרי "בטיפול".
	 */
	public function add_to_dropdown( $statuses ) {
		$new = array();
		foreach ( $statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( self::INSERT_AFTER === $key ) {
				foreach ( self::statuses() as $k => $data ) {
					$new[ $k ] = $data['label'];
				}
			}
		}

		// אם "בטיפול" לא נמצא מסיבה כלשהי — להוסיף בסוף, שלא ייעלמו.
		foreach ( self::statuses() as $k => $data ) {
			if ( ! isset( $new[ $k ] ) ) {
				$new[ $k ] = $data['label'];
			}
		}

		return $new;
	}

	/**
	 * הזמנה בסטטוסים האלה כבר שולמה — היא נמצאת בדרך ללקוח.
	 *
	 * בלי זה ההכנסה תיעלם מהדוחות, ותוספי חשבונית ומלאי עלולים
	 * להתייחס להזמנה כאילו לא שולמה.
	 *
	 * הערכים כאן הם ללא הקידומת wc-, כפי שווקומרס מצפה.
	 */
	public function mark_as_paid( $statuses ) {
		foreach ( array_keys( self::statuses() ) as $key ) {
			$statuses[] = substr( $key, 3 );
		}
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * הכללה בדוחות ווקומרס.
	 */
	public function include_in_reports( $statuses ) {
		foreach ( array_keys( self::statuses() ) as $key ) {
			$statuses[] = substr( $key, 3 );
		}
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * פעולות קבוצתיות במסך ההזמנות.
	 */
	public function bulk_actions( $actions ) {
		foreach ( self::statuses() as $key => $data ) {
			$actions[ 'mark_' . substr( $key, 3 ) ] = 'שינוי ל: ' . $data['label'];
		}
		return $actions;
	}

	/**
	 * צביעת תגית הסטטוס במסך ההזמנות, כדי שתהיה מובחנת מהשאר.
	 */
	public function status_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'order' ) ) {
			return;
		}

		echo '<style>';
		foreach ( self::statuses() as $key => $data ) {
			printf(
				'.order-status.status-%1$s{background:%2$s;color:%3$s}',
				esc_attr( substr( $key, 3 ) ),
				esc_attr( $data['bg'] ),
				esc_attr( $data['color'] )
			);
		}
		echo '</style>';
	}
}

Nama_Order_Statuses::boot();
