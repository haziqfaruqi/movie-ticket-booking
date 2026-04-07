# Movie Ticket Booking — WordPress Plugin

Full movie ticket booking system with WooCommerce integration and QR code ticket generation.

## Requirements
- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+
- GD extension (for QR generation)

## Installation

1. Upload the `movie-ticket-booking` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. **Install phpqrcode** (see below)
4. Go to **Movie Booking** in the admin sidebar

## Install phpqrcode (Required for QR codes)

**Option A — Download (no Composer):**
1. Download from https://sourceforge.net/projects/phpqrcode/
2. Extract and copy `qrlib.php` into `/lib/phpqrcode/qrlib.php`

**Option B — Composer:**
```
composer require endroid/qr-code
```
Then update `MTB_Booking::generate_qr()` to use the Endroid API.

## Setup Steps

### 1. Add a Cinema
Movie Booking → Cinemas → Add Cinema (name, location, address)

### 2. Add a Screen
Movie Booking → Screens → Add Screen (select cinema, name e.g. "Hall 1", rows × cols)

### 3. Add Movies
Movie Booking → Movies → Add New
- Fill in title, synopsis, featured image (poster)
- Fill in Movie Details sidebar: genre, duration, rating, language

### 4. Add Showtimes
Movie Booking → Showtimes → Add Showtime
- Select movie, screen, date, time, ticket price

### 5. Add shortcodes to pages

**Movie listing page** — shows all movies as cards:
```
[movie_listing columns="3" limit="12"]
```

**Individual movie booking page** — add to each movie's single page or a custom page:
```
[movie_booking id="POST_ID"]
```
Or if placed on the movie's single post template, omit the ID:
```
[movie_booking]
```

**Just the showtimes list:**
```
[movie_showtimes id="POST_ID"]
```

## Booking Flow

1. Customer sees movie listing → clicks "Book Now"
2. Selects a showtime (date + time + cinema)
3. Seat map loads via AJAX — available/booked seats shown
4. Clicks seats → seats held for 15 minutes
5. Clicks "Proceed to Checkout" → redirected to WooCommerce checkout
6. After payment: booking created, QR generated, email sent

## QR Scanner

Go to **Movie Booking → QR Scanner**
- Click "Start Camera" — allow browser camera access
- Point at ticket QR code
- System validates and marks ticket as used
- Or type the booking ref (MTB-XXXXXXXX) manually

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_mtb_movies` | Extended movie metadata |
| `wp_mtb_cinemas` | Cinema records |
| `wp_mtb_screens` | Screen layouts per cinema |
| `wp_mtb_showtimes` | Movie + screen + date/time + price |
| `wp_mtb_bookings` | Confirmed bookings + QR path |
| `wp_mtb_seat_holds` | Temporary seat locks (15 min) |

## Shortcodes Summary

| Shortcode | Description |
|-----------|-------------|
| `[movie_listing]` | All movies grid |
| `[movie_listing columns="4" limit="8"]` | 4-column grid, 8 movies |
| `[movie_booking id="123"]` | Booking flow for movie ID 123 |
| `[movie_booking]` | Booking flow for current post |
| `[movie_showtimes id="123"]` | Showtime list only |

## REST API (for scanner)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/wp-json/mtb/v1/validate` | POST | Validate + mark ticket used |
| `/wp-json/mtb/v1/booking/{ref}` | GET | Get booking details |

Requires `manage_woocommerce` or `manage_options` capability.
