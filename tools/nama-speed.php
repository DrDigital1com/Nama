<?php
/**
 * Plugin Name: Nama Speed — הפחתת עומס בעמודי עגלה ותשלום
 * Description: מסיר נכסים של תוספים שאין להם תפקיד בעמודי העגלה והתשלום, ומצמצם עבודה מיותרת בכל טעינת עמוד. לא נוגע בטרנזילה, בווקומרס, ב-WPML, ב-YayCurrency או בווידג׳ט הנגישות.
 * Version: 1.0.0
 * Author: Nama audit
 *
 * ────────────────────────────────────────────────────────────────────────────
 * התקנה
 * ────────────────────────────────────────────────────────────────────────────
 * להעלות את הקובץ ל-`wp-content/mu-plugins/nama-speed.php`.
 * אם התיקייה `mu-plugins` לא קיימת — ליצור אותה. אין צורך להפעיל דבר;
 * תוספי mu נטענים אוטומטית.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * כיבוי חירום
 * ────────────────────────────────────────────────────────────────────────────
 * אם משהו נשבר — שלוש דרכים, מהמהירה לאיטית:
 *
 *   1. להוסיף `?nama_speed=off` לכתובת. מכבה את התוסף לאותה בקשה בלבד.
 *      זו הדרך לבדוק אם התוסף הוא האשם, בלי לגעת בקבצים.
 *   2. להוסיף ל-`wp-config.php`:  define( 'NAMA_SPEED_DISABLE', true );
 *   3. למחוק את הקובץ. אין נתונים בבסיס הנתונים, אין מה לנקות.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * מה התוסף הזה לא עושה — במכוון
 * ────────────────────────────────────────────────────────────────────────────
 * לא נוגע ב:
 *   • WCGatewayTranzila  — שער הסליקה. שום דבר לא נוגע בו.
 *   • WooCommerce core   — checkout.js, blockUI, selectWoo, country-select וכו׳.
 *   • WPML / WCML        — שבירה שלהם שוברת את הצד הערבי של האתר.
 *   • YayCurrency        — נמדד: האתר באמת מציג שני מטבעות (ILS + USD)
 *                          ויש בורר מטבע מוצג. זה לא "תוסף מיותר".
 *   • dr-access          — ווידג׳ט נגישות. בישראל זו לרוב דרישה חוקית.
 *   • Facebook pixel     — מדידת המרות. הסרה שלו מעוורת את הפרסום.
 * ────────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'NAMA_SPEED_DISABLE' ) && NAMA_SPEED_DISABLE ) {
	return;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- kill switch, read-only.
if ( isset( $_GET['nama_speed'] ) && 'off' === $_GET['nama_speed'] ) {
	return;
}

/**
 * מתגים. לשנות ל-false כדי לכבות חלק מסוים.
 *
 * כל אחד מהם עצמאי — אפשר לכבות אחד בלי לגעת בשאר.
 */
const NAMA_SPEED_FLAGS = array(

	// מסיר נכסים של תוספים שאין להם תפקיד בעמודי עגלה/תשלום.
	// זה הליבה של התוסף, והחלק הבטוח ביותר בו.
	'trim_checkout_assets'  => true,

	// מסיר את גופני ברירת המחדל של Elementor (Roboto + Roboto Slab בכל
	// המשקלים והנטיות) מעמודי עגלה/תשלום. הערכה משתמשת ב-Rubik.
	'trim_elementor_fonts'  => true,

	// מאט את ה-Heartbeat מ-15 שניות ל-60 בצד הקדמי.
	'slow_heartbeat'        => true,

	// מגביל גרסאות פוסטים ל-5. מקטין את wp_posts לאורך זמן.
	'limit_revisions'       => true,

	// ⚠️  כבוי בכוונה. ראה את ההסבר הארוך אצל nama_speed_cart_fragments().
	//     אל תדליק את זה לפני שקראת אותו.
	'kill_cart_fragments'   => false,
);

/**
 * @param string $flag
 * @return bool
 */
function nama_speed_on( $flag ) {
	return ! empty( NAMA_SPEED_FLAGS[ $flag ] );
}

/**
 * האם אנחנו בעמוד עגלה או תשלום.
 *
 * שים לב: `is_checkout()` מחזירה true גם ב-order-received. זה מכוון —
 * גם שם אין צורך בטפסי ניוזלטר או בכפתור וואטסאפ.
 *
 * @return bool
 */
function nama_speed_is_transactional() {
	if ( ! function_exists( 'is_cart' ) ) {
		return false;
	}
	return is_cart() || is_checkout();
}

/**
 * מסיר נכסים של תוספים שאין להם תפקיד בעמוד תשלום.
 *
 * הרשימה נבנתה מקוד העמוד החי של nama-c.com ב-30.8.2026, לא מניחוש.
 * כל שורה כאן היא נכס שנמדד כנטען בפועל בעמוד `/checkout/`.
 */
function nama_speed_trim_checkout_assets() {
	if ( ! nama_speed_on( 'trim_checkout_assets' ) || ! nama_speed_is_transactional() ) {
		return;
	}

	$styles = array(
		// טופס הרשמה לניוזלטר — אין טופס כזה בעמוד תשלום.
		'mc4wp-form-basic',
		// כפתור וואטסאפ צף — מסתיר שדות בנייד בדיוק בעמוד שאסור להסתיר בו כלום.
		'ht-ctc-main-css',
		// CSS של בלוקים. הצ׳קאאוט כאן קלאסי (ווידג׳ט Elementor), לא בלוקים.
		'wc-blocks-style',
		// CSS של safe-svg לבלוקים — אין בלוקים בעמוד הזה.
		'safe-svg-block-frontend',
	);

	$scripts = array(
		'mc4wp-forms-api',
		'ht_ctc_app_js',
		// מדידת אירועי תוכן של Site Kit. המרות נמדדות בצד השרת ממילא.
		'googlesitekit-events-provider-content-events',
		'googlesitekit-events-provider-mailchimp',
	);

	foreach ( $styles as $handle ) {
		wp_dequeue_style( $handle );
		wp_deregister_style( $handle );
	}

	foreach ( $scripts as $handle ) {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'nama_speed_trim_checkout_assets', 100 );

/**
 * מסיר את גופני ברירת המחדל של Elementor מעמודי עגלה/תשלום.
 *
 * נמדד חי: העמוד טוען את Roboto ואת Roboto Slab בכל המשקלים 100–900
 * ובכל הנטיות, משני קבצי CSS נפרדים מ-Google Fonts. הערכה עצמה
 * משתמשת ב-Rubik, שנטען בנפרד ונשאר.
 */
function nama_speed_trim_elementor_fonts() {
	if ( ! nama_speed_on( 'trim_elementor_fonts' ) || ! nama_speed_is_transactional() ) {
		return;
	}
	wp_dequeue_style( 'google-fonts-1' );
	wp_dequeue_style( 'google-fonts-2' );
	wp_dequeue_style( 'elementor-gf-default-roboto' );
	wp_dequeue_style( 'elementor-gf-default-robotoslab' );
}
add_action( 'wp_enqueue_scripts', 'nama_speed_trim_elementor_fonts', 100 );

/**
 * מאט את ה-Heartbeat בצד הקדמי.
 *
 * ברירת המחדל היא בקשת POST ל-admin-ajax כל 15 שניות מכל לשונית פתוחה.
 * נמדד חי: `admin-ajax.php` מחזירה תשובה תוך ~2.9 שניות באתר הזה.
 * הורדה ל-60 שניות מרביעה את העומס בלי לפגוע בשום דבר בצד הלקוח.
 */
function nama_speed_slow_heartbeat( $settings ) {
	if ( ! nama_speed_on( 'slow_heartbeat' ) || is_admin() ) {
		return $settings;
	}
	$settings['interval'] = 60;
	return $settings;
}
add_filter( 'heartbeat_settings', 'nama_speed_slow_heartbeat' );

/**
 * מגביל גרסאות פוסטים.
 *
 * לא משפיע על מהירות הצ׳קאאוט. נכלל כי `wp_posts` באתר הזה גדלה בלי הגבלה,
 * וזה מאט כל שאילתה שנוגעת בה.
 */
function nama_speed_limit_revisions( $num, $post ) {
	return nama_speed_on( 'limit_revisions' ) ? 5 : $num;
}
add_filter( 'wp_revisions_to_keep', 'nama_speed_limit_revisions', 10, 2 );

/**
 * מבטל את wc-cart-fragments בעמודים שאינם עמודי חנות.
 *
 * ⚠️  כבוי כברירת מחדל, ובכוונה. לקרוא את כל ההסבר לפני שמדליקים.
 *
 * מה שנמדד חי ב-30.8.2026:
 *   • `?wc-ajax=get_refreshed_fragments` רצה בכל טעינת עמוד באתר, כולל
 *     `/contact-us/` ו-`/blog/` — עמודים בלי שום תפקיד מסחרי.
 *   • **בלי עגלה** התשובה חוזרת תוך ~650ms.
 *   • **עם עגלה פעילה** היא לוקחת 2,283–2,873ms, מול timeout של 5,000ms
 *     שווקומרס מגדירה לעצמה. מרווח של פי 2 בלבד, על שרת פנוי.
 *
 * למה זה בכל זאת כבוי:
 *   תבנית Woodmart מציגה מונה עגלה בכותרת של כל עמוד. דף הבית ועמודי
 *   התוכן מוגשים ממטמון WP Rocket כ-HTML סטטי — כלומר המונה בהם קפוא
 *   ברגע שבו נוצר המטמון. **בדיוק בשביל זה קיימת קריאת ה-fragments.**
 *   אם מכבים אותה, לקוח שמוסיף מוצר לעגלה ואז עובר לדף הבית יראה מונה
 *   ריק — והרושם הוא שהעגלה נמחקה.
 *
 * המסקנה: זו לא בעיה שמכבים, אלא בעיה שמתקנים. התיקון הנכון הוא
 * Redis Object Cache (שלב 3 בספר הפעולות), שמוריד את הקריאה הזו
 * לטווח של מאות מילישניות במקום 2.4 שניות.
 *
 * להדליק רק אם: אין מונה עגלה בכותרת, או שהוחלט במודע לוותר עליו.
 * ואחרי ההדלקה — לבצע את שלוש הבדיקות האלה:
 *   1. להוסיף מוצר לעגלה מעמוד מוצר   -> המונה מתעדכן?
 *   2. לעבור לדף הבית                 -> המונה עדיין מציג את הכמות?
 *   3. להיכנס לעגלה                   -> המוצר שם?
 * אם אחת נכשלה — להחזיר ל-false.
 */
function nama_speed_cart_fragments() {
	if ( ! nama_speed_on( 'kill_cart_fragments' ) || ! function_exists( 'is_woocommerce' ) ) {
		return;
	}
	if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
		return;
	}
	wp_dequeue_script( 'wc-cart-fragments' );
}
add_action( 'wp_enqueue_scripts', 'nama_speed_cart_fragments', 100 );

/**
 * שורת אבחון בקוד המקור, כדי לדעת שהתוסף באמת פעיל.
 *
 * לבדיקה:  curl -s https://nama-c.com/checkout/ | grep nama-speed
 */
function nama_speed_marker() {
	$on = array();
	foreach ( NAMA_SPEED_FLAGS as $flag => $enabled ) {
		if ( $enabled ) {
			$on[] = $flag;
		}
	}
	printf(
		"\n<!-- nama-speed 1.0.0 active | transactional=%s | flags=%s -->\n",
		nama_speed_is_transactional() ? 'yes' : 'no',
		esc_html( implode( ',', $on ) )
	);
}
add_action( 'wp_footer', 'nama_speed_marker', 999 );
