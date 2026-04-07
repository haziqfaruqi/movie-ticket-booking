<?php
defined( 'ABSPATH' ) || exit;

class MTB_Seats {

    /**
     * Generate seat labels for a screen.
     * Rows = A,B,C... Cols = 1,2,3...
     * Returns [ 'A1','A2','A3'... ]
     */
    public static function generate_labels( $rows, $cols ) {
        $labels = [];
        for ( $r = 0; $r < $rows; $r++ ) {
            for ( $c = 1; $c <= $cols; $c++ ) {
                $labels[] = chr( 65 + $r ) . $c;
            }
        }
        return $labels;
    }

    /**
     * AJAX: return seat availability for a showtime
     */
    public static function ajax_get_seats() {
        // Verify nonce — send clear error if it fails
        if ( ! check_ajax_referer( 'mtb_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Security check failed. Please refresh the page.' );
        }

        global $wpdb;

        $showtime_id = intval( $_POST['showtime_id'] ?? 0 );
        if ( ! $showtime_id ) {
            wp_send_json_error( 'No showtime ID received.' );
        }

        // Step 1: get the screen_id linked to this showtime
        $screen_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT screen_id FROM {$wpdb->prefix}mtb_showtimes WHERE id = %d LIMIT 1",
            $showtime_id
        ) );

        if ( ! $screen_id ) {
            $last_err = $wpdb->last_error;
            wp_send_json_error( 'Showtime not found (ID: ' . $showtime_id . ')' . ( $last_err ? ' — DB: ' . $last_err : '' ) );
        }

        // Step 2: get screen dimensions using safe column names (seat_rows / seat_cols)
        $screen = $wpdb->get_row( $wpdb->prepare(
            "SELECT seat_rows AS num_rows, seat_cols AS num_cols
             FROM {$wpdb->prefix}mtb_screens
             WHERE id = %d
             LIMIT 1",
            intval( $screen_id )
        ) );

        if ( ! $screen ) {
            $last_err = $wpdb->last_error;
            wp_send_json_error( 'Screen not found for showtime (screen_id: ' . $screen_id . ')' . ( $last_err ? ' — DB: ' . $last_err : '' ) );
        }

        MTB_DB::purge_expired_holds();
        $booked = MTB_DB::get_booked_seats( $showtime_id );
        $all    = self::generate_labels( intval( $screen->num_rows ), intval( $screen->num_cols ) );

        $seats = [];
        foreach ( $all as $label ) {
            $seats[] = [
                'label'  => $label,
                'row'    => substr( $label, 0, 1 ),
                'status' => in_array( $label, $booked ) ? 'booked' : 'available',
            ];
        }

        wp_send_json_success( [
            'seats' => $seats,
            'rows'  => intval( $screen->num_rows ),
            'cols'  => intval( $screen->num_cols ),
        ] );
    }

    /**
     * AJAX: temporarily hold selected seats for this session
     */
    public static function ajax_hold_seat() {
        check_ajax_referer( 'mtb_nonce', 'nonce' );
        global $wpdb;

        $showtime_id = intval( $_POST['showtime_id'] ?? 0 );
        $seats       = array_map( 'sanitize_text_field', (array) ( $_POST['seats'] ?? [] ) );
        $session_id  = session_id() ?: wp_generate_password( 32, false );

        if ( ! $showtime_id || empty( $seats ) ) wp_send_json_error( 'Invalid data' );

        MTB_DB::purge_expired_holds();

        // Check none are already taken
        $booked = MTB_DB::get_booked_seats( $showtime_id );
        $conflict = array_intersect( $seats, $booked );
        if ( $conflict ) {
            wp_send_json_error( [
                'message'   => 'Some seats are no longer available.',
                'conflicts' => array_values( $conflict ),
            ] );
        }

        // Remove old holds for this session
        $wpdb->delete( $wpdb->prefix . 'mtb_seat_holds', [ 'session_id' => $session_id ] );

        $expires = gmdate( 'Y-m-d H:i:s', time() + 15 * 60 ); // 15 min hold
        foreach ( $seats as $seat ) {
            $wpdb->insert( $wpdb->prefix . 'mtb_seat_holds', [
                'showtime_id' => $showtime_id,
                'seat_label'  => $seat,
                'session_id'  => $session_id,
                'expires_at'  => $expires,
            ] );
        }

        wp_send_json_success( [
            'session_id' => $session_id,
            'expires_at' => $expires,
            'seats'      => $seats,
        ] );
    }
}
