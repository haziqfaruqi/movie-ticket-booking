<?php
defined( 'ABSPATH' ) || exit;

class MTB_Shortcodes {

    public static function register() {
        add_shortcode( 'movie_listing',  [ self::class, 'movie_listing'  ] );
        add_shortcode( 'movie_booking',  [ self::class, 'movie_booking'  ] );
        add_shortcode( 'movie_showtimes',[ self::class, 'movie_showtimes'] );
    }

    // ── [movie_listing] ──────────────────────────────────────────────────
    // Shows all published movies as cards
    public static function movie_listing( $atts ) {
        $atts = shortcode_atts( [
            'columns'      => 3,
            'limit'        => 12,
            'booking_page' => '',
        ], $atts );
        // Assets already enqueued globally

        $movies = get_posts( [
            'post_type'      => 'mtb_movie',
            'post_status'    => 'publish',
            'numberposts'    => intval( $atts['limit'] ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        ob_start();
        include MTB_PATH . 'templates/movie-list.php';
        return ob_get_clean();
    }

    // ── [movie_booking id="POST_ID"] ─────────────────────────────────────
    // Full booking flow for a specific movie
    public static function movie_booking( $atts ) {
        $atts    = shortcode_atts( [ 'id' => 0 ], $atts );
        // Accept ?movie=ID in the URL (used when linking from movie listing)
        $post_id = intval( $atts['id'] )
                   ?: intval( $_GET['movie'] ?? 0 )
                   ?: get_the_ID();

        if ( ! $post_id ) return '<p>No movie specified.</p>';

        // Assets already enqueued globally
        ob_start();
        include MTB_PATH . 'templates/showtime-picker.php';
        return ob_get_clean();
    }

    // ── [movie_showtimes id="POST_ID"] ───────────────────────────────────
    // Just the showtime list for a movie
    public static function movie_showtimes( $atts ) {
        $atts    = shortcode_atts( [ 'id' => 0 ], $atts );
        $post_id = intval( $atts['id'] ) ?: get_the_ID();
        if ( ! $post_id ) return '';

        // Assets already enqueued globally
        $showtimes = MTB_DB::get_showtimes_for_movie( $post_id );
        ob_start();
        include MTB_PATH . 'templates/showtime-picker.php';
        return ob_get_clean();
    }
}
