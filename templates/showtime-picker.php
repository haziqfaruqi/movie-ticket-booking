<?php defined('ABSPATH') || exit;
$movie     = get_post($post_id);
$showtimes = MTB_DB::get_showtimes_for_movie($post_id);
$img       = get_the_post_thumbnail_url($post_id, 'large');
?>
<div class="mtb-booking-wrap" id="mtb-booking-<?php echo $post_id; ?>">

    <!-- Step indicator -->
    <div class="mtb-steps">
        <div class="mtb-step mtb-step--active" data-step="1">
            <span class="mtb-step__num">1</span>
            <span class="mtb-step__label">Choose Showtime</span>
        </div>
        <div class="mtb-step" data-step="2">
            <span class="mtb-step__num">2</span>
            <span class="mtb-step__label">Select Seats</span>
        </div>
        <div class="mtb-step" data-step="3">
            <span class="mtb-step__num">3</span>
            <span class="mtb-step__label">Checkout</span>
        </div>
    </div>

    <!-- Step 1: Showtime picker -->
    <div class="mtb-panel" id="mtb-step-1">
        <?php if ($img) : ?>
        <div class="mtb-movie-banner" style="background-image:url('<?php echo esc_url($img); ?>')"></div>
        <?php endif; ?>
        <h2 class="mtb-panel__title"><?php echo esc_html($movie->post_title); ?></h2>
        <?php if (empty($showtimes)) : ?>
        <p class="mtb-empty">No upcoming showtimes available.</p>
        <?php else :
            $by_date = [];
            foreach ($showtimes as $st) $by_date[$st->show_date][] = $st;
        ?>
        <div class="mtb-showtimes">
        <?php foreach ($by_date as $date => $day_shows) : ?>
            <div class="mtb-showtime-day">
                <h4 class="mtb-date-label"><?php echo date('l, d M Y', strtotime($date)); ?></h4>
                <div class="mtb-showtime-slots">
                <?php foreach ($day_shows as $st) : ?>
                <button
                    class="mtb-showtime-slot"
                    data-showtime="<?php echo $st->id; ?>"
                    data-price="<?php echo $st->price; ?>"
                    data-cinema="<?php echo esc_attr($st->cinema_name); ?>"
                    data-screen="<?php echo esc_attr($st->screen_name); ?>"
                    data-time="<?php echo esc_attr(date('g:i A', strtotime($st->show_time))); ?>"
                >
                    <span class="mtb-slot__time"><?php echo date('g:i A', strtotime($st->show_time)); ?></span>
                    <span class="mtb-slot__meta"><?php echo esc_html($st->cinema_name); ?></span>
                    <span class="mtb-slot__price">RM <?php echo number_format($st->price, 2); ?></span>
                </button>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Step 2: Seat map (loaded via AJAX) -->
    <div class="mtb-panel" id="mtb-step-2" style="display:none">
        <div class="mtb-seat-header">
            <button class="mtb-back-btn" onclick="MTB_App.goToStep(1)">← Back</button>
            <div>
                <strong id="mtb-selected-show"></strong>
                <span id="mtb-selected-price" style="color:#1D9E75;font-weight:600"></span>
            </div>
        </div>

        <div class="mtb-screen-label">SCREEN</div>
        <div id="mtb-seat-map" class="mtb-seat-map">
            <div class="mtb-loading">Loading seats...</div>
        </div>

        <div class="mtb-seat-legend">
            <span class="mtb-legend-item"><span class="mtb-seat mtb-seat--available"></span> Available</span>
            <span class="mtb-legend-item"><span class="mtb-seat mtb-seat--selected"></span> Selected</span>
            <span class="mtb-legend-item"><span class="mtb-seat mtb-seat--booked"></span> Booked</span>
        </div>

        <div class="mtb-selection-summary" id="mtb-selection-summary" style="display:none">
            <div>
                Selected: <strong id="mtb-selected-seats-label">—</strong>
                &nbsp;|&nbsp;
                Total: <strong id="mtb-total-price">RM 0.00</strong>
            </div>
            <button id="mtb-proceed-checkout" class="mtb-btn">Proceed to Checkout</button>
        </div>
    </div>

    <!-- Step 3: Adding to cart (hidden form) -->
    <div class="mtb-panel" id="mtb-step-3" style="display:none">
        <div style="text-align:center;padding:40px 0">
            <div class="mtb-spinner"></div>
            <p>Reserving your seats and redirecting to checkout...</p>
        </div>
        <form id="mtb-checkout-form" method="POST" action="<?php echo esc_url(wc_get_cart_url()); ?>" style="display:none">
            <?php wp_nonce_field('mtb_add_to_cart', 'mtb_nonce_field'); ?>
            <input type="hidden" name="add-to-cart" value="<?php echo MTB_WooCommerce::get_ticket_product_id(); ?>">
            <input type="hidden" name="mtb_showtime_id" id="form_showtime_id">
            <div id="form_seats_container"></div>
        </form>
    </div>
</div>
