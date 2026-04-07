<?php
defined( 'ABSPATH' ) || exit;

class MTB_Movies {

    const CPT = 'mtb_movie';

    public static function register_post_types() {
        register_post_type( self::CPT, [
            'labels'        => [
                'name'          => 'Movies',
                'singular_name' => 'Movie',
                'add_new_item'  => 'Add New Movie',
                'edit_item'     => 'Edit Movie',
            ],
            'public'        => true,
            'show_in_menu'  => false, // we use our own menu
            'supports'      => [ 'title', 'editor', 'thumbnail' ],
            'rewrite'       => [ 'slug' => 'movies' ],
            'menu_icon'     => 'dashicons-video-alt2',
        ] );
    }

    public static function admin_menus() {
        add_menu_page(
            'Movie Booking',
            'Movie Booking',
            'manage_options',
            'mtb-dashboard',
            [ self::class, 'page_dashboard' ],
            'dashicons-tickets-alt',
            30
        );
        add_submenu_page( 'mtb-dashboard', 'Movies',   'Movies',   'manage_options', 'edit.php?post_type=' . self::CPT );
        add_submenu_page( 'mtb-dashboard', 'Cinemas',  'Cinemas',  'manage_options', 'mtb-cinemas',  [ self::class, 'page_cinemas'  ] );
        add_submenu_page( 'mtb-dashboard', 'Screens',  'Screens',  'manage_options', 'mtb-screens',  [ self::class, 'page_screens'  ] );
        add_submenu_page( 'mtb-dashboard', 'Bookings', 'Bookings', 'manage_options', 'mtb-bookings', [ self::class, 'page_bookings' ] );
    }

    public static function meta_boxes() {
        add_meta_box( 'mtb_movie_details', 'Movie Details', [ self::class, 'render_meta_box' ], self::CPT, 'side' );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'mtb_movie_save', 'mtb_movie_nonce' );
        $genre    = get_post_meta( $post->ID, '_mtb_genre',    true );
        $duration = get_post_meta( $post->ID, '_mtb_duration', true );
        $rating   = get_post_meta( $post->ID, '_mtb_rating',   true );
        $language = get_post_meta( $post->ID, '_mtb_language', true );
        ?>
        <p>
            <label><strong>Genre</strong></label><br>
            <input type="text" name="mtb_genre" value="<?php echo esc_attr($genre); ?>" class="widefat" placeholder="Action, Drama...">
        </p>
        <p>
            <label><strong>Duration (minutes)</strong></label><br>
            <input type="number" name="mtb_duration" value="<?php echo esc_attr($duration); ?>" class="widefat" placeholder="120">
        </p>
        <p>
            <label><strong>Rating</strong></label><br>
            <select name="mtb_rating" class="widefat">
                <?php foreach ( ['U','PG','PG13','18'] as $r ) : ?>
                <option value="<?php echo $r; ?>" <?php selected($rating, $r); ?>><?php echo $r; ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label><strong>Language</strong></label><br>
            <input type="text" name="mtb_language" value="<?php echo esc_attr($language); ?>" class="widefat" placeholder="English / BM">
        </p>
        <?php
    }

    public static function save_meta( $post_id ) {
        if (
            ! isset( $_POST['mtb_movie_nonce'] ) ||
            ! wp_verify_nonce( $_POST['mtb_movie_nonce'], 'mtb_movie_save' ) ||
            ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) ||
            get_post_type( $post_id ) !== self::CPT
        ) return;

        foreach ( ['genre','duration','rating','language'] as $field ) {
            if ( isset( $_POST["mtb_{$field}"] ) ) {
                update_post_meta( $post_id, "_mtb_{$field}", sanitize_text_field( $_POST["mtb_{$field}"] ) );
            }
        }
    }

    // ── Admin pages ──────────────────────────────────────────────────────

    public static function page_dashboard() {
        global $wpdb;
        $movies    = wp_count_posts( self::CPT )->publish;
        $showtimes = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mtb_showtimes WHERE status='active'" );
        $bookings  = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mtb_bookings WHERE status='confirmed'" );
        $revenue   = $wpdb->get_var( "SELECT SUM(o.total_amount) FROM {$wpdb->prefix}wc_order_stats o
                                       JOIN {$wpdb->prefix}mtb_bookings b ON b.order_id = o.order_id
                                       WHERE b.status IN ('confirmed','used')" ) ?: 0;
        ?>
        <div class="wrap">
            <h1>Movie Booking Dashboard</h1>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:20px 0">
                <?php foreach ( [
                    ['Movies',    $movies,    '#7F77DD'],
                    ['Showtimes', $showtimes, '#1D9E75'],
                    ['Bookings',  $bookings,  '#378ADD'],
                    ['Revenue',   'RM ' . number_format($revenue,2), '#BA7517'],
                ] as [$label, $val, $color] ) : ?>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;text-align:center">
                    <div style="font-size:32px;font-weight:700;color:<?php echo $color; ?>"><?php echo $val; ?></div>
                    <div style="color:#666;margin-top:4px"><?php echo $label; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <p><a href="<?php echo admin_url('post-new.php?post_type=mtb_movie'); ?>" class="button button-primary">+ Add Movie</a>
               <a href="<?php echo admin_url('admin.php?page=mtb-cinemas'); ?>" class="button">Manage Cinemas</a>
               <a href="<?php echo admin_url('admin.php?page=mtb-scanner'); ?>" class="button">QR Scanner</a></p>
        </div>
        <?php
    }

    public static function page_cinemas() {
        global $wpdb;
        $table = $wpdb->prefix . 'mtb_cinemas';

        // Handle form submissions
        if ( isset($_POST['mtb_cinema_action']) && check_admin_referer('mtb_cinema') ) {
            if ( $_POST['mtb_cinema_action'] === 'add' ) {
                $wpdb->insert( $table, [
                    'name'     => sanitize_text_field( $_POST['name'] ),
                    'location' => sanitize_text_field( $_POST['location'] ),
                    'address'  => sanitize_textarea_field( $_POST['address'] ),
                ] );
            } elseif ( $_POST['mtb_cinema_action'] === 'delete' && ! empty($_POST['cinema_id']) ) {
                $wpdb->delete( $table, [ 'id' => intval($_POST['cinema_id']) ] );
            }
        }

        $cinemas = $wpdb->get_results( "SELECT * FROM $table ORDER BY name" );
        ?>
        <div class="wrap">
            <h1>Cinemas</h1>
            <h2>Add Cinema</h2>
            <form method="post">
                <?php wp_nonce_field('mtb_cinema'); ?>
                <input type="hidden" name="mtb_cinema_action" value="add">
                <table class="form-table">
                    <tr><th>Name</th><td><input type="text" name="name" class="regular-text" required></td></tr>
                    <tr><th>Location</th><td><input type="text" name="location" class="regular-text"></td></tr>
                    <tr><th>Address</th><td><textarea name="address" rows="3" class="regular-text"></textarea></td></tr>
                </table>
                <?php submit_button('Add Cinema'); ?>
            </form>
            <h2>All Cinemas</h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>Name</th><th>Location</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ( $cinemas as $c ) : ?>
                <tr>
                    <td><?php echo $c->id; ?></td>
                    <td><?php echo esc_html($c->name); ?></td>
                    <td><?php echo esc_html($c->location); ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('mtb_cinema'); ?>
                            <input type="hidden" name="mtb_cinema_action" value="delete">
                            <input type="hidden" name="cinema_id" value="<?php echo $c->id; ?>">
                            <button class="button button-small" onclick="return confirm('Delete cinema?')">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function page_screens() {
        global $wpdb;
        $screens_table = $wpdb->prefix . 'mtb_screens';
        $cinemas_table = $wpdb->prefix . 'mtb_cinemas';

        if ( isset($_POST['mtb_screen_action']) && check_admin_referer('mtb_screen') ) {
            if ( $_POST['mtb_screen_action'] === 'add' ) {
                $wpdb->insert( $screens_table, [
                    'cinema_id' => intval( $_POST['cinema_id'] ),
                    'name'      => sanitize_text_field( $_POST['name'] ),
                    'seat_rows' => intval( $_POST['rows'] ),
                    'seat_cols' => intval( $_POST['cols'] ),
                ] );
            } elseif ( $_POST['mtb_screen_action'] === 'delete' ) {
                $wpdb->delete( $screens_table, [ 'id' => intval($_POST['screen_id']) ] );
            }
        }

        $cinemas = $wpdb->get_results( "SELECT * FROM $cinemas_table ORDER BY name" );
        $screens = $wpdb->get_results(
            "SELECT sc.*, c.name AS cinema_name FROM $screens_table sc
             JOIN $cinemas_table c ON sc.cinema_id = c.id ORDER BY c.name, sc.name"
        );
        ?>
        <div class="wrap">
            <h1>Screens</h1>
            <h2>Add Screen</h2>
            <form method="post">
                <?php wp_nonce_field('mtb_screen'); ?>
                <input type="hidden" name="mtb_screen_action" value="add">
                <table class="form-table">
                    <tr><th>Cinema</th><td>
                        <select name="cinema_id" required>
                            <?php foreach ($cinemas as $c) : ?>
                            <option value="<?php echo $c->id; ?>"><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Screen Name</th><td><input type="text" name="name" class="regular-text" placeholder="Hall 1" required></td></tr>
                    <tr><th>Rows</th><td><input type="number" name="rows" value="8" min="1" max="30"></td></tr>
                    <tr><th>Columns (seats/row)</th><td><input type="number" name="cols" value="12" min="1" max="30"></td></tr>
                </table>
                <?php submit_button('Add Screen'); ?>
            </form>
            <h2>All Screens</h2>
            <table class="widefat striped">
                <thead><tr><th>Cinema</th><th>Screen</th><th>Layout</th><th>Total Seats</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ( $screens as $s ) : ?>
                <tr>
                    <td><?php echo esc_html($s->cinema_name); ?></td>
                    <td><?php echo esc_html($s->name); ?></td>
                    <td><?php echo "{$s->seat_rows} rows × {$s->seat_cols} seats"; ?></td>
                    <td><?php echo $s->seat_rows * $s->seat_cols; ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('mtb_screen'); ?>
                            <input type="hidden" name="mtb_screen_action" value="delete">
                            <input type="hidden" name="screen_id" value="<?php echo $s->id; ?>">
                            <button class="button button-small" onclick="return confirm('Delete screen?')">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function page_bookings() {
        global $wpdb;
        $bookings = $wpdb->get_results(
            "SELECT b.*, s.show_date, s.show_time, p.post_title AS movie,
                    sc.name AS screen, c.name AS cinema
             FROM {$wpdb->prefix}mtb_bookings b
             JOIN {$wpdb->prefix}mtb_showtimes s ON b.showtime_id = s.id
             JOIN {$wpdb->prefix}mtb_screens sc  ON s.screen_id = sc.id
             JOIN {$wpdb->prefix}mtb_cinemas c   ON sc.cinema_id = c.id
             JOIN {$wpdb->posts} p               ON s.movie_id = p.ID
             ORDER BY b.created_at DESC
             LIMIT 100"
        );

        $status_colors = [
            'confirmed' => '#1D9E75',
            'used'      => '#888',
            'cancelled' => '#E24B4A',
            'refunded'  => '#BA7517',
        ];
        ?>
        <div class="wrap">
            <h1>Bookings</h1>
            <table class="widefat striped">
                <thead><tr>
                    <th>Ref</th><th>Movie</th><th>Cinema / Screen</th>
                    <th>Show</th><th>Seats</th><th>Status</th><th>Order</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $bookings as $b ) : $color = $status_colors[$b->status] ?? '#333'; ?>
                <tr>
                    <td><code><?php echo esc_html($b->booking_ref); ?></code></td>
                    <td><?php echo esc_html($b->movie); ?></td>
                    <td><?php echo esc_html("{$b->cinema} / {$b->screen}"); ?></td>
                    <td><?php echo esc_html("{$b->show_date} {$b->show_time}"); ?></td>
                    <td><?php echo esc_html( implode(', ', json_decode($b->seats, true) ?: []) ); ?></td>
                    <td><span style="color:<?php echo $color; ?>;font-weight:600"><?php echo ucfirst($b->status); ?></span></td>
                    <td><a href="<?php echo admin_url("post.php?post={$b->order_id}&action=edit"); ?>">#<?php echo $b->order_id; ?></a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
