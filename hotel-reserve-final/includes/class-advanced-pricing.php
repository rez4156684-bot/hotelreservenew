<?php
/**
 * سیستم قیمت‌گذاری پیشرفته با قابلیت انتخاب بازه تاریخ
 */

if (!defined('ABSPATH')) exit;

class WCHR_Advanced_Pricing {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // AJAX handlers برای قیمت‌گذاری
        add_action('wp_ajax_wchr_save_price_range', [$this, 'ajax_save_price_range']);
        add_action('wp_ajax_wchr_get_price_ranges', [$this, 'ajax_get_price_ranges']);
        add_action('wp_ajax_wchr_delete_price_range', [$this, 'ajax_delete_price_range']);
    }

    /**
     * ذخیره قیمت برای یک بازه تاریخی
     */
    public function ajax_save_price_range() {
        check_ajax_referer('wchr_manager_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'عدم دسترسی']);
        }

        $hotel_id = isset($_POST['hotel_id']) ? intval($_POST['hotel_id']) : 0;
        $room_index = isset($_POST['room_index']) ? intval($_POST['room_index']) : 0;
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;

        if (!$hotel_id || !$start_date || !$end_date || !$price) {
            wp_send_json_error(['message' => 'داده‌های ناقص']);
        }

        // دریافت اتاق‌ها
        $rooms = get_post_meta($hotel_id, '_hotel_rooms', true);
        if (!isset($rooms[$room_index])) {
            wp_send_json_error(['message' => 'اتاق یافت نشد']);
        }

        // ایجاد لیست تاریخ‌های بین start و end
        $dates = $this->get_date_range($start_date, $end_date);

        // دریافت قیمت‌های فعلی
        $daily_prices = isset($rooms[$room_index]['daily_prices']) ? $rooms[$room_index]['daily_prices'] : [];

        // اعمال قیمت به همه تاریخ‌ها
        $count = 0;
        foreach ($dates as $date) {
            $daily_prices[$date] = $price;
            $count++;
        }

        // ذخیره
        $rooms[$room_index]['daily_prices'] = $daily_prices;
        update_post_meta($hotel_id, '_hotel_rooms', $rooms);

        // ذخیره در price_ranges برای نمایش در لیست
        $price_ranges = get_post_meta($hotel_id, '_hotel_price_ranges_' . $room_index, true);
        if (!is_array($price_ranges)) {
            $price_ranges = [];
        }

        $price_ranges[] = [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'price' => $price,
            'days_count' => $count,
            'created_at' => current_time('mysql')
        ];

        update_post_meta($hotel_id, '_hotel_price_ranges_' . $room_index, $price_ranges);

        wp_send_json_success([
            'message' => "قیمت برای {$count} روز با موفقیت ذخیره شد",
            'count' => $count,
            'total_days' => count($daily_prices)
        ]);
    }

    /**
     * دریافت لیست بازه‌های قیمتی
     */
    public function ajax_get_price_ranges() {
        check_ajax_referer('wchr_manager_nonce', 'nonce');

        $hotel_id = isset($_POST['hotel_id']) ? intval($_POST['hotel_id']) : 0;
        $room_index = isset($_POST['room_index']) ? intval($_POST['room_index']) : 0;

        $price_ranges = get_post_meta($hotel_id, '_hotel_price_ranges_' . $room_index, true);

        if (!is_array($price_ranges)) {
            $price_ranges = [];
        }

        wp_send_json_success($price_ranges);
    }

    /**
     * حذف یک بازه قیمتی
     */
    public function ajax_delete_price_range() {
        check_ajax_referer('wchr_manager_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'عدم دسترسی']);
        }

        $hotel_id = isset($_POST['hotel_id']) ? intval($_POST['hotel_id']) : 0;
        $room_index = isset($_POST['room_index']) ? intval($_POST['room_index']) : 0;
        $range_index = isset($_POST['range_index']) ? intval($_POST['range_index']) : -1;

        $price_ranges = get_post_meta($hotel_id, '_hotel_price_ranges_' . $room_index, true);

        if (!is_array($price_ranges) || !isset($price_ranges[$range_index])) {
            wp_send_json_error(['message' => 'بازه قیمتی یافت نشد']);
        }

        // حذف بازه
        $deleted_range = $price_ranges[$range_index];
        unset($price_ranges[$range_index]);
        $price_ranges = array_values($price_ranges); // reindex

        update_post_meta($hotel_id, '_hotel_price_ranges_' . $room_index, $price_ranges);

        // حذف قیمت‌های روزانه مربوط به این بازه
        $rooms = get_post_meta($hotel_id, '_hotel_rooms', true);
        if (isset($rooms[$room_index]['daily_prices'])) {
            $dates = $this->get_date_range($deleted_range['start_date'], $deleted_range['end_date']);
            foreach ($dates as $date) {
                unset($rooms[$room_index]['daily_prices'][$date]);
            }
            update_post_meta($hotel_id, '_hotel_rooms', $rooms);
        }

        wp_send_json_success(['message' => 'بازه قیمتی با موفقیت حذف شد']);
    }

    /**
     * دریافت لیست تاریخ‌های بین دو تاریخ
     */
    private function get_date_range($start_date, $end_date) {
        $dates = [];

        // تبدیل تاریخ شمسی به میلادی
        $start_parts = explode('/', $start_date);
        $end_parts = explode('/', $end_date);

        if (count($start_parts) != 3 || count($end_parts) != 3) {
            return $dates;
        }

        $start_gregorian = $this->jalali_to_gregorian($start_parts[0], $start_parts[1], $start_parts[2]);
        $end_gregorian = $this->jalali_to_gregorian($end_parts[0], $end_parts[1], $end_parts[2]);

        $current = strtotime($start_gregorian);
        $end = strtotime($end_gregorian);

        while ($current <= $end) {
            $g_date = date('Y/m/d', $current);
            $j_date = $this->gregorian_to_jalali_date($g_date);
            $dates[] = $j_date;
            $current = strtotime('+1 day', $current);
        }

        return $dates;
    }

    /**
     * تبدیل تاریخ شمسی به میلادی
     */
    private function jalali_to_gregorian($jy, $jm, $jd) {
        $jy = intval($jy);
        $jm = intval($jm);
        $jd = intval($jd);

        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy = ($jy > 979) ? 1600 : 979;
        $jy -= ($jy > 979) ? 979 : 0;
        $days = (365 * $jy) + ((int)($jy / 33) * 8) + (int)(($jy % 33 + 3) / 4) + 78 + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy += 400 * (int)($days / 146097);
        $days %= 146097;
        $leap = true;

        if ($days >= 36525) {
            $days--;
            $gy += 100 * (int)($days / 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
            $leap = false;
        }

        $gy += 4 * (int)($days / 1461);
        $days %= 1461;
        $gy += (int)(($days - 1) / 365);

        if ($days > 365) $days = ($days - 1) % 365;
        $gm = 0;
        $sal_a = [0, 31, (($leap && ($gy % 100 != 0)) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        for ($gm = 0; $gm < 13; $gm++) {
            $v = $sal_a[$gm];
            if ($days <= $v) break;
            $days -= $v;
        }

        return sprintf("%04d-%02d-%02d", $gy, $gm, $days);
    }

    /**
     * تبدیل تاریخ میلادی به شمسی
     */
    private function gregorian_to_jalali_date($g_date) {
        list($gy, $gm, $gd) = explode('/', str_replace('-', '/', $g_date));
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $jy = ($gy > 1600) ? 979 : 0;
        $gy -= ($gy > 1600) ? 1600 : 621;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * (int)($days / 12053);
        $days %= 12053;
        $jy += 4 * (int)($days / 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        $jm = ($days < 186) ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));

        return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
    }
}

WCHR_Advanced_Pricing::get_instance();
