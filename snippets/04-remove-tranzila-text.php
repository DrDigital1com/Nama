<?php
/**
 * מחליף כל אזכור של "טרנזילה" בעמוד התשלום בטקסט של Z-Credit.
 *
 * הרקע: עמוד התשלום עדיין מציג "תועברו לעמוד המאובטח של טרנזילה",
 * למרות שתוסף טרנזילה כבוי. כלומר הטקסט מגיע ממקום אחר — תיאור של
 * שער, תרגום ב-WPML, הגדרת תבנית, או קטע קוד.
 *
 * הקטע הזה תופס אותו בכל אחד מהמקרים, בלי צורך לדעת מאיפה הוא בא:
 *   - woocommerce_gateway_description  -> תיאור של כל שער תשלום
 *   - gettext / gettext_with_context   -> מחרוזות מתורגמות, כולל WPML
 *
 * ⚠️ זהו פתרון ביניים. הוא מסתיר את הטקסט, לא מוחק אותו מהמקור.
 * כדאי בכל זאת לאתר את המקור ולתקן שם, ואז להסיר את הקטע הזה.
 * השאילתה לאיתור המקור נמצאת ב-sql/yesterday-cancelled.sql בדיקה 8.
 *
 * התקנה: WPCode -> Add Snippet -> PHP Snippet -> Save & Activate
 *        (לבחור Run Everywhere)
 *
 * אחרי ההפעלה: לנקות מטמון WP Rocket וגם Cloudflare,
 * ולבדוק בגלישה פרטית בעברית, באנגלית ובערבית.
 */

/** הטקסט שיוצג במקום. לערוך כאן אם רוצים נוסח אחר. */
function nama_zcredit_payment_text() {
	return 'תועברו לעמוד התשלום המאובטח של Z-Credit להשלמת הרכישה. פרטי האשראי אינם נשמרים באתר.';
}

/**
 * האם המחרוזת מזכירה את ספק הסליקה הישן.
 */
function nama_mentions_old_gateway( $text ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return false;
	}
	return ( false !== strpos( $text, 'טרנזילה' ) )
		|| ( false !== stripos( $text, 'tranzila' ) );
}

// 1. תיאור של שער תשלום — מכסה את המקרה שהטקסט הועתק להגדרות Z-Credit.
add_filter(
	'woocommerce_gateway_description',
	function ( $description, $gateway_id ) {
		return nama_mentions_old_gateway( $description )
			? nama_zcredit_payment_text()
			: $description;
	},
	99,
	2
);

// 2. מחרוזות מתורגמות — מכסה תרגומי WPML, מחרוזות תבנית וקטעי קוד.
//    בדיקת strpos מהירה רצה ראשונה, כדי לא להכביד על כל קריאת טקסט.
$nama_filter_translated = function ( $translated ) {
	return nama_mentions_old_gateway( $translated )
		? nama_zcredit_payment_text()
		: $translated;
};

add_filter( 'gettext', $nama_filter_translated, 99 );

add_filter(
	'gettext_with_context',
	function ( $translated ) use ( $nama_filter_translated ) {
		return $nama_filter_translated( $translated );
	},
	99
);

// 3. כותרת של שער — ליתר ביטחון, אם השם הישן נשאר ככותרת.
add_filter(
	'woocommerce_gateway_title',
	function ( $title, $gateway_id ) {
		return nama_mentions_old_gateway( $title ) ? 'Z-Credit' : $title;
	},
	99,
	2
);
