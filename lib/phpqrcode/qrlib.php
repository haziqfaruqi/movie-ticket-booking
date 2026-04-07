<?php
/**
 * phpqrcode stub — replace with real library
 * 
 * INSTALL THE REAL LIBRARY:
 * Option A (recommended): Download phpqrcode from https://sourceforge.net/projects/phpqrcode/
 *   then drop qrlib.php into /lib/phpqrcode/qrlib.php
 *
 * Option B (Composer): composer require endroid/qr-code
 *   then update MTB_Booking::generate_qr() to use Endroid's API
 */
if (!defined('QR_ECLEVEL_M')) {
    define('QR_ECLEVEL_L', 0);
    define('QR_ECLEVEL_M', 1);
    define('QR_ECLEVEL_Q', 2);
    define('QR_ECLEVEL_H', 3);
}
class QRcode {
    public static function png($text, $outfile = false, $level = QR_ECLEVEL_M, $size = 6, $margin = 2) {
        $px  = max(4, intval($size));
        $dim = 29 * $px + 2 * $margin * $px;
        $img = imagecreatetruecolor($dim, $dim);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        imagerectangle($img, 0, 0, $dim-1, $dim-1, $black);
        imagerectangle($img, 2, 2, $dim-3, $dim-3, $black);
        imagestring($img, 2, 10, intval($dim/2)-6, 'INSTALL phpqrcode', $black);
        imagestring($img, 1, 10, intval($dim/2)+6, substr($text,0,24), $black);
        if ($outfile) { imagepng($img, $outfile); } 
        else { header('Content-Type: image/png'); imagepng($img); }
        imagedestroy($img);
    }
}
