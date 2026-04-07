<?php
defined( 'ABSPATH' ) || exit;

class MTB_Scanner {

    public static function init() {
        add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );
    }

    public static function admin_menu() {
        add_submenu_page(
            'mtb-dashboard',
            'QR Scanner',
            'QR Scanner',
            'manage_options',
            'mtb-scanner',
            [ self::class, 'page_scanner' ]
        );
    }

    public static function register_rest_routes() {
        // POST /wp-json/mtb/v1/validate — validates a booking ref from QR scan
        register_rest_route( 'mtb/v1', '/validate', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_validate' ],
            'permission_callback' => [ self::class, 'rest_permission' ],
            'args' => [
                'booking_ref' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // GET /wp-json/mtb/v1/booking/{ref} — get booking details
        register_rest_route( 'mtb/v1', '/booking/(?P<ref>[A-Z0-9\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'rest_get_booking' ],
            'permission_callback' => [ self::class, 'rest_permission' ],
        ] );
    }

    public static function rest_permission( $request ) {
        // Requires staff to be logged in with manage_options or a custom capability
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    }

    public static function rest_validate( $request ) {
        $ref    = $request->get_param( 'booking_ref' );
        $result = MTB_Booking::mark_used( $ref );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return new WP_REST_Response( [
            'success'     => true,
            'booking_ref' => $result->booking_ref,
            'movie'       => $result->movie_title,
            'show_date'   => $result->show_date,
            'show_time'   => $result->show_time,
            'cinema'      => $result->cinema_name,
            'screen'      => $result->screen_name,
            'seats'       => json_decode( $result->seats, true ),
            'message'     => 'Ticket validated successfully.',
        ], 200 );
    }

    public static function rest_get_booking( $request ) {
        $ref     = $request->get_param( 'ref' );
        $booking = MTB_DB::get_booking_by_ref( $ref );
        if ( ! $booking ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Not found.' ], 404 );
        }
        return new WP_REST_Response( [ 'success' => true, 'booking' => $booking ], 200 );
    }

    public static function page_scanner() {
        $rest_url = rest_url( 'mtb/v1/validate' );
        $nonce    = wp_create_nonce( 'wp_rest' );
        ?>
        <div class="wrap">
            <h1>QR Ticket Scanner</h1>
            <p style="color:#666">Point camera at a ticket QR code. Only accessible to authorised staff.</p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:900px">

                <!-- Camera feed -->
                <div>
                    <div style="position:relative;background:#000;border-radius:12px;overflow:hidden;aspect-ratio:1">
                        <video id="mtb-camera" autoplay playsinline style="width:100%;height:100%;object-fit:cover"></video>
                        <canvas id="mtb-canvas" style="display:none"></canvas>
                        <!-- Scan overlay -->
                        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none">
                            <div style="width:200px;height:200px;border:3px solid #fff;border-radius:12px;opacity:0.6"></div>
                        </div>
                    </div>
                    <button id="mtb-start-scan" class="button button-primary" style="margin-top:12px;width:100%">Start Camera</button>
                    <button id="mtb-stop-scan" class="button" style="margin-top:8px;width:100%;display:none">Stop Camera</button>
                </div>

                <!-- Result panel -->
                <div>
                    <div id="mtb-scan-result" style="border:1px solid #ddd;border-radius:12px;padding:20px;min-height:200px;background:#fafafa">
                        <p style="color:#999;text-align:center;margin-top:60px">Waiting for scan...</p>
                    </div>
                    <div style="margin-top:12px">
                        <label style="font-weight:600;display:block;margin-bottom:4px">Or enter booking ref manually:</label>
                        <div style="display:flex;gap:8px">
                            <input type="text" id="mtb-manual-ref" placeholder="MTB-XXXXXXXX" class="regular-text" style="text-transform:uppercase">
                            <button id="mtb-manual-validate" class="button button-primary">Validate</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
        <script>
        const REST_URL = '<?php echo esc_js($rest_url); ?>';
        const NONCE    = '<?php echo esc_js($nonce); ?>';
        let stream = null, scanning = false;

        const video   = document.getElementById('mtb-camera');
        const canvas  = document.getElementById('mtb-canvas');
        const result  = document.getElementById('mtb-scan-result');
        const ctx     = canvas.getContext('2d');

        document.getElementById('mtb-start-scan').addEventListener('click', async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                video.srcObject = stream;
                scanning = true;
                document.getElementById('mtb-start-scan').style.display = 'none';
                document.getElementById('mtb-stop-scan').style.display  = '';
                requestAnimationFrame(scan);
            } catch(e) {
                alert('Camera access denied: ' + e.message);
            }
        });

        document.getElementById('mtb-stop-scan').addEventListener('click', () => {
            scanning = false;
            if (stream) stream.getTracks().forEach(t => t.stop());
            video.srcObject = null;
            document.getElementById('mtb-start-scan').style.display = '';
            document.getElementById('mtb-stop-scan').style.display  = 'none';
        });

        function scan() {
            if (!scanning) return;
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvas.width  = video.videoWidth;
                canvas.height = video.videoHeight;
                ctx.drawImage(video, 0, 0);
                const data = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(data.data, data.width, data.height, { inversionAttempts: 'dontInvert' });
                if (code && code.data.startsWith('MTB-')) {
                    validate(code.data);
                    return; // pause scanning while validating
                }
            }
            requestAnimationFrame(scan);
        }

        document.getElementById('mtb-manual-validate').addEventListener('click', () => {
            const ref = document.getElementById('mtb-manual-ref').value.trim().toUpperCase();
            if (ref) validate(ref);
        });

        async function validate(ref) {
            showResult('loading', '⏳ Validating ' + ref + '...');
            try {
                const res = await fetch(REST_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body: JSON.stringify({ booking_ref: ref })
                });
                const data = await res.json();

                if (data.success) {
                    const seats = (data.seats || []).join(', ');
                    showResult('success',
                        `<div style="font-size:22px;font-weight:700;color:#1D9E75;margin-bottom:12px">✓ Valid Ticket</div>
                         <table style="width:100%;font-size:14px">
                           <tr><td style="color:#666;padding:4px 0">Ref</td><td><strong>${data.booking_ref}</strong></td></tr>
                           <tr><td style="color:#666;padding:4px 0">Movie</td><td>${data.movie}</td></tr>
                           <tr><td style="color:#666;padding:4px 0">Cinema</td><td>${data.cinema} / ${data.screen}</td></tr>
                           <tr><td style="color:#666;padding:4px 0">Show</td><td>${data.show_date} ${data.show_time}</td></tr>
                           <tr><td style="color:#666;padding:4px 0">Seats</td><td>${seats}</td></tr>
                         </table>`
                    );
                } else {
                    const msgs = {
                        'already_used': '⚠️ This ticket has already been used.',
                        'cancelled':    '❌ This booking is cancelled.',
                        'not_found':    '❌ Booking not found.',
                    };
                    showResult('error', msgs[data.code] || ('❌ ' + data.message));
                }
            } catch(e) {
                showResult('error', '❌ Network error: ' + e.message);
            }

            // Resume scanning after 3s
            if (scanning) setTimeout(() => requestAnimationFrame(scan), 3000);
        }

        function showResult(type, html) {
            const colors = { success:'#f0faf5', error:'#fff5f5', loading:'#f5f5ff' };
            result.style.background = colors[type] || '#fafafa';
            result.innerHTML = '<div style="padding:8px">' + html + '</div>';
        }
        </script>
        <?php
    }
}
