<?php
/**
 * ماژول قوانین و کنسلی - نمایش قوانین رزرو و سیاست کنسلی
 */

if (!defined('ABSPATH')) exit;

class WCHR_Rules_Module {

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
            'wchr-rules',
            WCHR_PLUGIN_URL . 'includes/modules/rules/assets/rules.css',
            [],
            WCHR_VERSION
        );
    }

    /**
     * رندر قوانین و کنسلی
     */
    public function render_rules($product_id) {
        if (!WCHR_Settings::is_module_enabled('rules')) {
            return;
        }

        $checkin_time = get_post_meta($product_id, '_hotel_checkin_time', true) ?: '14:00';
        $checkout_time = get_post_meta($product_id, '_hotel_checkout_time', true) ?: '12:00';
        $cancellation_policy = get_post_meta($product_id, '_hotel_cancellation_policy', true);
        $hotel_rules = get_post_meta($product_id, '_hotel_rules', true);

        ob_start();
        ?>
        <div class="wchr-rules-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span class="icon">📋</span>
                    <span>قوانین و مقررات</span>
                </h2>
                <p class="section-description">لطفاً قوانین را با دقت مطالعه فرمایید</p>
            </div>

            <div class="rules-container">

                <!-- زمان ورود و خروج -->
                <div class="rule-card">
                    <h3 class="rule-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>
                        </svg>
                        زمان ورود و خروج
                    </h3>
                    <div class="rule-content">
                        <div class="time-item">
                            <span class="time-label">ورود از ساعت:</span>
                            <span class="time-value"><?php echo esc_html($checkin_time); ?></span>
                        </div>
                        <div class="time-item">
                            <span class="time-label">خروج تا ساعت:</span>
                            <span class="time-value"><?php echo esc_html($checkout_time); ?></span>
                        </div>
                    </div>
                </div>

                <!-- سیاست کنسلی -->
                <?php if ($cancellation_policy): ?>
                <div class="rule-card">
                    <h3 class="rule-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                        </svg>
                        سیاست کنسلی
                    </h3>
                    <div class="rule-content">
                        <?php echo wpautop(esc_html($cancellation_policy)); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- قوانین هتل -->
                <?php if ($hotel_rules): ?>
                <div class="rule-card">
                    <h3 class="rule-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                        </svg>
                        قوانین هتل
                    </h3>
                    <div class="rule-content">
                        <?php echo wpautop(esc_html($hotel_rules)); ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php
        echo ob_get_clean();
    }
}

WCHR_Rules_Module::get_instance();
