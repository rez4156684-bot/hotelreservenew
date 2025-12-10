<?php
/**
 * تنظیمات افزونه - فعال/غیرفعال کردن ماژول‌ها
 */

if (!defined('ABSPATH')) exit;

class WCHR_Settings {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * اضافه کردن صفحه تنظیمات به منوی وردپرس
     */
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=product',
            'تنظیمات رزرو هتل',
            'تنظیمات رزرو',
            'manage_options',
            'wchr-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * ثبت تنظیمات
     */
    public function register_settings() {
        register_setting('wchr_settings', 'wchr_module_rooms', ['default' => 'yes']);
        register_setting('wchr_settings', 'wchr_module_search', ['default' => 'yes']);
        register_setting('wchr_settings', 'wchr_module_facilities', ['default' => 'yes']);
        register_setting('wchr_settings', 'wchr_module_rules', ['default' => 'yes']);
        register_setting('wchr_settings', 'wchr_rooms_layout', ['default' => 'grid']);
        register_setting('wchr_settings', 'wchr_show_room_images', ['default' => 'yes']);
        register_setting('wchr_settings', 'wchr_currency_symbol', ['default' => 'تومان']);
    }

    /**
     * رندر صفحه تنظیمات
     */
    public function render_settings_page() {
        ?>
        <div class="wrap wchr-settings-page">
            <h1>⚙️ تنظیمات سیستم رزرو هتل</h1>
            <p class="description">از این قسمت می‌توانید ماژول‌های مختلف را فعال یا غیرفعال کنید.</p>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✓ تنظیمات با موفقیت ذخیره شد!</strong></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('wchr_settings'); ?>

                <div class="wchr-settings-grid">

                    <!-- ماژول اتاق‌ها -->
                    <div class="wchr-settings-card">
                        <div class="card-header">
                            <span class="icon">🏠</span>
                            <h2>ماژول اتاق‌ها</h2>
                        </div>
                        <div class="card-body">
                            <label class="toggle-switch">
                                <input type="checkbox" name="wchr_module_rooms" value="yes"
                                    <?php checked(get_option('wchr_module_rooms', 'yes'), 'yes'); ?>>
                                <span class="slider"></span>
                                <span class="label">نمایش بخش اتاق‌ها</span>
                            </label>

                            <div class="sub-settings">
                                <label>نوع نمایش:</label>
                                <select name="wchr_rooms_layout">
                                    <option value="grid" <?php selected(get_option('wchr_rooms_layout', 'grid'), 'grid'); ?>>
                                        شبکه‌ای (Grid)
                                    </option>
                                    <option value="list" <?php selected(get_option('wchr_rooms_layout', 'grid'), 'list'); ?>>
                                        لیستی (List)
                                    </option>
                                    <option value="carousel" <?php selected(get_option('wchr_rooms_layout', 'grid'), 'carousel'); ?>>
                                        کاروسل (Carousel)
                                    </option>
                                </select>

                                <label class="toggle-switch" style="margin-top: 15px;">
                                    <input type="checkbox" name="wchr_show_room_images" value="yes"
                                        <?php checked(get_option('wchr_show_room_images', 'yes'), 'yes'); ?>>
                                    <span class="slider"></span>
                                    <span class="label">نمایش تصاویر اتاق‌ها</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- ماژول جستجو -->
                    <div class="wchr-settings-card">
                        <div class="card-header">
                            <span class="icon">🔍</span>
                            <h2>ماژول جستجو</h2>
                        </div>
                        <div class="card-body">
                            <label class="toggle-switch">
                                <input type="checkbox" name="wchr_module_search" value="yes"
                                    <?php checked(get_option('wchr_module_search', 'yes'), 'yes'); ?>>
                                <span class="slider"></span>
                                <span class="label">نمایش باکس جستجو</span>
                            </label>
                            <p class="description">باکس جستجوی تاریخ، تعداد مهمان و انتخاب اتاق</p>
                        </div>
                    </div>

                    <!-- ماژول امکانات -->
                    <div class="wchr-settings-card">
                        <div class="card-header">
                            <span class="icon">✨</span>
                            <h2>ماژول امکانات</h2>
                        </div>
                        <div class="card-body">
                            <label class="toggle-switch">
                                <input type="checkbox" name="wchr_module_facilities" value="yes"
                                    <?php checked(get_option('wchr_module_facilities', 'yes'), 'yes'); ?>>
                                <span class="slider"></span>
                                <span class="label">نمایش امکانات هتل</span>
                            </label>
                            <p class="description">نمایش امکانات و خدمات هتل (وای‌فای، پارکینگ، ...)</p>
                        </div>
                    </div>

                    <!-- ماژول قوانین -->
                    <div class="wchr-settings-card">
                        <div class="card-header">
                            <span class="icon">📋</span>
                            <h2>ماژول قوانین و کنسلی</h2>
                        </div>
                        <div class="card-body">
                            <label class="toggle-switch">
                                <input type="checkbox" name="wchr_module_rules" value="yes"
                                    <?php checked(get_option('wchr_module_rules', 'yes'), 'yes'); ?>>
                                <span class="slider"></span>
                                <span class="label">نمایش قوانین و کنسلی</span>
                            </label>
                            <p class="description">نمایش قوانین رزرو و سیاست کنسلی</p>
                        </div>
                    </div>

                    <!-- تنظیمات عمومی -->
                    <div class="wchr-settings-card full-width">
                        <div class="card-header">
                            <span class="icon">⚙️</span>
                            <h2>تنظیمات عمومی</h2>
                        </div>
                        <div class="card-body">
                            <label>واحد پول:</label>
                            <input type="text" name="wchr_currency_symbol"
                                value="<?php echo esc_attr(get_option('wchr_currency_symbol', 'تومان')); ?>"
                                placeholder="تومان" class="regular-text">
                        </div>
                    </div>

                </div>

                <?php submit_button('💾 ذخیره تنظیمات', 'primary large'); ?>
            </form>
        </div>

        <style>
            .wchr-settings-page {
                max-width: 1400px;
            }

            .wchr-settings-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                gap: 20px;
                margin: 30px 0;
            }

            .wchr-settings-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
                transition: all 0.3s;
            }

            .wchr-settings-card:hover {
                box-shadow: 0 4px 16px rgba(0,0,0,0.1);
                transform: translateY(-2px);
            }

            .wchr-settings-card.full-width {
                grid-column: 1 / -1;
            }

            .card-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 20px;
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .card-header .icon {
                font-size: 28px;
            }

            .card-header h2 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: white;
            }

            .card-body {
                padding: 25px;
            }

            .toggle-switch {
                display: flex;
                align-items: center;
                gap: 12px;
                cursor: pointer;
                margin-bottom: 15px;
            }

            .toggle-switch input[type="checkbox"] {
                display: none;
            }

            .toggle-switch .slider {
                position: relative;
                width: 50px;
                height: 26px;
                background: #ccc;
                border-radius: 34px;
                transition: 0.3s;
            }

            .toggle-switch .slider:before {
                content: "";
                position: absolute;
                height: 20px;
                width: 20px;
                left: 3px;
                top: 3px;
                background: white;
                border-radius: 50%;
                transition: 0.3s;
            }

            .toggle-switch input:checked + .slider {
                background: #2271b1;
            }

            .toggle-switch input:checked + .slider:before {
                transform: translateX(24px);
            }

            .toggle-switch .label {
                font-weight: 600;
                color: #333;
            }

            .sub-settings {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #f0f0f0;
            }

            .sub-settings label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #555;
            }

            .sub-settings select {
                width: 100%;
                padding: 10px;
                border: 2px solid #ddd;
                border-radius: 6px;
                font-size: 14px;
            }

            .description {
                color: #666;
                font-size: 13px;
                margin-top: 10px;
            }

            .submit .button {
                padding: 12px 40px !important;
                font-size: 16px !important;
                height: auto !important;
            }
        </style>
        <?php
    }

    /**
     * چک کردن فعال بودن ماژول
     */
    public static function is_module_enabled($module) {
        return get_option('wchr_module_' . $module, 'yes') === 'yes';
    }
}

WCHR_Settings::get_instance();
