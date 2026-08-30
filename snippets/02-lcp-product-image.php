<?php
/**
 * מונע lazy-loading על תמונת המוצר הראשית.
 *
 * הרקע: ב-99% מעמודי המוצר, תמונת המוצר הראשית היא רכיב ה-LCP —
 * האלמנט הגדול ביותר שנטען. כשהיא בטעינה עצלה, הדפדפן מחכה
 * לסיום עיבוד ה-CSS/JS לפני שהוא בכלל מתחיל להוריד אותה,
 * וה-LCP נדחה בשנייה או יותר.
 *
 * הקטע מסמן אותה כ-eager עם עדיפות גבוהה, כך שההורדה מתחילה מיד.
 *
 * סיכון: נמוך. משפיע רק על עמוד מוצר בודד ורק על התמונה הראשית.
 *
 * בדיקה: PageSpeed Insights על עמוד מוצר לפני ואחרי — יעד LCP < 2.5 שניות.
 *
 * התקנה: WPCode -> Add Snippet -> PHP Snippet -> Save & Activate
 */

add_filter(
	'wp_get_attachment_image_attributes',
	function ( $attr, $attachment, $size ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $attr;
		}

		$sizes = array( 'woocommerce_single', 'shop_single', 'full', 'large' );
		if ( ! in_array( $size, $sizes, true ) ) {
			return $attr;
		}

		// רק לתמונה הראשית של המוצר, לא לגלריה.
		global $product;
		if ( $product && is_object( $attachment ) && (int) $product->get_image_id() !== (int) $attachment->ID ) {
			return $attr;
		}

		$attr['loading']       = 'eager';
		$attr['fetchpriority'] = 'high';
		unset( $attr['decoding'] );

		return $attr;
	},
	10,
	3
);
