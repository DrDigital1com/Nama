<?php
/**
 * Plugin Name: Nama Audit — WooCommerce Site Health Report
 * Description: בדיקת מערכת מקיפה לאתר וורדפרס/ווקומרס: תשתית, ביצועים, בריאות DB, קרון, ותקלות הזמנות (pending/failed). מייצר דוח מלא + ייצוא JSON ללא PII.
 * Version: 1.1.0
 * Author: Nama Audit
 * Requires PHP: 7.4
 *
 * התקנה: להעלות ל-wp-content/mu-plugins/nama-audit.php
 * שימוש:  לוח בקרה -> כלים -> Nama Audit   |   WP-CLI: wp nama audit [--format=json] [--out=<file>]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Nama_Audit' ) ) {
	return;
}

class Nama_Audit {

	const VERSION = '1.1.0';

	/** @var array<int,array> ממצאים מדורגים */
	private $findings = array();

	/** @var array<int,array> מקטעי הדוח */
	private $sections = array();

	/** @var array מטמון פנימי */
	private $cache = array();

	public static function boot() {
		$self = new self();
		add_action( 'admin_menu', array( $self, 'register_admin_page' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'nama audit', array( $self, 'cli_audit' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * תשתית איסוף
	 * ------------------------------------------------------------------ */

	/**
	 * רישום ממצא.
	 *
	 * @param string $severity critical|warning|notice|ok
	 */
	private function finding( $severity, $title, $detail = '', $fix = '' ) {
		$this->findings[] = array(
			'severity' => $severity,
			'title'    => $title,
			'detail'   => $detail,
			'fix'      => $fix,
		);
	}

	private function section( $key, $title, array $rows, array $tables = array() ) {
		$this->sections[] = array(
			'key'    => $key,
			'title'  => $title,
			'rows'   => $rows,
			'tables' => $tables,
		);
	}

	private static function row( $label, $value, $status = '' ) {
		return array(
			'label'  => $label,
			'value'  => is_scalar( $value ) || is_null( $value ) ? (string) $value : wp_json_encode( $value ),
			'status' => $status,
		);
	}

	private static function bytes_to_mb( $bytes ) {
		return round( ( (float) $bytes ) / 1048576, 2 );
	}

	private static function ini_bytes( $val ) {
		$val  = trim( (string) $val );
		if ( '' === $val ) {
			return 0;
		}
		$last = strtolower( substr( $val, -1 ) );
		$num  = (float) $val;
		switch ( $last ) {
			case 'g':
				$num *= 1024;
				// no break
			case 'm':
				$num *= 1024;
				// no break
			case 'k':
				$num *= 1024;
		}
		return (int) $num;
	}

	private function wc_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * מחזיר את מבנה טבלת ההזמנות בהתאם ל-HPOS או לאחסון המסורתי.
	 *
	 * @return array{table:string,status:string,date:string,total:string,type_where:string,gateway:string|null,id:string}
	 */
	private function orders_schema() {
		global $wpdb;

		if ( isset( $this->cache['orders_schema'] ) ) {
			return $this->cache['orders_schema'];
		}

		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		if ( $hpos ) {
			$schema = array(
				'hpos'       => true,
				'table'      => $wpdb->prefix . 'wc_orders',
				'id'         => 'id',
				'status'     => 'status',
				'date'       => 'date_created_gmt',
				'total'      => 'total_amount',
				'gateway'    => 'payment_method',
				'type_where' => "type = 'shop_order'",
			);
		} else {
			$schema = array(
				'hpos'       => false,
				'table'      => $wpdb->posts,
				'id'         => 'ID',
				'status'     => 'post_status',
				'date'       => 'post_date_gmt',
				'total'      => null,
				'gateway'    => null,
				'type_where' => "post_type = 'shop_order'",
			);
		}

		$this->cache['orders_schema'] = $schema;
		return $schema;
	}

	private function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/* ---------------------------------------------------------------------
	 * 1. סביבת שרת
	 * ------------------------------------------------------------------ */

	private function collect_environment() {
		global $wpdb;

		$rows = array();

		$php = PHP_VERSION;
		$rows[] = self::row( 'גרסת PHP', $php, version_compare( $php, '8.1', '>=' ) ? 'ok' : 'warning' );
		if ( version_compare( $php, '8.1', '<' ) ) {
			$this->finding(
				version_compare( $php, '7.4', '<' ) ? 'critical' : 'warning',
				'גרסת PHP ישנה (' . $php . ')',
				'PHP 8.1+ מהיר משמעותית מ-7.x בעומסי ווקומרס (עד ~30% פחות זמן עיבוד לבקשה) ומקבל עדכוני אבטחה.',
				'לשדרג ל-PHP 8.2 או 8.3 בפאנל האחסון. לפני השדרוג להריץ בדיקת תאימות (PHP Compatibility Checker) ולגבות.'
			);
		}

		$rows[] = self::row( 'גרסת WordPress', get_bloginfo( 'version' ) );
		$rows[] = self::row( 'גרסת WooCommerce', $this->wc_active() && defined( 'WC_VERSION' ) ? WC_VERSION : 'לא מותקן/לא פעיל', $this->wc_active() ? 'ok' : 'critical' );
		$rows[] = self::row( 'גרסת שרת DB', $wpdb->db_version() );
		$rows[] = self::row( 'Web server', isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'לא ידוע' );

		$mem      = ini_get( 'memory_limit' );
		$mem_b    = self::ini_bytes( $mem );
		$rows[]   = self::row( 'memory_limit', $mem, $mem_b >= 268435456 ? 'ok' : 'warning' );
		$wp_mem   = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'לא מוגדר';
		$wp_max   = defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : 'לא מוגדר';
		$rows[]   = self::row( 'WP_MEMORY_LIMIT', $wp_mem );
		$rows[]   = self::row( 'WP_MAX_MEMORY_LIMIT', $wp_max );
		if ( $mem_b > 0 && $mem_b < 268435456 ) {
			$this->finding(
				'warning',
				'memory_limit נמוך (' . $mem . ')',
				'חנות ווקומרס עם תוספים רבים דורשת 256M לפחות; מתחת לזה מתקבלות שגיאות 500 אקראיות בעגלה/צ׳קאאוט ובייבוא.',
				"להגדיר memory_limit=512M ב-php.ini, וב-wp-config.php:\ndefine('WP_MEMORY_LIMIT','512M');\ndefine('WP_MAX_MEMORY_LIMIT','512M');"
			);
		}

		$max_exec = (int) ini_get( 'max_execution_time' );
		$rows[]   = self::row( 'max_execution_time', $max_exec ? $max_exec . 's' : 'ללא הגבלה', ( 0 === $max_exec || $max_exec >= 60 ) ? 'ok' : 'warning' );
		if ( $max_exec > 0 && $max_exec < 30 ) {
			$this->finding(
				'warning',
				'max_execution_time נמוך מדי (' . $max_exec . 's)',
				'יצירת הזמנה בצ׳קאאוט עם סליקה חיצונית + מיילים + סנכרון מלאי יכולה לחרוג מהזמן ולהשאיר הזמנה במצב pending ללא סיום.',
				'להעלות ל-120 שניות לפחות (max_execution_time ו-max_input_time).'
			);
		}

		$rows[] = self::row( 'post_max_size', ini_get( 'post_max_size' ) );
		$rows[] = self::row( 'upload_max_filesize', ini_get( 'upload_max_filesize' ) );
		$rows[] = self::row( 'max_input_vars', ini_get( 'max_input_vars' ) );

		// הרחבות קריטיות.
		$needed = array( 'curl', 'mbstring', 'json', 'intl', 'zip', 'dom', 'openssl', 'sodium', 'bcmath' );
		$img    = array();
		if ( extension_loaded( 'imagick' ) ) {
			$img[] = 'imagick';
		}
		if ( extension_loaded( 'gd' ) ) {
			$img[] = 'gd';
		}
		$missing = array();
		foreach ( $needed as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing[] = $ext;
			}
		}
		$rows[] = self::row( 'הרחבות תמונה', $img ? implode( ', ', $img ) : 'חסר!', $img ? 'ok' : 'critical' );
		$rows[] = self::row( 'הרחבות חסרות', $missing ? implode( ', ', $missing ) : 'אין', $missing ? 'warning' : 'ok' );
		if ( in_array( 'intl', $missing, true ) ) {
			$this->finding( 'warning', 'הרחבת intl חסרה', 'ווקומרס משתמשת ב-intl לפורמט מטבע/תאריך ולוקליזציה עברית תקינה.', 'להתקין php-intl בשרת.' );
		}
		if ( in_array( 'curl', $missing, true ) ) {
			$this->finding( 'critical', 'הרחבת cURL חסרה', 'בלי cURL אין תקשורת מול שערי סליקה — כל הזמנה תיכשל.', 'להתקין php-curl מיידית.' );
		}

		// OPcache.
		$opcache = 'לא פעיל';
		$op_stat = 'warning';
		if ( function_exists( 'opcache_get_status' ) ) {
			$st = @opcache_get_status( false );
			if ( is_array( $st ) && ! empty( $st['opcache_enabled'] ) ) {
				$hit     = isset( $st['opcache_statistics']['opcache_hit_rate'] ) ? round( $st['opcache_statistics']['opcache_hit_rate'], 1 ) : null;
				$used    = isset( $st['memory_usage']['used_memory'] ) ? self::bytes_to_mb( $st['memory_usage']['used_memory'] ) : null;
				$free    = isset( $st['memory_usage']['free_memory'] ) ? self::bytes_to_mb( $st['memory_usage']['free_memory'] ) : null;
				$opcache = sprintf( 'פעיל | hit rate: %s%% | בשימוש: %sMB | פנוי: %sMB', $hit, $used, $free );
				$op_stat = ( null !== $hit && $hit >= 95 ) ? 'ok' : 'warning';
				if ( null !== $hit && $hit < 90 ) {
					$this->finding( 'warning', 'OPcache hit rate נמוך (' . $hit . '%)', 'סימן שזיכרון ה-OPcache קטן מדי ומתבצע פינוי תכוף — כל בקשה מקמפלת מחדש קוד PHP.', 'להגדיל opcache.memory_consumption ל-256, opcache.max_accelerated_files ל-20000.' );
				}
			}
		}
		$rows[] = self::row( 'OPcache', $opcache, $op_stat );
		if ( 'לא פעיל' === $opcache ) {
			$this->finding( 'critical', 'OPcache כבוי', 'זו ההשפעה הבודדת הגדולה ביותר על TTFB באתר ווקומרס. בלי OPcache כל בקשה מקמפלת אלפי קבצי PHP מחדש.', 'להפעיל: opcache.enable=1, memory_consumption=256, max_accelerated_files=20000, validate_timestamps=1, revalidate_freq=2' );
		}

		// Object cache.
		$oc = 'לא (רק מטמון זיכרון לבקשה בודדת)';
		if ( wp_using_ext_object_cache() ) {
			$backend = 'חיצוני';
			if ( class_exists( 'Redis' ) || defined( 'WP_REDIS_HOST' ) ) {
				$backend = 'Redis';
			} elseif ( class_exists( 'Memcached' ) ) {
				$backend = 'Memcached';
			}
			$oc = 'כן — ' . $backend;
		}
		$rows[] = self::row( 'Persistent Object Cache', $oc, wp_using_ext_object_cache() ? 'ok' : 'warning' );
		if ( ! wp_using_ext_object_cache() ) {
			$this->finding(
				'warning',
				'אין Object Cache מתמיד (Redis/Memcached)',
				'דפי חנות, ארכיוני מוצרים ועגלה מריצים עשרות-מאות שאילתות שחוזרות על עצמן בכל בקשה. עמודים דינמיים (עגלה/צ׳קאאוט/חשבון) לא נהנים ממטמון דפים — רק Object Cache עוזר שם.',
				'להתקין Redis בשרת + תוסף Redis Object Cache. שיפור טיפוסי: 30–60% בזמן תגובה של דפים דינמיים.'
			);
		}

		$rows[] = self::row( 'HTTPS', is_ssl() || 0 === strpos( home_url(), 'https' ) ? 'כן' : 'לא', is_ssl() || 0 === strpos( home_url(), 'https' ) ? 'ok' : 'critical' );
		$rows[] = self::row( 'WP_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG ? 'פעיל' : 'כבוי', defined( 'WP_DEBUG' ) && WP_DEBUG ? 'warning' : 'ok' );
		$rows[] = self::row( 'WP_DEBUG_DISPLAY', defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'פעיל' : 'כבוי', defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'critical' : 'ok' );
		$debug_on      = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$display_errs  = ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY;
		if ( $debug_on && $display_errs ) {
			$this->finding( 'critical', 'WP_DEBUG_DISPLAY פעיל בסביבת ייצור', 'הודעות שגיאה מודפסות ל-HTML. בצ׳קאאוט זה שובר את תגובת ה-AJAX (JSON לא תקין) וגורם ל"שגיאה בביצוע ההזמנה" — סיבה ישירה להזמנות שנתקעות.', "ב-wp-config.php:\ndefine('WP_DEBUG', true);\ndefine('WP_DEBUG_DISPLAY', false);\ndefine('WP_DEBUG_LOG', true);\n@ini_set('display_errors', 0);" );
		}
		$rows[] = self::row( 'SCRIPT_DEBUG', defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? 'פעיל' : 'כבוי' );
		$rows[] = self::row( 'display_errors (PHP)', ini_get( 'display_errors' ) ? 'פעיל' : 'כבוי', ini_get( 'display_errors' ) ? 'critical' : 'ok' );

		$rows[] = self::row( 'זמן שרת (UTC)', gmdate( 'Y-m-d H:i:s' ) );
		$rows[] = self::row( 'אזור זמן באתר', wp_timezone_string() );
		$rows[] = self::row( 'שפת אתר', get_locale() );
		$rows[] = self::row( 'RTL', is_rtl() ? 'כן' : 'לא' );

		$this->section( 'env', '1. סביבת שרת ו-PHP', $rows );
	}

	/* ---------------------------------------------------------------------
	 * 2. תוספים ותבנית
	 * ------------------------------------------------------------------ */

	private function collect_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all      = get_plugins();
		$active   = (array) get_option( 'active_plugins', array() );
		$network  = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$active   = array_unique( array_merge( $active, $network ) );
		$mu       = get_mu_plugins();
		$dropins  = get_dropins();
		$updates  = get_site_transient( 'update_plugins' );
		$pending  = ( $updates && ! empty( $updates->response ) ) ? array_keys( (array) $updates->response ) : array();

		$rows = array();
		$rows[] = self::row( 'תוספים מותקנים', count( $all ) );
		$rows[] = self::row( 'תוספים פעילים', count( $active ), count( $active ) > 30 ? 'warning' : 'ok' );
		$rows[] = self::row( 'MU-plugins', count( $mu ) );
		$rows[] = self::row( 'Drop-ins', $dropins ? implode( ', ', array_keys( $dropins ) ) : 'אין' );
		$rows[] = self::row( 'ממתינים לעדכון', count( $pending ), count( $pending ) ? 'warning' : 'ok' );

		if ( count( $active ) > 30 ) {
			$this->finding(
				'warning',
				'מספר תוספים פעילים גבוה (' . count( $active ) . ')',
				'כל תוסף פעיל נטען בכל בקשה, כולל בקשות AJAX של העגלה והצ׳קאאוט. מעל 30 תוספים זה בדרך כלל המקור המרכזי ל-TTFB איטי.',
				'למפות תוספים לפי תרומה עסקית, לכבות מה שלא בשימוש, ולהשתמש ב-Plugin Organizer / asset-unloading (Perfmatters/Asset CleanUp) כדי לא לטעון תוספים בעמודים שלא צריכים אותם.'
			);
		}
		if ( $pending ) {
			$this->finding( 'warning', count( $pending ) . ' תוספים ממתינים לעדכון', implode( ', ', array_slice( $pending, 0, 20 ) ), 'לעדכן בסביבת staging תחילה, במיוחד את WooCommerce ותוספי הסליקה.' );
		}

		// זיהוי קטגוריות מתנגשות.
		$categories = array(
			'caching'  => array( 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-fastest-cache', 'cache-enabler', 'hummingbird-performance', 'autoptimize', 'swift-performance', 'nitropack', 'wp-optimize', 'sg-cachepress', 'breeze', 'comet-cache', 'flying-press' ),
			'seo'      => array( 'wordpress-seo', 'all-in-one-seo-pack', 'seo-by-rank-math', 'autodescription', 'squirrly-seo', 'slim-seo' ),
			'security' => array( 'wordfence', 'better-wp-security', 'all-in-one-wp-security-and-firewall', 'sucuri-scanner', 'ninjafirewall', 'wp-cerber', 'defender-security' ),
			'builder'  => array( 'elementor', 'js_composer', 'beaver-builder-lite-version', 'brizy', 'oxygen', 'siteorigin-panels', 'divi-builder', 'bricks' ),
			'backup'   => array( 'updraftplus', 'backwpup', 'duplicator', 'all-in-one-wp-migration', 'backupbuddy' ),
		);

		$active_slugs = array();
		foreach ( $active as $file ) {
			$active_slugs[ dirname( $file ) ] = isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $file;
		}

		$found = array();
		foreach ( $categories as $cat => $slugs ) {
			$hits = array();
			foreach ( $slugs as $s ) {
				if ( isset( $active_slugs[ $s ] ) ) {
					$hits[] = $active_slugs[ $s ];
				}
			}
			$found[ $cat ] = $hits;
			$rows[]        = self::row( 'תוספי ' . $cat, $hits ? implode( ', ', $hits ) : 'אין', count( $hits ) > 1 ? 'critical' : 'ok' );
		}

		if ( count( $found['caching'] ) > 1 ) {
			$this->finding(
				'critical',
				'יותר מתוסף מטמון/אופטימיזציה פעיל אחד',
				'פעילים: ' . implode( ', ', $found['caching'] ) . '. שני מנועי מטמון שמאחדים ומדחיסים CSS/JS גורמים לשבירת סקריפטים בצ׳קאאוט, ל-nonce לא תקין ולהזמנות שנכשלות — וגם מאטים במקום להאיץ.',
				'להשאיר תוסף מטמון אחד בלבד. כלי אופטימיזציית נכסים (Autoptimize/Perfmatters) יכולים לחיות לצד תוסף מטמון — אבל לא שני מנועי מטמון דפים.'
			);
		}
		if ( count( $found['seo'] ) > 1 ) {
			$this->finding( 'warning', 'יותר מתוסף SEO אחד פעיל', implode( ', ', $found['seo'] ), 'להשאיר אחד; שניים יוצרים תגיות meta ו-schema כפולות שפוגעות באינדוקס.' );
		}
		if ( count( $found['security'] ) > 1 ) {
			$this->finding( 'warning', 'יותר מתוסף אבטחה אחד פעיל', implode( ', ', $found['security'] ), 'חומות אש כפולות חוסמות זו את זו וגם עלולות לחסום קריאות callback משערי סליקה.' );
		}
		if ( $found['security'] ) {
			$this->finding(
				'notice',
				'תוסף אבטחה/חומת אש פעיל: ' . implode( ', ', $found['security'] ),
				'זה חשוד מספר 1 לחסימת קריאות server-to-server (IPN/webhook/callback) של שער הסליקה. כשה-callback נחסם — הלקוח משלם, אבל ההזמנה נשארת "ממתינה לתשלום".',
				'לבדוק ביומן החסימות של התוסף אם יש חסימות מכתובות ה-IP של חברת הסליקה, ולהוסיף אותן ל-allowlist. ראה docs/02-incomplete-orders.md.'
			);
		}

		// תבנית.
		$theme  = wp_get_theme();
		$parent = $theme->parent();
		$rows[] = self::row( 'תבנית פעילה', $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) );
		$rows[] = self::row( 'תבנית אב', $parent ? $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) : 'אין (לא תבנית בת)', $parent ? 'ok' : 'warning' );
		if ( ! $parent ) {
			$this->finding( 'notice', 'האתר לא משתמש בתבנית בת (child theme)', 'אם בוצעו שינויים ישירות בתבנית האב, כל עדכון תבנית ימחק אותם.', 'ליצור child theme ולהעביר אליו התאמות אישיות.' );
		}

		// דריסות תבנית של ווקומרס.
		if ( $this->wc_active() ) {
			$overrides = $this->scan_wc_template_overrides();
			$rows[]    = self::row( 'דריסות תבניות WooCommerce', count( $overrides['all'] ) );
			$rows[]    = self::row( 'דריסות מיושנות', count( $overrides['outdated'] ), $overrides['outdated'] ? 'critical' : 'ok' );
			if ( $overrides['outdated'] ) {
				$list = array();
				foreach ( array_slice( $overrides['outdated'], 0, 15 ) as $o ) {
					$list[] = sprintf( '%s (תבנית: %s / ליבה: %s)', $o['file'], $o['theme_version'], $o['core_version'] );
				}
				$this->finding(
					'critical',
					count( $overrides['outdated'] ) . ' תבניות WooCommerce מיושנות בתבנית',
					"קבצי תבנית שנדרסו ולא עודכנו מול הליבה. אם אחד מהם הוא form-checkout.php / payment.php / review-order.php — זו סיבה ישירה להזמנות שלא נסגרות: שדות או hooks חדשים חסרים.\n" . implode( "\n", $list ),
					'להשוות כל קובץ מול הגרסה ב-woocommerce/templates ולמזג את השינויים החדשים. לתת עדיפות לקבצים תחת templates/checkout/ ו-templates/cart/.'
				);
			}
		}

		// טבלת תוספים פעילים.
		$plugin_rows = array();
		foreach ( $active as $file ) {
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$plugin_rows[] = array(
				'שם'     => $all[ $file ]['Name'],
				'גרסה'   => $all[ $file ]['Version'],
				'עדכון'  => in_array( $file, $pending, true ) ? 'ממתין' : '—',
				'נתיב'   => $file,
			);
		}
		usort(
			$plugin_rows,
			static function ( $a, $b ) {
				return strcasecmp( $a['שם'], $b['שם'] );
			}
		);

		$this->section( 'plugins', '2. תוספים ותבנית', $rows, array( 'תוספים פעילים' => $plugin_rows ) );
	}

	/**
	 * סורק דריסות תבנית של ווקומרס ומשווה גרסאות.
	 */
	private function scan_wc_template_overrides() {
		$result = array(
			'all'      => array(),
			'outdated' => array(),
		);

		if ( ! defined( 'WC_ABSPATH' ) ) {
			return $result;
		}

		$core_dir = WC_ABSPATH . 'templates/';
		$dirs     = array( get_stylesheet_directory(), get_template_directory() );
		$dirs     = array_unique( $dirs );

		foreach ( $dirs as $dir ) {
			$base = trailingslashit( $dir ) . 'woocommerce';
			if ( ! is_dir( $base ) ) {
				continue;
			}
			try {
				$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			} catch ( Exception $e ) {
				continue;
			}
			$count = 0;
			foreach ( $it as $file ) {
				if ( ++$count > 500 ) {
					break;
				}
				if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}
				$rel  = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $base ) + 1 ) );
				$core = $core_dir . $rel;
				if ( ! file_exists( $core ) ) {
					continue;
				}
				$theme_v = $this->template_version( $file->getPathname() );
				$core_v  = $this->template_version( $core );
				$result['all'][] = $rel;
				if ( $theme_v && $core_v && version_compare( $theme_v, $core_v, '<' ) ) {
					$result['outdated'][] = array(
						'file'          => $rel,
						'theme_version' => $theme_v,
						'core_version'  => $core_v,
					);
				}
			}
		}

		return $result;
	}

	private function template_version( $path ) {
		$fp = @fopen( $path, 'r' );
		if ( ! $fp ) {
			return null;
		}
		$head = fread( $fp, 2048 );
		fclose( $fp );
		if ( preg_match( '/@version\s+([\d.]+)/', (string) $head, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * 3. תצורת WooCommerce
	 * ------------------------------------------------------------------ */

	private function collect_woocommerce() {
		if ( ! $this->wc_active() ) {
			$this->section( 'woo', '3. תצורת WooCommerce', array( self::row( 'סטטוס', 'WooCommerce לא פעיל', 'critical' ) ) );
			return;
		}

		$schema = $this->orders_schema();
		$rows   = array();

		$rows[] = self::row( 'HPOS (אחסון הזמנות מהיר)', $schema['hpos'] ? 'פעיל' : 'כבוי (posts מסורתי)', $schema['hpos'] ? 'ok' : 'notice' );
		if ( ! $schema['hpos'] ) {
			$this->finding(
				'notice',
				'HPOS כבוי — הזמנות נשמרות בטבלת posts',
				'בטבלת posts כל הזמנה מפוזרת על עשרות שורות ב-postmeta. מעל ~20K הזמנות, מסכי ההזמנות בניהול והחיפוש נהיים איטיים מאוד.',
				'WooCommerce → הגדרות → מתקדם → Features → הפעלת High-Performance Order Storage, לאחר בדיקת תאימות של כל תוספי ההזמנות/סליקה.'
			);
		}

		// עמודי חנות.
		$pages = array(
			'עמוד חנות'  => wc_get_page_id( 'shop' ),
			'עמוד עגלה'  => wc_get_page_id( 'cart' ),
			'עמוד תשלום' => wc_get_page_id( 'checkout' ),
			'החשבון שלי' => wc_get_page_id( 'myaccount' ),
			'תנאים'      => wc_get_page_id( 'terms' ),
		);
		foreach ( $pages as $label => $pid ) {
			$ok     = $pid > 0 && 'publish' === get_post_status( $pid );
			$rows[] = self::row( $label, $pid > 0 ? get_the_title( $pid ) . ' (#' . $pid . ', ' . get_post_status( $pid ) . ')' : 'לא מוגדר', $ok ? 'ok' : ( 'תנאים' === $label ? 'notice' : 'critical' ) );
			if ( ! $ok && 'תנאים' !== $label ) {
				$this->finding( 'critical', 'עמוד ' . $label . ' לא מוגדר או לא מפורסם', 'ווקומרס לא יכולה להפנות לקוחות לשלב הבא בתהליך הרכישה — גורם ישיר לנטישה והזמנות תקועות.', 'WooCommerce → הגדרות → מתקדם → הגדרת העמוד הנכון.' );
			}
		}

		// סוג צ׳קאאוט.
		$checkout_id = wc_get_page_id( 'checkout' );
		$checkout_kind = 'לא ידוע';
		if ( $checkout_id > 0 ) {
			$content = (string) get_post_field( 'post_content', $checkout_id );
			if ( false !== strpos( $content, 'woocommerce/checkout' ) ) {
				$checkout_kind = 'Checkout Block (Blocks)';
			} elseif ( false !== strpos( $content, '[woocommerce_checkout]' ) ) {
				$checkout_kind = 'Shortcode קלאסי';
			} elseif ( '' === trim( $content ) ) {
				$checkout_kind = 'ריק — כנראה נבנה ע"י התבנית/בונה עמודים';
			} else {
				$checkout_kind = 'תוכן מותאם (בונה עמודים?)';
			}
		}
		$rows[] = self::row( 'סוג עמוד תשלום', $checkout_kind, false !== strpos( $checkout_kind, 'בונה' ) ? 'warning' : 'ok' );
		if ( 'Checkout Block (Blocks)' === $checkout_kind ) {
			$this->finding(
				'notice',
				'הצ׳קאאוט הוא Checkout Block',
				'צ׳קאאוט בלוקים יוצר הזמנות במצב "checkout-draft" כבר בכניסה לעמוד, לפני כל ניסיון תשלום. אלו יופיעו כ"הזמנות לא מושלמות" אם סופרים את כל הסטטוסים — אך זו התנהגות תקינה, לא באג.',
				'לוודא שסופרים רק pending/failed/on-hold כ"בעייתיות", ולנקות checkout-draft ישנים אוטומטית.'
			);
		}

		// מלאי.
		$manage_stock = get_option( 'woocommerce_manage_stock' );
		$hold_minutes = get_option( 'woocommerce_hold_stock_minutes' );
		$rows[]       = self::row( 'ניהול מלאי', 'yes' === $manage_stock ? 'פעיל' : 'כבוי' );
		$rows[]       = self::row( 'שמירת מלאי (דקות)', '' === $hold_minutes || null === $hold_minutes ? 'כבוי' : $hold_minutes );
		if ( 'yes' === $manage_stock && ( '' === $hold_minutes || null === $hold_minutes || 0 === (int) $hold_minutes ) ) {
			$this->finding(
				'warning',
				'"שמירת מלאי (דקות)" כבוי בזמן שניהול מלאי פעיל',
				'הזמנות pending לא מבוטלות אוטומטית לעולם. הן נערמות לנצח וגם תופסות מלאי אם תוסף כלשהו מפחית מלאי בעת יצירת הזמנה.',
				'להגדיר 60 דקות (WooCommerce → הגדרות → מוצרים → מלאי). זה מפעיל את משימת woocommerce_cancel_unpaid_orders שמנקה הזמנות שלא שולמו.'
			);
		}

		$rows[] = self::row( 'מטבע', get_woocommerce_currency() . ' (' . get_woocommerce_currency_symbol() . ')' );
		$rows[] = self::row( 'מיסים פעילים', 'yes' === get_option( 'woocommerce_calc_taxes' ) ? 'כן' : 'לא' );
		$rows[] = self::row( 'רכישה כאורח', 'yes' === get_option( 'woocommerce_enable_guest_checkout' ) ? 'מותרת' : 'חסומה', 'yes' === get_option( 'woocommerce_enable_guest_checkout' ) ? 'ok' : 'warning' );
		if ( 'yes' !== get_option( 'woocommerce_enable_guest_checkout' ) ) {
			$this->finding( 'warning', 'רכישה כאורח חסומה', 'חיוב הרשמה לפני רכישה מוריד המרה בשיעור דו-ספרתי ומגדיל נטישה בצ׳קאאוט.', 'WooCommerce → הגדרות → חשבונות ופרטיות → לאפשר רכישה ללא חשבון.' );
		}
		$rows[] = self::row( 'קופונים פעילים', 'yes' === get_option( 'woocommerce_enable_coupons' ) ? 'כן' : 'לא' );
		$rows[] = self::row( 'AJAX add-to-cart בארכיונים', 'yes' === get_option( 'woocommerce_enable_ajax_add_to_cart' ) ? 'כן' : 'לא' );
		$rows[] = self::row( 'Cart Fragments', 'yes' === get_option( 'woocommerce_enable_ajax_add_to_cart' ) ? 'כן (נטען)' : 'תלוי תבנית', 'notice' );

		// שערי תשלום.
		$gateway_rows = array();
		$enabled      = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gw ) {
				$is_on = ( 'yes' === $gw->enabled );
				if ( $is_on ) {
					$enabled[] = $gw->id;
				}
				$gateway_rows[] = array(
					'מזהה'   => $gw->id,
					'שם'     => $gw->get_title(),
					'פעיל'   => $is_on ? 'כן' : 'לא',
					'סוג'    => method_exists( $gw, 'get_option' ) && $gw->get_option( 'testmode' ) === 'yes' ? 'TEST MODE' : 'ייצור',
				);
				if ( $is_on && method_exists( $gw, 'get_option' ) && 'yes' === $gw->get_option( 'testmode' ) ) {
					$this->finding( 'critical', 'שער תשלום פעיל במצב בדיקה: ' . $gw->get_title(), 'תשלומים אמיתיים לא ייסלקו. הזמנות ייווצרו ויישארו ללא תשלום.', 'לכבות Test/Sandbox mode בהגדרות השער.' );
				}
			}
		}
		$rows[] = self::row( 'שערי תשלום פעילים', $enabled ? implode( ', ', $enabled ) : 'אין!', $enabled ? 'ok' : 'critical' );
		if ( ! $enabled ) {
			$this->finding( 'critical', 'אין אף שער תשלום פעיל', 'אי אפשר להשלים אף הזמנה.', 'WooCommerce → הגדרות → תשלומים → להפעיל שער.' );
		}

		// שיטות משלוח.
		$zones      = class_exists( 'WC_Shipping_Zones' ) ? WC_Shipping_Zones::get_zones() : array();
		$zone_count = count( $zones );
		$rows[]     = self::row( 'אזורי משלוח מוגדרים', $zone_count, $zone_count ? 'ok' : 'warning' );
		$rest_of_world = class_exists( 'WC_Shipping_Zones' ) ? WC_Shipping_Zones::get_zone( 0 ) : null;
		$row_methods   = $rest_of_world ? count( $rest_of_world->get_shipping_methods( true ) ) : 0;
		$rows[]        = self::row( 'שיטות באזור "שאר העולם"', $row_methods );
		if ( 0 === $zone_count ) {
			$this->finding(
				'critical',
				'לא הוגדרו אזורי משלוח',
				'לקוח שמגיע לצ׳קאאוט ואין שיטת משלוח זמינה לכתובת שלו — כפתור התשלום לא יעבוד. זו אחת הסיבות השכיחות ביותר להזמנות שנתקעות ולנטישה בצ׳קאאוט.',
				'WooCommerce → הגדרות → משלוחים → להגדיר אזור לישראל עם לפחות שיטה אחת, ולוודא שיש fallback ל"שאר העולם".'
			);
		}

		$this->section( 'woo', '3. תצורת WooCommerce', $rows, array( 'שערי תשלום' => $gateway_rows ) );
	}

	/* ---------------------------------------------------------------------
	 * 4. ניתוח הזמנות — לב הבדיקה
	 * ------------------------------------------------------------------ */

	private function collect_orders() {
		global $wpdb;

		if ( ! $this->wc_active() ) {
			return;
		}

		$s     = $this->orders_schema();
		$table = $s['table'];
		$rows  = array();
		$tables = array();

		if ( ! $this->table_exists( $table ) ) {
			$this->section( 'orders', '4. ניתוח הזמנות', array( self::row( 'שגיאה', 'טבלת הזמנות לא נמצאה: ' . $table, 'critical' ) ) );
			return;
		}

		// --- פילוח סטטוסים כולל ---
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- שמות טבלאות/עמודות נגזרים ממבנה ידוע.
		$all_statuses = $wpdb->get_results(
			"SELECT {$s['status']} AS status, COUNT(*) AS c
			 FROM {$table}
			 WHERE {$s['type_where']}
			 GROUP BY {$s['status']}
			 ORDER BY c DESC",
			ARRAY_A
		);

		$labels    = wc_get_order_statuses();
		$total     = 0;
		$by_status = array();
		foreach ( (array) $all_statuses as $r ) {
			$total += (int) $r['c'];
			$by_status[ $r['status'] ] = (int) $r['c'];
		}

		$status_rows = array();
		foreach ( (array) $all_statuses as $r ) {
			$key = $r['status'];
			$status_rows[] = array(
				'סטטוס'  => isset( $labels[ $key ] ) ? $labels[ $key ] . ' (' . $key . ')' : $key,
				'כמות'   => (int) $r['c'],
				'אחוז'   => $total ? round( 100 * $r['c'] / $total, 1 ) . '%' : '0%',
			);
		}
		$tables['פילוח כל ההזמנות לפי סטטוס'] = $status_rows;

		$get = static function ( $k ) use ( $by_status ) {
			return isset( $by_status[ $k ] ) ? $by_status[ $k ] : 0;
		};

		$pending   = $get( 'wc-pending' );
		$failed    = $get( 'wc-failed' );
		$onhold    = $get( 'wc-on-hold' );
		$cancelled = $get( 'wc-cancelled' );
		$draft     = $get( 'wc-checkout-draft' );
		$good      = $get( 'wc-processing' ) + $get( 'wc-completed' );
		$incomplete = $pending + $failed;
		$decided    = $incomplete + $good;

		$rows[] = self::row( 'סה"כ הזמנות', number_format_i18n( $total ) );
		$rows[] = self::row( 'הושלמו + בטיפול', number_format_i18n( $good ) );
		$rows[] = self::row( 'ממתינות לתשלום (pending)', number_format_i18n( $pending ), $pending ? 'warning' : 'ok' );
		$rows[] = self::row( 'נכשלו (failed)', number_format_i18n( $failed ), $failed ? 'critical' : 'ok' );
		$rows[] = self::row( 'בהמתנה (on-hold)', number_format_i18n( $onhold ) );
		$rows[] = self::row( 'בוטלו (cancelled)', number_format_i18n( $cancelled ) );
		$rows[] = self::row( 'טיוטות צ׳קאאוט (checkout-draft)', number_format_i18n( $draft ), $draft > 500 ? 'warning' : 'notice' );

		$fail_rate = $decided ? round( 100 * $incomplete / $decided, 1 ) : 0;
		$rows[]    = self::row( 'שיעור הזמנות לא מושלמות', $fail_rate . '%', $fail_rate >= 30 ? 'critical' : ( $fail_rate >= 15 ? 'warning' : 'ok' ) );

		if ( $fail_rate >= 15 ) {
			$this->finding(
				$fail_rate >= 30 ? 'critical' : 'warning',
				'שיעור הזמנות לא מושלמות: ' . $fail_rate . '%',
				sprintf(
					'%s הזמנות pending/failed מול %s הזמנות שהצליחו. בחנות תקינה עם סליקה מקומית שיעור ה-pending+failed נע סביב 8–15%%. מעל 25%% זו כמעט תמיד תקלה טכנית ולא נטישה טבעית של לקוחות.',
					number_format_i18n( $incomplete ),
					number_format_i18n( $good )
				),
				'לעבור על docs/02-incomplete-orders.md לפי הסדר. החשוד הראשון: קריאת ה-callback משער הסליקה לא מגיעה לאתר.'
			);
		}

		if ( $draft > 500 ) {
			$this->finding(
				'notice',
				number_format_i18n( $draft ) . ' הזמנות במצב checkout-draft',
				'אלו הזמנות שנוצרו אוטומטית ע"י צ׳קאאוט הבלוקים ולא מייצגות כשל. הן מנפחות את טבלת ההזמנות ומאטות את מסך הניהול.',
				"לוודא שהמשימה woocommerce_cleanup_draft_orders רצה. ניקוי ידני:\nwp wc order list --status=checkout-draft --field=id | xargs -n1 wp post delete --force"
			);
		}

		// --- מגמה חודשית: 6 חודשים אחרונים ---
		$trend = $wpdb->get_results(
			"SELECT DATE_FORMAT({$s['date']}, '%Y-%m') AS ym,
			        SUM({$s['status']} IN ('wc-processing','wc-completed')) AS ok_cnt,
			        SUM({$s['status']} = 'wc-pending') AS pending_cnt,
			        SUM({$s['status']} = 'wc-failed')  AS failed_cnt,
			        COUNT(*) AS total_cnt
			 FROM {$table}
			 WHERE {$s['type_where']}
			   AND {$s['status']} <> 'wc-checkout-draft'
			   AND {$s['date']} >= DATE_SUB(UTC_DATE(), INTERVAL 6 MONTH)
			 GROUP BY ym
			 ORDER BY ym DESC",
			ARRAY_A
		);

		$trend_rows = array();
		foreach ( (array) $trend as $t ) {
			$dec = (int) $t['ok_cnt'] + (int) $t['pending_cnt'] + (int) $t['failed_cnt'];
			$trend_rows[] = array(
				'חודש'                => $t['ym'],
				'הצליחו'              => (int) $t['ok_cnt'],
				'ממתינות'             => (int) $t['pending_cnt'],
				'נכשלו'               => (int) $t['failed_cnt'],
				'% לא מושלמות'        => $dec ? round( 100 * ( (int) $t['pending_cnt'] + (int) $t['failed_cnt'] ) / $dec, 1 ) . '%' : '—',
			);
		}
		$tables['מגמה חודשית (6 חודשים)'] = $trend_rows;

		// זיהוי נקודת שבירה: קפיצה חדה בין חודשים.
		if ( count( $trend_rows ) >= 3 ) {
			$rates = array();
			foreach ( $trend_rows as $tr ) {
				$rates[ $tr['חודש'] ] = (float) rtrim( $tr['% לא מושלמות'], '%' );
			}
			$keys = array_keys( $rates ); // מהחדש לישן.
			for ( $i = 0; $i < count( $keys ) - 1; $i++ ) {
				$now  = $rates[ $keys[ $i ] ];
				$prev = $rates[ $keys[ $i + 1 ] ];
				if ( $prev > 0 && $now - $prev >= 15 ) {
					$this->finding(
						'critical',
						'קפיצה חדה בכשלי הזמנות בחודש ' . $keys[ $i ],
						sprintf( 'שיעור ההזמנות הלא מושלמות עלה מ-%s%% ב-%s ל-%s%% ב-%s. קפיצה כזו מצביעה על שינוי נקודתי: עדכון תוסף/ווקומרס/PHP, החלפת שער סליקה, או שינוי הגדרות אבטחה/CDN.', $prev, $keys[ $i + 1 ], $now, $keys[ $i ] ),
						'לבדוק מה השתנה בתאריך הזה: יומן עדכוני תוספים, לוגי השרת, ותאריך פקיעת תעודות/מפתחות API של שער הסליקה.'
					);
					break;
				}
			}
		}

		// --- פילוח לפי שער תשלום ---
		if ( $s['hpos'] ) {
			$gw = $wpdb->get_results(
				"SELECT COALESCE(NULLIF({$s['gateway']},''),'(ריק)') AS gateway,
				        SUM({$s['status']} IN ('wc-processing','wc-completed')) AS ok_cnt,
				        SUM({$s['status']} = 'wc-pending') AS pending_cnt,
				        SUM({$s['status']} = 'wc-failed')  AS failed_cnt,
				        COUNT(*) AS total_cnt
				 FROM {$table}
				 WHERE {$s['type_where']}
				   AND {$s['status']} <> 'wc-checkout-draft'
				   AND {$s['date']} >= DATE_SUB(UTC_DATE(), INTERVAL 6 MONTH)
				 GROUP BY gateway
				 ORDER BY total_cnt DESC",
				ARRAY_A
			);
		} else {
			$gw = $wpdb->get_results(
				"SELECT COALESCE(NULLIF(pm.meta_value,''),'(ריק)') AS gateway,
				        SUM(p.post_status IN ('wc-processing','wc-completed')) AS ok_cnt,
				        SUM(p.post_status = 'wc-pending') AS pending_cnt,
				        SUM(p.post_status = 'wc-failed')  AS failed_cnt,
				        COUNT(*) AS total_cnt
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm
				        ON pm.post_id = p.ID AND pm.meta_key = '_payment_method'
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status <> 'wc-checkout-draft'
				   AND p.post_date_gmt >= DATE_SUB(UTC_DATE(), INTERVAL 6 MONTH)
				 GROUP BY gateway
				 ORDER BY total_cnt DESC",
				ARRAY_A
			);
		}

		$gw_rows = array();
		foreach ( (array) $gw as $g ) {
			$dec  = (int) $g['ok_cnt'] + (int) $g['pending_cnt'] + (int) $g['failed_cnt'];
			$rate = $dec ? round( 100 * ( (int) $g['pending_cnt'] + (int) $g['failed_cnt'] ) / $dec, 1 ) : 0;
			$gw_rows[] = array(
				'שער תשלום'    => $g['gateway'],
				'הצליחו'       => (int) $g['ok_cnt'],
				'ממתינות'      => (int) $g['pending_cnt'],
				'נכשלו'        => (int) $g['failed_cnt'],
				'% לא מושלמות' => $rate . '%',
			);
			if ( $dec >= 20 && $rate >= 40 ) {
				$this->finding(
					'critical',
					'שער התשלום "' . $g['gateway'] . '" עם ' . $rate . '% הזמנות לא מושלמות',
					sprintf( '%d הצליחו מול %d ממתינות ו-%d נכשלו ב-6 החודשים האחרונים. שיעור כזה בשער בודד מבודד את התקלה לשער עצמו — לא לצ׳קאאוט הכללי.', (int) $g['ok_cnt'], (int) $g['pending_cnt'], (int) $g['failed_cnt'] ),
					'לבדוק: (1) כתובת ה-callback/IPN המוגדרת אצל ספק הסליקה מול הכתובת שהתוסף מצפה לה; (2) האם קריאות ה-callback נחסמות ע"י חומת אש/CDN; (3) תוקף מפתחות API ותעודת ה-SSL; (4) לוגי השער תחת WooCommerce → סטטוס → יומנים.'
				);
			}
		}
		$tables['ביצועים לפי שער תשלום (6 חודשים)'] = $gw_rows;

		// --- הזמנות שנתקעו ---
		$stuck_24h = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE {$s['type_where']}
			   AND {$s['status']} = 'wc-pending'
			   AND {$s['date']} < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
		);
		$stuck_7d = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE {$s['type_where']}
			   AND {$s['status']} = 'wc-pending'
			   AND {$s['date']} < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
		);
		$rows[] = self::row( 'pending מעל 24 שעות', number_format_i18n( $stuck_24h ), $stuck_24h > 20 ? 'warning' : 'ok' );
		$rows[] = self::row( 'pending מעל 7 ימים', number_format_i18n( $stuck_7d ), $stuck_7d > 20 ? 'critical' : 'ok' );

		if ( $stuck_7d > 20 ) {
			$this->finding(
				'critical',
				number_format_i18n( $stuck_7d ) . ' הזמנות תקועות ב-pending מעל שבוע',
				'הזמנה שנשארת pending מעל שבוע לא תשולם לעולם. אם המשימה woocommerce_cancel_unpaid_orders הייתה רצה, הן היו עוברות ל-cancelled אחרי שעה.',
				'לוודא ש-WP-Cron ו-Action Scheduler עובדים (מקטע 5 בדוח), ולהגדיר "שמירת מלאי (דקות)" = 60.'
			);
		}

		// --- 30 ההזמנות הלא-מושלמות האחרונות (ללא PII) ---
		if ( $s['hpos'] ) {
			$recent = $wpdb->get_results(
				"SELECT {$s['id']} AS id, {$s['status']} AS status, {$s['date']} AS created,
				        {$s['gateway']} AS gateway, {$s['total']} AS total
				 FROM {$table}
				 WHERE {$s['type_where']}
				   AND {$s['status']} IN ('wc-pending','wc-failed')
				 ORDER BY {$s['date']} DESC
				 LIMIT 30",
				ARRAY_A
			);
		} else {
			$recent = $wpdb->get_results(
				"SELECT p.ID AS id, p.post_status AS status, p.post_date_gmt AS created,
				        pm.meta_value AS gateway, pm2.meta_value AS total
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm  ON pm.post_id = p.ID  AND pm.meta_key = '_payment_method'
				 LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-pending','wc-failed')
				 ORDER BY p.post_date_gmt DESC
				 LIMIT 30",
				ARRAY_A
			);
		}
		// phpcs:enable

		$recent_rows = array();
		foreach ( (array) $recent as $o ) {
			$recent_rows[] = array(
				'הזמנה'  => '#' . $o['id'],
				'סטטוס'  => $o['status'],
				'תאריך'  => $o['created'],
				'שער'    => $o['gateway'] ? $o['gateway'] : '(ריק)',
				'סכום'   => $o['total'],
			);
		}
		$tables['30 ההזמנות הלא-מושלמות האחרונות'] = $recent_rows;

		// הזמנות ללא שער תשלום כלל — סימן שהלקוח לא הגיע לשלב התשלום.
		$no_gw = 0;
		foreach ( (array) $gw as $g ) {
			if ( '(ריק)' === $g['gateway'] ) {
				$no_gw = (int) $g['total_cnt'];
			}
		}
		if ( $no_gw > 10 ) {
			$this->finding(
				'warning',
				number_format_i18n( $no_gw ) . ' הזמנות ללא שער תשלום מוגדר',
				'הזמנה נוצרה בלי ש-payment_method נשמר. זה קורה כשהצ׳קאאוט נכשל אחרי יצירת ההזמנה ולפני בחירת/הפעלת השער — למשל שגיאת JS, timeout, או nonce לא תקין בגלל מטמון על עמוד התשלום.',
				'להתקין את tools/nama-checkout-logger.php כדי לתפוס את השגיאה המדויקת ברגע אמת, ולוודא שעמוד התשלום מוחרג ממטמון (מקטע 6).'
			);
		}

		$this->section( 'orders', '4. ניתוח הזמנות (ליבת בעיית ההזמנות הלא מושלמות)', $rows, $tables );
	}

	/* ---------------------------------------------------------------------
	 * 5. Cron ו-Action Scheduler
	 * ------------------------------------------------------------------ */

	private function collect_cron() {
		global $wpdb;

		$rows   = array();
		$tables = array();

		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$rows[]   = self::row( 'DISABLE_WP_CRON', $disabled ? 'true (קרון פנימי כבוי)' : 'false' );
		$alt      = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
		$rows[]   = self::row( 'ALTERNATE_WP_CRON', $alt ? 'true' : 'false' );

		$crons   = _get_cron_array();
		$now     = time();
		$overdue = 0;
		$total   = 0;
		$oldest  = null;
		if ( is_array( $crons ) ) {
			foreach ( $crons as $ts => $hooks ) {
				foreach ( (array) $hooks as $hook => $events ) {
					$total += count( (array) $events );
					if ( $ts < $now - 300 ) {
						$overdue += count( (array) $events );
						if ( null === $oldest || $ts < $oldest ) {
							$oldest = $ts;
						}
					}
				}
			}
		}
		$rows[] = self::row( 'משימות cron מתוזמנות', $total );
		$rows[] = self::row( 'משימות באיחור (מעל 5 דק׳)', $overdue, $overdue > 5 ? 'critical' : ( $overdue ? 'warning' : 'ok' ) );
		if ( $oldest ) {
			$rows[] = self::row( 'המשימה הישנה ביותר באיחור', gmdate( 'Y-m-d H:i:s', $oldest ) . ' UTC (' . human_time_diff( $oldest, $now ) . ' באיחור)', 'critical' );
		}

		if ( $overdue > 5 ) {
			$this->finding(
				'critical',
				$overdue . ' משימות cron תקועות',
				"WP-Cron לא רץ. זה שובר ישירות: ביטול אוטומטי של הזמנות שלא שולמו, שליחת מיילים מושהים, סנכרון מלאי, עדכון סטטוסים משערי סליקה אסינכרוניים, וניקוי סשנים. זו סיבה מרכזית להצטברות הזמנות pending.",
				"להעביר לקרון אמיתי בשרת:\n1. ב-wp-config.php: define('DISABLE_WP_CRON', true);\n2. ב-crontab (כל 5 דקות):\n   */5 * * * * cd /path/to/site && /usr/bin/php wp-cron.php >/dev/null 2>&1\n   או: */5 * * * * cd /path/to/site && wp cron event run --due-now --quiet"
			);
		}

		// woocommerce_cancel_unpaid_orders
		$next_cancel = wp_next_scheduled( 'woocommerce_cancel_unpaid_orders' );
		$rows[]      = self::row( 'woocommerce_cancel_unpaid_orders', $next_cancel ? gmdate( 'Y-m-d H:i:s', $next_cancel ) . ' UTC' : 'לא מתוזמן', $next_cancel ? 'ok' : 'warning' );

		// Action Scheduler.
		$as_table = $wpdb->prefix . 'actionscheduler_actions';
		if ( $this->table_exists( $as_table ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$as_status = $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$as_table} GROUP BY status", ARRAY_A );
			$as_map    = array();
			foreach ( (array) $as_status as $r ) {
				$as_map[ $r['status'] ] = (int) $r['c'];
			}
			$as_pending  = isset( $as_map['pending'] ) ? $as_map['pending'] : 0;
			$as_failed   = isset( $as_map['failed'] ) ? $as_map['failed'] : 0;
			$as_progress = isset( $as_map['in-progress'] ) ? $as_map['in-progress'] : 0;
			$as_complete = isset( $as_map['complete'] ) ? $as_map['complete'] : 0;

			$rows[] = self::row( 'Action Scheduler — ממתינות', number_format_i18n( $as_pending ), $as_pending > 2000 ? 'critical' : ( $as_pending > 300 ? 'warning' : 'ok' ) );
			$rows[] = self::row( 'Action Scheduler — נכשלו', number_format_i18n( $as_failed ), $as_failed > 50 ? 'critical' : ( $as_failed ? 'warning' : 'ok' ) );
			$rows[] = self::row( 'Action Scheduler — בביצוע', number_format_i18n( $as_progress ), $as_progress > 20 ? 'warning' : 'ok' );
			$rows[] = self::row( 'Action Scheduler — הושלמו', number_format_i18n( $as_complete ), $as_complete > 100000 ? 'warning' : 'ok' );

			$oldest_pending = $wpdb->get_var( "SELECT MIN(scheduled_date_gmt) FROM {$as_table} WHERE status='pending' AND scheduled_date_gmt > '0000-00-00 00:00:00'" );
			if ( $oldest_pending ) {
				$age = $now - strtotime( $oldest_pending . ' UTC' );
				$rows[] = self::row( 'הפעולה הממתינה הישנה ביותר', $oldest_pending . ' UTC', $age > 3600 ? 'critical' : 'ok' );
				if ( $age > 3600 ) {
					$this->finding(
						'critical',
						'Action Scheduler תקוע — פעולה ממתינה מ-' . $oldest_pending,
						'תור הפעולות של ווקומרס לא מתקדם. עדכוני סטטוס הזמנות שמגיעים אסינכרונית משערי סליקה, שליחת מיילים ועדכוני מלאי — כולם נתקעים בתור. הזמנה ששולמה בפועל תישאר "ממתינה לתשלום".',
						"להריץ ידנית ולראות אם התור מתקדם:\nwp action-scheduler run --batch-size=50\nאם לא — לבדוק שהקרון בשרת רץ (ראה למעלה) ושאין פעולה תקועה ב-in-progress שחוסמת את התור."
					);
				}
			}

			if ( $as_failed > 50 ) {
				$failed_hooks = $wpdb->get_results( "SELECT hook, COUNT(*) c FROM {$as_table} WHERE status='failed' GROUP BY hook ORDER BY c DESC LIMIT 15", ARRAY_A );
				$frows        = array();
				foreach ( (array) $failed_hooks as $f ) {
					$frows[] = array(
						'hook' => $f['hook'],
						'כשלים' => (int) $f['c'],
					);
				}
				$tables['Action Scheduler — hooks שנכשלו'] = $frows;
				$this->finding(
					'critical',
					number_format_i18n( $as_failed ) . ' פעולות Action Scheduler נכשלו',
					'ראה טבלת ה-hooks. אם מופיע hook של שער סליקה או של עדכון הזמנה — זו הסיבה הישירה להזמנות שלא מתעדכנות.',
					"לבדוק את פירוט הכשל: WooCommerce → סטטוס → Scheduled Actions → Failed.\nלרוב הסיבה היא timeout או memory limit — להעלות max_execution_time ו-memory_limit."
				);
			}

			if ( $as_complete > 100000 ) {
				$this->finding(
					'warning',
					'טבלת Action Scheduler מנופחת (' . number_format_i18n( $as_complete ) . ' פעולות שהושלמו)',
					'טבלה ענקית מאטה כל בקשה שנוגעת בתור, כולל צ׳קאאוט.',
					"לנקות:\nwp action-scheduler clean --batch-size=1000 --before='1 month ago'"
				);
			}
			// phpcs:enable
		} else {
			$rows[] = self::row( 'Action Scheduler', 'טבלה לא נמצאה', 'warning' );
		}

		// סשנים של ווקומרס.
		$sess_table = $wpdb->prefix . 'woocommerce_sessions';
		if ( $this->table_exists( $sess_table ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$sess_total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess_table}" );
			$sess_expired = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sess_table} WHERE session_expiry < %d", $now ) );
			// phpcs:enable
			$rows[] = self::row( 'סשנים פעילים (WooCommerce)', number_format_i18n( $sess_total ) );
			$rows[] = self::row( 'סשנים שפג תוקפם', number_format_i18n( $sess_expired ), $sess_expired > 5000 ? 'warning' : 'ok' );
			if ( $sess_expired > 5000 ) {
				$this->finding(
					'warning',
					number_format_i18n( $sess_expired ) . ' סשני ווקומרס שפג תוקפם לא נוקו',
					'המשימה woocommerce_cleanup_sessions לא רצה — עוד סימן שהקרון תקוע. טבלת סשנים גדולה מאטה כל טעינת עגלה.',
					"wp db query \"DELETE FROM {$sess_table} WHERE session_expiry < UNIX_TIMESTAMP()\""
				);
			}
		}

		$this->section( 'cron', '5. Cron ו-Action Scheduler', $rows, $tables );
	}

	/* ---------------------------------------------------------------------
	 * 6. מטמון ו-HTTP
	 * ------------------------------------------------------------------ */

	private function collect_http_performance() {
		$rows   = array();
		$tables = array();

		$targets = array( 'דף הבית' => home_url( '/' ) );
		if ( $this->wc_active() ) {
			$shop = wc_get_page_permalink( 'shop' );
			$cart = wc_get_page_permalink( 'cart' );
			$co   = wc_get_page_permalink( 'checkout' );
			if ( $shop ) {
				$targets['עמוד חנות'] = $shop;
			}
			$product = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => 1,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			if ( $product ) {
				$targets['עמוד מוצר'] = get_permalink( $product[0] );
			}
			if ( $cart ) {
				$targets['עגלה'] = $cart;
			}
			if ( $co ) {
				$targets['תשלום'] = $co;
			}
		}

		$perf_rows = array();
		foreach ( $targets as $label => $url ) {
			$t0  = microtime( true );
			$res = wp_remote_get(
				$url,
				array(
					'timeout'     => 45,
					'redirection' => 3,
					'sslverify'   => false,
					'user-agent'  => 'NamaAudit/' . self::VERSION,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			$ms = round( ( microtime( true ) - $t0 ) * 1000 );

			if ( is_wp_error( $res ) ) {
				$perf_rows[] = array(
					'עמוד'    => $label,
					'קוד'     => 'שגיאה',
					'זמן'     => $ms . 'ms',
					'גודל'    => '—',
					'מטמון'   => $res->get_error_message(),
				);
				$this->finding( 'critical', 'לא ניתן לטעון את ' . $label, $res->get_error_message() . ' — ' . $url, 'ייתכן שהשרת חוסם בקשות loopback. זה גם שובר את WP-Cron ואת עדכוני ווקומרס.' );
				continue;
			}

			$code    = wp_remote_retrieve_response_code( $res );
			$body    = wp_remote_retrieve_body( $res );
			$headers = wp_remote_retrieve_headers( $res );
			$hdr     = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
			$hdr     = array_change_key_case( $hdr, CASE_LOWER );

			$cache_hint = array();
			foreach ( array( 'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-wp-rocket', 'x-cache-status', 'age', 'x-proxy-cache', 'x-nitro-cache' ) as $h ) {
				if ( isset( $hdr[ $h ] ) ) {
					$val          = is_array( $hdr[ $h ] ) ? implode( ',', $hdr[ $h ] ) : $hdr[ $h ];
					$cache_hint[] = $h . ': ' . $val;
				}
			}

			$perf_rows[] = array(
				'עמוד'  => $label,
				'קוד'   => $code,
				'זמן'   => $ms . 'ms',
				'גודל'  => self::bytes_to_mb( strlen( $body ) ) . 'MB',
				'מטמון' => $cache_hint ? implode( ' | ', $cache_hint ) : 'אין כותרות מטמון',
			);

			if ( 200 !== (int) $code ) {
				$this->finding( 'critical', $label . ' מחזיר קוד ' . $code, $url, 'לבדוק שגיאות בשרת ובלוגים.' );
			}

			// מטמון על עמודים דינמיים — סכנה אמיתית.
			if ( in_array( $label, array( 'עגלה', 'תשלום' ), true ) && $cache_hint ) {
				$hit = false;
				foreach ( $cache_hint as $c ) {
					if ( preg_match( '/\b(hit|cached)\b/i', $c ) ) {
						$hit = true;
					}
				}
				if ( $hit ) {
					$this->finding(
						'critical',
						'עמוד ה' . $label . ' מוגש מהמטמון (' . implode( ', ', $cache_hint ) . ')',
						'זו סיבה קלאסית להזמנות לא מושלמות: הלקוח מקבל HTML של מישהו אחר עם nonce ישן. התוצאה — "פג תוקף הסשן", עגלה ריקה, או הזמנה שנוצרת ואז נתקעת ללא תשלום.',
						'להחריג מהמטמון: /cart/, /checkout/, /my-account/, וכל כתובת עם הפרמטרים ?add-to-cart, wc-ajax. וגם: להחריג את הקוקיז woocommerce_items_in_cart, woocommerce_cart_hash, wp_woocommerce_session_*. ב-Cloudflare — Cache Rule עם Bypass Cache על הנתיבים האלה.'
					);
				}
			}

			if ( 'דף הבית' === $label && $ms > 1500 ) {
				$this->finding(
					$ms > 3000 ? 'critical' : 'warning',
					'זמן תגובה של דף הבית: ' . $ms . 'ms',
					'זו מדידה מהשרת אל עצמו — כלומר ללא רשת ו-DNS. אצל לקוח אמיתי זה יהיה גרוע יותר. יעד: מתחת ל-600ms.',
					'סדר טיפול: OPcache → Object Cache (Redis) → מטמון דפים → הפחתת תוספים → אופטימיזציית DB.'
				);
			}

			if ( strlen( $body ) > 1500000 ) {
				$this->finding( 'warning', $label . ' — HTML גדול מאוד (' . self::bytes_to_mb( strlen( $body ) ) . 'MB)', 'HTML כבד מאט את ה-parsing בדפדפן ופוגע ב-LCP.', 'לצמצם מספר מוצרים בעמוד, להסיר inline CSS ענק של בונה העמודים, ולהפעיל lazy-load.' );
			}
		}

		$tables['מדידת זמני תגובה (מהשרת)'] = $perf_rows;

		$this->section( 'http', '6. ביצועי HTTP ומטמון', $rows, $tables );
	}

	/* ---------------------------------------------------------------------
	 * 7. בריאות בסיס הנתונים
	 * ------------------------------------------------------------------ */

	private function collect_database() {
		global $wpdb;

		$rows   = array();
		$tables = array();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$sizes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name AS tname,
				        ROUND((data_length + index_length)/1048576, 2) AS size_mb,
				        ROUND(data_free/1048576, 2) AS overhead_mb,
				        table_rows AS rows_est,
				        engine
				 FROM information_schema.TABLES
				 WHERE table_schema = %s
				 ORDER BY (data_length + index_length) DESC
				 LIMIT 25",
				DB_NAME
			),
			ARRAY_A
		);

		$total_mb    = 0;
		$overhead_mb = 0;
		$size_rows   = array();
		$myisam      = array();
		foreach ( (array) $sizes as $t ) {
			$total_mb    += (float) $t['size_mb'];
			$overhead_mb += (float) $t['overhead_mb'];
			if ( 'MyISAM' === $t['engine'] ) {
				$myisam[] = $t['tname'];
			}
			$size_rows[] = array(
				'טבלה'      => $t['tname'],
				'גודל (MB)' => $t['size_mb'],
				'שורות'     => number_format_i18n( (int) $t['rows_est'] ),
				'עודף (MB)' => $t['overhead_mb'],
				'מנוע'      => $t['engine'],
			);
		}
		$tables['25 הטבלאות הגדולות'] = $size_rows;
		$rows[] = self::row( 'גודל 25 הטבלאות הגדולות', $total_mb . ' MB' );
		$rows[] = self::row( 'עודף (overhead)', $overhead_mb . ' MB', $overhead_mb > 50 ? 'warning' : 'ok' );

		if ( $overhead_mb > 50 ) {
			$this->finding( 'warning', 'עודף של ' . $overhead_mb . 'MB בבסיס הנתונים', 'שטח מבוזבז משורות שנמחקו; מאט סריקות טבלה.', 'wp db optimize  (בשעת עומס נמוכה, אחרי גיבוי)' );
		}
		if ( $myisam ) {
			$this->finding(
				'warning',
				count( $myisam ) . ' טבלאות עדיין ב-MyISAM',
				implode( ', ', array_slice( $myisam, 0, 10 ) ) . '. MyISAM נועל את כל הטבלה בכל כתיבה — בשעת עומס בחנות זה גורם לתורים ולטיים-אאוטים בצ׳קאאוט.',
				'להמיר ל-InnoDB: ALTER TABLE `שם_טבלה` ENGINE=InnoDB;  (לגבות קודם)'
			);
		}

		// Autoload.
		$autoload_yes = "autoload IN ('yes','on','auto','auto-on')";
		$auto_bytes   = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE {$autoload_yes}" );
		$auto_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$autoload_yes}" );
		$auto_kb      = round( $auto_bytes / 1024, 1 );
		$rows[]       = self::row( 'אפשרויות autoload', number_format_i18n( $auto_count ) );
		$rows[]       = self::row( 'גודל autoload', $auto_kb . ' KB', $auto_bytes > 1048576 ? 'critical' : ( $auto_bytes > 512000 ? 'warning' : 'ok' ) );

		if ( $auto_bytes > 512000 ) {
			$top = $wpdb->get_results(
				"SELECT option_name, ROUND(LENGTH(option_value)/1024,1) AS kb
				 FROM {$wpdb->options}
				 WHERE {$autoload_yes}
				 ORDER BY LENGTH(option_value) DESC
				 LIMIT 20",
				ARRAY_A
			);
			$trows = array();
			foreach ( (array) $top as $t ) {
				$trows[] = array(
					'אפשרות'  => $t['option_name'],
					'גודל KB' => $t['kb'],
				);
			}
			$tables['20 האפשרויות הכבדות ב-autoload'] = $trows;
			$this->finding(
				$auto_bytes > 1048576 ? 'critical' : 'warning',
				'נתוני autoload כבדים: ' . $auto_kb . ' KB',
				'כל הנתונים האלה נטענים מה-DB ומפוענחים (unserialize) בכל בקשה בודדת לאתר — כולל כל קריאת AJAX של העגלה. מעל 800KB זה מוסיף מאות אלפיות שנייה לכל טעינה. יעד: מתחת ל-300KB.',
				'לעבור על הטבלה: אפשרויות של תוספים שהוסרו ניתן למחוק; אפשרויות ענק שלא נחוצות בכל בקשה יש להעביר ל-autoload=no.'
			);
		}

		// Transients.
		$transients   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'" );
		$expired_tr   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_%%' AND option_value < %d", time() ) );
		$rows[]       = self::row( 'Transients', number_format_i18n( $transients ), $transients > 5000 ? 'warning' : 'ok' );
		$rows[]       = self::row( 'Transients שפג תוקפם', number_format_i18n( $expired_tr ), $expired_tr > 1000 ? 'warning' : 'ok' );
		if ( $expired_tr > 1000 ) {
			$this->finding( 'warning', number_format_i18n( $expired_tr ) . ' transients פגי תוקף בטבלת options', 'מנפחים את wp_options ומאטים כל שאילתה עליה.', 'wp transient delete --expired' );
		}

		// Revisions ו-postmeta יתומים.
		$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision'" );
		$rows[]    = self::row( 'גרסאות (revisions)', number_format_i18n( $revisions ), $revisions > 10000 ? 'warning' : 'ok' );

		$orphan_meta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
		$rows[]      = self::row( 'postmeta יתומים', number_format_i18n( $orphan_meta ), $orphan_meta > 5000 ? 'warning' : 'ok' );
		if ( $orphan_meta > 5000 ) {
			$this->finding( 'warning', number_format_i18n( $orphan_meta ) . ' שורות postmeta יתומות', 'מטא-דאטה של פוסטים/הזמנות שנמחקו. מנפח את הטבלה הכי נגישה בוורדפרס.', "לגבות, ואז:\nDELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL;" );
		}

		if ( $revisions > 10000 ) {
			$this->finding( 'notice', number_format_i18n( $revisions ) . ' גרסאות פוסטים', 'מנפח את wp_posts — אותה טבלה שמכילה מוצרים (ואת ההזמנות, אם HPOS כבוי).', "להגביל ב-wp-config.php: define('WP_POST_REVISIONS', 5);\nולנקות: wp post delete \$(wp post list --post_type=revision --format=ids) --force" );
		}

		// אינדקסים חסרים על postmeta להזמנות (רלוונטי כשאין HPOS).
		$s = $this->orders_schema();
		if ( ! $s['hpos'] ) {
			$idx    = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->postmeta}", ARRAY_A );
			$rows[] = self::row( 'אינדקסים על postmeta', count( (array) $idx ) );
		}
		// phpcs:enable

		$this->section( 'db', '7. בריאות בסיס הנתונים', $rows, $tables );
	}

	/* ---------------------------------------------------------------------
	 * 8. מדיה ותוכן
	 * ------------------------------------------------------------------ */

	private function collect_media() {
		global $wpdb;

		$rows   = array();
		$tables = array();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$attachments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'" );
		$products    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" );
		$variations  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product_variation'" );
		// phpcs:enable

		$rows[] = self::row( 'מוצרים מפורסמים', number_format_i18n( $products ) );
		$rows[] = self::row( 'וריאציות מוצר', number_format_i18n( $variations ), $variations > 20000 ? 'warning' : 'ok' );
		$rows[] = self::row( 'קבצי מדיה', number_format_i18n( $attachments ) );

		if ( $variations > 20000 ) {
			$this->finding( 'warning', number_format_i18n( $variations ) . ' וריאציות מוצר', 'מספר וריאציות גבוה מאט טעינת עמוד מוצר ואת מסכי הניהול, ומגדיל את wp_postmeta דרמטית.', 'לשקול המרת מוצרים עם עשרות וריאציות למוצרים נפרדים, ולהפעיל טעינת וריאציות ב-AJAX (ווקומרס עושה זאת אוטומטית מעל 30 וריאציות).' );
		}

		// גדלי תמונות רשומים.
		$sizes = wp_get_registered_image_subsizes();
		$rows[] = self::row( 'גדלי תמונה רשומים', count( $sizes ), count( $sizes ) > 12 ? 'warning' : 'ok' );
		if ( count( $sizes ) > 12 ) {
			$this->finding( 'warning', count( $sizes ) . ' גדלי תמונה רשומים', 'כל העלאה יוצרת ' . count( $sizes ) . ' עותקים. זה מנפח את הדיסק, מאט העלאות וייבוא מוצרים, ומאט גיבויים.', 'לבטל גדלים שלא בשימוש עם intermediate_image_sizes_advanced, ולהריץ Regenerate Thumbnails.' );
		}

		// סריקת תיקיית uploads (מוגבלת).
		$uploads = wp_get_upload_dir();
		$dir     = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		$scan    = $this->scan_uploads( $dir );
		$rows[]  = self::row( 'גודל uploads (נסרק)', $scan['size_mb'] . ' MB' . ( $scan['capped'] ? ' (סריקה חלקית — נעצרה ב-' . $scan['files'] . ' קבצים)' : '' ) );
		$rows[]  = self::row( 'קבצים שנסרקו', number_format_i18n( $scan['files'] ) );
		$rows[]  = self::row( 'תמונות מעל 300KB', number_format_i18n( $scan['heavy'] ), $scan['heavy'] > 100 ? 'critical' : ( $scan['heavy'] > 20 ? 'warning' : 'ok' ) );
		$rows[]  = self::row( 'קבצי WebP/AVIF', number_format_i18n( $scan['modern'] ), $scan['modern'] > 0 ? 'ok' : 'warning' );

		if ( $scan['heavy'] > 20 ) {
			$hrows = array();
			foreach ( array_slice( $scan['heaviest'], 0, 20 ) as $h ) {
				$hrows[] = array(
					'קובץ'     => $h['path'],
					'גודל KB'  => $h['kb'],
				);
			}
			$tables['התמונות הכבדות ביותר'] = $hrows;
			$this->finding(
				$scan['heavy'] > 100 ? 'critical' : 'warning',
				number_format_i18n( $scan['heavy'] ) . ' תמונות מעל 300KB',
				'תמונות כבדות הן הגורם מספר 1 ל-LCP איטי בחנויות. תמונת מוצר מיטבית: מתחת ל-150KB ברוחב 1200px, בפורמט WebP.',
				'להתקין תוסף המרה ודחיסה (ShortPixel / Imagify / EWWW), להריץ Bulk Optimize על כל הספרייה, ולהפעיל המרה אוטומטית ל-WebP + הגשה עם <picture>.'
			);
		}
		if ( 0 === $scan['modern'] && $scan['files'] > 50 ) {
			$this->finding( 'warning', 'אין אף קובץ WebP/AVIF באתר', 'WebP חוסך 25–40% ממשקל התמונות ללא אובדן איכות נראה לעין.', 'להפעיל המרה אוטומטית ל-WebP באחד מתוספי האופטימיזציה, או ב-CDN (Cloudflare Polish / Bunny Optimizer).' );
		}

		$this->section( 'media', '8. מדיה ותוכן', $rows, $tables );
	}

	/**
	 * סריקה מוגבלת של תיקיית ההעלאות.
	 */
	private function scan_uploads( $dir ) {
		$out = array(
			'size_mb'  => 0,
			'files'    => 0,
			'heavy'    => 0,
			'modern'   => 0,
			'capped'   => false,
			'heaviest' => array(),
		);

		if ( ! $dir || ! is_dir( $dir ) ) {
			return $out;
		}

		$max_files = 30000;
		$deadline  = microtime( true ) + 20;
		$bytes     = 0;

		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch ( Exception $e ) {
			return $out;
		}

		foreach ( $it as $file ) {
			if ( $out['files'] >= $max_files || microtime( true ) > $deadline ) {
				$out['capped'] = true;
				break;
			}
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			$sz  = $file->getSize();
			$out['files']++;
			$bytes += $sz;

			if ( in_array( $ext, array( 'webp', 'avif' ), true ) ) {
				$out['modern']++;
			}
			if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true ) && $sz > 307200 ) {
				$out['heavy']++;
				$out['heaviest'][] = array(
					'path' => str_replace( $dir, '', $file->getPathname() ),
					'kb'   => round( $sz / 1024 ),
				);
			}
		}

		usort(
			$out['heaviest'],
			static function ( $a, $b ) {
				return $b['kb'] <=> $a['kb'];
			}
		);
		$out['heaviest'] = array_slice( $out['heaviest'], 0, 20 );
		$out['size_mb']  = self::bytes_to_mb( $bytes );

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 9. יומנים ושגיאות
	 * ------------------------------------------------------------------ */

	private function collect_logs() {
		$rows   = array();
		$tables = array();

		// debug.log
		$debug_log = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $debug_log ) ) {
			$size   = filesize( $debug_log );
			$rows[] = self::row( 'debug.log', self::bytes_to_mb( $size ) . ' MB', $size > 52428800 ? 'critical' : 'notice' );
			$tail   = $this->tail_file( $debug_log, 120 );
			$fatals = 0;
			$lines  = array();
			foreach ( $tail as $line ) {
				if ( preg_match( '/(PHP (Fatal|Parse) error|Uncaught)/i', $line ) ) {
					$fatals++;
				}
				$lines[] = array( 'שורה' => mb_substr( $line, 0, 300 ) );
			}
			$rows[] = self::row( 'שגיאות פטאליות ב-120 שורות אחרונות', $fatals, $fatals ? 'critical' : 'ok' );
			$tables['debug.log — 120 שורות אחרונות'] = $lines;
			if ( $fatals ) {
				$this->finding( 'critical', $fatals . ' שגיאות PHP פטאליות ביומן', 'שגיאה פטאלית באמצע תהליך הצ׳קאאוט משאירה הזמנה חצי-יצורה: היא נשמרת ב-DB, אבל התשלום לא מופעל וההזמנה נשארת pending לנצח.', 'לקרוא את הטבלה למטה, לזהות את התוסף/הקובץ, ולטפל בו. אם השגיאה מופיעה בזמנים שמתאימים להזמנות שנתקעו — מצאת את הסיבה.' );
			}
			if ( $size > 52428800 ) {
				$this->finding( 'warning', 'debug.log ענק (' . self::bytes_to_mb( $size ) . 'MB)', 'קובץ יומן ענק ממלא את הדיסק וכתיבה אליו מאטה כל בקשה.', 'לרוקן את הקובץ, ולוודא שהיומן כבוי בסביבת ייצור או מסתובב (logrotate).' );
			}
		} else {
			$rows[] = self::row( 'debug.log', 'לא קיים', 'notice' );
			$this->finding(
				'notice',
				'לא מופעל יומן שגיאות PHP',
				'בלי יומן אי אפשר לאבחן הזמנות שנתקעות — לא רואים את השגיאה שקרתה בדיוק ברגע יצירת ההזמנה.',
				"להפעיל ב-wp-config.php:\ndefine('WP_DEBUG', true);\ndefine('WP_DEBUG_LOG', true);\ndefine('WP_DEBUG_DISPLAY', false);\n@ini_set('display_errors', 0);"
			);
		}

		// יומני ווקומרס.
		if ( $this->wc_active() ) {
			$log_dir = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : '';
			$rows[]  = self::row( 'תיקיית יומני WooCommerce', $log_dir ? $log_dir : 'לא ידוע' );
			if ( $log_dir && is_dir( $log_dir ) ) {
				$files = glob( trailingslashit( $log_dir ) . '*.log' );
				$files = is_array( $files ) ? $files : array();
				usort(
					$files,
					static function ( $a, $b ) {
						return filemtime( $b ) <=> filemtime( $a );
					}
				);
				$rows[] = self::row( 'קבצי יומן', count( $files ) );

				$log_rows = array();
				foreach ( array_slice( $files, 0, 25 ) as $f ) {
					$log_rows[] = array(
						'קובץ'   => basename( $f ),
						'גודל KB' => round( filesize( $f ) / 1024 ),
						'עודכן'  => gmdate( 'Y-m-d H:i', filemtime( $f ) ),
					);
				}
				$tables['יומני WooCommerce (25 אחרונים)'] = $log_rows;

				// חיפוש שגיאות בקבצי יומן טריים.
				$err_rows = array();
				foreach ( array_slice( $files, 0, 12 ) as $f ) {
					if ( filemtime( $f ) < time() - ( 14 * DAY_IN_SECONDS ) ) {
						continue;
					}
					foreach ( $this->tail_file( $f, 200 ) as $line ) {
						if ( preg_match( '/(CRITICAL|ERROR|EMERGENCY|ALERT|declined|failed|timeout|invalid|refus)/i', $line ) ) {
							$err_rows[] = array(
								'קובץ' => basename( $f ),
								'שורה' => mb_substr( $line, 0, 300 ),
							);
						}
						if ( count( $err_rows ) >= 120 ) {
							break 2;
						}
					}
				}
				if ( $err_rows ) {
					$tables['שגיאות ביומני WooCommerce'] = $err_rows;
					$this->finding(
						'critical',
						count( $err_rows ) . ' שורות שגיאה ביומני WooCommerce',
						'הטבלה למטה היא המקום הכי ישיר לזהות למה הזמנות נכשלות. לחפש בה את שם שער הסליקה, "declined", "timeout", "invalid signature" או "callback".',
						'לצלב כל שגיאה עם מספר ההזמנה ועם השעה. אם רואים "invalid signature"/"hash mismatch" — מפתח ה-API של השער שגוי או פג. אם רואים timeout — השרת לא מצליח לצאת החוצה לשער.'
					);
				}
			}

			// יומן שגיאות פטאליות של WordPress.
			$fatal = get_option( 'fatal-error-handler-recovery-mode' ) ? 'קיים' : '—';
			$rows[] = self::row( 'מצב שחזור (recovery mode)', $fatal );
		}

		$this->section( 'logs', '9. יומנים ושגיאות', $rows, $tables );
	}

	private function tail_file( $path, $lines = 100 ) {
		$out = array();
		$fp  = @fopen( $path, 'r' );
		if ( ! $fp ) {
			return $out;
		}
		$buffer = 4096;
		fseek( $fp, 0, SEEK_END );
		$pos  = ftell( $fp );
		$data = '';
		while ( $pos > 0 && substr_count( $data, "\n" ) <= $lines ) {
			$read = min( $buffer, $pos );
			$pos -= $read;
			fseek( $fp, $pos );
			$data = fread( $fp, $read ) . $data;
		}
		fclose( $fp );
		$all = explode( "\n", trim( $data ) );
		$out = array_slice( $all, -$lines );
		return array_values( array_filter( $out, static function ( $l ) {
			return '' !== trim( $l );
		} ) );
	}

	/* ---------------------------------------------------------------------
	 * 10. אבטחה ותקינות
	 * ------------------------------------------------------------------ */

	private function collect_security() {
		global $wpdb;

		$rows = array();

		$rows[] = self::row( 'עריכת קבצים מלוח הבקרה', defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? 'חסומה' : 'מותרת', defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? 'ok' : 'warning' );
		if ( ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
			$this->finding( 'warning', 'עורך הקבצים בלוח הבקרה פתוח', 'משתמש שנפרץ יכול להזריק קוד ישירות לתבנית או לתוסף.', "ב-wp-config.php: define('DISALLOW_FILE_EDIT', true);" );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$admin_user = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = %s", 'admin' ) );
		// phpcs:enable
		$rows[] = self::row( 'משתמש בשם "admin"', $admin_user ? 'קיים' : 'לא קיים', $admin_user ? 'warning' : 'ok' );
		if ( $admin_user ) {
			$this->finding( 'warning', 'קיים משתמש בשם המשתמש "admin"', 'יעד ברירת המחדל לכל התקפת brute force.', 'ליצור מנהל חדש בשם אחר, להעביר אליו תכנים, ולמחוק את "admin".' );
		}

		$admins = count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
		$rows[] = self::row( 'מספר מנהלים', $admins, $admins > 5 ? 'warning' : 'ok' );
		if ( $admins > 5 ) {
			$this->finding( 'warning', $admins . ' משתמשי מנהל באתר', 'כל מנהל הוא וקטור תקיפה נוסף וגם יכול לשנות הגדרות סליקה בטעות.', 'לצמצם להרשאות הנדרשות (Shop Manager / Editor) ולהשאיר 1–2 מנהלים.' );
		}

		$rows[] = self::row( 'XML-RPC', apply_filters( 'xmlrpc_enabled', true ) ? 'פעיל' : 'כבוי' );
		$rows[] = self::row( 'הרשמת משתמשים פתוחה', get_option( 'users_can_register' ) ? 'כן' : 'לא' );
		$rows[] = self::row( 'עדכוני ליבה אוטומטיים', defined( 'WP_AUTO_UPDATE_CORE' ) ? var_export( WP_AUTO_UPDATE_CORE, true ) : 'ברירת מחדל' );
		$rows[] = self::row( 'הרשאות wp-config.php', file_exists( ABSPATH . 'wp-config.php' ) ? substr( sprintf( '%o', fileperms( ABSPATH . 'wp-config.php' ) ), -4 ) : 'לא נמצא' );

		// אינדוקס.
		$blog_public = get_option( 'blog_public' );
		$rows[]      = self::row( 'נראות למנועי חיפוש', $blog_public ? 'גלוי' : 'חסום לאינדוקס!', $blog_public ? 'ok' : 'critical' );
		if ( ! $blog_public ) {
			$this->finding( 'critical', 'האתר חסום לאינדוקס במנועי חיפוש', 'ההגדרה "בקש ממנועי חיפוש לא לאנדקס" פעילה — האתר לא יופיע בגוגל.', 'הגדרות → קריאה → לבטל את הסימון.' );
		}

		$this->section( 'security', '10. אבטחה ותקינות', $rows );
	}

	/* ---------------------------------------------------------------------
	 * הרצה
	 * ------------------------------------------------------------------ */

	public function run() {
		$this->findings = array();
		$this->sections = array();

		@set_time_limit( 300 );

		$this->collect_environment();
		$this->collect_plugins();
		$this->collect_woocommerce();
		$this->collect_orders();
		$this->collect_cron();
		$this->collect_http_performance();
		$this->collect_database();
		$this->collect_media();
		$this->collect_logs();
		$this->collect_security();

		$order = array(
			'critical' => 0,
			'warning'  => 1,
			'notice'   => 2,
			'ok'       => 3,
		);
		usort(
			$this->findings,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a['severity'] ] <=> $order[ $b['severity'] ];
			}
		);

		return array(
			'meta'     => array(
				'generated_at' => gmdate( 'c' ),
				'audit_version' => self::VERSION,
				'site'         => home_url(),
				'wp'           => get_bloginfo( 'version' ),
				'wc'           => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'php'          => PHP_VERSION,
			),
			'findings' => $this->findings,
			'sections' => $this->sections,
		);
	}

	/* ---------------------------------------------------------------------
	 * ממשק ניהול
	 * ------------------------------------------------------------------ */

	public function register_admin_page() {
		// תפריט ראשי — כדי שיהיה קל למצוא בסרגל הצד.
		add_menu_page(
			'Nama Audit',
			'Nama Audit',
			'manage_options',
			'nama-audit',
			array( $this, 'render_admin_page' ),
			'dashicons-chart-area',
			58
		);

		// ובנוסף תחת "כלים", למי שרגיל לחפש שם.
		add_management_page(
			'Nama Audit',
			'Nama Audit',
			'manage_options',
			'nama-audit',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'אין הרשאה.' );
		}

		$run = isset( $_POST['nama_audit_run'] ) && check_admin_referer( 'nama_audit_run' );

		echo '<div class="wrap" dir="rtl" style="max-width:1100px">';
		echo '<h1>Nama Audit — דוח בדיקת מערכת</h1>';
		echo '<p>בדיקה מקיפה של תשתית, ביצועים, בסיס נתונים והזמנות. ההרצה עשויה לקחת 30–120 שניות.</p>';

		echo '<form method="post">';
		wp_nonce_field( 'nama_audit_run' );
		submit_button( 'הרצת בדיקה מלאה', 'primary', 'nama_audit_run', false );
		echo '</form>';

		if ( ! $run ) {
			echo '</div>';
			return;
		}

		$report = $this->run();

		echo '<style>
			.nama-f{border-right:5px solid #ccc;background:#fff;padding:12px 16px;margin:10px 0;box-shadow:0 1px 2px rgba(0,0,0,.08)}
			.nama-f.critical{border-right-color:#d63638}
			.nama-f.warning{border-right-color:#dba617}
			.nama-f.notice{border-right-color:#72aee6}
			.nama-f h3{margin:0 0 6px}
			.nama-f pre{background:#f6f7f7;padding:8px;overflow:auto;white-space:pre-wrap;direction:ltr;text-align:left}
			.nama-tbl{border-collapse:collapse;width:100%;margin:8px 0 18px;background:#fff}
			.nama-tbl th,.nama-tbl td{border:1px solid #dcdcde;padding:6px 10px;text-align:right;font-size:13px;vertical-align:top}
			.nama-tbl th{background:#f0f0f1}
			.st-ok{color:#00794b;font-weight:600}
			.st-warning{color:#996800;font-weight:600}
			.st-critical{color:#d63638;font-weight:700}
			.nama-sum span{display:inline-block;padding:6px 14px;margin-left:8px;border-radius:4px;font-weight:700;color:#fff}
		</style>';

		$counts = array( 'critical' => 0, 'warning' => 0, 'notice' => 0 );
		foreach ( $report['findings'] as $f ) {
			if ( isset( $counts[ $f['severity'] ] ) ) {
				$counts[ $f['severity'] ]++;
			}
		}

		echo '<h2>סיכום</h2><p class="nama-sum">';
		printf( '<span style="background:#d63638">קריטי: %d</span>', (int) $counts['critical'] );
		printf( '<span style="background:#dba617">אזהרה: %d</span>', (int) $counts['warning'] );
		printf( '<span style="background:#72aee6">לתשומת לב: %d</span>', (int) $counts['notice'] );
		echo '</p>';

		echo '<h2>ממצאים לפי סדר עדיפות</h2>';
		if ( ! $report['findings'] ) {
			echo '<p>לא נמצאו ממצאים.</p>';
		}
		foreach ( $report['findings'] as $i => $f ) {
			printf(
				'<div class="nama-f %1$s"><h3>%2$d. %3$s</h3><p>%4$s</p>%5$s</div>',
				esc_attr( $f['severity'] ),
				$i + 1,
				esc_html( $f['title'] ),
				nl2br( esc_html( $f['detail'] ) ),
				$f['fix'] ? '<strong>תיקון:</strong><pre>' . esc_html( $f['fix'] ) . '</pre>' : ''
			);
		}

		foreach ( $report['sections'] as $sec ) {
			echo '<h2>' . esc_html( $sec['title'] ) . '</h2>';
			if ( $sec['rows'] ) {
				echo '<table class="nama-tbl"><tbody>';
				foreach ( $sec['rows'] as $r ) {
					printf(
						'<tr><th style="width:32%%">%s</th><td class="st-%s">%s</td></tr>',
						esc_html( $r['label'] ),
						esc_attr( $r['status'] ),
						esc_html( $r['value'] )
					);
				}
				echo '</tbody></table>';
			}
			foreach ( (array) $sec['tables'] as $tname => $trows ) {
				echo '<h4>' . esc_html( $tname ) . '</h4>';
				if ( ! $trows ) {
					echo '<p>אין נתונים.</p>';
					continue;
				}
				echo '<table class="nama-tbl"><thead><tr>';
				foreach ( array_keys( (array) $trows[0] ) as $h ) {
					echo '<th>' . esc_html( $h ) . '</th>';
				}
				echo '</tr></thead><tbody>';
				foreach ( $trows as $tr ) {
					echo '<tr>';
					foreach ( $tr as $cell ) {
						echo '<td>' . esc_html( (string) $cell ) . '</td>';
					}
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
		}

		echo '<h2>ייצוא JSON (ללא פרטי לקוחות)</h2>';
		echo '<textarea readonly style="width:100%;height:260px;direction:ltr;font-family:monospace;font-size:11px">';
		echo esc_textarea( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		echo '</textarea>';
		echo '<p>להעתיק את התוכן ולשלוח לצורך ניתוח. הדוח לא כולל שמות, מיילים, כתובות או פרטי תשלום של לקוחות.</p>';

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * WP-CLI
	 * ------------------------------------------------------------------ */

	/**
	 * מריץ בדיקת מערכת מלאה.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : text או json. ברירת מחדל: text
	 *
	 * [--out=<file>]
	 * : נתיב לשמירת הפלט.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nama audit
	 *     wp nama audit --format=json --out=nama-audit.json
	 */
	public function cli_audit( $args, $assoc ) {
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'text';
		$report = $this->run();

		if ( 'json' === $format ) {
			$out = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} else {
			$out = $this->render_text( $report );
		}

		if ( ! empty( $assoc['out'] ) ) {
			file_put_contents( $assoc['out'], $out );
			WP_CLI::success( 'הדוח נשמר: ' . $assoc['out'] );
			return;
		}

		WP_CLI::line( $out );
	}

	private function render_text( array $report ) {
		$l   = array();
		$l[] = str_repeat( '=', 78 );
		$l[] = 'NAMA AUDIT — ' . $report['meta']['site'];
		$l[] = 'נוצר: ' . $report['meta']['generated_at'] . '  |  WP ' . $report['meta']['wp'] . '  |  WC ' . $report['meta']['wc'] . '  |  PHP ' . $report['meta']['php'];
		$l[] = str_repeat( '=', 78 );
		$l[] = '';

		$counts = array( 'critical' => 0, 'warning' => 0, 'notice' => 0 );
		foreach ( $report['findings'] as $f ) {
			if ( isset( $counts[ $f['severity'] ] ) ) {
				$counts[ $f['severity'] ]++;
			}
		}
		$l[] = sprintf( 'סיכום ממצאים:  קריטי=%d  אזהרה=%d  לתשומת לב=%d', $counts['critical'], $counts['warning'], $counts['notice'] );
		$l[] = '';
		$l[] = '--- ממצאים לפי עדיפות ---';
		foreach ( $report['findings'] as $i => $f ) {
			$l[] = '';
			$l[] = sprintf( '[%s] %d. %s', strtoupper( $f['severity'] ), $i + 1, $f['title'] );
			if ( $f['detail'] ) {
				$l[] = '    ' . str_replace( "\n", "\n    ", $f['detail'] );
			}
			if ( $f['fix'] ) {
				$l[] = '    תיקון: ' . str_replace( "\n", "\n           ", $f['fix'] );
			}
		}

		foreach ( $report['sections'] as $sec ) {
			$l[] = '';
			$l[] = str_repeat( '-', 78 );
			$l[] = $sec['title'];
			$l[] = str_repeat( '-', 78 );
			foreach ( $sec['rows'] as $r ) {
				$l[] = sprintf( '  %-42s %s%s', $r['label'], $r['value'], $r['status'] && 'ok' !== $r['status'] ? '   <<' . strtoupper( $r['status'] ) : '' );
			}
			foreach ( (array) $sec['tables'] as $tname => $trows ) {
				$l[] = '';
				$l[] = '  # ' . $tname;
				if ( ! $trows ) {
					$l[] = '    (אין נתונים)';
					continue;
				}
				$l[] = '    ' . implode( ' | ', array_keys( (array) $trows[0] ) );
				foreach ( array_slice( $trows, 0, 40 ) as $tr ) {
					$l[] = '    ' . implode( ' | ', array_map( 'strval', array_values( $tr ) ) );
				}
			}
		}

		return implode( "\n", $l ) . "\n";
	}
}

Nama_Audit::boot();
