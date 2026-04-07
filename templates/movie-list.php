<?php defined('ABSPATH') || exit;
// Build the base URL for the booking page.
// Priority: booking_page attribute → fallback to movie's own permalink.
$booking_base = '';
if ( ! empty( $atts['booking_page'] ) ) {
    // Could be a full URL or a page slug
    $val = $atts['booking_page'];
    if ( filter_var( $val, FILTER_VALIDATE_URL ) ) {
        $booking_base = trailingslashit( $val );
    } else {
        // Treat as page slug
        $page = get_page_by_path( $val );
        $booking_base = $page ? trailingslashit( get_permalink( $page->ID ) ) : '';
    }
}
?>
<div class="mtb-movie-grid" style="display:grid;grid-template-columns:repeat(<?php echo intval($atts['columns']); ?>,1fr);gap:24px;">
<?php foreach ( $movies as $movie ) :
    $genre    = get_post_meta($movie->ID, '_mtb_genre',    true);
    $duration = get_post_meta($movie->ID, '_mtb_duration', true);
    $rating   = get_post_meta($movie->ID, '_mtb_rating',   true);
    $img      = get_the_post_thumbnail_url($movie->ID, 'medium');
    $showtimes = MTB_DB::get_showtimes_for_movie($movie->ID);
?>
<div class="mtb-movie-card">
    <?php if ($img) : ?>
    <div class="mtb-movie-poster" style="background-image:url('<?php echo esc_url($img); ?>')"></div>
    <?php else : ?>
    <div class="mtb-movie-poster mtb-movie-poster--placeholder"></div>
    <?php endif; ?>
    <div class="mtb-movie-info">
        <?php if ($rating) : ?>
        <span class="mtb-badge"><?php echo esc_html($rating); ?></span>
        <?php endif; ?>
        <h3 class="mtb-movie-title"><?php echo esc_html($movie->post_title); ?></h3>
        <div class="mtb-movie-meta">
            <?php if ($genre)    echo '<span>' . esc_html($genre)    . '</span>'; ?>
            <?php if ($duration) echo '<span>' . esc_html($duration) . ' min</span>'; ?>
        </div>
        <?php if (!empty($showtimes)) :
            // If booking_page is set, link to it with ?movie=ID
            // Otherwise fall back to the movie's own permalink
            $book_url = $booking_base
                ? add_query_arg( 'movie', $movie->ID, $booking_base )
                : get_permalink( $movie->ID );
        ?>
        <a href="<?php echo esc_url($book_url); ?>" class="mtb-btn">
            Book Now (<?php echo count($showtimes); ?> shows)
        </a>
        <?php else : ?>
        <span class="mtb-no-shows">No upcoming shows</span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
