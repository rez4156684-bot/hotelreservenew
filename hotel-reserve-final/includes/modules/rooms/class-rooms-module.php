<?php
/**
 * ماژول اتاق‌ها - نمایش و مدیریت اتاق‌ها
 */

if (!defined('ABSPATH')) exit;

class WCHR_Rooms_Module {

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

    /**
     * لود کردن استایل‌ها و اسکریپت‌های ماژول
     */
    public function enqueue_scripts() {
        if (!is_singular('product')) return;

        global $product;
        if (!$product || get_post_meta($product->get_id(), '_enable_hotel_reservation', true) !== 'yes') {
            return;
        }

        wp_enqueue_style(
            'wchr-rooms',
            WCHR_PLUGIN_URL . 'includes/modules/rooms/assets/rooms.css',
            [],
            WCHR_VERSION
        );

        wp_enqueue_script(
            'wchr-rooms',
            WCHR_PLUGIN_URL . 'includes/modules/rooms/assets/rooms.js',
            ['jquery'],
            WCHR_VERSION,
            true
        );
    }

    /**
     * رندر اتاق‌ها در صفحه محصول
     */
    public function render_rooms($product_id) {
        if (!WCHR_Settings::is_module_enabled('rooms')) {
            return;
        }

        $rooms = get_post_meta($product_id, '_hotel_rooms', true);
        if (empty($rooms)) {
            return;
        }

        $layout = get_option('wchr_rooms_layout', 'grid');
        $show_images = get_option('wchr_show_room_images', 'yes') === 'yes';
        $currency = get_option('wchr_currency_symbol', 'تومان');

        ob_start();
        ?>
        <div class="wchr-rooms-section" data-layout="<?php echo esc_attr($layout); ?>">
            <div class="section-header">
                <h2 class="section-title">
                    <span class="icon">🏠</span>
                    <span>اتاق‌های موجود</span>
                </h2>
                <p class="section-description">اتاق مورد نظر خود را انتخاب کنید</p>
            </div>

            <div class="wchr-rooms-container layout-<?php echo esc_attr($layout); ?>">
                <?php foreach ($rooms as $index => $room): ?>
                    <?php $this->render_room_card($room, $index, $product_id, $show_images, $currency); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    }

    /**
     * رندر کارت تک اتاق
     */
    private function render_room_card($room, $index, $product_id, $show_images, $currency) {
        $room_image = !empty($room['image']) ? wp_get_attachment_image_url($room['image'], 'large') : '';
        $default_image = WCHR_PLUGIN_URL . 'assets/images/default-room.jpg';
        $image_url = $room_image ? $room_image : $default_image;
        ?>
        <div class="wchr-room-card" data-room-index="<?php echo $index; ?>">
            <?php if ($show_images): ?>
                <div class="room-image">
                    <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($room['name']); ?>">
                    <div class="room-badge">
                        <span class="capacity">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                            <?php echo esc_html($room['capacity']); ?> نفر
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="room-content">
                <h3 class="room-title"><?php echo esc_html($room['name']); ?></h3>

                <?php if (!empty($room['description'])): ?>
                    <p class="room-description"><?php echo esc_html($room['description']); ?></p>
                <?php endif; ?>

                <div class="room-features">
                    <div class="feature">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                        <span>ظرفیت: <?php echo esc_html($room['capacity']); ?> نفر</span>
                    </div>

                    <?php if (!empty($room['bed_count'])): ?>
                        <div class="feature">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20 9.557V3h-2v2H6V3H4v6.557C2.81 10.25 2 11.525 2 13v4a1 1 0 0 0 1 1h1v4h2v-4h12v4h2v-4h1a1 1 0 0 0 1-1v-4c0-1.475-.811-2.75-2-3.443zM18 7v2h-5V7h5zm-7 0v2H6V7h5z"/>
                            </svg>
                            <span><?php echo esc_html($room['bed_count']); ?> تخت</span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($room['area'])): ?>
                        <div class="feature">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/>
                            </svg>
                            <span><?php echo esc_html($room['area']); ?> متر</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="room-footer">
                    <div class="room-price">
                        <span class="price-label">قیمت هر شب:</span>
                        <span class="price-value"><?php echo number_format($room['base_price']); ?></span>
                        <span class="price-currency"><?php echo esc_html($currency); ?></span>
                    </div>
                    <button type="button" class="btn-select-room" data-room-index="<?php echo $index; ?>" data-product-id="<?php echo $product_id; ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                        </svg>
                        انتخاب این اتاق
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}

WCHR_Rooms_Module::get_instance();
