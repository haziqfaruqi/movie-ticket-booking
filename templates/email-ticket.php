<?php defined('ABSPATH') || exit; ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0}
  .wrap{max-width:560px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden}
  .header{background:#0f172a;padding:28px 32px;text-align:center}
  .header h1{color:#fff;margin:0;font-size:22px}
  .header p{color:#94a3b8;margin:4px 0 0;font-size:14px}
  .body{padding:32px}
  .ticket{border:2px dashed #e2e8f0;border-radius:10px;padding:24px;margin:20px 0}
  .ticket-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9}
  .ticket-row:last-child{border-bottom:none}
  .ticket-row .label{color:#64748b;font-size:13px}
  .ticket-row .value{font-weight:600;font-size:14px}
  .qr-block{text-align:center;margin:24px 0}
  .qr-block img{border:6px solid #fff;box-shadow:0 2px 16px rgba(0,0,0,0.1);border-radius:8px}
  .ref{font-family:monospace;font-size:18px;font-weight:700;color:#0f172a;margin:12px 0 4px}
  .ref-note{font-size:12px;color:#94a3b8}
  .footer{background:#f8fafc;padding:20px 32px;text-align:center;font-size:12px;color:#94a3b8}
  .btn{display:inline-block;background:#1D9E75;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;margin:16px 0}
</style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <h1>🎬 Your Movie Ticket</h1>
        <p>Booking confirmed — show QR code at the entrance</p>
    </div>
    <div class="body">
        <p>Hi <?php echo esc_html($name); ?>,</p>
        <p>Your booking is confirmed! Here are your ticket details:</p>

        <div class="ticket">
            <div class="ticket-row">
                <span class="label">Movie</span>
                <span class="value"><?php echo esc_html($booking->movie_title); ?></span>
            </div>
            <div class="ticket-row">
                <span class="label">Cinema</span>
                <span class="value"><?php echo esc_html($booking->cinema_name); ?></span>
            </div>
            <div class="ticket-row">
                <span class="label">Screen</span>
                <span class="value"><?php echo esc_html($booking->screen_name); ?></span>
            </div>
            <div class="ticket-row">
                <span class="label">Date & Time</span>
                <span class="value"><?php echo esc_html(date('D, d M Y g:i A', strtotime($booking->show_date . ' ' . $booking->show_time))); ?></span>
            </div>
            <div class="ticket-row">
                <span class="label">Seats</span>
                <span class="value"><?php echo esc_html($seats); ?></span>
            </div>
            <div class="ticket-row">
                <span class="label">Total Paid</span>
                <span class="value" style="color:#1D9E75"><?php echo esc_html($total); ?></span>
            </div>
        </div>

        <div class="qr-block">
            <?php if ($qr_url) : ?>
            <img src="<?php echo esc_url($qr_url); ?>" width="160" height="160" alt="QR Code">
            <?php endif; ?>
            <div class="ref"><?php echo esc_html($booking->booking_ref); ?></div>
            <div class="ref-note">QR code is also attached to this email</div>
        </div>

        <p style="font-size:13px;color:#64748b">Please arrive 15 minutes before the show. Present this QR code or booking reference at the counter.</p>
    </div>
    <div class="footer">
        <p>This email was sent by <?php echo get_bloginfo('name'); ?><br>
        Questions? Reply to this email.</p>
    </div>
</div>
</body>
</html>
