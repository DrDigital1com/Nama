<?php
/**
 * Plugin Name: Nama Speed — הפחתת עומס בעמודי עגלה ותשלום
 * Description: מסיר נכסים של תוספים שאין להם תפקיד בעמודי העגלה והתשלום, ומצמצם עבודה מיותרת בכל טעינת עמוד. לא נוגע בטרנזילה, בווקומרס, ב-WPML, ב-YayCurrency או בווידג׳ט הנגישות.
 * Version: 1.1.0
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

	// מתקן שלושה ליקויים שנמדדו בדפדפן אמיתי בעמוד התשלום.
	// ראה את ההסבר המלא אצל nama_speed_checkout_ux().
	'checkout_ux'           => true,

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

	// ⚠️  שמות ה-handle נלקחו מהקוד החי של העמוד (`id='<handle>-css'`), לא מניחוש.
	//     שלושה מהם היו שגויים בגרסה 1.0.0 ותוקנו כאן.
	$styles = array(
		// טופס הרשמה לניוזלטר — אין טופס כזה בעמוד תשלום.
		'mc4wp-form-basic',
		// כפתור וואטסאפ צף. שים לב: קו תחתון, לא מקף.
		'ht_ctc_main_css',
		// CSS של בלוקים. הצ׳קאאוט כאן קלאסי (ווידג׳ט Elementor), לא בלוקים.
		'wc-blocks-style',
		// safe-svg — השם המלא בפועל.
		'safe-svg-svg-icon-style',
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
	// שמות ה-handle האמיתיים באתר הזה, מתוך הקוד החי.
	// `xts-google-fonts` הוא של ערכת Woodmart (Rubik) — **לא** נוגעים בו.
	wp_dequeue_style( 'elementor-gf-roboto' );
	wp_dequeue_style( 'elementor-gf-robotoslab' );
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
 * תיקוני חוויית משתמש בעמוד התשלום.
 *
 * שלושת הליקויים האלה נמדדו בדפדפן Chromium אמיתי מול האתר החי ב-30.8.2026,
 * ותוקנו ואומתו באותו דפדפן לפני שנכתבו לכאן. הם אינם השערות.
 *
 * ── 1. תיבת התשלום ריקה ──────────────────────────────────────────────────
 * `.payment_box.payment_method_tranzila` נמדדה בגובה 50px ועם `innerText`
 * **ריק לחלוטין**. הלקוח רואה מלבן אפור ריק, ומתחתיו כפתור "Place Order".
 * בנוסף נמדד: אין בעמוד שום מלל על אבטחה או הצפנה, אין שום הודעה שהוא עומד
 * לעבור לאתר חיצוני, ואין תיבת אישור תנאים.
 *
 * לרכישה של ‎600 ₪ זו בקשה לתת מספר אשראי בלי שום סימן אמון. התיקון מוסיף
 * הודעה מפורשת שהתשלום מתבצע בעמוד המאובטח של טרנזילה.
 *
 * ── 2. לוגו טרנזילה מוסתר ────────────────────────────────────────────────
 * תוסף השער מזריק בעצמו, בתוך תיבת התשלום:
 *     label[for="payment_method_tranzila"] > img{display: none !important;}
 * כלומר סמל אמצעי התשלום היחיד בעמוד מוסתר בכוונה. הכלל כאן מנצח אותו
 * בעזרת ספציפיות גבוהה יותר (`li.wc_payment_method` בתחילת הסלקטור) —
 * `!important` לבדו לא היה מספיק, כי הסגנון של התוסף מופיע אחריו במסמך.
 *
 * ── 3. בנייד: הסכום לתשלום מופיע *אחרי* כפתור ההזמנה ─────────────────────
 * נמדד ב-390x844: `#place_order` בגובה y=1787, ושורת הסכום ב-y=2258.
 * **הלקוח מתבקש לאשר הזמנה 471 פיקסלים לפני שהוא רואה כמה הוא משלם.**
 * זה נובע מכך שעמודת סיכום ההזמנה של Elementor נערמת מתחת לעמודת הטופס.
 * אחרי התיקון נמדד: סכום ב-y=583, כפתור ב-y=2311.
 *
 * ── בונוס: כפתור הוואטסאפ ────────────────────────────────────────────────
 * `z-index: 99999999` — הגבוה בעמוד — והוא מרחף מעל טבלת ההזמנה.
 * מוסתר בעמוד התשלום בלבד; בשאר האתר הוא נשאר.
 */
function nama_speed_checkout_ux() {
	if ( ! nama_speed_on( 'checkout_ux' ) || ! nama_speed_is_transactional() ) {
		return;
	}

	$css = '
	/* 1. להחזיר את הלוגו שתוסף השער מסתיר בסגנון פנימי משלו.
	      דורש ספציפיות גבוהה משלו — !important לבדו לא מספיק,
	      כי הסגנון של התוסף מופיע אחריו במסמך. נבדק בדפדפן: עובד. */
	li.wc_payment_method label[for="payment_method_tranzila"] > img {
		display: inline-block !important;
		max-height: 26px !important;
		width: auto !important;
		vertical-align: middle;
		margin-inline-start: 8px;
	}
	/* 2. תיבת התשלום נמדדה בגובה קבוע של 50px. משחררים אותה כדי
	      שהודעת ההפניה (שנוספת ב-PHP למטה) תוכל להיראות. */
	.woocommerce-checkout .payment_box.payment_method_tranzila {
		height: auto !important;
		min-height: 0 !important;
	}
	.nama-speed-pay-notice {
		display: block;
		padding: 12px 14px;
		margin: 0 0 10px;
		background: #f4f0f8;
		border-inline-start: 3px solid #a98cc4;
		border-radius: 6px;
		font-size: 14px;
		line-height: 1.5;
		color: #3f3f3f;
	}
	/* 3. בנייד: להעלות את סיכום ההזמנה מעל הטופס, כדי שהסכום ייראה
	      לפני כפתור ההזמנה ולא אחריו. נבדק בדפדפן: עובד. */
	@media (max-width: 768px) {
		.wd-sticky-container-lg { order: -1 !important; }
	}
	/* בונוס: להוריד את בועת הוואטסאפ מעל אזורי הלחיצה של הצ׳קאאוט. */
	.woocommerce-checkout .ht-ctc.ht-ctc-chat { display: none !important; }
	';

	wp_register_style( 'nama-speed-checkout-ux', false, array(), '1.1.0' );
	wp_enqueue_style( 'nama-speed-checkout-ux' );
	wp_add_inline_style( 'nama-speed-checkout-ux', $css );
}
add_action( 'wp_enqueue_scripts', 'nama_speed_checkout_ux', 101 );

/**
 * מציג את הודעת ההפניה מעל אזור אמצעי התשלום.
 *
 * ⚠️  **שתי גרסאות קודמות של הפונקציה הזו לא עבדו. שתיהן נבדקו על האתר החי.**
 *
 * ניסיון 1 — פסאודו-אלמנט `::before` על `.payment_box`.
 *   הדפדפן דיווח שהכלל מחושב נכון (תוכן קיים, גובה 24px) — **אבל הוא לא נראה**,
 *   כי התבנית קובעת ל-`.payment_box` גובה קבוע של 50px.
 *
 * ניסיון 2 — מסנן `woocommerce_gateway_description`.
 *   נבדק אחרי ההתקנה: **ההודעה לא הופיעה בעמוד.** הסיבה — תוסף טרנזילה דורס את
 *   `payment_fields()` ומדפיס HTML משלו ישירות, ולכן `get_description()` שלו
 *   לעולם לא נקרא והמסנן לא רץ.
 *
 * ניסיון 3 (זה) — פעולה `woocommerce_review_order_before_payment`.
 *   ווקומרס מפעילה אותה ב-`woocommerce_checkout_payment()` לפני רשימת אמצעי
 *   התשלום, בלי תלות בשום שער. זה גם מיקום טוב יותר: ההודעה מתייחסת לשלב
 *   התשלום כולו ולא לשער מסוים.
 */
function nama_speed_payment_notice() {
	if ( ! nama_speed_on( 'checkout_ux' ) ) {
		return;
	}
	printf(
		'<div class="nama-speed-pay-notice">%s</div>',
		esc_html__( 'תועברו לעמוד המאובטח של טרנזילה להשלמת התשלום. פרטי האשראי אינם נשמרים באתר.', 'nama-speed' )
	);
}
add_action( 'woocommerce_review_order_before_payment', 'nama_speed_payment_notice' );

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
		"\n<!-- nama-speed 1.1.0 active | transactional=%s | flags=%s -->\n",
		nama_speed_is_transactional() ? 'yes' : 'no',
		esc_html( implode( ',', $on ) )
	);
}
add_action( 'wp_footer', 'nama_speed_marker', 999 );
