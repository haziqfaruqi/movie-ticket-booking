<?php
defined( 'ABSPATH' ) || exit;

class MTB_WooCommerce {

    const PRODUCT_META_KEY = '_mtb_ticket_product_id';

    public static function init() {
        // Add seat/showtime info to cart item
        add_filter( 'woocommerce_add_cart_item_data',   [ self::class, 'add_cart_item_data' ], 10, 3 );
        add_filter( 'woocommerce_get_item_data',         [ self::class, 'display_cart_item_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ self::class, 'save_order_item_meta' ], 10, 4 );

        // ── Cart display overrides ──────────────────────────────────────────
        // 1. Show movie title instead of generic "Movie Ticket"
        add_filter( 'woocommerce_cart_item_name',      [ self::class, 'cart_item_name'      ], 10, 3 );
        // 2. Show movie poster instead of the blank product image
        add_filter( 'woocommerce_cart_item_thumbnail', [ self::class, 'cart_item_thumbnail' ], 10, 3 );
        // 3. Set correct price (seats × price) in the cart totals
        add_action( 'woocommerce_before_calculate_totals', [ self::class, 'set_cart_item_price' ], 10, 1 );

        // Create booking when order is paid
        add_action( 'woocommerce_order_status_completed',  [ self::class, 'on_order_complete' ] );
        add_action( 'woocommerce_order_status_processing', [ self::class, 'on_order_complete' ] ); // for card payments

        // Prevent duplicate booking
        add_action( 'woocommerce_order_status_cancelled', [ self::class, 'on_order_cancelled' ] );
        add_action( 'woocommerce_order_status_refunded',  [ self::class, 'on_order_refunded'  ] );

        // Show ticket on My Account > Orders
        add_action( 'woocommerce_order_details_after_order_table', [ self::class, 'show_ticket_on_order' ] );
    }

    /**
     * Get or create the WooCommerce product used for ticket purchases.
     * A single virtual product acts as the ticket item.
     */
    public static function get_ticket_product_id() {
        $pid = get_option( self::PRODUCT_META_KEY );
        if ( $pid && get_post_status( $pid ) === 'publish' ) return $pid;

        // Create it
        $product = new WC_Product_Simple();
        $product->set_name( 'Movie Ticket' );
        $product->set_status( 'publish' );
        $product->set_virtual( true );
        $product->set_sold_individually( false );
        $product->set_price( 0 );
        $product->set_regular_price( 0 );
        $product->set_catalog_visibility( 'hidden' );
        $pid = $product->save();
        update_option( self::PRODUCT_META_KEY, $pid );
        return $pid;
    }

    /**
     * Attach showtime + seat data to the cart item.
     * Also store movie_id so we can pull the poster later.
     */
    public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        if ( ! isset( $_POST['mtb_showtime_id'] ) ) return $cart_item_data;

        $showtime_id = intval( $_POST['mtb_showtime_id'] );
        $seats       = array_map( 'sanitize_text_field', (array) ( $_POST['mtb_seats'] ?? [] ) );

        if ( ! $showtime_id || empty( $seats ) ) return $cart_item_data;

        // Get showtime details including movie_id
        global $wpdb;
        $showtime = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, p.post_title AS movie_title
             FROM {$wpdb->prefix}mtb_showtimes s
             JOIN {$wpdb->posts} p ON s.movie_id = p.ID
             WHERE s.id = %d",
            $showtime_id
        ) );

        if ( ! $showtime ) return $cart_item_data;

        $cart_item_data['mtb_showtime_id']   = $showtime_id;
        $cart_item_data['mtb_movie_id']      = intval( $showtime->movie_id );
        $cart_item_data['mtb_movie_title']   = $showtime->movie_title;
        $cart_item_data['mtb_seats']         = $seats;
        $cart_item_data['mtb_seat_count']    = count( $seats );
        $cart_item_data['mtb_ticket_price']  = floatval( $showtime->price );
        $cart_item_data['mtb_unique_key']    = md5( $showtime_id . implode(',', $seats) . microtime() );

        return $cart_item_data;
    }

    // ── Cart display overrides ────────────────────────────────────────────

    /**
     * Replace generic "Movie Ticket" name with the actual movie title.
     */
    public static function cart_item_name( $name, $cart_item, $cart_item_key ) {
        if ( empty( $cart_item['mtb_movie_title'] ) ) return $name;
        return esc_html( $cart_item['mtb_movie_title'] );
    }

    /**
     * Replace the blank product thumbnail with the movie poster.
     */
    public static function cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
        if ( empty( $cart_item['mtb_movie_id'] ) ) return $thumbnail;
        $img = get_the_post_thumbnail(
            $cart_item['mtb_movie_id'],
            'woocommerce_thumbnail',
            [ 'class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail' ]
        );
        return $img ?: $thumbnail;
    }

    /**
     * Set the correct cart price: ticket_price × seat_count.
     * Runs before WooCommerce calculates totals so the cart total is right.
     */
    public static function set_cart_item_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        foreach ( $cart->get_cart() as $item ) {
            if ( isset( $item['mtb_ticket_price'], $item['mtb_seat_count'] ) ) {
                $item['data']->set_price(
                    floatval( $item['mtb_ticket_price'] ) * intval( $item['mtb_seat_count'] )
                );
            }
        }
    }

    /**
     * Display seat info in cart and checkout.
     */
    public static function display_cart_item_data( $item_data, $cart_item ) {
        if ( empty( $cart_item['mtb_showtime_id'] ) ) return $item_data;

        global $wpdb;
        $st = $wpdb->get_row( $wpdb->prepare(
            "SELECT st.*, p.post_title AS movie, sc.name AS screen, c.name AS cinema
             FROM {$wpdb->prefix}mtb_showtimes st
             JOIN {$wpdb->posts} p        ON st.movie_id = p.ID
             JOIN {$wpdb->prefix}mtb_screens sc ON st.screen_id = sc.id
             JOIN {$wpdb->prefix}mtb_cinemas c  ON sc.cinema_id = c.id
             WHERE st.id = %d",
            $cart_item['mtb_showtime_id']
        ) );

        if ( $st ) {
            $item_data[] = [ 'key' => 'Movie',   'value' => $st->movie ];
            $item_data[] = [ 'key' => 'Cinema',  'value' => "{$st->cinema} — {$st->screen}" ];
            $item_data[] = [ 'key' => 'Show',    'value' => date('D, d M Y g:i A', strtotime("{$st->show_date} {$st->show_time}")) ];
            $item_data[] = [ 'key' => 'Seats',   'value' => implode(', ', $cart_item['mtb_seats']) ];
        }

        return $item_data;
    }

    /**
     * Persist meta to the order line item.
     */
    public static function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( empty( $values['mtb_showtime_id'] ) ) return;

        $item->add_meta_data( '_mtb_showtime_id', $values['mtb_showtime_id'] );
        $item->add_meta_data( '_mtb_seats',       json_encode( $values['mtb_seats'] ) );
        $item->add_meta_data( '_mtb_seat_count',  $values['mtb_seat_count'] );

        // Set correct price based on seat count × ticket price
        $item->set_subtotal( $values['mtb_ticket_price'] * $values['mtb_seat_count'] );
        $item->set_total(    $values['mtb_ticket_price'] * $values['mtb_seat_count'] );
    }

    /**
     * Create booking records when order is paid.
     */
    public static function on_order_complete( $order_id ) {
        // Prevent double-processing
        if ( get_post_meta( $order_id, '_mtb_booking_created', true ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        foreach ( $order->get_items() as $item ) {
            $showtime_id = $item->get_meta( '_mtb_showtime_id' );
            $seats_json  = $item->get_meta( '_mtb_seats' );

            if ( ! $showtime_id ) continue;

            $seats  = json_decode( $seats_json, true ) ?: [];
            $result = MTB_Booking::create( $order_id, $showtime_id, $seats, $order->get_user_id() );

            if ( is_wp_error( $result ) ) {
                $order->add_order_note( 'MTB Error: ' . $result->get_error_message() );
            } else {
                $order->add_order_note( "MTB: Booking created — " . MTB_DB::get_booking_by_ref(
                    MTB_DB::generate_booking_ref() // note only for log, ref already set
                ) );
            }
        }

        update_post_meta( $order_id, '_mtb_booking_created', 1 );
    }

    public static function on_order_cancelled( $order_id ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'mtb_bookings',
            [ 'status' => 'cancelled' ],
            [ 'order_id' => $order_id ]
        );
    }

    public static function on_order_refunded( $order_id ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'mtb_bookings',
            [ 'status' => 'refunded' ],
            [ 'order_id' => $order_id ]
        );
    }

    /**
     * Show ticket download on My Account > Order details page.
     */
    public static function show_ticket_on_order( $order ) {
        global $wpdb;
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mtb_bookings WHERE order_id = %d",
            $order->get_id()
        ) );

        if ( empty( $bookings ) ) return;
        ?>
        <h2>Your Tickets</h2>
        <?php foreach ( $bookings as $b ) :
            $showtime = $wpdb->get_row( $wpdb->prepare(
                "SELECT st.*, p.post_title AS movie, sc.name AS screen, c.name AS cinema
                 FROM {$wpdb->prefix}mtb_showtimes st
                 JOIN {$wpdb->posts} p ON st.movie_id = p.ID
                 JOIN {$wpdb->prefix}mtb_screens sc ON st.screen_id = sc.id
                 JOIN {$wpdb->prefix}mtb_cinemas c  ON sc.cinema_id = c.id
                 WHERE st.id = %d",
                $b->showtime_id
            ) );
            $seats = implode(', ', json_decode($b->seats, true) ?: []);
        ?>
        <div style="border:1px solid #ddd;border-radius:8px;padding:20px;margin:12px 0;display:flex;gap:24px;align-items:flex-start">
            <?php if ( $b->qr_path && file_exists( MTB_TICKETS_DIR . $b->qr_path ) ) : ?>
            <img src="<?php echo esc_url( MTB_TICKETS_URL . $b->qr_path ); ?>" width="120" height="120" alt="QR Code">
            <?php endif; ?>
            <div>
                <p style="margin:0 0 4px"><strong><?php echo esc_html($showtime->movie ?? ''); ?></strong></p>
                <p style="margin:0 0 4px;color:#666"><?php echo esc_html("{$showtime->cinema} — {$showtime->screen}"); ?></p>
                <p style="margin:0 0 4px;color:#666"><?php echo esc_html(date('D, d M Y g:i A', strtotime("{$showtime->show_date} {$showtime->show_time}"))); ?></p>
                <p style="margin:0 0 4px">Seats: <strong><?php echo esc_html($seats); ?></strong></p>
                <p style="margin:0"><code><?php echo esc_html($b->booking_ref); ?></code>
                    <span style="margin-left:8px;color:<?php echo $b->status === 'used' ? '#888' : '#1D9E75'; ?>"><?php echo ucfirst($b->status); ?></span>
                </p>
            </div>
        </div>
        <?php endforeach;
    }
}
