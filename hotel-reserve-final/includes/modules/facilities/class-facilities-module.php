<?php
/**
 * ماژول امکانات - نمایش امکانات و خدمات هتل
 */

if (!defined('ABSPATH')) exit;

class WCHR_Facilities_Module {

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
            'wchr-facilities',
            WCHR_PLUGIN_URL . 'includes/modules/facilities/assets/facilities.css',
            [],
            WCHR_VERSION
        );
    }

    /**
     * رندر امکانات هتل
     */
    public function render_facilities($product_id) {
        if (!WCHR_Settings::is_module_enabled('facilities')) {
            return;
        }

        $facilities = get_post_meta($product_id, '_hotel_facilities', true);
        if (empty($facilities)) {
            return;
        }

        // آیکون‌های پیش‌فرض برای امکانات
        $facility_icons = [
            'wifi' => '📶',
            'parking' => '🅿️',
            'pool' => '🏊',
            'gym' => '💪',
            'restaurant' => '🍽️',
            'breakfast' => '☕',
            'room_service' => '🛎️',
            'spa' => '💆',
            'elevator' => '🛗',
            'ac' => '❄️',
            'tv' => '📺',
            'safe' => '🔐',
            'laundry' => '👔',
            'pet' => '🐕',
            'smoking' => '🚭',
            'bar' => '🍹',
            'conference' => '🎯',
            'wheelchair' => '♿',
            'reception' => '🏨',
            'garden' => '🌳'
        ];

        ob_start();
        ?>
        <div class="wchr-facilities-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span class="icon">✨</span>
                    <span>امکانات و خدمات</span>
                </h2>
                <p class="section-description">تمام امکانات موجود در این هتل</p>
            </div>

            <div class="facilities-grid">
                <?php foreach ($facilities as $facility): ?>
                    <?php
                    $icon = $facility_icons[$facility['type']] ?? '✓';
                    ?>
                    <div class="facility-item">
                        <span class="facility-icon"><?php echo $icon; ?></span>
                        <span class="facility-name"><?php echo esc_html($facility['name']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    }
}

WCHR_Facilities_Module::get_instance();
