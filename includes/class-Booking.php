<?php
defined( 'ABSPATH' ) || exit;

class MTB_Booking {

    /**
     * Create a booking after WooCommerce payment completes.
     * Called from MTB_WooCommerce with parsed order item meta.
     */
    public static function create( $order_id, $showtime_id, $seats, $user_id = 0 ) {
        global $wpdb;

        $seats = is_array( $seats ) ? $seats : json_decode( $seats, true );
        if ( empty( $seats ) ) return false;

        // Double-check seats aren't booked (race condition guard)
        $booked = MTB_DB::get_booked_seats( $showtime_id );
        if ( array_intersect( $seats, $booked ) ) {
            return new WP_Error( 'seats_taken', 'One or more seats were already booked.' );
        }

        $ref = MTB_DB::generate_booking_ref();

        $wpdb->insert( $wpdb->prefix . 'mtb_bookings', [
            'booking_ref'  => $ref,
            'order_id'     => $order_id,
            'showtime_id'  => $showtime_id,
            'user_id'      => $user_id,
            'seats'        => json_encode( $seats ),
            'total_seats'  => count( $seats ),
            'status'       => 'confirmed',
        ] );

        $booking_id = $wpdb->insert_id;
        if ( ! $booking_id ) return false;

        // Generate QR code
        $qr_path = self::generate_qr( $ref );
        if ( $qr_path ) {
            $wpdb->update(
                $wpdb->prefix . 'mtb_bookings',
                [ 'qr_path' => $qr_path ],
                [ 'id' => $booking_id ]
            );
        }

        // Clear seat holds for these seats
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mtb_seat_holds WHERE showtime_id = %d AND seat_label IN ('" .
            implode( "','", array_map( 'esc_sql', $seats ) ) . "')",
            $showtime_id
        ) );

        // Send ticket email
        self::send_ticket_email( $booking_id );

        return $booking_id;
    }

    /**
     * Generate QR code image for a booking reference.
     * Uses phpqrcode library (bundled in /lib/phpqrcode/).
     * Returns relative path or false on failure.
     */
    public static function generate_qr( $booking_ref ) {
        $qr_lib = MTB_PATH . 'lib/phpqrcode/qrlib.php';
        if ( ! file_exists( $qr_lib ) ) {
            error_log( 'MTB: phpqrcode library not found at ' . $qr_lib );
            return false;
        }

        require_once $qr_lib;

        wp_mkdir_p( MTB_TICKETS_DIR );

        $filename = 'qr-' . $booking_ref . '.png';
        $filepath = MTB_TICKETS_DIR . $filename;

        // QRcode::png( data, file, error_correction_level, pixel_size, frame_size )
        QRcode::png( $booking_ref, $filepath, QR_ECLEVEL_M, 8, 2 );

        return file_exists( $filepath ) ? 'qr-' . $booking_ref . '.png' : false;
    }

    /**
     * Send the booking confirmation email with QR code attached.
     */
    public static function send_ticket_email( $booking_id ) {
        global $wpdb;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, st.show_date, st.show_time, st.price,
                    sc.name AS screen_name, c.name AS cinema_name,
                    p.post_title AS movie_title
             FROM {$wpdb->prefix}mtb_bookings b
             JOIN {$wpdb->prefix}mtb_showtimes st ON b.showtime_id = st.id
             JOIN {$wpdb->prefix}mtb_screens sc   ON st.screen_id = sc.id
             JOIN {$wpdb->prefix}mtb_cinemas c    ON sc.cinema_id = c.id
             JOIN {$wpdb->posts} p                ON st.movie_id = p.ID
             WHERE b.id = %d",
            $booking_id
        ) );

        if ( ! $booking ) return;

        $order = wc_get_order( $booking->order_id );
        if ( ! $order ) return;

        $to      = $order->get_billing_email();
        $name    = $order->get_billing_first_name();
        $subject = "Your Movie Ticket — {$booking->booking_ref}";
        $seats   = implode( ', ', json_decode( $booking->seats, true ) ?: [] );
        $qr_url  = $booking->qr_path ? MTB_TICKETS_URL . $booking->qr_path : '';
        $total   = 'RM ' . number_format( $booking->price * $booking->total_seats, 2 );

        ob_start();
        include MTB_PATH . 'templates/email-ticket.php';
        $body = ob_get_clean();

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // Attach QR image
        $attachments = [];
        if ( $booking->qr_path && file_exists( MTB_TICKETS_DIR . $booking->qr_path ) ) {
            $attachments[] = MTB_TICKETS_DIR . $booking->qr_path;
        }

        wp_mail( $to, $subject, $body, $headers, $attachments );
    }

    /**
     * Mark a booking as used (called by scanner).
     */
    public static function mark_used( $booking_ref ) {
        global $wpdb;
        $booking = MTB_DB::get_booking_by_ref( $booking_ref );
        if ( ! $booking ) return new WP_Error( 'not_found', 'Booking not found.' );
        if ( $booking->status === 'used' ) return new WP_Error( 'already_used', 'Ticket already used.' );
        if ( $booking->status === 'cancelled' ) return new WP_Error( 'cancelled', 'Booking is cancelled.' );

        $wpdb->update(
            $wpdb->prefix . 'mtb_bookings',
            [ 'status' => 'used' ],
            [ 'booking_ref' => $booking_ref ]
        );

        return $booking;
    }
}
