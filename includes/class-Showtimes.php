<?php
defined( 'ABSPATH' ) || exit;

class MTB_Showtimes {

    public static function register_post_types() {
        // Showtimes are stored in a custom DB table, not CPT.
        // This class handles the admin UI for them.
    }

    public static function admin_menus() {
        add_submenu_page(
            'mtb-dashboard',
            'Showtimes',
            'Showtimes',
            'manage_options',
            'mtb-showtimes',
            [ self::class, 'page_showtimes' ]
        );
    }

    public static function meta_boxes() {}
    public static function save_meta( $post_id ) {}

    public static function page_showtimes() {
        global $wpdb;
        $showtimes_table = $wpdb->prefix . 'mtb_showtimes';
        $screens_table   = $wpdb->prefix . 'mtb_screens';
        $cinemas_table   = $wpdb->prefix . 'mtb_cinemas';

        // Handle add/delete
        if ( isset($_POST['mtb_showtime_action']) && check_admin_referer('mtb_showtime') ) {
            if ( $_POST['mtb_showtime_action'] === 'add' ) {
                $wpdb->insert( $showtimes_table, [
                    'movie_id'  => intval($_POST['movie_id']),
                    'screen_id' => intval($_POST['screen_id']),
                    'show_date' => sanitize_text_field($_POST['show_date']),
                    'show_time' => sanitize_text_field($_POST['show_time']),
                    'price'     => floatval($_POST['price']),
                    'status'    => 'active',
                ] );
            } elseif ( $_POST['mtb_showtime_action'] === 'delete' ) {
                $wpdb->delete( $showtimes_table, [ 'id' => intval($_POST['showtime_id']) ] );
            } elseif ( $_POST['mtb_showtime_action'] === 'cancel' ) {
                $wpdb->update( $showtimes_table, ['status' => 'cancelled'], ['id' => intval($_POST['showtime_id'])] );
            }
        }

        // Fetch data for form
        $movies  = get_posts(['post_type' => 'mtb_movie', 'numberposts' => -1, 'post_status' => 'publish']);
        $screens = $wpdb->get_results(
            "SELECT sc.*, c.name AS cinema_name FROM $screens_table sc
             JOIN $cinemas_table c ON sc.cinema_id = c.id ORDER BY c.name, sc.name"
        );
        $showtimes = $wpdb->get_results(
            "SELECT st.*, p.post_title AS movie_title, sc.name AS screen_name, c.name AS cinema_name
             FROM $showtimes_table st
             JOIN {$wpdb->posts} p       ON st.movie_id = p.ID
             JOIN $screens_table sc      ON st.screen_id = sc.id
             JOIN $cinemas_table c       ON sc.cinema_id = c.id
             WHERE st.show_date >= CURDATE()
             ORDER BY st.show_date, st.show_time
             LIMIT 100"
        );
        ?>
        <div class="wrap">
            <h1>Showtimes</h1>
            <h2>Add Showtime</h2>
            <form method="post">
                <?php wp_nonce_field('mtb_showtime'); ?>
                <input type="hidden" name="mtb_showtime_action" value="add">
                <table class="form-table">
                    <tr><th>Movie</th><td>
                        <select name="movie_id" required>
                            <?php foreach($movies as $m): ?>
                            <option value="<?php echo $m->ID; ?>"><?php echo esc_html($m->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Screen</th><td>
                        <select name="screen_id" required>
                            <?php foreach($screens as $s): ?>
                            <option value="<?php echo $s->id; ?>"><?php echo esc_html("{$s->cinema_name} — {$s->name}"); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Date</th><td><input type="date" name="show_date" required min="<?php echo date('Y-m-d'); ?>"></td></tr>
                    <tr><th>Time</th><td><input type="time" name="show_time" required></td></tr>
                    <tr><th>Ticket Price (RM)</th><td><input type="number" name="price" value="15.00" min="0" step="0.50"></td></tr>
                </table>
                <?php submit_button('Add Showtime'); ?>
            </form>

            <h2>Upcoming Showtimes</h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>Movie</th><th>Cinema / Screen</th><th>Date</th><th>Time</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach($showtimes as $st): ?>
                <tr>
                    <td><?php echo $st->id; ?></td>
                    <td><?php echo esc_html($st->movie_title); ?></td>
                    <td><?php echo esc_html("{$st->cinema_name} / {$st->screen_name}"); ?></td>
                    <td><?php echo esc_html($st->show_date); ?></td>
                    <td><?php echo esc_html($st->show_time); ?></td>
                    <td>RM <?php echo number_format($st->price, 2); ?></td>
                    <td><?php echo ucfirst($st->status); ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('mtb_showtime'); ?>
                            <input type="hidden" name="mtb_showtime_action" value="cancel">
                            <input type="hidden" name="showtime_id" value="<?php echo $st->id; ?>">
                            <button class="button button-small">Cancel</button>
                        </form>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('mtb_showtime'); ?>
                            <input type="hidden" name="mtb_showtime_action" value="delete">
                            <input type="hidden" name="showtime_id" value="<?php echo $st->id; ?>">
                            <button class="button button-small" onclick="return confirm('Delete showtime?')">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
