/**
 * Movie Ticket Booking — Frontend JS
 */
( function () {
    'use strict';

    window.MTB_App = {
        state: {
            showtime_id:  null,
            showtime_meta: {},
            selected_seats: [],
            ticket_price: 0,
        },

        init() {
            this.bindShowtimeSlots();
            this.bindCheckout();
        },

        // ── Step navigation ──────────────────────────────────────────────
        goToStep( step ) {
            [1,2,3].forEach( n => {
                const panel = document.getElementById( 'mtb-step-' + n );
                if ( panel ) panel.style.display = n === step ? '' : 'none';
            } );

            document.querySelectorAll( '.mtb-step' ).forEach( el => {
                const n = parseInt( el.dataset.step );
                el.classList.toggle( 'mtb-step--active', n === step );
                el.classList.toggle( 'mtb-step--done',   n < step );
            } );

            window.scrollTo({ top: document.querySelector('.mtb-booking-wrap')?.offsetTop - 20, behavior: 'smooth' });
        },

        // ── Step 1: Showtime selection ────────────────────────────────────
        bindShowtimeSlots() {
            document.querySelectorAll( '.mtb-showtime-slot' ).forEach( btn => {
                btn.addEventListener( 'click', () => {
                    document.querySelectorAll( '.mtb-showtime-slot' ).forEach( b => b.classList.remove('mtb-selected') );
                    btn.classList.add( 'mtb-selected' );

                    this.state.showtime_id   = btn.dataset.showtime;
                    this.state.ticket_price  = parseFloat( btn.dataset.price );
                    this.state.showtime_meta = {
                        time:   btn.dataset.time,
                        cinema: btn.dataset.cinema,
                        screen: btn.dataset.screen,
                        price:  btn.dataset.price,
                    };

                    this.goToStep(2);
                    this.loadSeatMap( this.state.showtime_id );
                } );
            } );
        },

        // ── Step 2: Seat map ──────────────────────────────────────────────
        async loadSeatMap( showtime_id ) {
            const map = document.getElementById('mtb-seat-map');
            map.innerHTML = '<div class="mtb-loading">Loading seat map...</div>';

            document.getElementById('mtb-selected-show').textContent =
                `${this.state.showtime_meta.cinema} — ${this.state.showtime_meta.time}`;
            document.getElementById('mtb-selected-price').textContent =
                ` RM ${parseFloat(this.state.showtime_meta.price).toFixed(2)} / seat`;

            const resp = await fetch( MTB.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:      'mtb_get_seats',
                    nonce:       MTB.nonce,
                    showtime_id: showtime_id,
                }),
            } );

            const data = await resp.json();
            if ( ! data.success ) {
                const msg = typeof data.data === 'string' ? data.data : 'Failed to load seats. Please try again.';
                map.innerHTML = `<p style="color:red">${msg}</p>`;
                console.error( 'MTB seat load error:', data );
                return;
            }

            this.state.selected_seats = [];
            this.renderSeatMap( data.data.seats, data.data.rows, data.data.cols );
        },

        renderSeatMap( seats, rows, cols ) {
            const map = document.getElementById('mtb-seat-map');
            map.style.gridTemplateColumns = '1fr';
            map.innerHTML = '';

            // Group seats by row letter
            const byRow = {};
            seats.forEach( s => {
                const row = s.label.charAt(0);
                if ( ! byRow[row] ) byRow[row] = [];
                byRow[row].push(s);
            } );

            Object.entries(byRow).forEach( ([rowLetter, rowSeats]) => {
                const rowEl = document.createElement('div');
                rowEl.className = 'mtb-seat-row';

                const label = document.createElement('span');
                label.className = 'mtb-seat-row-label';
                label.textContent = rowLetter;
                rowEl.appendChild(label);

                rowSeats.forEach( seat => {
                    const btn = document.createElement('button');
                    btn.className = `mtb-seat mtb-seat--${seat.status}`;
                    btn.textContent = seat.label.slice(1); // just the number
                    btn.dataset.label = seat.label;
                    btn.title = seat.label;
                    btn.disabled = seat.status === 'booked';

                    if ( seat.status === 'available' ) {
                        btn.addEventListener( 'click', () => this.toggleSeat(btn, seat.label) );
                    }

                    rowEl.appendChild(btn);
                } );

                map.appendChild(rowEl);
            } );
        },

        toggleSeat( btn, label ) {
            const idx = this.state.selected_seats.indexOf(label);
            if ( idx > -1 ) {
                this.state.selected_seats.splice(idx, 1);
                btn.classList.remove('mtb-seat--selected');
                btn.classList.add('mtb-seat--available');
            } else {
                this.state.selected_seats.push(label);
                btn.classList.add('mtb-seat--selected');
                btn.classList.remove('mtb-seat--available');
            }
            this.updateSummary();
        },

        updateSummary() {
            const n     = this.state.selected_seats.length;
            const total = (n * this.state.ticket_price).toFixed(2);
            const summary = document.getElementById('mtb-selection-summary');

            summary.style.display = n > 0 ? 'flex' : 'none';
            document.getElementById('mtb-selected-seats-label').textContent = this.state.selected_seats.join(', ');
            document.getElementById('mtb-total-price').textContent = `RM ${total}`;
        },

        // ── Step 3: Hold seats + checkout ────────────────────────────────
        bindCheckout() {
            const btn = document.getElementById('mtb-proceed-checkout');
            if ( !btn ) return;

            btn.addEventListener( 'click', async () => {
                if ( this.state.selected_seats.length === 0 ) {
                    alert('Please select at least one seat.');
                    return;
                }

                btn.disabled = true;
                btn.textContent = 'Holding seats...';

                // Hold seats via AJAX
                const resp = await fetch( MTB.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action:      'mtb_hold_seat',
                        nonce:       MTB.nonce,
                        showtime_id: this.state.showtime_id,
                        'seats[]':   this.state.selected_seats,
                    } ),
                } );

                const data = await resp.json();

                if ( ! data.success ) {
                    alert( data.data?.message || 'Seats are no longer available. Please choose again.' );
                    btn.disabled = false;
                    btn.textContent = 'Proceed to Checkout';
                    // Reload seat map
                    this.loadSeatMap( this.state.showtime_id );
                    return;
                }

                // Submit hidden form to WooCommerce add-to-cart
                this.goToStep(3);
                const form = document.getElementById('mtb-checkout-form');
                document.getElementById('form_showtime_id').value = this.state.showtime_id;

                const container = document.getElementById('form_seats_container');
                container.innerHTML = '';
                this.state.selected_seats.forEach( seat => {
                    const input = document.createElement('input');
                    input.type  = 'hidden';
                    input.name  = 'mtb_seats[]';
                    input.value = seat;
                    container.appendChild(input);
                } );

                // Short delay for UX then submit
                setTimeout( () => form.submit(), 800 );
            } );
        },
    };

    document.addEventListener( 'DOMContentLoaded', () => MTB_App.init() );

} )();
