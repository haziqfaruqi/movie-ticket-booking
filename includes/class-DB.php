<?php
defined( 'ABSPATH' ) || exit;

class MTB_DB {

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Movies (extended metadata beyond CPT)
        dbDelta( "CREATE TABLE {$wpdb->prefix}mtb_movies (
            id          bigint(20) NOT NULL AUTO_INCREMENT,
            post_id     bigint(20) NOT NULL,
            genre       varchar(100),
            duration    int(5) COMMENT 'minutes',
            rating      varchar(10),
            language    varchar(50),
            created_at  datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_id (post_id)
        ) $charset;" );

        // Cinemas
        dbDelta( "CREATE TABLE {$wpdb->prefix}mtb_cinemas (
            id         bigint(20) NOT NULL AUTO_INCREMENT,
            name       varchar(150) NOT NULL,
            location   varchar(200),
            address    text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        // Screens (belong to a cinema, have a seat layout)
        // NOTE: seat_rows / seat_cols avoid MySQL reserved words 'rows' and 'cols'
        dbDelta( "CREATE TABLE {$wpdb->prefix}mtb_screens (
            id         bigint(20) NOT NULL AUTO_INCREMENT,
            cinema_id  bigint(20) NOT NULL,
            name       varchar(100) NOT NULL,
            seat_rows  int(3) NOT NULL DEFAULT 8,
            seat_cols  int(3) NOT NULL DEFAULT 12,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY cinema_id (cinema_id)
        ) $charset;" );

        // Showtimes
        dbDelta( "CREATE TABLE {$wpdb->prefix}mtb_showtimes (
            id           bigint(20) NOT NULL AUTO_INCREMENT,
            movie_id     bigint(20) NOT NULL COMMENT 'wp post id',
            screen_id    bigint(20) NOT NULL,
            show_date    date NOT NULL,
            show_time    time NOT NULL,
            price        decimal(10,2) NOT NULL DEFAULT 15.00,
            status       enum('active','cancelled','housefull') DEFAULT 'active',
            created_at   datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY movie_id (movie_id),
            KEY screen_id (screen_id),
            KEY show_date (show_date)
        ) $charset;" );

        // Bookings
        dbDelta( "CREATE TABLE {$wpdb->prefix}mtb_bookings (
            id           bigint(20) NOT NULL AUTO_INCREMENT,
            booking_ref  varchar(20) NOT NULL,
            order_id     bigint(20) NOT NULL COMMENT 'wc order id',
            showtime_id  bigint(20) NOT NULL,
            user_id      bigint(20),
            seats        text NOT NULL COMMENT 'json array of seat labels e.g. [A1,A2]',
            total_seats  int(3) NOT NULL DEFAULT 1,
            qr_path      varchar(255),
            status       enum('confirmed','used','cancelled','refunded') DEFAULT 'confirmed',
            created_at   datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_ref (booking_ref),
            KEY order_id (order_id),
            KEY showtime_id (showtime_id)
        ) $charset;" );

        // Seat holds (temporary reservation during checkout — released after 15 min)
        dbDelta( "CREATE TABLE {$wpdb->prefix}mtb_seat_holds (
            id          bigint(20) NOT NULL AUTO_INCREMENT,
            showtime_id bigint(20) NOT NULL,
            seat_label  varchar(10) NOT NULL,
            session_id  varchar(64) NOT NULL,
            expires_at  datetime NOT NULL,
            PRIMARY KEY (id),
            KEY showtime_id (showtime_id),
            KEY expires_at (expires_at)
        ) $charset;" );
    }

    /**
     * Runs on every `init`. Patches the mtb_screens table on existing installs
     * where dbDelta() silently skipped `rows`/`cols` because they are MySQL
     * reserved words. Adds seat_rows / seat_cols if missing, migrates any old
     * data, then drops the old reserved-word columns.
     */
    public static function maybe_upgrade() {
        global $wpdb;
        $table = $wpdb->prefix . 'mtb_screens';

        // Discover which columns actually exist in the live table
        $existing = $wpdb->get_col( "DESCRIBE `$table`", 0 );
        if ( empty( $existing ) ) return; // table doesn't exist yet — activation will create it

        $has_seat_rows = in_array( 'seat_rows', $existing, true );
        $has_seat_cols = in_array( 'seat_cols', $existing, true );
        $has_old_rows  = in_array( 'rows',      $existing, true );
        $has_old_cols  = in_array( 'cols',      $existing, true );

        // Add the safe-named columns if missing
        if ( ! $has_seat_rows ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `seat_rows` int(3) NOT NULL DEFAULT 8" );
        }
        if ( ! $has_seat_cols ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `seat_cols` int(3) NOT NULL DEFAULT 12" );
        }

        // Migrate data from old reserved-word columns if they exist
        if ( $has_old_rows && ! $has_seat_rows ) {
            $wpdb->query( "UPDATE `$table` SET `seat_rows` = `rows` WHERE `seat_rows` = 8" );
        }
        if ( $has_old_cols && ! $has_seat_cols ) {
            $wpdb->query( "UPDATE `$table` SET `seat_cols` = `cols` WHERE `seat_cols` = 12" );
        }

        // Drop the broken reserved-word columns now that data is safe
        if ( $has_old_rows ) {
            $wpdb->query( "ALTER TABLE `$table` DROP COLUMN `rows`" );
        }
        if ( $has_old_cols ) {
            $wpdb->query( "ALTER TABLE `$table` DROP COLUMN `cols`" );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public static function get_showtimes_for_movie( $movie_id, $from_date = null ) {
        global $wpdb;
        $from = $from_date ?: current_time( 'Y-m-d' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, sc.name AS screen_name, c.name AS cinema_name
             FROM {$wpdb->prefix}mtb_showtimes s
             JOIN {$wpdb->prefix}mtb_screens sc ON s.screen_id = sc.id
             JOIN {$wpdb->prefix}mtb_cinemas c  ON sc.cinema_id = c.id
             WHERE s.movie_id = %d
               AND s.show_date >= %s
               AND s.status = 'active'
             ORDER BY s.show_date ASC, s.show_time ASC",
            $movie_id, $from
        ) );
    }

    public static function get_booked_seats( $showtime_id ) {
        global $wpdb;
        // Confirmed bookings
        $booked = $wpdb->get_col( $wpdb->prepare(
            "SELECT seats FROM {$wpdb->prefix}mtb_bookings
             WHERE showtime_id = %d AND status IN ('confirmed','used')",
            $showtime_id
        ) );
        $seats = [];
        foreach ( $booked as $json ) {
            $seats = array_merge( $seats, json_decode( $json, true ) ?: [] );
        }

        // Active holds
        $held = $wpdb->get_col( $wpdb->prepare(
            "SELECT seat_label FROM {$wpdb->prefix}mtb_seat_holds
             WHERE showtime_id = %d AND expires_at > %s",
            $showtime_id, current_time( 'mysql' )
        ) );

        return array_unique( array_merge( $seats, $held ) );
    }

    public static function get_booking_by_ref( $ref ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, s.show_date, s.show_time, s.price,
                    sc.name AS screen_name, c.name AS cinema_name,
                    p.post_title AS movie_title
             FROM {$wpdb->prefix}mtb_bookings b
             JOIN {$wpdb->prefix}mtb_showtimes s  ON b.showtime_id = s.id
             JOIN {$wpdb->prefix}mtb_screens sc   ON s.screen_id = sc.id
             JOIN {$wpdb->prefix}mtb_cinemas c    ON sc.cinema_id = c.id
             JOIN {$wpdb->posts} p                ON s.movie_id = p.ID
             WHERE b.booking_ref = %s",
            $ref
        ) );
    }

    public static function generate_booking_ref() {
        global $wpdb;
        do {
            $ref = 'MTB-' . strtoupper( wp_generate_password( 8, false ) );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}mtb_bookings WHERE booking_ref = %s", $ref
            ) );
        } while ( $exists );
        return $ref;
    }

    public static function purge_expired_holds() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mtb_seat_holds WHERE expires_at < %s",
            current_time( 'mysql' )
        ) );
    }
}
