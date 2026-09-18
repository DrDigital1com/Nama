<?php
/**
 * Plugin Name: Nama Rocket — כפיית מיזעור ואיחוד ב-WP Rocket
 * Description: מפעיל את המיזעור והאיחוד של WP Rocket דרך מסננים, בלי גישה לממשק הניהול. סעיף-סעיף, כדי שאפשר יהיה לבודד תקלה.
 * Version: 1.0.0
 *
 * ────────────────────────────────────────────────────────────────────────────
 * למה הקובץ הזה קיים
 * ────────────────────────────────────────────────────────────────────────────
 * המיזעור של WP Rocket **כבוי לחלוטין** באתר (אומת: אפס חתימות `data-rocket`
 * בקוד החי, מול 216 בקשות בעמוד מוצר). הפעלתו היא השיפור הגדול ביותר שנותר
 * בצד הדפדפן — ‎~184 קבצים הופכים ל-‎~10.
 *
 * אי אפשר להפעיל אותו מרחוק: נבדקו כל 1,077 נקודות הקצה של ה-REST באתר,
 * ו-WP Rocket חושף שש בלבד — CDN, critical CSS, תמיכה וסטטוס. **אף אחת מהן
 * אינה משנה הגדרות.** ו-`wp-login.php` חסום ב-CAPTCHA של cPGuard.
 *
 * הפתרון: `get_rocket_option()` של WP Rocket מריצה מסנן
 * `pre_get_rocket_option_{שם}` לפני שהיא קוראת את הערך השמור. הקובץ הזה
 * משתמש בו כדי לכפות ערכים בלי לגעת בהגדרות עצמן.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * התקנה — ובעיקר: **סעיף אחד בכל פעם**
 * ────────────────────────────────────────────────────────────────────────────
 * להעלות ל-`wp-content/mu-plugins/nama-rocket.php`.
 *
 * **אל תדליק את כל המתגים בבת אחת.** הסדר למטה הוא מהבטוח למסוכן:
 *
 *   1. `minify_css`  -> להעלות, לנקות מטמון, לבדוק
 *   2. `minify_js`   -> להעלות, לנקות מטמון, לבדוק
 *   3. `combine_css` -> להעלות, לנקות מטמון, לבדוק
 *   4. `defer_js`    -> להעלות, לנקות מטמון, לבדוק
 *   5. `combine_js`  -> **הכי מסוכן.** אחרון. לבדוק הכי חזק.
 *
 * **מה לבדוק אחרי כל סעיף:**
 *   דף הבית · עמוד מוצר · בורר ווריאציות · הוספה לעגלה · עגלה · **צ׳קאאוט מלא**
 *   · תפריט נייד · בורר השפה · בורר המטבע
 *
 * **אם משהו נשבר:** להחזיר את המתג האחרון ל-false, לנקות מטמון WP Rocket.
 * או פשוט למחוק את הקובץ — הכול חוזר למצב הקודם מיד.
 *
 * ⚠️  **הערה חשובה:** הממשק של WP Rocket **לא** יראה את ההגדרות האלה כמסומנות,
 * כי הן נכפות בזמן ריצה ולא נשמרות. זה מכוון — כך אפשר לבטל הכול במחיקת קובץ.
 * מי שיסתכל בממשק בעוד חודש יתבלבל, ולכן: **כשתהיה גישה לניהול, להעביר את
 * ההגדרות לממשק ולמחוק את הקובץ הזה.**
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'NAMA_ROCKET_DISABLE' ) && NAMA_ROCKET_DISABLE ) {
	return;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- kill switch, read-only.
if ( isset( $_GET['nama_rocket'] ) && 'off' === $_GET['nama_rocket'] ) {
	return;
}

/**
 * המתגים. להדליק **אחד בכל פעם**, לפי הסדר.
 */
const NAMA_ROCKET_FLAGS = array(
	'minify_css'  => true,   // 1. הבטוח ביותר
	'minify_js'   => false,  // 2.
	'combine_css' => false,  // 3.
	'defer_js'    => false,  // 4.
	'combine_js'  => false,  // 5. ⚠️ הכי מסוכן — אחרון
);

/**
 * קבצים שאסור לגעת בהם.
 *
 * הרשימה נבנתה מהנכסים שנמדדו בפועל בעמוד התשלום החי. כל שורה כאן היא קובץ
 * שאם ישבר או יידחה — הצ׳קאאוט מפסיק לעבוד.
 */
function nama_rocket_exclusions() {
	return array(
		'/wp-content/plugins/woocommerce/assets/js/frontend/checkout(.*).js',
		'/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart(.*).js',
		'/wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments(.*).js',
		'/wp-content/plugins/woocommerce/assets/js/frontend/country-select(.*).js',
		'/wp-content/plugins/woocommerce/assets/js/frontend/address-i18n(.*).js',
		'/wp-content/plugins/woocommerce/assets/js/jquery-blockui/(.*).js',
		'/wp-content/plugins/woocommerce/assets/js/selectWoo/(.*).js',
		'/wp-content/plugins/WCGatewayTranzila/(.*).js',
		'/wp-content/plugins/yaycurrency/(.*).js',
		'/wp-content/plugins/sitepress-multilingual-cms/(.*).js',
		'/wp-content/plugins/woocommerce-multilingual/(.*).js',
		'/wp-includes/js/jquery/jquery.min.js',
	);
}

/**
 * @param string $flag
 * @return bool
 */
function nama_rocket_on( $flag ) {
	return ! empty( NAMA_ROCKET_FLAGS[ $flag ] );
}

/**
 * כופה את אפשרויות המיזעור.
 *
 * `pre_get_rocket_option_{option}` מחזירה ערך שאינו null -> WP Rocket משתמשת בו
 * ומדלגת על הערך השמור.
 */
function nama_rocket_force() {
	$map = array(
		'minify_css'             => 'minify_css',
		'minify_js'              => 'minify_js',
		'minify_concatenate_css' => 'combine_css',
		'minify_concatenate_js'  => 'combine_js',
		'defer_all_js'           => 'defer_js',
	);

	foreach ( $map as $rocket_option => $flag ) {
		add_filter(
			'pre_get_rocket_option_' . $rocket_option,
			function () use ( $flag ) {
				return nama_rocket_on( $flag ) ? 1 : 0;
			}
		);
	}

	// רשימות ההחרגה — נוספות תמיד, גם כשמתג כבוי.
	foreach ( array( 'exclude_js', 'exclude_css', 'exclude_defer_js', 'delay_js_exclusions' ) as $list ) {
		add_filter(
			'pre_get_rocket_option_' . $list,
			function ( $value ) {
				$current = is_array( $value ) ? $value : array();
				return array_values( array_unique( array_merge( $current, nama_rocket_exclusions() ) ) );
			}
		);
	}
}
add_action( 'plugins_loaded', 'nama_rocket_force', 5 );

/**
 * שורת אבחון, כדי לדעת מה בדיוק פעיל.
 *
 * בדיקה:  curl -s https://nama-c.com/ | grep nama-rocket
 */
function nama_rocket_marker() {
	$on = array();
	foreach ( NAMA_ROCKET_FLAGS as $flag => $enabled ) {
		if ( $enabled ) {
			$on[] = $flag;
		}
	}
	printf(
		"\n<!-- nama-rocket 1.0.0 | forcing: %s -->\n",
		esc_html( $on ? implode( ',', $on ) : 'nothing' )
	);
}
add_action( 'wp_footer', 'nama_rocket_marker', 999 );
