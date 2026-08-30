<?php
/**
 * שני ריסונים קטנים שמקטינים עומס מיותר.
 *
 * 1. הגבלת גרסאות פוסטים ל-5.
 *    באתר נמדדו 734 גרסאות. הן יושבות ב-wpgh_posts — אותה טבלה
 *    שמכילה את המוצרים — ומנפחות אותה (40MB כיום).
 *    ההגבלה חלה על שמירות עתידיות; גרסאות קיימות נשארות.
 *
 * 2. האטת ה-Heartbeat API מ-15 שניות ל-60.
 *    Heartbeat שולח בקשת AJAX מכל לשונית ניהול פתוחה. עם עורך
 *    Elementor פתוח ברקע זה נטל מתמשך על השרת.
 *    60 שניות עדיין מספיקות לנעילת עריכה ולשמירה אוטומטית.
 *
 * סיכון: נמוך.
 *
 * התקנה: WPCode -> Add Snippet -> PHP Snippet -> Save & Activate
 */

// 1. הגבלת גרסאות.
add_filter(
	'wp_revisions_to_keep',
	function ( $num, $post ) {
		return 5;
	},
	10,
	2
);

// 2. האטת Heartbeat.
add_filter(
	'heartbeat_settings',
	function ( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}
);
