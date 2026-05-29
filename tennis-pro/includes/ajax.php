<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Register AJAX hooks ──────────────────────────────────────────────── */
add_action( 'wp_ajax_tennis_save',             'tennis_pro_ajax_save'             );
add_action( 'wp_ajax_tennis_update',           'tennis_pro_ajax_update'           );
add_action( 'wp_ajax_tennis_delete',           'tennis_pro_ajax_delete'           );
add_action( 'wp_ajax_tennis_waitlist_join',    'tennis_pro_ajax_waitlist_join'    );
add_action( 'wp_ajax_tennis_waitlist_leave',   'tennis_pro_ajax_waitlist_leave'   );
add_action( 'wp_ajax_tennis_block_slot',       'tennis_pro_ajax_block_slot'       );
add_action( 'wp_ajax_tennis_unblock_slot',     'tennis_pro_ajax_unblock_slot'     );
add_action( 'wp_ajax_tennis_cancel_recurring', 'tennis_pro_ajax_cancel_recurring' );
add_action( 'wp_ajax_tennis_update_email',     'tennis_pro_ajax_update_email'     );
add_action( 'wp_ajax_tennis_test_mycal',       'tennis_pro_ajax_test_mycal'       );

// Guests may only VIEW – no nopriv actions registered intentionally.

/* ══════════════════════════════════════════════════════════════════════════
   SHARED HELPERS
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_ajax_auth_check(): void {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => __( 'Bitte zuerst einloggen.', 'tennis-pro' ) ], 403 );
    }
    if ( ! check_ajax_referer( 'tennis_frontend_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Ungültige Anfrage (Nonce).', 'tennis-pro' ) ], 403 );
    }
}

/**
 * Simple rate limiter using transients.
 * Allows max $limit calls per $window_seconds per user per action key.
 */
function tennis_pro_rate_limit( string $key, int $limit = 5, int $window_seconds = 10 ): void {
    $user_id     = get_current_user_id();
    $transient   = 'tnp_rl_' . $key . '_' . $user_id;
    $count       = (int) get_transient( $transient );

    if ( $count >= $limit ) {
        wp_send_json_error( [ 'message' => __( 'Zu viele Anfragen. Bitte kurz warten.', 'tennis-pro' ) ], 429 );
    }

    if ( $count === 0 ) {
        set_transient( $transient, 1, $window_seconds );
    } else {
        // Increment without resetting the TTL (best-effort; WP has no increment API)
        set_transient( $transient, $count + 1, $window_seconds );
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   SAVE (new booking – single or recurring)
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_save() {
    tennis_pro_ajax_auth_check();
    tennis_pro_rate_limit( 'save' );
    global $wpdb;

    $date      = tennis_pro_validate_date( sanitize_text_field( wp_unslash( $_POST['date']        ?? '' ) ) );
    $timeslot  = sanitize_text_field( wp_unslash( $_POST['timeslot']   ?? '' ) );
    $court_id  = (int) ( $_POST['court_id']    ?? 0 );
    $cat_id    = (int) ( $_POST['category_id'] ?? 0 );
    $duration  = max( 1, min( 8, (int) ( $_POST['duration'] ?? 1 ) ) );
    $name      = sanitize_text_field( wp_unslash( $_POST['player_name'] ?? '' ) );
    $user_id   = get_current_user_id();
    $is_admin  = current_user_can( 'tennis_manage' );

    // Trainer (admin-only)
    $trainer_id   = 0;
    $trainer_name = '';
    if ( $is_admin ) {
        $raw_trainer = (int) ( $_POST['trainer_id'] ?? 0 );
        if ( $raw_trainer > 0 ) {
            $tr = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}tennis_trainers WHERE id=%d", $raw_trainer
            ) );
            if ( $tr ) {
                $trainer_id   = (int) $tr->id;
                $trainer_name = $tr->name;
            }
        }
    }

    // Recurring params
    $recurring        = ! empty( $_POST['recurring'] );
    $rec_pattern      = in_array( $_POST['rec_pattern'] ?? '', [ 'daily', 'weekly' ], true )
        ? sanitize_text_field( wp_unslash( $_POST['rec_pattern'] ) )
        : 'weekly';
    $rec_day_of_week  = (int) ( $_POST['rec_day_of_week'] ?? (int) gmdate( 'w', strtotime( $date ) ) );
    $rec_end_date     = tennis_pro_validate_date( sanitize_text_field( wp_unslash( $_POST['rec_end_date'] ?? $date ) ) );

    // ── Validations ────────────────────────────────────────────────────
    if ( ! tennis_pro_validate_timeslot( $timeslot ) ) {
        wp_send_json_error( [ 'message' => __( 'Ungültige Uhrzeit.', 'tennis-pro' ) ], 400 );
    }
    if ( $date < gmdate( 'Y-m-d' ) ) {
        wp_send_json_error( [ 'message' => __( 'Vergangene Termine können nicht gebucht werden.', 'tennis-pro' ) ], 400 );
    }

    // ── Buchungshorizont (nur für Nicht-Admins) ────────────────────────
    if ( ! $is_admin ) {
        $settings = tennis_pro_get_settings();
        $horizon  = (int) $settings['booking_horizon'];
        if ( $horizon > 0 ) {
            $max_date = gmdate( 'Y-m-d', strtotime( "+{$horizon} days" ) );
            if ( $date > $max_date ) {
                wp_send_json_error( [
                    'message' => sprintf(
                        /* translators: %d = number of days */
                        __( 'Buchungen sind maximal %d Tage im Voraus möglich.', 'tennis-pro' ),
                        $horizon
                    ),
                ], 400 );
            }
        }
    }

    if ( $court_id <= 0 || ! $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}tennis_courts WHERE id=%d", $court_id
    ) ) ) {
        wp_send_json_error( [ 'message' => __( 'Ungültiger Platz.', 'tennis-pro' ) ], 400 );
    }
    if ( $cat_id > 0 ) {
        $cat_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, admin_only FROM {$wpdb->prefix}tennis_categories WHERE id=%d", $cat_id
        ) );
        if ( ! $cat_row ) {
            wp_send_json_error( [ 'message' => __( 'Ungültige Kategorie.', 'tennis-pro' ) ], 400 );
        }
        if ( (int) $cat_row->admin_only && ! $is_admin ) {
            wp_send_json_error( [ 'message' => __( 'Diese Kategorie ist nur für Administratoren verfügbar.', 'tennis-pro' ) ], 403 );
        }
    }

    // ── Booking limit (skip for admin) ─────────────────────────────────
    if ( ! $is_admin ) {
        $settings = tennis_pro_get_settings();
        $limit    = (int) $settings['booking_limit'];
        if ( $limit > 0 ) {
            $count = tennis_pro_count_user_bookings( $user_id, $settings['booking_limit_period'] );
            if ( $count >= $limit ) {
                wp_send_json_error( [
                    'message' => sprintf(
                        /* translators: %d = limit number */
                        __( 'Du hast das Buchungslimit von %d erreicht.', 'tennis-pro' ),
                        $limit
                    )
                ], 409 );
            }
        }
    }

    // ── Recurring: admin-only guard ────────────────────────────────────
    if ( $recurring && ! $is_admin ) {
        wp_send_json_error( [ 'message' => __( 'Wiederkehrende Buchungen sind nur für Administratoren verfügbar.', 'tennis-pro' ) ], 403 );
    }

    // ── Recurring: generate dates and insert all (wrapped in transaction) ─
    if ( $recurring && $rec_end_date >= $date ) {
        $dates = tennis_pro_recurring_dates( $rec_pattern, $rec_day_of_week, $date, $rec_end_date );

        $wpdb->query( 'START TRANSACTION' );

        // Create recurring group
        $ok_group = $wpdb->insert(
            $wpdb->prefix . 'tennis_recurring_groups',
            [
                'pattern'      => $rec_pattern,
                'day_of_week'  => $rec_day_of_week,
                'start_date'   => $date,
                'end_date'     => $rec_end_date,
                'court_id'     => $court_id,
                'timeslot'     => $timeslot,
                'duration'     => $duration,
                'user_id'      => $user_id,
                'player_name'  => $name,
                'category_id'  => $cat_id,
                'trainer_id'   => $trainer_id,
            ],
            [ '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d' ]
        );

        if ( ! $ok_group ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( [ 'message' => __( 'Datenbankfehler beim Anlegen der Serie.', 'tennis-pro' ) ], 500 );
        }

        $recurring_id  = (int) $wpdb->insert_id;
        $created       = 0;
        $skipped       = 0;
        $skipped_slots = [];   // collect {date, reason} for each skipped slot
        $db_error      = false;

        // Load court name once for skip messages
        $court_row_name = (string) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tennis_courts WHERE id=%d", $court_id
        ) ) ?? '' );

        foreach ( $dates as $d ) {
            if ( $d < gmdate( 'Y-m-d' ) ) { $skipped++; continue; }
            if ( tennis_pro_booking_conflict( $court_id, $d, $timeslot, $duration ) ||
                 tennis_pro_slot_is_blocked(  $court_id, $d, $timeslot, $duration ) ||
                 tennis_pro_slot_has_mycal_conflict( $court_id, $d, $timeslot, $duration ) ) {
                $skipped++;
                $skipped_slots[] = [
                    'date'      => $d,
                    'date_fmt'  => date_i18n( 'j.n.Y', strtotime( $d ) ),
                    'timeslot'  => $timeslot,
                    'court'     => $court_row_name,
                ];
                continue;
            }
            $ok = $wpdb->insert(
                $wpdb->prefix . 'tennis_bookings',
                [
                    'court_id'     => $court_id, 'date' => $d, 'timeslot' => $timeslot,
                    'duration'     => $duration,  'user_id' => $user_id,
                    'player_name'  => $name, 'category_id' => $cat_id,
                    'trainer_id'   => $trainer_id,
                    'recurring_id' => $recurring_id,
                ],
                [ '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d' ]
            );
            if ( $ok ) {
                $created++;
            } else {
                $db_error = true;
                $skipped++;
            }
        }

        if ( $db_error && $created === 0 ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( [ 'message' => __( 'Datenbankfehler: keine Buchungen konnten angelegt werden.', 'tennis-pro' ) ], 500 );
        }

        $wpdb->query( 'COMMIT' );

        wp_send_json_success( [
            'recurring'     => true,
            'created'       => $created,
            'skipped'       => $skipped,
            'skipped_slots' => $skipped_slots,
            'recurring_id'  => $recurring_id,
        ] );
    }

    // ── Single booking ─────────────────────────────────────────────────
    if ( tennis_pro_slot_is_blocked( $court_id, $date, $timeslot, $duration ) ) {
        wp_send_json_error( [ 'message' => __( 'Dieser Slot ist gesperrt.', 'tennis-pro' ) ], 409 );
    }
    if ( tennis_pro_booking_conflict( $court_id, $date, $timeslot, $duration ) ) {
        wp_send_json_error( [ 'message' => __( 'Dieser Slot ist bereits belegt.', 'tennis-pro' ) ], 409 );
    }

    $ok = $wpdb->insert(
        $wpdb->prefix . 'tennis_bookings',
        [
            'court_id'    => $court_id, 'date' => $date, 'timeslot' => $timeslot,
            'duration'    => $duration, 'user_id' => $user_id,
            'player_name' => $name,    'category_id' => $cat_id,
            'trainer_id'  => $trainer_id,
        ],
        [ '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d' ]
    );

    if ( ! $ok ) {
        wp_send_json_error( [ 'message' => __( 'Datenbankfehler.', 'tennis-pro' ) ], 500 );
    }

    $new_id = $wpdb->insert_id;

    // Email
    $booking_obj = (object) [
        'id' => $new_id, 'court_id' => $court_id, 'date' => $date,
        'timeslot' => $timeslot, 'duration' => $duration,
        'user_id' => $user_id, 'player_name' => $name, 'category_id' => $cat_id,
        'recurring_id' => 0, 'created_at' => current_time( 'mysql' ),
    ];
    $court_row  = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}tennis_courts WHERE id=%d", $court_id ) );
    $cat_map    = tennis_pro_cat_map( tennis_pro_get_categories() );
    $cat        = $cat_map[ $cat_id ] ?? null;
    tennis_pro_mail_booking_confirmed( $booking_obj, $court_row->name ?? '', $cat->name ?? '' );

    wp_send_json_success( [
        'id'            => $new_id,
        'category_id'   => $cat_id,
        'player_name'   => $name,
        'duration'      => $duration,
        'duration_label'=> tennis_pro_duration_label( $duration ),
        'cat_name'      => $cat->name       ?? '',
        'color'         => $cat->color      ?? '#999999',
        'text_color'    => $cat->text_color ?? '#ffffff',
        'display_name'  => wp_get_current_user()->display_name,
        'trainer_id'    => $trainer_id,
        'trainer_name'  => $trainer_name,
    ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   UPDATE
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_update() {
    tennis_pro_ajax_auth_check();
    global $wpdb;

    $id       = (int) ( $_POST['id'] ?? 0 );
    $cat_id   = (int) ( $_POST['category_id'] ?? 0 );
    $name     = sanitize_text_field( wp_unslash( $_POST['player_name'] ?? '' ) );
    $is_admin = current_user_can( 'tennis_manage' );

    // Trainer (admin-only)
    $trainer_id   = 0;
    $trainer_name = '';
    if ( $is_admin ) {
        $raw_trainer = (int) ( $_POST['trainer_id'] ?? -1 ); // -1 = not sent → keep current
        if ( $raw_trainer >= 0 ) { // 0 = "no trainer" explicitly chosen
            if ( $raw_trainer > 0 ) {
                $tr = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, name FROM {$wpdb->prefix}tennis_trainers WHERE id=%d", $raw_trainer
                ) );
                if ( $tr ) {
                    $trainer_id   = (int) $tr->id;
                    $trainer_name = $tr->name;
                }
            }
            // $raw_trainer === 0 → trainer_id stays 0, trainer_name stays ''
        }
    }

    // New: timeslot + duration change
    $new_timeslot = sanitize_text_field( wp_unslash( $_POST['timeslot'] ?? '' ) );
    $max_dur      = $is_admin ? 8 : 6;  // Users max 3 h (6×30 min)
    $new_duration = max( 1, min( $max_dur, (int) ( $_POST['duration'] ?? 1 ) ) );

    if ( $cat_id > 0 ) {
        $cat_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, admin_only FROM {$wpdb->prefix}tennis_categories WHERE id=%d", $cat_id
        ) );
        if ( ! $cat_row ) {
            wp_send_json_error( [ 'message' => __( 'Ungültige Kategorie.', 'tennis-pro' ) ], 400 );
        }
        if ( (int) $cat_row->admin_only && ! $is_admin ) {
            wp_send_json_error( [ 'message' => __( 'Diese Kategorie ist nur für Administratoren verfügbar.', 'tennis-pro' ) ], 403 );
        }
    }

    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}tennis_bookings WHERE id=%d", $id
    ) );
    if ( ! $booking ) {
        wp_send_json_error( [ 'message' => __( 'Buchung nicht gefunden.', 'tennis-pro' ) ], 404 );
    }
    if ( ! $is_admin && (int) $booking->user_id !== get_current_user_id() ) {
        wp_send_json_error( [ 'message' => __( 'Keine Berechtigung.', 'tennis-pro' ) ], 403 );
    }

    // Resolve final timeslot/duration (fall back to original if not sent)
    if ( ! $new_timeslot || ! tennis_pro_validate_timeslot( $new_timeslot ) ) {
        $new_timeslot = $booking->timeslot;
    }
    // Clamp duration again against original if no value sent
    if ( ! isset( $_POST['duration'] ) ) {
        $new_duration = (int) $booking->duration;
    }

    // Detect whether the slot actually moved
    $slot_changed = ( $new_timeslot !== $booking->timeslot || $new_duration !== (int) $booking->duration );

    // If the slot changed, check for conflicts (exclude this booking from the check)
    if ( $slot_changed ) {
        if ( tennis_pro_booking_conflict( (int) $booking->court_id, $booking->date, $new_timeslot, $new_duration, $id ) ) {
            wp_send_json_error( [ 'message' => __( 'Dieser Zeitslot ist bereits belegt.', 'tennis-pro' ) ], 409 );
        }
        if ( tennis_pro_slot_is_blocked( (int) $booking->court_id, $booking->date, $new_timeslot, $new_duration ) ) {
            wp_send_json_error( [ 'message' => __( 'Dieser Zeitslot ist gesperrt.', 'tennis-pro' ) ], 409 );
        }
    }

    // Build update data; include trainer_id only if admin sent it explicitly
    $update_data   = [
        'player_name' => $name,
        'category_id' => $cat_id,
        'timeslot'    => $new_timeslot,
        'duration'    => $new_duration,
    ];
    $update_format = [ '%s', '%d', '%s', '%d' ];
    if ( $is_admin && isset( $_POST['trainer_id'] ) ) {
        $update_data['trainer_id'] = $trainer_id;
        $update_format[]           = '%d';
        // Resolve trainer_name if not already set (e.g. trainer_id > 0 but lookup failed above)
        if ( $trainer_id > 0 && $trainer_name === '' ) {
            $tr = $wpdb->get_row( $wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}tennis_trainers WHERE id=%d", $trainer_id
            ) );
            $trainer_name = $tr ? $tr->name : '';
        }
    } else {
        // Non-admin or trainer_id not sent: keep existing trainer_name from booking row
        $trainer_name = $booking->trainer_name ?? '';
        $trainer_id   = (int) ( $booking->trainer_id ?? 0 );
    }

    $wpdb->update(
        $wpdb->prefix . 'tennis_bookings',
        $update_data,
        [ 'id' => $id ],
        $update_format,
        [ '%d' ]
    );

    $cat_map = tennis_pro_cat_map( tennis_pro_get_categories() );
    $cat     = $cat_map[ $cat_id ] ?? null;

    wp_send_json_success( [
        'id'             => $id,
        'category_id'    => $cat_id,
        'player_name'    => $name,
        'cat_name'       => $cat->name       ?? '',
        'color'          => $cat->color      ?? '#999999',
        'text_color'     => $cat->text_color ?? '#ffffff',
        'timeslot'       => $new_timeslot,
        'duration'       => $new_duration,
        'duration_label' => tennis_pro_duration_label( $new_duration ),
        'slot_changed'   => $slot_changed,
        'trainer_id'     => $trainer_id,
        'trainer_name'   => $trainer_name,
    ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   DELETE
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_delete() {
    tennis_pro_ajax_auth_check();
    global $wpdb;

    $id            = (int) ( $_POST['id']             ?? 0 );
    $cancel_series = ! empty( $_POST['cancel_series'] );
    $is_admin      = current_user_can( 'tennis_manage' );

    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}tennis_bookings WHERE id=%d", $id
    ) );
    if ( ! $booking ) {
        wp_send_json_error( [ 'message' => __( 'Buchung nicht gefunden.', 'tennis-pro' ) ], 404 );
    }
    if ( ! $is_admin && (int) $booking->user_id !== get_current_user_id() ) {
        wp_send_json_error( [ 'message' => __( 'Keine Berechtigung.', 'tennis-pro' ) ], 403 );
    }

    // ── Stornierungsfrist prüfen (nur für Nicht-Admins) ────────────────
    if ( ! $is_admin ) {
        $settings         = tennis_pro_get_settings();
        $deadline_hours   = (int) $settings['cancel_deadline'];
        if ( $deadline_hours > 0 ) {
            $booking_ts = strtotime( $booking->date . ' ' . $booking->timeslot . ':00' );
            if ( $booking_ts !== false && time() > ( $booking_ts - $deadline_hours * 3600 ) ) {
                wp_send_json_error( [
                    'message' => sprintf(
                        /* translators: %d = hours */
                        __( 'Stornierung nur bis %d Stunde(n) vor dem Termin möglich.', 'tennis-pro' ),
                        $deadline_hours
                    ),
                ], 409 );
            }
        }
    }

    // Cancel entire recurring series?
    if ( $cancel_series && (int) $booking->recurring_id > 0 ) {
        $rid             = (int) $booking->recurring_id;
        $future_bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tennis_bookings
              WHERE recurring_id=%d AND date >= %s",
            $rid, gmdate( 'Y-m-d' )
        ) );
        // Fetch court name once for cancellation mails.
        $series_court = $wpdb->get_row( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tennis_courts WHERE id=%d", (int) $booking->court_id
        ) );
        foreach ( $future_bookings as $b ) {
            tennis_pro_mail_booking_cancelled( $b, $series_court->name ?? '' );
            $wpdb->delete( $wpdb->prefix . 'tennis_bookings', [ 'id' => (int) $b->id ], [ '%d' ] );
        }
        // Remove the orphaned recurring group record.
        $wpdb->delete( $wpdb->prefix . 'tennis_recurring_groups', [ 'id' => $rid ], [ '%d' ] );
        wp_send_json_success( [ 'id' => $id, 'series_cancelled' => true ] );
    }

    // Single delete + waitlist notification
    $court_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}tennis_courts WHERE id=%d", (int) $booking->court_id
    ) );
    tennis_pro_mail_booking_cancelled( $booking, $court_row->name ?? '' );
    $wpdb->delete( $wpdb->prefix . 'tennis_bookings', [ 'id' => $id ], [ '%d' ] );
    tennis_pro_mail_waitlist_notify( (int) $booking->court_id, $booking->date, $booking->timeslot );

    wp_send_json_success( [ 'id' => $id ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   WAITLIST – JOIN
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_waitlist_join() {
    tennis_pro_ajax_auth_check();
    tennis_pro_rate_limit( 'wl', 3, 15 );
    global $wpdb;

    $court_id = (int) ( $_POST['court_id'] ?? 0 );
    $date     = tennis_pro_validate_date( sanitize_text_field( wp_unslash( $_POST['date']     ?? '' ) ) );
    $timeslot = sanitize_text_field( wp_unslash( $_POST['timeslot'] ?? '' ) );
    $duration = max( 1, min( 8, (int) ( $_POST['duration'] ?? 1 ) ) );
    $user_id  = get_current_user_id();
    $user     = wp_get_current_user();

    if ( ! tennis_pro_validate_timeslot( $timeslot ) || $court_id <= 0 ) {
        wp_send_json_error( [ 'message' => __( 'Ungültige Daten.', 'tennis-pro' ) ], 400 );
    }

    // Already on waitlist?
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}tennis_waitlist
          WHERE court_id=%d AND date=%s AND timeslot=%s AND user_id=%d",
        $court_id, $date, $timeslot, $user_id
    ) );
    if ( $existing ) {
        wp_send_json_error( [ 'message' => __( 'Du stehst bereits auf der Warteliste.', 'tennis-pro' ) ], 409 );
    }

    $wpdb->insert(
        $wpdb->prefix . 'tennis_waitlist',
        [
            'court_id'    => $court_id,
            'date'        => $date,
            'timeslot'    => $timeslot,
            'duration'    => $duration,
            'user_id'     => $user_id,
            'email'       => $user->user_email,
            'player_name' => $user->display_name,
        ],
        [ '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
    );

    $court_row  = $wpdb->get_row( $wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}tennis_courts WHERE id=%d", $court_id
    ) );
    $end_min    = tennis_pro_slot_to_minutes( $timeslot ) + $duration * 30;
    $end_time   = tennis_pro_minutes_to_slot( $end_min );

    wp_send_json_success( [
        'message'     => __( 'Du stehst jetzt auf der Warteliste.', 'tennis-pro' ),
        'court_id'    => $court_id,
        'court_name'  => $court_row->name ?? '',
        'date'        => $date,
        'date_label'  => date_i18n( 'l, j. F Y', strtotime( $date ) ),
        'timeslot'    => $timeslot,
        'end_time'    => $end_time,
        'player_name' => $user->display_name,
    ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   WAITLIST – LEAVE
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_waitlist_leave() {
    tennis_pro_ajax_auth_check();
    global $wpdb;

    $court_id = (int) ( $_POST['court_id'] ?? 0 );
    $date     = tennis_pro_validate_date( sanitize_text_field( wp_unslash( $_POST['date']     ?? '' ) ) );
    $timeslot = sanitize_text_field( wp_unslash( $_POST['timeslot'] ?? '' ) );
    $user_id  = get_current_user_id();

    $wpdb->delete(
        $wpdb->prefix . 'tennis_waitlist',
        [ 'court_id' => $court_id, 'date' => $date, 'timeslot' => $timeslot, 'user_id' => $user_id ],
        [ '%d', '%s', '%s', '%d' ]
    );

    wp_send_json_success( [ 'message' => __( 'Du wurdest von der Warteliste entfernt.', 'tennis-pro' ) ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   BLOCK SLOT (admin only)
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_block_slot() {
    tennis_pro_ajax_auth_check();
    if ( ! current_user_can( 'tennis_manage' ) ) {
        wp_send_json_error( [ 'message' => __( 'Keine Berechtigung.', 'tennis-pro' ) ], 403 );
    }
    global $wpdb;

    $court_id = (int) ( $_POST['court_id'] ?? 0 );  // 0 = all
    $date     = tennis_pro_validate_date( sanitize_text_field( wp_unslash( $_POST['date']     ?? '' ) ) );
    $timeslot = sanitize_text_field( wp_unslash( $_POST['timeslot'] ?? '' ) );  // '' = all day
    $duration = max( 1, min( 8, (int) ( $_POST['duration'] ?? 1 ) ) );
    $reason   = sanitize_text_field( wp_unslash( $_POST['reason']   ?? '' ) );

    if ( ! $date ) {
        wp_send_json_error( [ 'message' => __( 'Ungültiges Datum.', 'tennis-pro' ) ], 400 );
    }
    if ( $timeslot !== '' && ! tennis_pro_validate_timeslot( $timeslot ) ) {
        wp_send_json_error( [ 'message' => __( 'Ungültige Uhrzeit.', 'tennis-pro' ) ], 400 );
    }

    $wpdb->insert(
        $wpdb->prefix . 'tennis_blocked_slots',
        [
            'court_id'   => $court_id,
            'date'       => $date,
            'timeslot'   => $timeslot,
            'duration'   => $duration,
            'reason'     => $reason,
            'created_by' => get_current_user_id(),
        ],
        [ '%d', '%s', '%s', '%d', '%s', '%d' ]
    );

    wp_send_json_success( [ 'id' => $wpdb->insert_id ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   UNBLOCK SLOT (admin only)
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_unblock_slot() {
    tennis_pro_ajax_auth_check();
    if ( ! current_user_can( 'tennis_manage' ) ) {
        wp_send_json_error( [ 'message' => __( 'Keine Berechtigung.', 'tennis-pro' ) ], 403 );
    }
    global $wpdb;

    $id = (int) ( $_POST['id'] ?? 0 );
    $wpdb->delete( $wpdb->prefix . 'tennis_blocked_slots', [ 'id' => $id ], [ '%d' ] );
    wp_send_json_success( [ 'id' => $id ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   UPDATE USER E-MAIL
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_update_email(): void {
    tennis_pro_ajax_auth_check();

    $new_email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    if ( ! is_email( $new_email ) ) {
        wp_send_json_error( [ 'message' => __( 'Ungültige E-Mail-Adresse.', 'tennis-pro' ) ], 400 );
    }

    $user_id = get_current_user_id();

    // Reject if another user already owns this e-mail
    $existing = email_exists( $new_email );
    if ( $existing && (int) $existing !== $user_id ) {
        wp_send_json_error( [ 'message' => __( 'Diese E-Mail-Adresse ist bereits vergeben.', 'tennis-pro' ) ], 409 );
    }

    $result = wp_update_user( [ 'ID' => $user_id, 'user_email' => $new_email ] );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
    }

    wp_send_json_success( [
        'message' => __( 'E-Mail-Adresse erfolgreich gespeichert.', 'tennis-pro' ),
        'email'   => $new_email,
    ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   CANCEL RECURRING SERIES (overview + frontend)
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_cancel_recurring() {
    tennis_pro_ajax_auth_check();
    global $wpdb;

    $recurring_id = (int) ( $_POST['recurring_id'] ?? 0 );
    if ( ! $recurring_id ) {
        wp_send_json_error( [ 'message' => __( 'Ungültige Serien-ID.', 'tennis-pro' ) ], 400 );
    }

    $group = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}tennis_recurring_groups WHERE id=%d", $recurring_id
    ) );
    if ( ! $group ) {
        wp_send_json_error( [ 'message' => __( 'Serie nicht gefunden.', 'tennis-pro' ) ], 404 );
    }
    if ( ! current_user_can( 'tennis_manage' ) && (int) $group->user_id !== get_current_user_id() ) {
        wp_send_json_error( [ 'message' => __( 'Keine Berechtigung.', 'tennis-pro' ) ], 403 );
    }

    $future_bookings = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}tennis_bookings WHERE recurring_id=%d AND date >= %s",
        $recurring_id, gmdate( 'Y-m-d' )
    ) );
    $court_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}tennis_courts WHERE id=%d", (int) $group->court_id
    ) );

    foreach ( $future_bookings as $b ) {
        tennis_pro_mail_booking_cancelled( $b, $court_row->name ?? '' );
        $wpdb->delete( $wpdb->prefix . 'tennis_bookings', [ 'id' => (int) $b->id ], [ '%d' ] );
    }

    // Remove the recurring group record itself – all bookings are gone.
    $wpdb->delete( $wpdb->prefix . 'tennis_recurring_groups', [ 'id' => $recurring_id ], [ '%d' ] );

    wp_send_json_success( [ 'cancelled' => count( $future_bookings ) ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   MY CALENDAR DIAGNOSTIC (admin-only)
══════════════════════════════════════════════════════════════════════════ */
function tennis_pro_ajax_test_mycal(): void {
    if ( ! current_user_can( 'tennis_manage' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }
    if ( ! check_ajax_referer( 'tennis_test_mycal', 'nonce', false ) ) {
        wp_send_json_error( 'Ungültige Anfrage (Nonce).', 403 );
    }

    global $wpdb;
    $lines = [];

    // ── Table existence ──
    $tbl_events = $wpdb->prefix . 'my_calendar';
    $tbl_cats   = $wpdb->prefix . 'my_calendar_categories';
    $tbl_dates  = $wpdb->prefix . 'my_calendar_dates';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery
    $has_events = ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl_events}'" ) === $tbl_events );
    $has_cats   = ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl_cats}'"   ) === $tbl_cats   );
    $has_dates  = ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl_dates}'"  ) === $tbl_dates  );
    // phpcs:enable

    $lines[] = '── Tabellen ──────────────────────────────────';
    $lines[] = ( $has_events ? '✔' : '✘' ) . ' ' . $tbl_events;
    $lines[] = ( $has_cats   ? '✔' : '✘' ) . ' ' . $tbl_cats;
    $lines[] = ( $has_dates  ? '✔' : '✘' ) . ' ' . $tbl_dates . ( $has_dates ? ' (My Calendar 3.x)' : ' (nicht vorhanden → 2.x-Modus)' );

    if ( ! $has_events ) {
        wp_send_json_success( implode( "\n", $lines ) );
    }

    // ── Saved settings ──
    $s        = tennis_pro_get_settings();
    $cat_ids  = array_filter( array_map( 'intval', explode( ',', $s['mycal_categories'] ?? '' ) ) );
    $horizon  = max( 1, (int) ( $s['mycal_horizon'] ?? 30 ) );
    $enabled  = (int) $s['mycal_enabled'];

    $lines[] = '';
    $lines[] = '── Einstellungen ─────────────────────────────';
    $lines[] = 'Integration: ' . ( $enabled ? 'aktiviert' : 'DEAKTIVIERT' );
    $lines[] = 'Kategorien (IDs): ' . ( $cat_ids ? implode( ', ', $cat_ids ) : '— keine ausgewählt —' );
    $lines[] = 'Zeitraum: ' . $horizon . ' Tage';

    // ── Category names ──
    if ( $has_cats ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $all_cats = (array) $wpdb->get_results( "SELECT category_id, category_name, category_color FROM {$tbl_cats} ORDER BY category_name" );
        $lines[] = '';
        $lines[] = '── Alle My-Calendar-Kategorien ───────────────';
        if ( empty( $all_cats ) ) {
            $lines[] = '  (keine Kategorien vorhanden)';
        } else {
            foreach ( $all_cats as $c ) {
                $is_sel = in_array( (int) $c->category_id, $cat_ids, true );
                $lines[] = ( $is_sel ? '☑' : '☐' ) . ' ID ' . $c->category_id . ': ' . $c->category_name . '  (Farbe: ' . ( $c->category_color ?: 'n/a' ) . ')';
            }
        }
    }

    if ( empty( $cat_ids ) ) {
        $lines[] = '';
        $lines[] = '⚠ Keine Kategorien ausgewählt — bitte Kategorien ankreuzen und Einstellungen speichern.';
        wp_send_json_success( implode( "\n", $lines ) );
    }

    // ── Event query ──
    $in_sql   = implode( ',', $cat_ids );
    $today    = gmdate( 'Y-m-d' );
    $end_date = gmdate( 'Y-m-d', strtotime( "+{$horizon} days" ) );

    $lines[] = '';
    $lines[] = '── Abfrage ───────────────────────────────────';
    $lines[] = 'Zeitraum: ' . $today . ' bis ' . $end_date;

    if ( $has_dates ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $events = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT e.event_title, DATE(d.occur_begin) AS event_date,
                    TIME(d.occur_begin) AS event_time, TIME(d.occur_end) AS event_endtime,
                    e.event_approved, e.event_category,
                    c.category_name
               FROM {$tbl_dates} d
               JOIN {$tbl_events} e ON e.event_id = d.event_id
          LEFT JOIN {$tbl_cats}  c ON c.category_id = e.event_category
              WHERE e.event_category IN ({$in_sql})
                AND DATE(d.occur_begin) BETWEEN %s AND %s
              ORDER BY d.occur_begin
              LIMIT 50",
            $today, $end_date
        ) );
        $lines[] = 'Modus: 3.x (wp_my_calendar_dates)';
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $events = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT e.event_title, DATE(e.event_begin) AS event_date,
                    e.event_time, e.event_endtime,
                    e.event_approved, e.event_category,
                    c.category_name
               FROM {$tbl_events} e
          LEFT JOIN {$tbl_cats}  c ON c.category_id = e.event_category
              WHERE e.event_category IN ({$in_sql})
                AND DATE(e.event_begin) <= %s
                AND DATE(e.event_end)   >= %s
              ORDER BY e.event_begin, e.event_time
              LIMIT 50",
            $end_date, $today
        ) );
        $lines[] = 'Modus: 2.x (direkte event_begin/event_end)';
    }
    // phpcs:enable

    $lines[] = 'Gefundene Termine (inkl. nicht genehmigter): ' . count( $events );

    if ( empty( $events ) ) {
        $lines[] = '';
        $lines[] = '⚠ Keine Termine gefunden. Mögliche Ursachen:';
        $lines[] = '  • Die gewählten Kategorie-IDs haben keine Termine in diesem Zeitraum';
        $lines[] = '  • Die Termine liegen außerhalb des Horizonts';
        $lines[] = '  • event_category-Feld stimmt nicht mit Kategorie-IDs überein';
    } else {
        $lines[] = '';
        $lines[] = '── Termine ───────────────────────────────────';
        $approved_count = 0;
        foreach ( $events as $ev ) {
            $approved = (int) $ev->event_approved;
            $flag     = $approved === 1 ? '✔' : ( $approved === 0 ? '✘ (nicht genehmigt)' : '? (approved=' . $approved . ')' );
            if ( $approved === 1 ) $approved_count++;
            $lines[] = $flag . ' ' . $ev->event_date . ' ' . substr( $ev->event_time, 0, 5 ) . '–' . substr( $ev->event_endtime, 0, 5 )
                       . '  [Kat ' . $ev->event_category . ': ' . ( $ev->category_name ?: '?' ) . ']'
                       . '  ' . $ev->event_title;
        }
        $lines[] = '';
        $lines[] = 'Davon event_approved=1: ' . $approved_count . ' / ' . count( $events );
        if ( $approved_count === 0 ) {
            $lines[] = '⚠ Alle Termine haben event_approved ≠ 1 → werden von der Abfrage gefiltert!';
            $lines[] = '  Tipp: In My Calendar → Termine prüfen und genehmigen.';
        }
    }

    wp_send_json_success( implode( "\n", $lines ) );
}
