<?php
/**
 * Plugin Name: WooCommerce Hotel Reserve - پنل مدیریت دستی
 * Description: سیستم رزرو هتل با پنل مدیریت و قیمت‌گذاری دستی
 * Version: 8.0.0
 * Author: بهادر
 * Text Domain: wc-hotel-reserve
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Hotel_Reserve {

    private static $instance = null;

    private function __construct() {
        $this->init_hooks();
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_hooks() {
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_data_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'add_product_data_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
        add_action('admin_footer', [$this, 'admin_calendar_modal']);
        
        add_action('add_meta_boxes', [$this, 'add_hotel_settings_metabox']);

        add_action('woocommerce_after_single_product_summary', [$this, 'display_hotel_rooms'], 5);
        add_action('wp_footer', [$this, 'frontend_scripts']);
        add_action('woocommerce_single_product_summary', [$this, 'hide_default_cart_elements'], 1);
        
        add_filter('the_title', [$this, 'add_stars_to_product_title'], 10, 2);

        add_action('wp_ajax_hotel_check_room_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_nopriv_hotel_check_room_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_hotel_add_room_to_cart', [$this, 'ajax_add_room_to_cart']);
        add_action('wp_ajax_nopriv_hotel_add_room_to_cart', [$this, 'ajax_add_room_to_cart']);

        add_action('woocommerce_before_calculate_totals', [$this, 'update_cart_item_price']);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_meta'], 10, 4);
        
        // مخفی کردن quantity در سبد خرید برای اتاق‌های هتل
        add_filter('woocommerce_cart_item_quantity', [$this, 'disable_cart_item_quantity'], 10, 3);
        add_filter('woocommerce_is_sold_individually', [$this, 'make_hotel_sold_individually'], 10, 2);
    }

    public function hide_default_cart_elements() {
        global $product;
        if ($product && get_post_meta($product->get_id(), '_enable_hotel_reservation', true) === 'yes') {
            echo '<style>
                form.cart { display: none !important; }
                .quantity { display: none !important; }
                .single_add_to_cart_button { display: none !important; }
            </style>';
        }
    }

    // غیرفعال کردن تغییر quantity در سبد خرید
    public function disable_cart_item_quantity($product_quantity, $cart_item_key, $cart_item) {
        if (isset($cart_item['hotel_room_id'])) {
            return '<span style="color:#666;font-weight:600;">1 (غیرقابل تغییر)</span>';
        }
        return $product_quantity;
    }

    // تنظیم محصول به صورت Sold Individually
    public function make_hotel_sold_individually($sold_individually, $product) {
        if ($product && get_post_meta($product->get_id(), '_enable_hotel_reservation', true) === 'yes') {
            return true;
        }
        return $sold_individually;
    }

    public function add_stars_to_product_title($title, $id) {
        if (!is_singular('product') || !in_the_loop() || !is_main_query()) {
            return $title;
        }
        
        $enabled = get_post_meta($id, '_enable_hotel_reservation', true);
        if ($enabled !== 'yes') {
            return $title;
        }
        
        $hotel_rating = get_post_meta($id, '_hotel_rating', true);
        if (empty($hotel_rating)) {
            return $title;
        }
        
        if (is_numeric($hotel_rating) && $hotel_rating > 0) {
            $stars = intval($hotel_rating);
            $stars_html = '<span style="color:#ffc107;font-size:0.8em;margin-right:8px;white-space:nowrap;">';
            for ($i = 0; $i < $stars; $i++) {
                $stars_html .= '⭐';
            }
            $stars_html .= '</span>';
            return $title . ' ' . $stars_html;
        } else {
            return $title . ' <span style="color:#666;font-size:0.85em;">(' . esc_html($hotel_rating) . ')</span>';
        }
    }

    public function add_product_data_tab($tabs) {
        $tabs['hotel_rooms'] = [
            'label' => '🏨 اتاق‌های هتل',
            'target' => 'hotel_rooms_data',
            'class' => ['show_if_simple']
        ];
        return $tabs;
    }

    public function add_hotel_settings_metabox() {
        add_meta_box(
            'hotel_general_settings',
            '🏨 تنظیمات عمومی هتل',
            [$this, 'render_hotel_settings_metabox'],
            'product',
            'normal',
            'high'
        );
    }

    public function render_hotel_settings_metabox($post) {
        $enabled = get_post_meta($post->ID, '_enable_hotel_reservation', true) === 'yes';
        
        if (!$enabled) {
            echo '<p style="padding:15px;background:#fff3cd;border-radius:5px;">⚠️ برای نمایش تنظیمات عمومی، ابتدا سیستم رزرو هتل را از تب "اتاق‌های هتل" فعال کنید.</p>';
            return;
        }
        
        $hotel_rating = get_post_meta($post->ID, '_hotel_rating', true) ?: '';
        $amenities = get_post_meta($post->ID, '_hotel_amenities', true);
        $amenities = is_array($amenities) ? $amenities : [];
        $check_in_time = get_post_meta($post->ID, '_hotel_check_in_time', true) ?: '14:00';
        $check_out_time = get_post_meta($post->ID, '_hotel_check_out_time', true) ?: '12:00';
        $cancellation_policy = get_post_meta($post->ID, '_hotel_cancellation_policy', true) ?: '';
        $hotel_rules = get_post_meta($post->ID, '_hotel_rules', true) ?: '';
        ?>
        <div style="padding:15px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin-bottom:20px;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">⭐ نوع و درجه اقامتگاه</label>
                    <input type="text" name="_hotel_rating" value="<?php echo esc_attr($hotel_rating); ?>" placeholder="مثال: 5 یا هتل آپارتمان یا بومگردی" style="width:100%;padding:10px;border:2px solid #e0e0e0;border-radius:6px;">
                    <small style="color:#666;">برای هتل ستاره‌دار فقط عدد وارد کنید (مثل: 5) و برای سایر موارد متن دلخواه (مثل: هتل آپارتمان)</small>
                </div>
                
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">🕐 ساعت ورود</label>
                    <input type="time" name="_hotel_check_in_time" value="<?php echo esc_attr($check_in_time); ?>" style="width:100%;padding:10px;border:2px solid #e0e0e0;border-radius:6px;">
                </div>
                
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">🕐 ساعت خروج</label>
                    <input type="time" name="_hotel_check_out_time" value="<?php echo esc_attr($check_out_time); ?>" style="width:100%;padding:10px;border:2px solid #e0e0e0;border-radius:6px;">
                </div>
            </div>
            
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">🎯 امکانات</label>
                <div id="amenities-container-metabox" style="margin-bottom:10px;">
                    <?php if (!empty($amenities)): ?>
                        <?php foreach ($amenities as $index => $amenity): ?>
                            <div class="amenity-item-metabox" style="display:flex;gap:10px;margin-bottom:10px;align-items:center;">
                                <input type="text" name="hotel_amenities[<?php echo $index; ?>][icon]" value="<?php echo esc_attr($amenity['icon'] ?? '✓'); ?>" placeholder="آیکون" style="width:60px;padding:10px;border:2px solid #e0e0e0;border-radius:6px;text-align:center;">
                                <input type="text" name="hotel_amenities[<?php echo $index; ?>][text]" value="<?php echo esc_attr($amenity['text'] ?? $amenity); ?>" placeholder="مثال: Wi-Fi رایگان" style="flex:1;padding:10px;border:2px solid #e0e0e0;border-radius:6px;">
                                <button type="button" class="remove-amenity-metabox" style="background:#dc3545;color:white;border:none;padding:10px 15px;border-radius:6px;cursor:pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button type="button" id="add-amenity-btn-metabox" class="button" style="margin-top:5px;">➕ افزودن امکانات</button>
            </div>
            
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">📋 قوانین کنسلی</label>
                <textarea name="_hotel_cancellation_policy" rows="3" style="width:100%;padding:10px;border:2px solid #e0e0e0;border-radius:6px;"><?php echo esc_textarea($cancellation_policy); ?></textarea>
            </div>
            
            <div>
                <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">📜 سایر قوانین</label>
                <textarea name="_hotel_rules" rows="3" style="width:100%;padding:10px;border:2px solid #e0e0e0;border-radius:6px;"><?php echo esc_textarea($hotel_rules); ?></textarea>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#add-amenity-btn-metabox').on('click', function() {
                var newIndex = $('.amenity-item-metabox').length;
                $('#amenities-container-metabox').append(
                    '<div class="amenity-item-metabox" style="display:flex;gap:10px;margin-bottom:10px;align-items:center;">' +
                    '<input type="text" name="hotel_amenities[' + newIndex + '][icon]" value="✓" placeholder="آیکون" style="width:60px;padding:10px;border:2px solid #e0e0e0;border-radius:6px;text-align:center;">' +
                    '<input type="text" name="hotel_amenities[' + newIndex + '][text]" placeholder="مثال: استخر" style="flex:1;padding:10px;border:2px solid #e0e0e0;border-radius:6px;">' +
                    '<button type="button" class="remove-amenity-metabox" style="background:#dc3545;color:white;border:none;padding:10px 15px;border-radius:6px;cursor:pointer;">✕</button>' +
                    '</div>'
                );
            });

            $(document).on('click', '.remove-amenity-metabox', function() {
                $(this).closest('.amenity-item-metabox').remove();
            });
        });
        </script>
        <?php
    }

    public function add_product_data_panel() {
        global $post;
        $enabled = get_post_meta($post->ID, '_enable_hotel_reservation', true) === 'yes';
        $rooms = get_post_meta($post->ID, '_hotel_rooms', true);
        $rooms = is_array($rooms) ? $rooms : [];
        ?>
        <div id="hotel_rooms_data" class="panel woocommerce_options_panel">
            <div class="options_group" style="padding:20px;">

                <div style="background:#e3f2fd;padding:20px;border-radius:10px;margin-bottom:25px;border-right:5px solid #2196f3;">
                    <label style="display:flex;align-items:center;cursor:pointer;">
                        <input type="checkbox" name="_enable_hotel_reservation" id="enable_hotel_reservation" value="yes" <?php checked($enabled, true); ?> style="width:20px;height:20px;margin-left:10px;">
                        <span style="font-size:16px;font-weight:bold;">✓ فعال کردن سیستم رزرو هتل</span>
                    </label>
                    <p style="margin:10px 0 0 0;color:#666;font-size:13px;">💡 پس از فعال‌سازی، تنظیمات عمومی هتل را در باکس "تنظیمات عمومی هتل" پایین توضیحات محصول وارد کنید.</p>
                </div>

                <div id="hotel-rooms-panel" style="<?php echo $enabled ? '' : 'display:none;'; ?>">

                    <button type="button" class="button button-primary button-large" id="add-new-room-btn" style="margin-bottom:20px;">
                        ➕ افزودن اتاق
                    </button>

                    <div id="hotel-rooms-container">
                        <?php if (!empty($rooms)): ?>
                            <?php foreach ($rooms as $index => $room): ?>
                                <?php $this->render_room_admin($index, $room); ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div id="no-rooms-msg" style="background:#fff3cd;padding:20px;border-radius:8px;text-align:center;">
                                <p style="margin:0;">⚠️ هنوز اتاقی اضافه نشده است.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <style>
        .room-admin-item {
            background: #fff;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 0;
            margin-bottom: 25px;
            overflow: hidden;
        }
        .room-admin-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 18px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .room-admin-header h3 { margin: 0; font-size: 17px; }
        .room-admin-body { padding: 25px; }
        .room-admin-fields {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .room-admin-fields { grid-template-columns: 1fr; }
        }
        .room-admin-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }
        .room-admin-field input,
        .room-admin-field textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .pricing-calendar-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var roomCounter = <?php echo count($rooms); ?>;

            $('#enable_hotel_reservation').on('change', function() {
                $('#hotel-rooms-panel').toggle($(this).is(':checked'));
            });

            $('#add-new-room-btn').on('click', function() {
                $('#no-rooms-msg').remove();
                $('#hotel-rooms-container').append(generateRoomHTML(roomCounter, {
                    name: '', guest_capacity: 2, inventory: 1, base_price: '',
                    description: '', allow_extra_guest: false, max_extra_guests: 0,
                    extra_guest_price: 0, daily_prices: {}, blocked_dates: []
                }));
                roomCounter++;
            });

            function generateRoomHTML(index, data) {
                return '<div class="room-admin-item" data-room-index="' + index + '">' +
                    '<div class="room-admin-header">' +
                        '<h3>🚪 اتاق #' + (index + 1) + '</h3>' +
                        '<button type="button" class="btn-remove-room">🗑️ حذف</button>' +
                    '</div>' +
                    '<div class="room-admin-body">' +
                        '<div class="room-admin-fields">' +
                            '<div class="room-admin-field"><label>نام *</label><input type="text" name="hotel_rooms[' + index + '][name]" required></div>' +
                            '<div class="room-admin-field"><label>ظرفیت *</label><input type="number" name="hotel_rooms[' + index + '][guest_capacity]" value="2" min="1" required></div>' +
                            '<div class="room-admin-field"><label>موجودی *</label><input type="number" name="hotel_rooms[' + index + '][inventory]" value="1" min="0" required style="background:#e8f5e9;"></div>' +
                            '<div class="room-admin-field"><label>قیمت پایه *</label><input type="number" name="hotel_rooms[' + index + '][base_price]" step="1000" required></div>' +
                            '<div class="room-admin-field" style="grid-column:1/-1;"><label>توضیحات</label><textarea name="hotel_rooms[' + index + '][description]"></textarea></div>' +
                        '</div>' +
                        '<div style="background:#fff3cd;padding:15px;border-radius:8px;margin-bottom:20px;">' +
                            '<label style="display:flex;align-items:center;cursor:pointer;margin-bottom:10px;">' +
                                '<input type="checkbox" class="allow-extra-guest" name="hotel_rooms[' + index + '][allow_extra_guest]" value="1" style="width:18px;height:18px;margin-left:8px;">' +
                                '<strong>نفر اضافه</strong>' +
                            '</label>' +
                            '<div class="extra-guest-fields" style="display:none;grid-template-columns:1fr 1fr;gap:15px;">' +
                                '<div><label style="font-size:13px;">حداکثر</label><input type="number" name="hotel_rooms[' + index + '][max_extra_guests]" value="0" min="0" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:5px;box-sizing:border-box;"></div>' +
                                '<div><label style="font-size:13px;">قیمت/شب</label><input type="number" name="hotel_rooms[' + index + '][extra_guest_price]" value="0" step="1000" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:5px;box-sizing:border-box;"></div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="pricing-calendar-section">' +
                            '<h4>📅 قیمت‌گذاری و بستن تاریخ</h4>' +
                            '<p style="margin:0 0 15px 0;color:#666;font-size:13px;">روی تاریخ‌ها کلیک کنید</p>' +
                            '<button type="button" class="btn-open-pricing-calendar" data-room-index="' + index + '" style="background:#2196f3;color:white;border:none;padding:12px 20px;border-radius:6px;cursor:pointer;font-weight:600;">📅 تقویم</button>' +
                            '<input type="hidden" name="hotel_rooms[' + index + '][daily_prices_json]" class="daily-prices-json">' +
                            '<input type="hidden" name="hotel_rooms[' + index + '][blocked_dates_json]" class="blocked-dates-json">' +
                            '<div class="selected-dates-preview" style="margin-top:15px;display:none;background:white;padding:15px;border-radius:6px;border:1px solid #e0e0e0;"></div>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            }

            $(document).on('change', '.allow-extra-guest', function() {
                $(this).closest('div').find('.extra-guest-fields').slideToggle();
            });

            $(document).on('click', '.btn-remove-room', function() {
                if (confirm('حذف شود؟')) {
                    $(this).closest('.room-admin-item').remove();
                }
            });

            var currentRoomIndex = null;
            var currentRoomData = { dailyPrices: {}, blockedDates: [] };

            $(document).on('click', '.btn-open-pricing-calendar', function() {
                currentRoomIndex = $(this).data('room-index');
                var roomItem = $('.room-admin-item[data-room-index="' + currentRoomIndex + '"]');
                
                var dailyPricesJson = roomItem.find('.daily-prices-json').val();
                var blockedDatesJson = roomItem.find('.blocked-dates-json').val();
                
                currentRoomData.dailyPrices = dailyPricesJson ? JSON.parse(dailyPricesJson) : {};
                currentRoomData.blockedDates = blockedDatesJson ? JSON.parse(blockedDatesJson) : [];
                
                window.adminSelectedDates = [];
                $('#admin-calendar-modal').fadeIn();
                renderPricingCalendar();
            });

            window.adminCurrentYear = null;
            window.adminCurrentMonth = null;
            window.adminSelectedDates = [];

            window.toggleDateSelection = function(dateStr) {
                var index = window.adminSelectedDates.indexOf(dateStr);
                if (index > -1) {
                    window.adminSelectedDates.splice(index, 1);
                } else {
                    window.adminSelectedDates.push(dateStr);
                }
                renderPricingCalendar();
            };

            window.renderPricingCalendar = function() {
                var today = new Date();
                var jalali = gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());

                if (!window.adminCurrentYear || !window.adminCurrentMonth) {
                    window.adminCurrentYear = jalali[0];
                    window.adminCurrentMonth = jalali[1];
                }

                $('#admin-cal-year').text(window.adminCurrentYear);
                $('#admin-cal-month').text(getPersianMonth(window.adminCurrentMonth));

                var html = '';
                var daysInMonth = getDaysInJalaliMonth(window.adminCurrentYear, window.adminCurrentMonth);
                var firstDayOfWeek = getFirstDayOfJalaliMonth(window.adminCurrentYear, window.adminCurrentMonth);

                for (var i = 0; i < firstDayOfWeek; i++) {
                    html += '<div class="admin-cal-day empty"></div>';
                }

                for (var day = 1; day <= daysInMonth; day++) {
                    var dateStr = window.adminCurrentYear + '/' + pad(window.adminCurrentMonth) + '/' + pad(day);
                    var classes = 'admin-cal-day';

                    if (window.adminSelectedDates.indexOf(dateStr) > -1) {
                        classes += ' selected';
                    }

                    if (currentRoomData.blockedDates.indexOf(dateStr) > -1) {
                        classes += ' blocked-date';
                    }
                    
                    if (currentRoomData.dailyPrices[dateStr]) {
                        classes += ' has-custom-price';
                    }

                    html += '<div class="' + classes + '" data-date="' + dateStr + '">' + day + '</div>';
                }

                $('#admin-calendar-days').html(html);
                updateSelectionInfo();
            };

            function updateSelectionInfo() {
                var count = window.adminSelectedDates.length;
                if (count > 0) {
                    $('#admin-selection-info').html('✓ ' + count + ' تاریخ انتخاب شده').show();
                } else {
                    $('#admin-selection-info').hide();
                }
            }

            $(document).on('click', '.admin-cal-day:not(.empty)', function() {
                toggleDateSelection($(this).data('date'));
            });

            $('#admin-cal-close').on('click', function() {
                $('#admin-calendar-modal').fadeOut();
            });

            $('#admin-cal-prev').on('click', function() {
                window.adminCurrentMonth--;
                if (window.adminCurrentMonth < 1) {
                    window.adminCurrentMonth = 12;
                    window.adminCurrentYear--;
                }
                renderPricingCalendar();
            });

            $('#admin-cal-next').on('click', function() {
                window.adminCurrentMonth++;
                if (window.adminCurrentMonth > 12) {
                    window.adminCurrentMonth = 1;
                    window.adminCurrentYear++;
                }
                renderPricingCalendar();
            });

            $('#admin-set-price-btn').on('click', function() {
                var price = $('#admin-price-input').val();
                if (!price || price <= 0) {
                    alert('قیمت معتبر وارد کنید');
                    return;
                }
                if (window.adminSelectedDates.length === 0) {
                    alert('تاریخ انتخاب کنید');
                    return;
                }

                window.adminSelectedDates.forEach(function(date) {
                    currentRoomData.dailyPrices[date] = parseInt(price);
                });

                saveCurrentRoomData();
                window.adminSelectedDates = [];
                $('#admin-price-input').val('');
                renderPricingCalendar();
                alert('قیمت تنظیم شد');
            });

            $('#admin-block-dates-btn').on('click', function() {
                if (window.adminSelectedDates.length === 0) {
                    alert('تاریخ انتخاب کنید');
                    return;
                }

                window.adminSelectedDates.forEach(function(date) {
                    if (currentRoomData.blockedDates.indexOf(date) === -1) {
                        currentRoomData.blockedDates.push(date);
                    }
                });

                saveCurrentRoomData();
                window.adminSelectedDates = [];
                renderPricingCalendar();
                alert('بسته شد');
            });

            $('#admin-unblock-dates-btn').on('click', function() {
                if (window.adminSelectedDates.length === 0) {
                    alert('تاریخ انتخاب کنید');
                    return;
                }

                window.adminSelectedDates.forEach(function(date) {
                    var index = currentRoomData.blockedDates.indexOf(date);
                    if (index > -1) {
                        currentRoomData.blockedDates.splice(index, 1);
                    }
                });

                saveCurrentRoomData();
                window.adminSelectedDates = [];
                renderPricingCalendar();
                alert('باز شد');
            });

            function saveCurrentRoomData() {
                if (currentRoomIndex === null) return;
                
                var roomItem = $('.room-admin-item[data-room-index="' + currentRoomIndex + '"]');
                roomItem.find('.daily-prices-json').val(JSON.stringify(currentRoomData.dailyPrices));
                roomItem.find('.blocked-dates-json').val(JSON.stringify(currentRoomData.blockedDates));
                
                var preview = roomItem.find('.selected-dates-preview');
                var priceCount = Object.keys(currentRoomData.dailyPrices).length;
                var blockedCount = currentRoomData.blockedDates.length;
                
                if (priceCount > 0 || blockedCount > 0) {
                    var html = '<strong>تنظیمات فعلی:</strong><br><br>';
                    
                    if (priceCount > 0) {
                        html += '<div style="margin-bottom:15px;"><strong style="color:#28a745;">📊 تاریخ‌های با قیمت سفارشی:</strong><br>';
                        var priceEntries = Object.entries(currentRoomData.dailyPrices).sort();
                        priceEntries.forEach(function(entry) {
                            html += '<span style="display:inline-block;background:#e8f5e9;padding:3px 8px;margin:3px;border-radius:4px;font-size:12px;">' + 
                                    entry[0] + ' → ' + parseInt(entry[1]).toLocaleString('fa-IR') + ' تومان</span>';
                        });
                        html += '</div>';
                    }
                    
                    if (blockedCount > 0) {
                        html += '<div><strong style="color:#dc3545;">🚫 تاریخ‌های بسته:</strong><br>';
                        var sortedBlocked = currentRoomData.blockedDates.slice().sort();
                        sortedBlocked.forEach(function(date) {
                            html += '<span style="display:inline-block;background:#ffe0e0;padding:3px 8px;margin:3px;border-radius:4px;font-size:12px;">' + 
                                    date + '</span>';
                        });
                        html += '</div>';
                    }
                    
                    preview.html(html).show();
                } else {
                    preview.hide();
                }
            }

            function gregorianToJalali(gy, gm, gd) {
                var g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
                var jy = (gy <= 1600) ? 0 : 979;
                gy -= (gy <= 1600) ? 621 : 1600;
                var gy2 = (gm > 2) ? (gy + 1) : gy;
                var days = (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
                jy += 33 * Math.floor(days / 12053);
                days %= 12053;
                jy += 4 * Math.floor(days / 1461);
                days %= 1461;
                if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
                var jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
                var jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
                return [jy, jm, jd];
            }

            function jalaliToGregorian(jy, jm, jd) {
                jy = parseInt(jy); 
                jm = parseInt(jm); 
                jd = parseInt(jd);
                
                var total_days = 0;
                
                for (var i = 1; i < jy; i++) {
                    if (i % 33 === 1 || i % 33 === 5 || i % 33 === 9 || i % 33 === 13 || i % 33 === 17 || i % 33 === 22 || i % 33 === 26 || i % 33 === 30) {
                        total_days += 366;
                    } else {
                        total_days += 365;
                    }
                }
                
                for (var i = 1; i < jm; i++) {
                    if (i <= 6) {
                        total_days += 31;
                    } else if (i <= 11) {
                        total_days += 30;
                    } else {
                        if (jy % 33 === 1 || jy % 33 === 5 || jy % 33 === 9 || jy % 33 === 13 || jy % 33 === 17 || jy % 33 === 22 || jy % 33 === 26 || jy % 33 === 30) {
                            total_days += 30;
                        } else {
                            total_days += 29;
                        }
                    }
                }
                
                total_days += jd;
                
                var reference_date = new Date(622, 2, 21);
                var result_date = new Date(reference_date.getTime() + (total_days - 1) * 86400000);
                
                return [result_date.getFullYear(), result_date.getMonth() + 1, result_date.getDate()];
            }

            function getDaysInJalaliMonth(year, month) {
                if (month <= 6) return 31;
                if (month <= 11) return 30;
                if (year % 33 === 1 || year % 33 === 5 || year % 33 === 9 || year % 33 === 13 || 
                    year % 33 === 17 || year % 33 === 22 || year % 33 === 26 || year % 33 === 30) {
                    return 30;
                }
                return 29;
            }

            function getFirstDayOfJalaliMonth(year, month) {
                var greg = jalaliToGregorian(year, month, 1);
                var date = new Date(greg[0], greg[1] - 1, greg[2]);
                var dayOfWeek = date.getDay();
                var persianDayOfWeek = (dayOfWeek + 1) % 7;
                return persianDayOfWeek;
            }

            function getPersianMonth(m) {
                var months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
                return months[m - 1];
            }

            function pad(n) { return n < 10 ? '0' + n : n; }
        });
        </script>
        <?php
    }

    private function render_room_admin($index, $room) {
        $daily_prices = $room['daily_prices'] ?? [];
        $blocked_dates = $room['blocked_dates'] ?? [];
        ?>
        <div class="room-admin-item" data-room-index="<?php echo $index; ?>">
            <div class="room-admin-header">
                <h3>🚪 <?php echo esc_html($room['name'] ?? 'اتاق #' . ($index + 1)); ?></h3>
                <button type="button" class="btn-remove-room">🗑️</button>
            </div>
            <div class="room-admin-body">
                <div class="room-admin-fields">
                    <div class="room-admin-field">
                        <label>نام *</label>
                        <input type="text" name="hotel_rooms[<?php echo $index; ?>][name]" value="<?php echo esc_attr($room['name'] ?? ''); ?>" required>
                    </div>
                    <div class="room-admin-field">
                        <label>ظرفیت *</label>
                        <input type="number" name="hotel_rooms[<?php echo $index; ?>][guest_capacity]" value="<?php echo esc_attr($room['guest_capacity'] ?? 2); ?>" min="1" required>
                    </div>
                    <div class="room-admin-field">
                        <label>موجودی *</label>
                        <input type="number" name="hotel_rooms[<?php echo $index; ?>][inventory]" value="<?php echo esc_attr($room['inventory'] ?? 1); ?>" min="0" required style="background:#e8f5e9;">
                    </div>
                    <div class="room-admin-field">
                        <label>قیمت پایه *</label>
                        <input type="number" name="hotel_rooms[<?php echo $index; ?>][base_price]" value="<?php echo esc_attr($room['base_price'] ?? ''); ?>" step="1000" required>
                    </div>
                    <div class="room-admin-field" style="grid-column:1/-1;">
                        <label>توضیحات</label>
                        <textarea name="hotel_rooms[<?php echo $index; ?>][description]"><?php echo esc_textarea($room['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div style="background:#fff3cd;padding:15px;border-radius:8px;margin-bottom:20px;">
                    <label style="display:flex;align-items:center;cursor:pointer;margin-bottom:10px;">
                        <input type="checkbox" class="allow-extra-guest" name="hotel_rooms[<?php echo $index; ?>][allow_extra_guest]" value="1" <?php checked($room['allow_extra_guest'] ?? false, true); ?> style="width:18px;height:18px;margin-left:8px;">
                        <strong>نفر اضافه</strong>
                    </label>
                    <div class="extra-guest-fields" style="display:<?php echo (!empty($room['allow_extra_guest']) ? 'grid' : 'none'); ?>;grid-template-columns:1fr 1fr;gap:15px;">
                        <div>
                            <label style="font-size:13px;">حداکثر</label>
                            <input type="number" name="hotel_rooms[<?php echo $index; ?>][max_extra_guests]" value="<?php echo esc_attr($room['max_extra_guests'] ?? 0); ?>" min="0" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:5px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="font-size:13px;">قیمت/شب</label>
                            <input type="number" name="hotel_rooms[<?php echo $index; ?>][extra_guest_price]" value="<?php echo esc_attr($room['extra_guest_price'] ?? 0); ?>" step="1000" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:5px;box-sizing:border-box;">
                        </div>
                    </div>
                </div>

                <div class="pricing-calendar-section">
                    <h4>📅 قیمت‌گذاری و بستن تاریخ</h4>
                    <p style="margin:0 0 15px 0;color:#666;font-size:13px;">روی تاریخ‌ها کلیک کنید</p>
                    <button type="button" class="btn-open-pricing-calendar" data-room-index="<?php echo $index; ?>" style="background:#2196f3;color:white;border:none;padding:12px 20px;border-radius:6px;cursor:pointer;font-weight:600;">📅 تقویم</button>
                    <input type="hidden" name="hotel_rooms[<?php echo $index; ?>][daily_prices_json]" class="daily-prices-json" value='<?php echo esc_attr(json_encode($daily_prices)); ?>'>
                    <input type="hidden" name="hotel_rooms[<?php echo $index; ?>][blocked_dates_json]" class="blocked-dates-json" value='<?php echo esc_attr(json_encode($blocked_dates)); ?>'>
                    <div class="selected-dates-preview" style="margin-top:15px;<?php echo (empty($daily_prices) && empty($blocked_dates)) ? 'display:none;' : ''; ?>background:white;padding:15px;border-radius:6px;border:1px solid #e0e0e0;">
                        <?php if (!empty($daily_prices) || !empty($blocked_dates)): ?>
                            <strong>تنظیمات فعلی:</strong><br><br>
                            <?php if (!empty($daily_prices)): ?>
                                <div style="margin-bottom:15px;"><strong style="color:#28a745;">📊 تاریخ‌های با قیمت سفارشی:</strong><br>
                                <?php 
                                ksort($daily_prices);
                                foreach ($daily_prices as $date => $price): ?>
                                    <span style="display:inline-block;background:#e8f5e9;padding:3px 8px;margin:3px;border-radius:4px;font-size:12px;"><?php echo $date; ?> → <?php echo number_format($price); ?> تومان</span>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($blocked_dates)): ?>
                                <div><strong style="color:#dc3545;">🚫 تاریخ‌های بسته:</strong><br>
                                <?php 
                                sort($blocked_dates);
                                foreach ($blocked_dates as $date): ?>
                                    <span style="display:inline-block;background:#ffe0e0;padding:3px 8px;margin:3px;border-radius:4px;font-size:12px;"><?php echo $date; ?></span>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function admin_calendar_modal() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'product') {
            ?>
            <div id="admin-calendar-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;align-items:center;justify-content:center;">
                <div style="background:white;border-radius:12px;width:600px;max-width:95%;">
                    <div style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:18px 20px;border-radius:12px 12px 0 0;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;font-size:16px;">📅 قیمت‌گذاری و مدیریت تاریخ</h3>
                        <button type="button" id="admin-cal-close" style="background:rgba(255,255,255,0.2);color:white;border:none;padding:6px 12px;border-radius:5px;cursor:pointer;">✕</button>
                    </div>
                    <div style="padding:20px;">
                        <div id="admin-selection-info" style="display:none;background:#e3f2fd;padding:10px;border-radius:6px;margin-bottom:15px;text-align:center;font-weight:600;color:#1976d2;"></div>
                        
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                            <button type="button" id="admin-cal-prev" style="background:#667eea;color:white;border:none;padding:8px 14px;border-radius:5px;cursor:pointer;">❮</button>
                            <div style="font-weight:600;"><span id="admin-cal-month"></span> <span id="admin-cal-year"></span></div>
                            <button type="button" id="admin-cal-next" style="background:#667eea;color:white;border:none;padding:8px 14px;border-radius:5px;cursor:pointer;">❯</button>
                        </div>
                        
                        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:5px;margin-bottom:10px;text-align:center;font-weight:bold;color:#666;font-size:13px;">
                            <div>ش</div><div>ی</div><div>د</div><div>س</div><div>چ</div><div>پ</div><div>ج</div>
                        </div>
                        <div id="admin-calendar-days" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:20px;"></div>

                        <div style="background:#f8f9fa;padding:15px;border-radius:8px;">
                            <div style="display:grid;gap:12px;">
                                <div style="display:flex;gap:10px;align-items:end;">
                                    <div style="flex:1;">
                                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">قیمت (تومان)</label>
                                        <input type="number" id="admin-price-input" placeholder="1000000" step="1000" style="width:100%;padding:10px;border:2px solid #e0e0e0;border-radius:6px;box-sizing:border-box;">
                                    </div>
                                    <button type="button" id="admin-set-price-btn" style="background:#28a745;color:white;border:none;padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:600;">💰 تنظیم</button>
                                </div>
                                <div style="display:flex;gap:10px;">
                                    <button type="button" id="admin-block-dates-btn" style="flex:1;background:#dc3545;color:white;border:none;padding:10px;border-radius:6px;cursor:pointer;font-weight:600;">🚫 بستن</button>
                                    <button type="button" id="admin-unblock-dates-btn" style="flex:1;background:#ffc107;color:#333;border:none;padding:10px;border-radius:6px;cursor:pointer;font-weight:600;">✓ باز کردن</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <style>
            .admin-cal-day {
                padding: 12px;
                text-align: center;
                border-radius: 6px;
                cursor: pointer;
                background: #f5f5f5;
                font-size: 14px;
                transition: all 0.2s;
                border: 2px solid transparent;
            }
            .admin-cal-day:hover:not(.empty) {
                background: #e3f2fd;
                transform: scale(1.05);
            }
            .admin-cal-day.selected {
                background: #667eea;
                color: white;
                font-weight: bold;
            }
            .admin-cal-day.blocked-date {
                background: #ffe0e0;
                text-decoration: line-through;
                color: #dc3545;
            }
            .admin-cal-day.blocked-date.selected {
                background: #dc3545;
                color: white;
            }
            .admin-cal-day.has-custom-price {
                border-color: #28a745;
                font-weight: bold;
            }
            .admin-cal-day.empty {
                background: transparent;
                cursor: default;
            }
            </style>
            <?php
        }
    }

    public function save_product_meta($post_id) {
        $enabled = isset($_POST['_enable_hotel_reservation']) && $_POST['_enable_hotel_reservation'] === 'yes' ? 'yes' : 'no';
        update_post_meta($post_id, '_enable_hotel_reservation', $enabled);

        if (isset($_POST['_hotel_rating'])) {
            update_post_meta($post_id, '_hotel_rating', sanitize_text_field($_POST['_hotel_rating']));
        }
        
        if (isset($_POST['hotel_amenities'])) {
            $amenities = [];
            foreach ($_POST['hotel_amenities'] as $amenity_data) {
                if (!empty($amenity_data['text'])) {
                    $amenities[] = [
                        'icon' => sanitize_text_field($amenity_data['icon'] ?? '✓'),
                        'text' => sanitize_text_field($amenity_data['text'])
                    ];
                }
            }
            update_post_meta($post_id, '_hotel_amenities', $amenities);
        } else {
            delete_post_meta($post_id, '_hotel_amenities');
        }
        
        if (isset($_POST['_hotel_check_in_time'])) {
            update_post_meta($post_id, '_hotel_check_in_time', sanitize_text_field($_POST['_hotel_check_in_time']));
        }
        
        if (isset($_POST['_hotel_check_out_time'])) {
            update_post_meta($post_id, '_hotel_check_out_time', sanitize_text_field($_POST['_hotel_check_out_time']));
        }
        
        if (isset($_POST['_hotel_cancellation_policy'])) {
            update_post_meta($post_id, '_hotel_cancellation_policy', wp_kses_post($_POST['_hotel_cancellation_policy']));
        }
        
        if (isset($_POST['_hotel_rules'])) {
            update_post_meta($post_id, '_hotel_rules', wp_kses_post($_POST['_hotel_rules']));
        }

        if (!isset($_POST['hotel_rooms'])) {
            delete_post_meta($post_id, '_hotel_rooms');
            return;
        }

        $rooms = [];
        foreach ($_POST['hotel_rooms'] as $index => $room_data) {
            if (!empty($room_data['name']) && !empty($room_data['base_price'])) {
                $daily_prices = [];
                if (!empty($room_data['daily_prices_json'])) {
                    $decoded = json_decode(stripslashes($room_data['daily_prices_json']), true);
                    if (is_array($decoded)) {
                        $daily_prices = $decoded;
                    }
                }

                $blocked_dates = [];
                if (!empty($room_data['blocked_dates_json'])) {
                    $decoded = json_decode(stripslashes($room_data['blocked_dates_json']), true);
                    if (is_array($decoded)) {
                        $blocked_dates = $decoded;
                    }
                }

                $rooms[] = [
                    'id' => 'room_' . $post_id . '_' . $index . '_' . time(),
                    'name' => sanitize_text_field($room_data['name']),
                    'guest_capacity' => intval($room_data['guest_capacity'] ?? 2),
                    'inventory' => intval($room_data['inventory'] ?? 1),
                    'base_price' => floatval($room_data['base_price']),
                    'description' => sanitize_textarea_field($room_data['description'] ?? ''),
                    'allow_extra_guest' => isset($room_data['allow_extra_guest']),
                    'max_extra_guests' => intval($room_data['max_extra_guests'] ?? 0),
                    'extra_guest_price' => floatval($room_data['extra_guest_price'] ?? 0),
                    'daily_prices' => $daily_prices,
                    'blocked_dates' => $blocked_dates
                ];
            }
        }

        update_post_meta($post_id, '_hotel_rooms', $rooms);
    }

    public function display_hotel_rooms() {
        global $product;

        if (!$product || !$product->is_type('simple')) return;
        $enabled = get_post_meta($product->get_id(), '_enable_hotel_reservation', true);
        if ($enabled !== 'yes') return;
        $rooms = get_post_meta($product->get_id(), '_hotel_rooms', true);
        if (empty($rooms) || !is_array($rooms)) return;

        $amenities = get_post_meta($product->get_id(), '_hotel_amenities', true);
        $amenities = is_array($amenities) ? $amenities : [];
        $check_in_time = get_post_meta($product->get_id(), '_hotel_check_in_time', true) ?: '14:00';
        $check_out_time = get_post_meta($product->get_id(), '_hotel_check_out_time', true) ?: '12:00';
        $cancellation_policy = get_post_meta($product->get_id(), '_hotel_cancellation_policy', true) ?: '';
        $hotel_rules = get_post_meta($product->get_id(), '_hotel_rules', true) ?: '';

        usort($rooms, function($a, $b) {
            return $a['base_price'] - $b['base_price'];
        });

        ?>
        <div class="hotel-rooms-section" style="margin:30px 0;padding:20px;background:#fff;border-radius:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
                <h3 style="font-size:22px;margin:0;">🏨 اتاق‌های موجود</h3>
            </div>

            <div id="hotel-search-box" style="background:linear-gradient(135deg,#FF6B6B,#FFE66D);padding:25px;border-radius:12px;margin-bottom:30px;box-shadow:0 8px 25px rgba(0,0,0,0.15);">
                <h4 style="color:#000;margin:0 0 20px 0;font-size:18px;font-weight:bold;">🔍 جستجوی اتاق بر اساس تاریخ</h4>
                <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:15px;align-items:end;">
                    <div>
                        <label style="color:#000;display:block;margin-bottom:8px;font-weight:700;">📅 تاریخ ورود</label>
                        <input type="text" id="search-checkin-display" readonly placeholder="انتخاب کنید..." style="width:100%;padding:12px;border:2px solid #000;border-radius:8px;font-size:14px;box-sizing:border-box;cursor:pointer;background:#fff;font-weight:600;">
                        <input type="hidden" id="search-checkin-value">
                    </div>
                    <div>
                        <label style="color:#000;display:block;margin-bottom:8px;font-weight:700;">🌙 تعداد شب</label>
                        <select id="search-nights" style="width:100%;padding:12px;border:2px solid #000;border-radius:8px;font-size:14px;box-sizing:border-box;cursor:pointer;background:#fff;font-weight:600;">
                            <option value="">انتخاب کنید...</option>
                            <?php for($i = 1; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?> شب</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="button" id="search-rooms-btn" style="background:#28a745;color:white;border:2px solid #000;padding:13px 30px;border-radius:8px;cursor:pointer;font-weight:bold;font-size:15px;white-space:nowrap;box-shadow:0 4px 10px rgba(0,0,0,0.2);">جستجو</button>
                        <button type="button" id="reset-search-btn" style="background:#dc3545;color:white;border:2px solid #000;padding:13px 20px;border-radius:8px;cursor:pointer;font-weight:bold;font-size:15px;display:none;box-shadow:0 4px 10px rgba(0,0,0,0.2);">پاک</button>
                    </div>
                </div>
            </div>

            <div id="search-calendar-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:999998;align-items:center;justify-content:center;padding:10px;box-sizing:border-box;">
                <div style="background:white;border-radius:15px;max-width:550px;width:100%;">
                    <div style="padding:20px;background:linear-gradient(135deg,#FF6B6B,#FFE66D);color:#000;border-radius:15px 15px 0 0;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;font-size:16px;font-weight:bold;">📅 انتخاب تاریخ ورود</h3>
                        <button type="button" id="search-cal-close" style="background:rgba(0,0,0,0.2);color:#000;border:none;padding:8px 15px;border-radius:5px;cursor:pointer;font-weight:bold;">✕</button>
                    </div>
                    <div style="padding:20px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                            <button type="button" id="search-cal-prev" style="background:#FF6B6B;color:white;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;font-weight:bold;">❮</button>
                            <div id="search-current-month-display" style="font-weight:bold;font-size:16px;"></div>
                            <button type="button" id="search-cal-next" style="background:#FF6B6B;color:white;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;font-weight:bold;">❯</button>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:12px;text-align:center;font-weight:bold;color:#666;font-size:12px;">
                            <div>ش</div><div>ی</div><div>د</div><div>س</div><div>چ</div><div>پ</div><div>ج</div>
                        </div>
                        <div id="search-calendar-days-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;"></div>
                    </div>
                </div>
            </div>

            <div class="rooms-grid" id="rooms-container" style="display:grid;gap:15px;margin-bottom:30px;"></div>

            <div id="unavailable-rooms-container" style="display:none;">
                <h4 style="color:#999;margin:30px 0 15px 0;font-size:16px;border-top:2px dashed #ddd;padding-top:20px;">اتاق‌های بدون ظرفیت در تاریخ انتخابی</h4>
                <div id="unavailable-rooms-list" style="display:grid;gap:15px;"></div>
            </div>

            <?php if (!empty($amenities) || !empty($check_in_time)): ?>
            <div style="background:#f8f9fa;border-radius:10px;padding:25px;margin-top:30px;">
                <?php if (!empty($amenities)): ?>
                    <div style="margin-bottom:25px;">
                        <h4 style="margin:0 0 15px 0;color:#667eea;">🎯 امکانات</h4>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
                            <?php foreach ($amenities as $amenity): ?>
                                <?php 
                                $icon = is_array($amenity) ? ($amenity['icon'] ?? '✓') : '✓';
                                $text = is_array($amenity) ? ($amenity['text'] ?? $amenity) : $amenity;
                                ?>
                                <div style="background:white;padding:12px;border-radius:8px;display:flex;align-items:center;">
                                    <span style="color:#28a745;margin-left:8px;font-size:18px;"><?php echo esc_html($icon); ?></span>
                                    <span style="font-size:14px;"><?php echo esc_html($text); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;">
                    <div style="background:white;padding:20px;border-radius:8px;">
                        <h4 style="margin:0 0 12px 0;color:#667eea;">🕐 ساعات</h4>
                        <p style="margin:0 0 8px 0;font-size:14px;"><strong>ورود:</strong> <?php echo esc_html($check_in_time); ?></p>
                        <p style="margin:0;font-size:14px;"><strong>خروج:</strong> <?php echo esc_html($check_out_time); ?></p>
                    </div>

                    <?php if (!empty($cancellation_policy)): ?>
                        <div style="background:white;padding:20px;border-radius:8px;">
                            <h4 style="margin:0 0 12px 0;color:#667eea;">📋 کنسلی</h4>
                            <p style="margin:0;font-size:14px;"><?php echo nl2br(esc_html($cancellation_policy)); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($hotel_rules)): ?>
                        <div style="background:white;padding:20px;border-radius:8px;">
                            <h4 style="margin:0 0 12px 0;color:#667eea;">📜 قوانین</h4>
                            <p style="margin:0;font-size:14px;"><?php echo nl2br(esc_html($hotel_rules)); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <style>
        .room-card {
            background:#f8f9fa;
            border:2px solid #e0e0e0;
            border-radius:10px;
            padding:18px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            transition:all 0.3s;
            flex-wrap:wrap;
            gap:15px;
        }
        .room-card:hover {
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            border-color: #667eea;
            transform: translateY(-2px);
        }
        .room-card.unavailable {
            background:#f5f5f5;
            border-color:#ccc;
            opacity:0.7;
        }
        .room-card.unavailable:hover {
            transform: none;
            box-shadow: none;
        }
        .search-cal-day {
            padding: 10px 4px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            background: #f8f9fa;
            border: 2px solid transparent;
            transition: all 0.3s;
            min-height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
        }
        .search-cal-day:hover:not(.disabled):not(.past):not(.empty) {
            background: #FFE66D;
            transform: translateY(-2px);
            border-color: #FF6B6B;
        }
        .search-cal-day.disabled, .search-cal-day.past { 
            background: #f5f5f5; 
            color: #ccc; 
            cursor: not-allowed; 
        }
        .search-cal-day.selected {
            background: linear-gradient(135deg,#FF6B6B,#FFE66D);
            color: #000;
            font-weight: bold;
            border-color: #000;
        }
        .search-cal-day.empty { 
            background: transparent; 
            cursor: default; 
        }
        </style>

        <script>
        var hotelRoomsData = <?php echo json_encode($rooms); ?>;
        var productId = <?php echo $product->get_id(); ?>;
        </script>
        <?php
    }

    public function frontend_scripts() {
        if (!is_product()) return;
        global $product;
        if (!$product || !$product->is_type('simple')) return;
        $enabled = get_post_meta($product->get_id(), '_enable_hotel_reservation', true);
        if ($enabled !== 'yes') return;
        $rooms = get_post_meta($product->get_id(), '_hotel_rooms', true);
        if (empty($rooms)) return;

        $ajax_url = admin_url('admin-ajax.php');
        $cart_url = wc_get_cart_url();
        ?>
        <div id="booking-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:999999;align-items:center;justify-content:center;padding:10px;box-sizing:border-box;">
            <div style="background:white;border-radius:15px;max-width:650px;width:100%;max-height:90vh;overflow-y:auto;" id="booking-modal-content">
                <div style="padding:20px;background:linear-gradient(135deg,#667eea,#764ba2);color:white;border-radius:15px 15px 0 0;display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;font-size:16px;" id="modal-title">📅 رزرو</h3>
                    <button type="button" id="close-modal-btn" style="background:rgba(255,255,255,0.2);color:white;border:none;padding:8px 15px;border-radius:5px;cursor:pointer;">✕</button>
                </div>
                <div style="padding:20px;">
                    <div id="booking-guide" style="background:#e3f2fd;padding:14px;border-radius:8px;margin-bottom:20px;text-align:center;font-weight:600;">
                        🎯 تاریخ ورود را انتخاب کنید
                    </div>

                    <div id="extra-guests-section" style="display:none;background:#fff3cd;padding:15px;border-radius:8px;margin-bottom:20px;">
                        <label style="display:block;font-weight:600;margin-bottom:10px;">👥 نفر اضافه:</label>
                        <select id="extra-guests-select" style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;"></select>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                        <button type="button" id="prev-month-btn" style="background:#667eea;color:white;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;">❮</button>
                        <div id="current-month-display" style="font-weight:bold;font-size:16px;"></div>
                        <button type="button" id="next-month-btn" style="background:#667eea;color:white;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;">❯</button>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:12px;text-align:center;font-weight:bold;color:#666;font-size:12px;">
                        <div>ش</div><div>ی</div><div>د</div><div>س</div><div>چ</div><div>پ</div><div>ج</div>
                    </div>
                    <div id="calendar-days-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;"></div>
                    
                    <div id="booking-summary" style="display:none;background:#f0f8ff;padding:18px;border-radius:10px;margin-top:20px;border:2px solid #2196f3;">
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:15px;margin-bottom:15px;">
                            <div><small style="color:#666;font-size:12px;">ورود</small><div style="font-weight:bold;color:#667eea;" id="sum-checkin">-</div></div>
                            <div><small style="color:#666;font-size:12px;">خروج</small><div style="font-weight:bold;color:#11998e;" id="sum-checkout">-</div></div>
                        </div>
                        <div style="margin-bottom:10px;"><strong>شب:</strong> <span id="sum-nights">0</span></div>
                        <div style="margin-bottom:10px;" id="sum-extra-info"></div>
                        <div style="margin-bottom:10px;font-size:16px;"><strong style="color:#28a745;">💰 جمع:</strong> <span id="sum-price" style="font-weight:bold;color:#28a745;">0</span> تومان</div>
                        <div id="price-detail" style="font-size:12px;color:#666;max-height:120px;overflow-y:auto;border-top:1px solid #ddd;padding-top:10px;"></div>
                    </div>
                    
                    <button type="button" id="reserve-room-btn" style="display:none;background:linear-gradient(135deg,#11998e,#38ef7d);color:white;padding:14px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:bold;width:100%;margin-top:20px;">
                        ✓ رزرو اتاق
                    </button>
                </div>
            </div>
        </div>

        <style>
        .cal-day {
            padding: 8px 4px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            background: #f8f9fa;
            border: 2px solid transparent;
            transition: all 0.3s;
            min-height: 50px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }
        .cal-day-number { font-size: 14px; font-weight: bold; }
        .cal-day-price { font-size: 10px; color: #28a745; margin-top: 2px; }
        .cal-day:hover:not(.disabled):not(.past):not(.empty):not(.blocked) {
            background: #e3f2fd;
            transform: translateY(-2px);
        }
        .cal-day.disabled, .cal-day.past, .cal-day.blocked { 
            background: #f5f5f5; 
            color: #ccc; 
            cursor: not-allowed; 
        }
        .cal-day.selected-in { background: linear-gradient(135deg,#667eea,#764ba2); color: white; }
        .cal-day.selected-out { background: linear-gradient(135deg,#11998e,#38ef7d); color: white; }
        .cal-day.in-range { background: #fff3cd; }
        .cal-day.empty { background: transparent; cursor: default; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var currentRoom = null;
            var currentYear, currentMonth;
            var selectedCheckIn = null;
            var selectedCheckOut = null;
            var selectingMode = 'checkin';
            var selectedExtraGuests = 0;
            var persianMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
            var searchActive = false;
            var searchCheckIn = '';
            var searchCheckOut = '';
            
            var searchCurrentYear, searchCurrentMonth;

            renderAllRooms();

            function gregorianToJalali(gy, gm, gd) {
                var g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
                var jy = (gy <= 1600) ? 0 : 979;
                gy -= (gy <= 1600) ? 621 : 1600;
                var gy2 = (gm > 2) ? (gy + 1) : gy;
                var days = (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
                jy += 33 * Math.floor(days / 12053);
                days %= 12053;
                jy += 4 * Math.floor(days / 1461);
                days %= 1461;
                if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
                var jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
                var jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
                return [jy, jm, jd];
            }

            function jalaliToGregorian(jy, jm, jd) {
                jy = parseInt(jy); 
                jm = parseInt(jm); 
                jd = parseInt(jd);
                
                var total_days = 0;
                
                for (var i = 1; i < jy; i++) {
                    if (i % 33 === 1 || i % 33 === 5 || i % 33 === 9 || i % 33 === 13 || i % 33 === 17 || i % 33 === 22 || i % 33 === 26 || i % 33 === 30) {
                        total_days += 366;
                    } else {
                        total_days += 365;
                    }
                }
                
                for (var i = 1; i < jm; i++) {
                    if (i <= 6) {
                        total_days += 31;
                    } else if (i <= 11) {
                        total_days += 30;
                    } else {
                        if (jy % 33 === 1 || jy % 33 === 5 || jy % 33 === 9 || jy % 33 === 13 || jy % 33 === 17 || jy % 33 === 22 || jy % 33 === 26 || jy % 33 === 30) {
                            total_days += 30;
                        } else {
                            total_days += 29;
                        }
                    }
                }
                
                total_days += jd;
                
                var reference_date = new Date(622, 2, 21);
                var result_date = new Date(reference_date.getTime() + (total_days - 1) * 86400000);
                
                return [result_date.getFullYear(), result_date.getMonth() + 1, result_date.getDate()];
            }

            function pad(n) { return n < 10 ? '0' + n : n; }
            function formatDate(y, m, d) { return y + '/' + pad(m) + '/' + pad(d); }
            
            function getTodayJalali() {
                var now = new Date();
                return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
            }
            
            function getDaysInMonth(year, month) {
                if (month <= 6) return 31;
                if (month <= 11) return 30;
                if (year % 33 === 1 || year % 33 === 5 || year % 33 === 9 || year % 33 === 13 || 
                    year % 33 === 17 || year % 33 === 22 || year % 33 === 26 || year % 33 === 30) {
                    return 30;
                }
                return 29;
            }
            
            function getFirstDayOfMonth(year, month) {
                var greg = jalaliToGregorian(year, month, 1);
                var date = new Date(greg[0], greg[1] - 1, greg[2]);
                var dayOfWeek = date.getDay();
                var persianDayOfWeek = (dayOfWeek + 1) % 7;
                return persianDayOfWeek;
            }

            function isDateBlocked(dateStr) {
                if (!currentRoom || !currentRoom.blocked_dates) return false;
                return currentRoom.blocked_dates.indexOf(dateStr) > -1;
            }

            function getDayPrice(dateStr) {
                if (!currentRoom) return 0;
                
                if (currentRoom.daily_prices && currentRoom.daily_prices[dateStr]) {
                    return parseInt(currentRoom.daily_prices[dateStr]);
                }
                
                return parseInt(currentRoom.base_price);
            }

            function renderCalendar() {
                var daysInMonth = getDaysInMonth(currentYear, currentMonth);
                var firstDay = getFirstDayOfMonth(currentYear, currentMonth);
                var today = getTodayJalali();
                var todayStr = formatDate(today[0], today[1], today[2]);

                $('#current-month-display').text(persianMonths[currentMonth - 1] + ' ' + currentYear);

                var html = '';
                
                for (var i = 0; i < firstDay; i++) {
                    html += '<div class="cal-day empty"></div>';
                }

                for (var day = 1; day <= daysInMonth; day++) {
                    var dateStr = formatDate(currentYear, currentMonth, day);
                    var classes = ['cal-day'];
                    var disabled = false;

                    if (dateStr < todayStr) {
                        classes.push('past');
                        disabled = true;
                    }

                    if (isDateBlocked(dateStr)) {
                        classes.push('blocked');
                        disabled = true;
                    }

                    if (selectedCheckIn && dateStr === selectedCheckIn) classes.push('selected-in');
                    if (selectedCheckOut && dateStr === selectedCheckOut) classes.push('selected-out');
                    if (selectedCheckIn && selectedCheckOut && dateStr > selectedCheckIn && dateStr < selectedCheckOut) {
                        classes.push('in-range');
                    }

                    var dayPrice = getDayPrice(dateStr);
                    var priceFormatted = '';
                    
                    if (dayPrice >= 1000000) {
                        priceFormatted = (dayPrice / 1000000).toFixed(1) + 'م';
                    } else if (dayPrice >= 1000) {
                        priceFormatted = (dayPrice / 1000).toFixed(0) + 'هزار';
                    } else {
                        priceFormatted = dayPrice.toString();
                    }

                    html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '" data-disabled="' + disabled + '">' +
                        '<div class="cal-day-number">' + day + '</div>' +
                        '<div class="cal-day-price">' + priceFormatted + '</div>' +
                        '</div>';
                }

                $('#calendar-days-grid').html(html);
            }

            function renderSearchCalendar() {
                var today = getTodayJalali();
                
                if (!searchCurrentYear || !searchCurrentMonth) {
                    searchCurrentYear = today[0];
                    searchCurrentMonth = today[1];
                }

                var daysInMonth = getDaysInMonth(searchCurrentYear, searchCurrentMonth);
                var firstDay = getFirstDayOfMonth(searchCurrentYear, searchCurrentMonth);
                var todayStr = formatDate(today[0], today[1], today[2]);

                $('#search-current-month-display').text(persianMonths[searchCurrentMonth - 1] + ' ' + searchCurrentYear);

                var html = '';
                
                for (var i = 0; i < firstDay; i++) {
                    html += '<div class="search-cal-day empty"></div>';
                }

                for (var day = 1; day <= daysInMonth; day++) {
                    var dateStr = formatDate(searchCurrentYear, searchCurrentMonth, day);
                    var classes = ['search-cal-day'];
                    var disabled = false;

                    if (dateStr < todayStr) {
                        classes.push('past');
                        disabled = true;
                    }

                    if ($('#search-checkin-value').val() === dateStr) {
                        classes.push('selected');
                    }

                    html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '" data-disabled="' + disabled + '">' + day + '</div>';
                }

                $('#search-calendar-days-grid').html(html);
            }

            $('#search-checkin-display').on('click', function() {
                var today = getTodayJalali();
                searchCurrentYear = today[0];
                searchCurrentMonth = today[1];
                renderSearchCalendar();
                $('#search-calendar-modal').css('display', 'flex');
            });

            $('#search-cal-close, #search-calendar-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#search-calendar-modal').css('display', 'none');
                }
            });

            $('#search-cal-prev').on('click', function() {
                searchCurrentMonth--;
                if (searchCurrentMonth < 1) {
                    searchCurrentMonth = 12;
                    searchCurrentYear--;
                }
                renderSearchCalendar();
            });

            $('#search-cal-next').on('click', function() {
                searchCurrentMonth++;
                if (searchCurrentMonth > 12) {
                    searchCurrentMonth = 1;
                    searchCurrentYear++;
                }
                renderSearchCalendar();
            });

            $(document).on('click', '.search-cal-day:not(.empty):not(.past):not(.disabled)', function() {
                var selectedDate = $(this).data('date');
                $('#search-checkin-value').val(selectedDate);
                $('#search-checkin-display').val(selectedDate);
                $('#search-calendar-modal').css('display', 'none');
            });

            function scrollToBookingSummary() {
                setTimeout(function() {
                    var summaryElement = document.getElementById('booking-summary');
                    if (summaryElement) {
                        summaryElement.scrollIntoView({ 
                            behavior: 'smooth', 
                            block: 'nearest' 
                        });
                    }
                }, 300);
            }

            function calculatePrice() {
                if (!selectedCheckIn || !selectedCheckOut || !currentRoom) return;

                selectedExtraGuests = parseInt($('#extra-guests-select').val() || 0);

                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'hotel_check_room_availability',
                    room_data: JSON.stringify(currentRoom),
                    check_in: selectedCheckIn,
                    check_out: selectedCheckOut,
                    extra_guests: selectedExtraGuests,
                    product_id: productId,
                    nonce: '<?php echo wp_create_nonce("hotel_booking"); ?>'
                }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        $('#sum-nights').text(data.nights);

                        if (selectedExtraGuests > 0) {
                            $('#sum-extra-info').html('<strong>👥 نفر اضافه:</strong> ' + selectedExtraGuests + ' نفر').show();
                        } else {
                            $('#sum-extra-info').hide();
                        }

                        $('#sum-price').text(data.total.toLocaleString('fa-IR'));

                        var detail = '<strong>جزئیات:</strong><br>';
                        data.breakdown.forEach(function(item) {
                            detail += item.date + ': ' + item.price.toLocaleString('fa-IR') + '<br>';
                        });

                        if (data.extra_guest_total > 0) {
                            detail += '<br><strong>نفرات اضافه:</strong> ' + data.extra_guest_total.toLocaleString('fa-IR');
                        }

                        $('#price-detail').html(detail);
                        $('#booking-summary').show();
                        $('#reserve-room-btn').show();
                        
                        scrollToBookingSummary();
                    } else {
                        alert('❌ ' + (response.data && response.data.message ? response.data.message : 'خطا'));
                        selectedCheckIn = null;
                        selectedCheckOut = null;
                        selectingMode = 'checkin';
                        $('#booking-summary').hide();
                        $('#reserve-room-btn').hide();
                        renderCalendar();
                    }
                }).fail(function() {
                    alert('خطا در ارتباط');
                });
            }

            function renderAllRooms() {
                var container = $('#rooms-container');
                container.empty();
                
                hotelRoomsData.forEach(function(room) {
                    if (!room.inventory || room.inventory <= 0) return;
                    
                    var minPrice = room.base_price;
                    if (room.daily_prices) {
                        Object.values(room.daily_prices).forEach(function(price) {
                            if (price < minPrice) minPrice = price;
                        });
                    }
                    
                    var html = '<div class="room-card" data-room=\'' + JSON.stringify(room) + '\'>' +
                        '<div style="flex:1;min-width:250px;">' +
                            '<h4 style="margin:0 0 10px 0;font-size:18px;color:#667eea;">🚪 ' + room.name + '</h4>';
                    
                    if (room.description) {
                        html += '<p style="margin:0 0 10px 0;color:#666;font-size:14px;">' + room.description + '</p>';
                    }
                    
                    html += '<div style="color:#999;font-size:13px;">👥 ظرفیت: ' + room.guest_capacity + ' نفر';
                    
                    if (room.allow_extra_guest && room.max_extra_guests > 0) {
                        html += '<span style="color:#28a745;font-weight:600;"> + تا ' + room.max_extra_guests + ' نفر</span>';
                    }
                    
                    html += '</div></div>' +
                        '<div style="text-align:left;padding:0 20px;">' +
                            '<div style="color:#999;font-size:12px;margin-bottom:5px;">از:</div>' +
                            '<div style="font-size:22px;font-weight:bold;color:#28a745;margin-bottom:12px;">' +
                                minPrice.toLocaleString('fa-IR') + ' <span style="font-size:13px;">تومان</span>' +
                            '</div>' +
                            '<button type="button" class="room-select-btn" style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;border:none;padding:12px 22px;border-radius:8px;cursor:pointer;font-weight:bold;">📅 رزرو</button>' +
                        '</div>' +
                    '</div>';
                    
                    container.append(html);
                });
            }

            // تابع بررسی تاریخ‌های بسته در بازه
            function hasBlockedDatesInRange(room, checkin, checkout) {
                if (!room.blocked_dates || room.blocked_dates.length === 0) {
                    return false;
                }
                
                // بررسی هر تاریخ در بازه
                var checkinParts = checkin.split('/');
                var checkoutParts = checkout.split('/');
                
                var checkinGreg = jalaliToGregorian(checkinParts[0], checkinParts[1], checkinParts[2]);
                var checkoutGreg = jalaliToGregorian(checkoutParts[0], checkoutParts[1], checkoutParts[2]);
                
                var currentDate = new Date(checkinGreg[0], checkinGreg[1] - 1, checkinGreg[2]);
                var endDate = new Date(checkoutGreg[0], checkoutGreg[1] - 1, checkoutGreg[2]);
                
                while (currentDate < endDate) {
                    var jalaliDate = gregorianToJalali(currentDate.getFullYear(), currentDate.getMonth() + 1, currentDate.getDate());
                    var dateStr = formatDate(jalaliDate[0], jalaliDate[1], jalaliDate[2]);
                    
                    if (room.blocked_dates.indexOf(dateStr) > -1) {
                        return true;
                    }
                    
                    currentDate.setDate(currentDate.getDate() + 1);
                }
                
                return false;
            }

            $('#search-rooms-btn').on('click', function() {
                var checkin = $('#search-checkin-value').val();
                var nights = parseInt($('#search-nights').val());
                
                if (!checkin) {
                    alert('لطفاً تاریخ ورود را انتخاب کنید');
                    return;
                }
                
                if (!nights) {
                    alert('لطفاً تعداد شب را انتخاب کنید');
                    return;
                }
                
                var checkinParts = checkin.split('/');
                var checkinGreg = jalaliToGregorian(checkinParts[0], checkinParts[1], checkinParts[2]);
                var checkoutDate = new Date(checkinGreg[0], checkinGreg[1] - 1, checkinGreg[2]);
                checkoutDate.setDate(checkoutDate.getDate() + nights);
                
                var checkoutJalali = gregorianToJalali(checkoutDate.getFullYear(), checkoutDate.getMonth() + 1, checkoutDate.getDate());
                var checkout = formatDate(checkoutJalali[0], checkoutJalali[1], checkoutJalali[2]);
                
                searchCheckIn = checkin;
                searchCheckOut = checkout;
                searchActive = true;
                
                $('#reset-search-btn').show();
                
                var availableRooms = [];
                var unavailableRooms = [];
                var processed = 0;
                
                hotelRoomsData.forEach(function(room) {
                    if (!room.inventory || room.inventory <= 0) {
                        processed++;
                        return;
                    }
                    
                    // بررسی تاریخ‌های بسته
                    if (hasBlockedDatesInRange(room, checkin, checkout)) {
                        unavailableRooms.push(room);
                        processed++;
                        
                        if (processed === hotelRoomsData.length) {
                            displaySearchResults(availableRooms, unavailableRooms);
                        }
                        return;
                    }
                    
                    $.post('<?php echo esc_js($ajax_url); ?>', {
                        action: 'hotel_check_room_availability',
                        room_data: JSON.stringify(room),
                        check_in: checkin,
                        check_out: checkout,
                        extra_guests: 0,
                        product_id: productId,
                        nonce: '<?php echo wp_create_nonce("hotel_booking"); ?>'
                    }, function(response) {
                        processed++;
                        
                        if (response.success && response.data.available) {
                            availableRooms.push(room);
                        } else {
                            unavailableRooms.push(room);
                        }
                        
                        if (processed === hotelRoomsData.length) {
                            displaySearchResults(availableRooms, unavailableRooms);
                        }
                    });
                });
            });

            function displaySearchResults(available, unavailable) {
                var container = $('#rooms-container');
                var unavailableContainer = $('#unavailable-rooms-list');
                
                container.empty();
                unavailableContainer.empty();
                
                if (available.length > 0) {
                    available.forEach(function(room) {
                        var html = createRoomCard(room, false);
                        container.append(html);
                    });
                } else {
                    container.html('<div style="background:#fff3cd;padding:20px;border-radius:8px;text-align:center;"><p style="margin:0;">⚠️ متأسفانه در این تاریخ اتاق موجود نیست.</p></div>');
                }
                
                if (unavailable.length > 0) {
                    $('#unavailable-rooms-container').show();
                    unavailable.forEach(function(room) {
                        var html = createRoomCard(room, true);
                        unavailableContainer.append(html);
                    });
                } else {
                    $('#unavailable-rooms-container').hide();
                }
            }

            function createRoomCard(room, unavailable) {
                var minPrice = room.base_price;
                if (room.daily_prices) {
                    Object.values(room.daily_prices).forEach(function(price) {
                        if (price < minPrice) minPrice = price;
                    });
                }
                
                var unavailableClass = unavailable ? ' unavailable' : '';
                var unavailableText = unavailable ? '<div style="background:#dc3545;color:white;padding:8px;border-radius:5px;margin-top:10px;font-size:13px;text-align:center;">⚠️ ظرفیت ندارد</div>' : '';
                
                var html = '<div class="room-card' + unavailableClass + '" data-room=\'' + JSON.stringify(room) + '\'>' +
                    '<div style="flex:1;min-width:250px;">' +
                        '<h4 style="margin:0 0 10px 0;font-size:18px;color:#667eea;">🚪 ' + room.name + '</h4>';
                
                if (room.description) {
                    html += '<p style="margin:0 0 10px 0;color:#666;font-size:14px;">' + room.description + '</p>';
                }
                
                html += '<div style="color:#999;font-size:13px;">👥 ظرفیت: ' + room.guest_capacity + ' نفر';
                
                if (room.allow_extra_guest && room.max_extra_guests > 0) {
                    html += '<span style="color:#28a745;font-weight:600;"> + تا ' + room.max_extra_guests + ' نفر</span>';
                }
                
                html += '</div>' + unavailableText + '</div>' +
                    '<div style="text-align:left;padding:0 20px;">' +
                        '<div style="color:#999;font-size:12px;margin-bottom:5px;">از:</div>' +
                        '<div style="font-size:22px;font-weight:bold;color:#28a745;margin-bottom:12px;">' +
                            minPrice.toLocaleString('fa-IR') + ' <span style="font-size:13px;">تومان</span>' +
                        '</div>';
                
                if (!unavailable) {
                    html += '<button type="button" class="room-select-btn" style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;border:none;padding:12px 22px;border-radius:8px;cursor:pointer;font-weight:bold;">📅 رزرو</button>';
                } else {
                    html += '<button type="button" disabled style="background:#ccc;color:#666;border:none;padding:12px 22px;border-radius:8px;cursor:not-allowed;font-weight:bold;">غیرفعال</button>';
                }
                
                html += '</div></div>';
                
                return html;
            }

            $('#reset-search-btn').on('click', function() {
                searchActive = false;
                searchCheckIn = '';
                searchCheckOut = '';
                $('#search-checkin-value').val('');
                $('#search-checkin-display').val('');
                $('#search-nights').val('');
                $(this).hide();
                $('#unavailable-rooms-container').hide();
                renderAllRooms();
            });

            $(document).on('click', '.room-select-btn', function() {
                currentRoom = JSON.parse($(this).closest('.room-card').attr('data-room'));
                $('#modal-title').text('📅 رزرو ' + currentRoom.name);

                if (currentRoom.allow_extra_guest && currentRoom.max_extra_guests > 0) {
                    var options = '<option value="0">بدون نفر اضافه</option>';
                    for (var i = 1; i <= currentRoom.max_extra_guests; i++) {
                        options += '<option value="' + i + '">' + i + ' نفر (' + currentRoom.extra_guest_price.toLocaleString('fa-IR') + ' تومان/نفر/شب)</option>';
                    }
                    $('#extra-guests-select').html(options);
                    $('#extra-guests-section').show();
                } else {
                    $('#extra-guests-section').hide();
                }

                var today = getTodayJalali();
                currentYear = today[0];
                currentMonth = today[1];
                
                selectedCheckIn = null;
                selectedCheckOut = null;
                selectingMode = 'checkin';
                selectedExtraGuests = 0;
                
                $('#sum-checkin').text('-');
                $('#sum-checkout').text('-');
                $('#booking-guide').html('🎯 تاریخ ورود را انتخاب کنید');
                $('#booking-summary').hide();
                $('#reserve-room-btn').hide();
                
                renderCalendar();
                $('#booking-modal').addClass('active').css('display', 'flex');
            });

            $('#close-modal-btn, #booking-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#booking-modal').removeClass('active').css('display', 'none');
                }
            });

            $(document).on('click', '.cal-day', function() {
                if ($(this).data('disabled') || $(this).hasClass('empty') || $(this).hasClass('blocked')) return;

                var dateStr = $(this).data('date');

                if (selectingMode === 'checkin') {
                    selectedCheckIn = dateStr;
                    selectedCheckOut = null;
                    selectingMode = 'checkout';
                    $('#sum-checkin').text(dateStr);
                    $('#sum-checkout').text('-');
                    $('#booking-guide').html('🎯 تاریخ خروج را انتخاب کنید');
                    $('#booking-summary').hide();
                    $('#reserve-room-btn').hide();
                    renderCalendar();
                } else {
                    if (dateStr <= selectedCheckIn) {
                        alert('❌ تاریخ خروج باید بعد از ورود باشد');
                        return;
                    }
                    
                    selectedCheckOut = dateStr;
                    $('#sum-checkout').text(dateStr);
                    selectingMode = 'checkin';
                    renderCalendar();
                    calculatePrice();
                }
            });

            $('#extra-guests-select').on('change', function() {
                if (selectedCheckIn && selectedCheckOut) {
                    calculatePrice();
                }
            });

            $('#prev-month-btn').on('click', function() {
                currentMonth--;
                if (currentMonth < 1) { currentMonth = 12; currentYear--; }
                renderCalendar();
            });

            $('#next-month-btn').on('click', function() {
                currentMonth++;
                if (currentMonth > 12) { currentMonth = 1; currentYear++; }
                renderCalendar();
            });

            $('#reserve-room-btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('در حال رزرو...');

                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'hotel_add_room_to_cart',
                    product_id: productId,
                    room_data: JSON.stringify(currentRoom),
                    check_in: selectedCheckIn,
                    check_out: selectedCheckOut,
                    extra_guests: selectedExtraGuests,
                    nonce: '<?php echo wp_create_nonce("hotel_add_cart"); ?>'
                }, function(response) {
                    if (response.success) {
                        window.location.href = '<?php echo esc_js($cart_url); ?>';
                    } else {
                        alert('❌ ' + (response.data && response.data.message ? response.data.message : 'خطا'));
                        btn.prop('disabled', false).text('✓ رزرو اتاق');
                    }
                }).fail(function() {
                    alert('خطا در ارتباط');
                    btn.prop('disabled', false).text('✓ رزرو اتاق');
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_check_availability() {
        check_ajax_referer('hotel_booking', 'nonce');

        $room_data = json_decode(stripslashes($_POST['room_data']), true);
        $check_in = sanitize_text_field($_POST['check_in']);
        $check_out = sanitize_text_field($_POST['check_out']);
        $extra_guests = intval($_POST['extra_guests'] ?? 0);
        $product_id = intval($_POST['product_id']);

        $available = $this->check_room_availability($product_id, $room_data['id'], $check_in, $check_out);
        
        if (!$available) {
            wp_send_json_error(['message' => 'موجود نیست']);
            return;
        }

        $pricing = $this->calculate_room_price($room_data, $check_in, $check_out, $extra_guests);
        wp_send_json_success(array_merge(['available' => true], $pricing));
    }

    private function check_room_availability($product_id, $room_id, $check_in, $check_out) {
        $rooms = get_post_meta($product_id, '_hotel_rooms', true);
        $current_room = null;
        
        foreach ($rooms as $room) {
            if ($room['id'] === $room_id) {
                $current_room = $room;
                break;
            }
        }

        if (!$current_room || !isset($current_room['inventory'])) {
            return false;
        }

        $total_inventory = intval($current_room['inventory']);
        if ($total_inventory <= 0) return false;

        // بررسی تاریخ‌های بسته
        if (!empty($current_room['blocked_dates'])) {
            $check_in_parts = explode('/', $check_in);
            $check_out_parts = explode('/', $check_out);
            
            $check_in_gregorian = $this->jalali_to_gregorian($check_in_parts[0], $check_in_parts[1], $check_in_parts[2]);
            $check_out_gregorian = $this->jalali_to_gregorian($check_out_parts[0], $check_out_parts[1], $check_out_parts[2]);
            
            $current_date = new DateTime($check_in_gregorian[0] . '-' . $check_in_gregorian[1] . '-' . $check_in_gregorian[2]);
            $end_date = new DateTime($check_out_gregorian[0] . '-' . $check_out_gregorian[1] . '-' . $check_out_gregorian[2]);
            
            while ($current_date < $end_date) {
                $current_jalali = $this->gregorian_to_jalali(
                    $current_date->format('Y'),
                    $current_date->format('m'),
                    $current_date->format('d')
                );
                
                $date_str = $current_jalali[0] . '/' . 
                           str_pad($current_jalali[1], 2, '0', STR_PAD_LEFT) . '/' . 
                           str_pad($current_jalali[2], 2, '0', STR_PAD_LEFT);
                
                if (in_array($date_str, $current_room['blocked_dates'])) {
                    return false;
                }
                
                $current_date->modify('+1 day');
            }
        }

        $orders = wc_get_orders([
            'limit' => -1,
            'status' => ['processing', 'completed'],
            'return' => 'ids'
        ]);

        $booked_count = 0;

        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            
            foreach ($order->get_items() as $item) {
                $item_product_id = $item->get_product_id();
                $item_room_id = $item->get_meta('_hotel_room_data')['id'] ?? '';
                $item_check_in = $item->get_meta('_hotel_check_in');
                $item_check_out = $item->get_meta('_hotel_check_out');

                if ($item_product_id == $product_id && $item_room_id === $room_id) {
                    if (!($check_out <= $item_check_in || $check_in >= $item_check_out)) {
                        $booked_count++;
                    }
                }
            }
        }

        return ($booked_count < $total_inventory);
    }

    private function calculate_room_price($room, $check_in, $check_out, $extra_guests = 0) {
        $check_in_parts = explode('/', $check_in);
        $check_out_parts = explode('/', $check_out);

        $check_in_gregorian = $this->jalali_to_gregorian($check_in_parts[0], $check_in_parts[1], $check_in_parts[2]);
        $check_out_gregorian = $this->jalali_to_gregorian($check_out_parts[0], $check_out_parts[1], $check_out_parts[2]);

        $start = new DateTime($check_in_gregorian[0] . '-' . $check_in_gregorian[1] . '-' . $check_in_gregorian[2]);
        $end = new DateTime($check_out_gregorian[0] . '-' . $check_out_gregorian[1] . '-' . $check_out_gregorian[2]);

        $total_price = 0;
        $nights = 0;
        $breakdown = [];

        $current = clone $start;
        
        while ($current < $end) {
            $current_jalali = $this->gregorian_to_jalali($current->format('Y'), $current->format('m'), $current->format('d'));
            $current_date_str = $current_jalali[0] . '/' . str_pad($current_jalali[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($current_jalali[2], 2, '0', STR_PAD_LEFT);

            $night_price = $room['base_price'];
            if (!empty($room['daily_prices']) && isset($room['daily_prices'][$current_date_str])) {
                $night_price = floatval($room['daily_prices'][$current_date_str]);
            }

            $total_price += $night_price;
            $nights++;
            $breakdown[] = ['date' => $current_date_str, 'price' => $night_price];

            $current->modify('+1 day');
        }

        $extra_guest_total = 0;
        if ($extra_guests > 0 && !empty($room['extra_guest_price'])) {
            $extra_guest_total = $extra_guests * $nights * floatval($room['extra_guest_price']);
            $total_price += $extra_guest_total;
        }

        return [
            'total' => $total_price,
            'nights' => $nights,
            'breakdown' => $breakdown,
            'extra_guest_total' => $extra_guest_total
        ];
    }

    public function ajax_add_room_to_cart() {
        check_ajax_referer('hotel_add_cart', 'nonce');

        $product_id = intval($_POST['product_id']);
        $room_data = json_decode(stripslashes($_POST['room_data']), true);
        $check_in = sanitize_text_field($_POST['check_in']);
        $check_out = sanitize_text_field($_POST['check_out']);
        $extra_guests = intval($_POST['extra_guests'] ?? 0);

        $available = $this->check_room_availability($product_id, $room_data['id'], $check_in, $check_out);
        
        if (!$available) {
            wp_send_json_error(['message' => 'موجود نیست']);
            return;
        }

        $cart_item_data = [
            'hotel_room_id' => $room_data['id'],
            'hotel_room_name' => $room_data['name'],
            'hotel_check_in' => $check_in,
            'hotel_check_out' => $check_out,
            'hotel_extra_guests' => $extra_guests,
            'hotel_room_data' => $room_data
        ];

        $added = WC()->cart->add_to_cart($product_id, 1, 0, [], $cart_item_data);

        if ($added) {
            wp_send_json_success(['message' => 'رزرو شد']);
        } else {
            wp_send_json_error(['message' => 'خطا']);
        }
    }

    public function update_cart_item_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['hotel_room_data'])) {
                // تنظیم quantity به 1 برای اتاق‌های هتل
                $cart_item['quantity'] = 1;
                
                $pricing = $this->calculate_room_price(
                    $cart_item['hotel_room_data'],
                    $cart_item['hotel_check_in'],
                    $cart_item['hotel_check_out'],
                    $cart_item['hotel_extra_guests'] ?? 0
                );
                $cart_item['data']->set_price($pricing['total']);
            }
        }
    }

    public function display_cart_item_data($item_data, $cart_item) {
        if (isset($cart_item['hotel_room_name'])) {
            $item_data[] = ['name' => '🚪 اتاق', 'value' => $cart_item['hotel_room_name']];
        }
        if (isset($cart_item['hotel_check_in'])) {
            $item_data[] = ['name' => '📅 ورود', 'value' => $cart_item['hotel_check_in']];
        }
        if (isset($cart_item['hotel_check_out'])) {
            $item_data[] = ['name' => '📅 خروج', 'value' => $cart_item['hotel_check_out']];
        }
        if (isset($cart_item['hotel_room_data'])) {
            $pricing = $this->calculate_room_price(
                $cart_item['hotel_room_data'],
                $cart_item['hotel_check_in'],
                $cart_item['hotel_check_out'],
                $cart_item['hotel_extra_guests'] ?? 0
            );
            $item_data[] = ['name' => '🌙 شب', 'value' => $pricing['nights']];
        }
        if (isset($cart_item['hotel_extra_guests']) && $cart_item['hotel_extra_guests'] > 0) {
            $item_data[] = ['name' => '👥 نفرات اضافه', 'value' => $cart_item['hotel_extra_guests']];
        }
        return $item_data;
    }

    public function save_order_item_meta($item, $cart_item_key, $values, $order) {
        if (isset($values['hotel_room_name'])) {
            $item->add_meta_data('اتاق', $values['hotel_room_name'], true);
        }
        if (isset($values['hotel_check_in'])) {
            $item->add_meta_data('_hotel_check_in', $values['hotel_check_in'], false);
            $item->add_meta_data('ورود', $values['hotel_check_in'], true);
        }
        if (isset($values['hotel_check_out'])) {
            $item->add_meta_data('_hotel_check_out', $values['hotel_check_out'], false);
            $item->add_meta_data('خروج', $values['hotel_check_out'], true);
        }
        if (isset($values['hotel_extra_guests']) && $values['hotel_extra_guests'] > 0) {
            $item->add_meta_data('نفرات اضافه', $values['hotel_extra_guests'], true);
        }
        if (isset($values['hotel_room_data'])) {
            $item->add_meta_data('_hotel_room_data', $values['hotel_room_data'], false);
        }
        if (isset($values['hotel_room_id'])) {
            $item->add_meta_data('_hotel_room_id', $values['hotel_room_id'], false);
        }
    }

    private function jalali_to_gregorian($jy, $jm, $jd) {
        $jy = intval($jy); 
        $jm = intval($jm); 
        $jd = intval($jd);
        
        $total_days = 0;
        
        for ($i = 1; $i < $jy; $i++) {
            if ($i % 33 === 1 || $i % 33 === 5 || $i % 33 === 9 || $i % 33 === 13 || 
                $i % 33 === 17 || $i % 33 === 22 || $i % 33 === 26 || $i % 33 === 30) {
                $total_days += 366;
            } else {
                $total_days += 365;
            }
        }
        
        for ($i = 1; $i < $jm; $i++) {
            if ($i <= 6) {
                $total_days += 31;
            } else if ($i <= 11) {
                $total_days += 30;
            } else {
                if ($jy % 33 === 1 || $jy % 33 === 5 || $jy % 33 === 9 || $jy % 33 === 13 || 
                    $jy % 33 === 17 || $jy % 33 === 22 || $jy % 33 === 26 || $jy % 33 === 30) {
                    $total_days += 30;
                } else {
                    $total_days += 29;
                }
            }
        }
        
        $total_days += $jd;
        
        $timestamp = strtotime('622-03-21') + (($total_days - 1) * 86400);
        $date = getdate($timestamp);
        
        return [$date['year'], $date['mon'], $date['mday']];
    }

    private function gregorian_to_jalali($gy, $gm, $gd) {
        $gy = intval($gy); $gm = intval($gm); $gd = intval($gd);
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + floor(($gy2 + 3) / 4) - floor(($gy2 + 99) / 100) + floor(($gy2 + 399) / 400) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * floor($days / 12053);
        $days %= 12053;
        $jy += 4 * floor($days / 1461);
        $days %= 1461;
        if ($days > 365) { $jy += floor(($days - 1) / 365); $days = ($days - 1) % 365; }
        $jm = ($days < 186) ? 1 + floor($days / 31) : 7 + floor(($days - 186) / 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
        return [$jy, $jm, $jd];
    }
}

// ================================================
// کلاس مدیریت دستی هتل‌ها
// ================================================

class WC_Hotel_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_wchr_get_hotels', [$this, 'ajax_get_hotels']);
        add_action('wp_ajax_wchr_get_rooms', [$this, 'ajax_get_rooms']);
        add_action('wp_ajax_wchr_save_prices', [$this, 'ajax_save_prices']);
        add_action('wp_ajax_wchr_get_categories', [$this, 'ajax_get_categories']);
    }
    
    public function add_menu() {
        add_menu_page(
            'مدیریت هتل‌ها',
            '🏨 مدیریت هتل',
            'manage_woocommerce',
            'hotel-manager',
            [$this, 'render_page'],
            'dashicons-building',
            56
        );
    }
    
    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_hotel-manager') return;
        
        wp_enqueue_style(
            'wchr-manager',
            plugin_dir_url(__FILE__) . 'assets/css/manager.css',
            [],
            '8.0'
        );
        
        wp_enqueue_script(
            'wchr-manager',
            plugin_dir_url(__FILE__) . 'assets/js/manager.js',
            ['jquery'],
            '8.0',
            true
        );
        
        wp_localize_script('wchr-manager', 'wchrManager', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wchr_manager')
        ]);
    }
    
    public function render_page() {
        ?>
        <div class="wrap wchr-manager">
            <h1>🏨 مدیریت هتل‌ها و قیمت‌گذاری</h1>
            
            <!-- فیلترها -->
            <div class="wchr-filters">
                <div class="filter-group">
                    <label>🔍 جستجو:</label>
                    <input type="text" id="hotel-search" placeholder="نام هتل...">
                </div>
                
                <div class="filter-group">
                    <label>⭐ ستاره:</label>
                    <select id="filter-rating">
                        <option value="">همه</option>
                        <option value="5">⭐⭐⭐⭐⭐ (5 ستاره)</option>
                        <option value="4">⭐⭐⭐⭐ (4 ستاره)</option>
                        <option value="3">⭐⭐⭐ (3 ستاره)</option>
                        <option value="2">⭐⭐ (2 ستاره)</option>
                        <option value="1">⭐ (1 ستاره)</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>📁 دسته:</label>
                    <select id="filter-category">
                        <option value="">همه</option>
                    </select>
                </div>
                
                <button id="apply-filters" class="button button-primary">اعمال فیلتر</button>
                <button id="reset-filters" class="button">پاک کردن</button>
            </div>
            
            <!-- لیست هتل‌ها -->
            <div id="hotels-list" class="wchr-loading">
                <p>در حال بارگذاری...</p>
            </div>
            
            <!-- مودال تقویم قیمت‌گذاری -->
            <div id="pricing-modal" class="wchr-modal" style="display:none;">
                <div class="modal-content">
                    <span class="modal-close">&times;</span>
                    <h2 id="modal-title">تقویم قیمت‌گذاری</h2>
                    
                    <div class="modal-info">
                        <p><strong>هتل:</strong> <span id="modal-hotel-name"></span></p>
                        <p><strong>اتاق:</strong> <span id="modal-room-name"></span></p>
                        <p><strong>قیمت پایه:</strong> <span id="modal-base-price"></span> تومان</p>
                    </div>
                    
                    <div class="calendar-controls">
                        <button id="prev-month" class="button">« ماه قبل</button>
                        <span id="current-month"></span>
                        <button id="next-month" class="button">ماه بعد »</button>
                    </div>
                    
                    <div id="pricing-calendar" class="wchr-calendar"></div>
                    
                    <div class="modal-actions">
                        <button id="save-prices" class="button button-primary">💾 ذخیره همه تغییرات</button>
                        <button class="button modal-close">انصراف</button>
                    </div>
                </div>
            </div>
            
        </div>
        <?php
    }
    
    // AJAX: دریافت لیست هتل‌ها
    public function ajax_get_hotels() {
        check_ajax_referer('wchr_manager', 'nonce');
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $rating = isset($_POST['rating']) ? sanitize_text_field($_POST['rating']) : '';
        $category = isset($_POST['category']) ? intval($_POST['category']) : 0;
        
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft'],
            'meta_query' => [[
                'key' => '_enable_hotel_reservation',
                'value' => 'yes'
            ]]
        ];
        
        if (!empty($search)) {
            $args['s'] = $search;
        }
        
        if (!empty($rating)) {
            $args['meta_query'][] = [
                'key' => '_hotel_rating',
                'value' => $rating,
                'compare' => '='
            ];
        }
        
        if (!empty($category)) {
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $category
            ]];
        }
        
        $hotels = get_posts($args);
        $data = [];
        
        foreach ($hotels as $hotel) {
            $rooms = get_post_meta($hotel->ID, '_hotel_rooms', true) ?: [];
            $rating_val = get_post_meta($hotel->ID, '_hotel_rating', true);
            
            // دریافت دسته‌بندی
            $terms = get_the_terms($hotel->ID, 'product_cat');
            $categories = [];
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $categories[] = $term->name;
                }
            }
            
            $data[] = [
                'id' => $hotel->ID,
                'title' => $hotel->post_title,
                'status' => $hotel->post_status,
                'rating' => $rating_val,
                'categories' => implode(', ', $categories),
                'rooms_count' => count($rooms),
                'rooms' => $this->format_rooms($rooms)
            ];
        }
        
        wp_send_json_success($data);
    }
    
    // AJAX: دریافت اتاق‌های یک هتل
    public function ajax_get_rooms() {
        check_ajax_referer('wchr_manager', 'nonce');
        
        $hotel_id = intval($_POST['hotel_id']);
        $room_index = intval($_POST['room_index']);
        
        $rooms = get_post_meta($hotel_id, '_hotel_rooms', true) ?: [];
        
        if (!isset($rooms[$room_index])) {
            wp_send_json_error(['message' => 'اتاق یافت نشد']);
        }
        
        $room = $rooms[$room_index];
        $hotel_title = get_the_title($hotel_id);
        
        wp_send_json_success([
            'hotel_id' => $hotel_id,
            'hotel_title' => $hotel_title,
            'room_index' => $room_index,
            'room' => $room
        ]);
    }
    
    // AJAX: ذخیره قیمت‌ها
    public function ajax_save_prices() {
        check_ajax_referer('wchr_manager', 'nonce');
        
        $hotel_id = intval($_POST['hotel_id']);
        $room_index = intval($_POST['room_index']);
        $prices = isset($_POST['prices']) ? $_POST['prices'] : [];
        
        $rooms = get_post_meta($hotel_id, '_hotel_rooms', true) ?: [];
        
        if (!isset($rooms[$room_index])) {
            wp_send_json_error(['message' => 'اتاق یافت نشد']);
        }
        
        // به‌روزرسانی قیمت‌ها
        if (!isset($rooms[$room_index]['daily_prices'])) {
            $rooms[$room_index]['daily_prices'] = [];
        }
        
        foreach ($prices as $date => $price) {
            $date = sanitize_text_field($date);
            $price = floatval($price);
            
            if ($price > 0) {
                $rooms[$room_index]['daily_prices'][$date] = $price;
            } else {
                // حذف قیمت (بازگشت به قیمت پایه)
                unset($rooms[$room_index]['daily_prices'][$date]);
            }
        }
        
        update_post_meta($hotel_id, '_hotel_rooms', $rooms);
        
        wp_send_json_success(['message' => 'قیمت‌ها با موفقیت ذخیره شد']);
    }
    
    // AJAX: دریافت دسته‌بندی‌ها
    public function ajax_get_categories() {
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ]);
        
        $data = [];
        foreach ($categories as $cat) {
            $data[] = [
                'id' => $cat->term_id,
                'name' => $cat->name,
                'count' => $cat->count
            ];
        }
        
        wp_send_json_success($data);
    }
    
    // Helper: فرمت کردن اتاق‌ها
    private function format_rooms($rooms) {
        $formatted = [];
        foreach ($rooms as $index => $room) {
            $formatted[] = [
                'index' => $index,
                'name' => $room['name'],
                'capacity' => $room['capacity'],
                'base_price' => number_format($room['base_price']),
                'daily_prices_count' => isset($room['daily_prices']) ? count($room['daily_prices']) : 0
            ];
        }
        return $formatted;
    }
}

function wc_hotel_reserve_init() {
    if (class_exists('WooCommerce')) {
        WC_Hotel_Reserve::get_instance();
        WC_Hotel_Manager::get_instance();
    }
}
add_action('plugins_loaded', 'wc_hotel_reserve_init', 20);