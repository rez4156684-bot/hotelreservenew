<?php
/**
 * ماژول جستجو - باکس جستجوی پیشرفته
 */

if (!defined('ABSPATH')) exit;

class WCHR_Search_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts() {
        if (!is_singular('product')) return;

        global $product;
        if (!$product || get_post_meta($product->get_id(), '_enable_hotel_reservation', true) !== 'yes') {
            return;
        }

        wp_enqueue_style(
            'wchr-search',
            WCHR_PLUGIN_URL . 'includes/modules/search/assets/search.css',
            [],
            WCHR_VERSION
        );

        wp_enqueue_script(
            'wchr-search',
            WCHR_PLUGIN_URL . 'includes/modules/search/assets/search.js',
            ['jquery'],
            WCHR_VERSION,
            true
        );

        // اضافه کردن Persian Datepicker
        wp_enqueue_style('persian-datepicker', 'https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css');
        wp_enqueue_script('persian-date', 'https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js', [], null, true);
        wp_enqueue_script('persian-datepicker', 'https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js', ['jquery', 'persian-date'], null, true);
    }

    /**
     * رندر باکس جستجو
     */
    public function render_search_box($product_id) {
        if (!WCHR_Settings::is_module_enabled('search')) {
            return;
        }

        $rooms = get_post_meta($product_id, '_hotel_rooms', true);
        if (empty($rooms)) {
            return;
        }

        ob_start();
        ?>
        <div class="wchr-search-section">
            <div class="search-container">
                <div class="search-header">
                    <h3 class="search-title">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                        </svg>
                        جستجو و رزرو
                    </h3>
                    <p class="search-subtitle">اطلاعات سفر خود را وارد کنید</p>
                </div>

                <form class="wchr-search-form" id="wchr-search-form">
                    <div class="search-fields">

                        <!-- تاریخ ورود -->
                        <div class="search-field">
                            <label class="field-label">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/>
                                </svg>
                                تاریخ ورود
                            </label>
                            <input type="text" id="checkin-date" class="field-input date-input" placeholder="انتخاب تاریخ" readonly required>
                        </div>

                        <!-- تاریخ خروج -->
                        <div class="search-field">
                            <label class="field-label">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/>
                                </svg>
                                تاریخ خروج
                            </label>
                            <input type="text" id="checkout-date" class="field-input date-input" placeholder="انتخاب تاریخ" readonly required>
                        </div>

                        <!-- تعداد مهمان -->
                        <div class="search-field">
                            <label class="field-label">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                                </svg>
                                تعداد مهمان
                            </label>
                            <div class="guest-selector">
                                <button type="button" class="guest-btn" data-action="decrease">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M19 13H5v-2h14v2z"/>
                                    </svg>
                                </button>
                                <input type="number" id="guest-count" class="field-input guest-input" value="2" min="1" max="20" readonly>
                                <button type="button" class="guest-btn" data-action="increase">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- انتخاب اتاق -->
                        <div class="search-field">
                            <label class="field-label">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M20 9.557V3h-2v2H6V3H4v6.557C2.81 10.25 2 11.525 2 13v4a1 1 0 0 0 1 1h1v4h2v-4h12v4h2v-4h1a1 1 0 0 0 1-1v-4c0-1.475-.811-2.75-2-3.443zM18 7v2h-5V7h5zm-7 0v2H6V7h5z"/>
                                </svg>
                                انتخاب اتاق
                            </label>
                            <select id="room-select" class="field-input" required>
                                <option value="">انتخاب کنید</option>
                                <?php foreach ($rooms as $index => $room): ?>
                                    <option value="<?php echo $index; ?>" data-capacity="<?php echo $room['capacity']; ?>">
                                        <?php echo esc_html($room['name']); ?>
                                        (<?php echo $room['capacity']; ?> نفر - <?php echo number_format($room['base_price']); ?> تومان)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                    <!-- دکمه بررسی موجودی -->
                    <button type="submit" class="search-submit-btn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                        </svg>
                        <span>بررسی موجودی و قیمت</span>
                    </button>

                    <!-- نمایش نتیجه -->
                    <div id="search-result" class="search-result" style="display: none;"></div>
                </form>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    }
}

WCHR_Search_Module::get_instance();
