<?php
/**
 * Plugin Name: WooCommerce Hotel Reserve - ماژولار
 * Description: سیستم رزرو هتل با معماری ماژولار و قابلیت فعال/غیرفعال کردن بخش‌ها
 * Version: 9.0.0
 * Author: بهادر
 * Text Domain: wc-hotel-reserve
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// تعریف ثابت‌ها
define('WCHR_VERSION', '9.0.0');
define('WCHR_PLUGIN_FILE', __FILE__);
define('WCHR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCHR_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * کلاس اصلی افزونه
 */
class WC_Hotel_Reserve_Modular {

    private static $instance = null;
    private $modules = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // چک کردن وجود WooCommerce
        add_action('plugins_loaded', [$this, 'check_woocommerce']);

        // بارگذاری کلاس‌ها و ماژول‌ها
        add_action('plugins_loaded', [$this, 'load_modules'], 20);

        // فعال‌سازی هوک‌های اصلی
        add_action('init', [$this, 'init']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
    }

    /**
     * چک کردن فعال بودن WooCommerce
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p><strong>Hotel Reserve:</strong> این افزونه نیاز به WooCommerce دارد. لطفاً ابتدا WooCommerce را نصب و فعال کنید.</p></div>';
            });
            return;
        }
    }

    /**
     * بارگذاری ماژول‌ها
     */
    public function load_modules() {
        // بارگذاری کلاس تنظیمات
        require_once WCHR_PLUGIN_DIR . 'includes/class-settings.php';

        // بارگذاری سیستم قیمت‌گذاری پیشرفته
        require_once WCHR_PLUGIN_DIR . 'includes/class-advanced-pricing.php';

        // بارگذاری ماژول اتاق‌ها
        if (WCHR_Settings::is_module_enabled('rooms')) {
            require_once WCHR_PLUGIN_DIR . 'includes/modules/rooms/class-rooms-module.php';
            $this->modules['rooms'] = WCHR_Rooms_Module::get_instance();
        }

        // بارگذاری ماژول جستجو
        if (WCHR_Settings::is_module_enabled('search')) {
            require_once WCHR_PLUGIN_DIR . 'includes/modules/search/class-search-module.php';
            $this->modules['search'] = WCHR_Search_Module::get_instance();
        }

        // بارگذاری ماژول امکانات
        if (WCHR_Settings::is_module_enabled('facilities')) {
            require_once WCHR_PLUGIN_DIR . 'includes/modules/facilities/class-facilities-module.php';
            $this->modules['facilities'] = WCHR_Facilities_Module::get_instance();
        }

        // بارگذاری ماژول قوانین
        if (WCHR_Settings::is_module_enabled('rules')) {
            require_once WCHR_PLUGIN_DIR . 'includes/modules/rules/class-rules-module.php';
            $this->modules['rules'] = WCHR_Rules_Module::get_instance();
        }

        // بارگذاری کلاس اصلی قدیمی (برای سازگاری با عملکردهای قبلی)
        require_once WCHR_PLUGIN_DIR . 'hotel-reserve-original-backup.php';
    }

    /**
     * مقداردهی اولیه
     */
    public function init() {
        // ثبت تب محصول
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_data_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'add_product_data_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);

        // هوک نمایش در صفحه محصول
        add_action('woocommerce_after_single_product_summary', [$this, 'display_hotel_content'], 5);
        add_action('woocommerce_single_product_summary', [$this, 'hide_default_cart_elements'], 1);

        // AJAX handlers
        add_action('wp_ajax_hotel_check_room_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_nopriv_hotel_check_room_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_hotel_add_room_to_cart', [$this, 'ajax_add_room_to_cart']);
        add_action('wp_ajax_nopriv_hotel_add_room_to_cart', [$this, 'ajax_add_room_to_cart']);

        // سبد خرید
        add_action('woocommerce_before_calculate_totals', [$this, 'update_cart_item_price']);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_meta'], 10, 4);
        add_filter('woocommerce_cart_item_quantity', [$this, 'disable_cart_item_quantity'], 10, 3);
    }

    /**
     * اسکریپت‌های ادمین
     */
    public function admin_scripts($hook) {
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            global $post;
            if ($post && $post->post_type === 'product') {
                wp_enqueue_style('wchr-admin', WCHR_PLUGIN_URL . 'assets/css/manager.css', [], WCHR_VERSION);
                wp_enqueue_script('wchr-admin', WCHR_PLUGIN_URL . 'assets/js/manager.js', ['jquery'], WCHR_VERSION, true);

                wp_localize_script('wchr-admin', 'wchrData', [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wchr_manager_nonce')
                ]);
            }
        }
    }

    /**
     * اسکریپت‌های فرانت
     */
    public function frontend_scripts() {
        if (!is_singular('product')) return;

        global $product;
        if (!$product || get_post_meta($product->get_id(), '_enable_hotel_reservation', true) !== 'yes') {
            return;
        }

        // استایل‌های اصلی
        wp_enqueue_style('wchr-frontend', WCHR_PLUGIN_URL . 'assets/css/frontend.css', [], WCHR_VERSION);

        // اسکریپت‌های اصلی
        wp_enqueue_script('wchr-frontend', WCHR_PLUGIN_URL . 'assets/js/frontend.js', ['jquery'], WCHR_VERSION, true);

        wp_localize_script('wchr-frontend', 'wchrData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wchr_nonce'),
            'product_id' => $product->get_id()
        ]);
    }

    /**
     * مخفی کردن المان‌های پیش‌فرض سبد خرید
     */
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

    /**
     * افزودن تب محصول
     */
    public function add_product_data_tab($tabs) {
        $tabs['hotel_rooms'] = [
            'label' => '🏨 اتاق‌های هتل',
            'target' => 'hotel_rooms_data',
            'class' => ['show_if_simple']
        ];
        return $tabs;
    }

    /**
     * رندر پنل تب محصول
     */
    public function add_product_data_panel() {
        global $post;
        ?>
        <div id="hotel_rooms_data" class="panel woocommerce_options_panel">
            <?php
            // اینجا می‌توانید از فایل اصلی استفاده کنید یا یک نسخه جدید بسازید
            // برای سادگی، از کلاس قدیمی استفاده می‌کنم
            if (class_exists('WC_Hotel_Reserve')) {
                $old_instance = WC_Hotel_Reserve::get_instance();
                if (method_exists($old_instance, 'add_product_data_panel')) {
                    $old_instance->add_product_data_panel();
                }
            }
            ?>
        </div>
        <?php
    }

    /**
     * ذخیره متا محصول
     */
    public function save_product_meta($post_id) {
        if (class_exists('WC_Hotel_Reserve')) {
            $old_instance = WC_Hotel_Reserve::get_instance();
            if (method_exists($old_instance, 'save_product_meta')) {
                $old_instance->save_product_meta($post_id);
            }
        }
    }

    /**
     * نمایش محتوای هتل در صفحه محصول
     */
    public function display_hotel_content() {
        global $product;

        if (!$product || get_post_meta($product->get_id(), '_enable_hotel_reservation', true) !== 'yes') {
            return;
        }

        $product_id = $product->get_id();

        echo '<div class="wchr-hotel-content">';

        // ماژول جستجو
        if (isset($this->modules['search'])) {
            $this->modules['search']->render_search_box($product_id);
        }

        // ماژول اتاق‌ها
        if (isset($this->modules['rooms'])) {
            $this->modules['rooms']->render_rooms($product_id);
        }

        // ماژول امکانات
        if (isset($this->modules['facilities'])) {
            $this->modules['facilities']->render_facilities($product_id);
        }

        // ماژول قوانین
        if (isset($this->modules['rules'])) {
            $this->modules['rules']->render_rules($product_id);
        }

        echo '</div>';
    }

    /**
     * AJAX: چک کردن موجودی اتاق
     */
    public function ajax_check_availability() {
        if (class_exists('WC_Hotel_Reserve')) {
            $old_instance = WC_Hotel_Reserve::get_instance();
            if (method_exists($old_instance, 'ajax_check_availability')) {
                $old_instance->ajax_check_availability();
            }
        }
    }

    /**
     * AJAX: افزودن اتاق به سبد خرید
     */
    public function ajax_add_room_to_cart() {
        if (class_exists('WC_Hotel_Reserve')) {
            $old_instance = WC_Hotel_Reserve::get_instance();
            if (method_exists($old_instance, 'ajax_add_room_to_cart')) {
                $old_instance->ajax_add_room_to_cart();
            }
        }
    }

    /**
     * به روزرسانی قیمت آیتم سبد خرید
     */
    public function update_cart_item_price($cart) {
        if (class_exists('WC_Hotel_Reserve')) {
            $old_instance = WC_Hotel_Reserve::get_instance();
            if (method_exists($old_instance, 'update_cart_item_price')) {
                $old_instance->update_cart_item_price($cart);
            }
        }
    }

    /**
     * نمایش داده‌های آیتم در سبد خرید
     */
    public function display_cart_item_data($item_data, $cart_item) {
        if (class_exists('WC_Hotel_Reserve')) {
            $old_instance = WC_Hotel_Reserve::get_instance();
            if (method_exists($old_instance, 'display_cart_item_data')) {
                return $old_instance->display_cart_item_data($item_data, $cart_item);
            }
        }
        return $item_data;
    }

    /**
     * ذخیره متای آیتم سفارش
     */
    public function save_order_item_meta($item, $cart_item_key, $values, $order) {
        if (class_exists('WC_Hotel_Reserve')) {
            $old_instance = WC_Hotel_Reserve::get_instance();
            if (method_exists($old_instance, 'save_order_item_meta')) {
                $old_instance->save_order_item_meta($item, $cart_item_key, $values, $order);
            }
        }
    }

    /**
     * غیرفعال کردن quantity در سبد خرید
     */
    public function disable_cart_item_quantity($product_quantity, $cart_item_key, $cart_item) {
        if (isset($cart_item['hotel_room_id'])) {
            return '<span style="color:#666;font-weight:600;">1 (غیرقابل تغییر)</span>';
        }
        return $product_quantity;
    }
}

// راه‌اندازی افزونه
function wchr_modular_init() {
    return WC_Hotel_Reserve_Modular::get_instance();
}

add_action('plugins_loaded', 'wchr_modular_init', 10);
