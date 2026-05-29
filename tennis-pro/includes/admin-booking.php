<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'tennis_pro_booking_page_menu', 20 );
add_action( 'admin_enqueue_scripts', 'tennis_pro_enqueue_admin_booking_scripts' );

function tennis_pro_booking_page_menu() {
    add_submenu_page(
        'tennis-pro',
        __( 'Buchung anlegen', 'tennis-pro' ),
        __( 'Buchung anlegen', 'tennis-pro' ),
        'tennis_manage',
        'tennis-pro-booking',
        'tennis_pro_admin_booking_page'
    );
}

function tennis_pro_enqueue_admin_booking_scripts( string $hook ): void {
    if ( $hook !== 'tennis_page_tennis-pro-booking' ) return;
    wp_enqueue_script(
        'tennis-pro-admin-booking',
        TENNIS_PRO_URL . 'assets/admin-booking.js',
        [],
        TENNIS_PRO_VER,
        true
    );
    wp_localize_script( 'tennis-pro-admin-booking', 'TennisAdminBooking', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'tennis_frontend_nonce' ),
        'i18n'    => [
            'confirmCancel'  => __( 'Serie wirklich stornieren? Alle zukünftigen Buchungen dieser Serie werden gelöscht.', 'tennis-pro' ),
            'creating'       => __( 'Buchungen werden angelegt…', 'tennis-pro' ),
            'success'        => __( 'Buchungen erfolgreich angelegt.', 'tennis-pro' ),
            'error'          => __( 'Fehler beim Anlegen der Buchungen.', 'tennis-pro' ),
        ],
    ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   PAGE
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_admin_booking_page() {
    if ( ! current_user_can( 'tennis_manage' ) ) wp_die( __( 'Zugriff verweigert.', 'tennis-pro' ) );
    global $wpdb;

    $notice   = '';
    $courts   = tennis_pro_get_courts();
    $cats     = tennis_pro_get_categories();
    $trainers = tennis_pro_get_trainers();

    /* ── Handle unblock (remove blocked slot) ── */
    if (
        isset( $_GET['unblock_id'] ) &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tennis_unblock_' . (int) $_GET['unblock_id'] )
    ) {
        $del_id = (int) $_GET['unblock_id'];
        $wpdb->delete( $wpdb->prefix . 'tennis_blocked_slots', [ 'id' => $del_id ], [ '%d' ] );
        $notice = __( 'Sperrung aufgehoben.', 'tennis-pro' );
    }

    /* ── Handle form submit ── */
    if (
        isset( $_POST['tennis_admin_booking_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tennis_admin_booking_nonce'] ) ), 'tennis_admin_booking_save' )
    ) {
        $court_ids    = array_map( 'intval', (array) ( $_POST['court_ids']  ?? [] ) );
        $date         = tennis_pro_validate_date( sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) ) );
        $timeslot     = sanitize_text_field( wp_unslash( $_POST['timeslot']   ?? '' ) );
        $cat_id       = (int) ( $_POST['category_id'] ?? 0 );
        $name         = sanitize_text_field( wp_unslash( $_POST['player_name'] ?? '' ) );
        $recurring    = ! empty( $_POST['recurring'] );
        $rec_pattern  = in_array( $_POST['rec_pattern'] ?? '', [ 'daily', 'weekly' ], true )
            ? sanitize_text_field( wp_unslash( $_POST['rec_pattern'] ) ) : 'weekly';
        $rec_day      = (int) ( $_POST['rec_day_of_week'] ?? (int) gmdate( 'w', strtotime( $date ) ) );
        $rec_end      = tennis_pro_validate_date( sanitize_text_field( wp_unslash( $_POST['rec_end_date'] ?? $date ) ) );

        // Trainer (admin-only)
        $trainer_id   = 0;
        $raw_trainer  = (int) ( $_POST['trainer_id'] ?? 0 );
        if ( $raw_trainer > 0 ) {
            $tr = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tennis_trainers WHERE id=%d", $raw_trainer ) );
            if ( $tr ) $trainer_id = (int) $tr->id;
        }

        // Block mode
        $block_mode   = ! empty( $_POST['block_mode'] );
        $all_day      = $block_mode && ! empty( $_POST['all_day'] );
        $block_reason = sanitize_text_field( wp_unslash( $_POST['block_reason'] ?? '' ) );

        // Duration cap: blocks are unlimited (up to 48 slots = 24 h), regular bookings max 8
        $max_dur  = $block_mode ? 48 : 8;
        $duration = max( 1, min( $max_dur, (int) ( $_POST['duration'] ?? 1 ) ) );

        $created        = 0;
        $skipped        = 0;
        $errors         = [];
        $skipped_detail = [];    // human-readable list of skipped slots
        $user_id        = get_current_user_id();

        // Build court-name map for readable conflict messages
        $court_name_map = [];
        foreach ( $courts as $c ) {
            $court_name_map[ (int) $c->id ] = $c->name;
        }

        if ( empty( $court_ids ) ) {
            $errors[] = __( 'Bitte mindestens einen Platz auswählen.', 'tennis-pro' );
        } elseif ( ! $all_day && ! tennis_pro_validate_timeslot( $timeslot ) ) {
            $errors[] = __( 'Ungültige Uhrzeit.', 'tennis-pro' );
        } else {
            foreach ( $court_ids as $cid ) {
                $court_label = $court_name_map[ $cid ] ?? ( 'Platz ' . $cid );

                if ( $block_mode ) {
                    // Insert blocked slot(s) – optionally recurring
                    if ( $recurring && $rec_end >= $date ) {
                        $dates = tennis_pro_recurring_dates( $rec_pattern, $rec_day, $date, $rec_end );
                    } else {
                        $dates = [ $date ];
                    }
                    foreach ( $dates as $d ) {
                        $wpdb->insert( $wpdb->prefix . 'tennis_blocked_slots', [
                            'court_id'    => $cid,
                            'date'        => $d,
                            'timeslot'    => $all_day ? '' : $timeslot,
                            'duration'    => $all_day ? 1 : $duration,
                            'reason'      => $block_reason,
                            'category_id' => $cat_id,
                            'created_by'  => $user_id,
                        ], [ '%d', '%s', '%s', '%d', '%s', '%d', '%d' ] );
                        $created++;
                    }
                } else {
                    // Regular booking – optionally recurring
                    if ( $recurring && $rec_end >= $date ) {
                        $dates = tennis_pro_recurring_dates( $rec_pattern, $rec_day, $date, $rec_end );
                        // Create recurring group for this court
                        $wpdb->insert( $wpdb->prefix . 'tennis_recurring_groups', [
                            'pattern'     => $rec_pattern, 'day_of_week' => $rec_day,
                            'start_date'  => $date,        'end_date'    => $rec_end,
                            'court_id'    => $cid,         'timeslot'    => $timeslot,
                            'duration'    => $duration,    'user_id'     => $user_id,
                            'player_name' => $name,        'category_id' => $cat_id,
                            'trainer_id'  => $trainer_id,
                        ], [ '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d' ] );
                        $rec_id = (int) $wpdb->insert_id;
                    } else {
                        $dates  = [ $date ];
                        $rec_id = 0;
                    }

                    foreach ( $dates as $d ) {
                        if ( tennis_pro_booking_conflict( $cid, $d, $timeslot, $duration ) ||
                             tennis_pro_slot_is_blocked( $cid, $d, $timeslot, $duration ) ||
                             tennis_pro_slot_has_mycal_conflict( $cid, $d, $timeslot, $duration ) ) {
                            $skipped++;
                            $skipped_detail[] = date_i18n( 'j.n.Y', strtotime( $d ) )
                                . ' · ' . $timeslot . ' Uhr · ' . $court_label;
                            continue;
                        }
                        $ok = $wpdb->insert( $wpdb->prefix . 'tennis_bookings', [
                            'court_id'     => $cid,       'date'         => $d,
                            'timeslot'     => $timeslot,  'duration'     => $duration,
                            'user_id'      => $user_id,   'player_name'  => $name,
                            'category_id'  => $cat_id,    'recurring_id' => $rec_id,
                            'trainer_id'   => $trainer_id,
                        ], [ '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d' ] );
                        $ok ? $created++ : $skipped++;
                    }
                }
            }
        }

        if ( empty( $errors ) && empty( $skipped_detail ) ) {
            $notice = sprintf( __( '%d Einträge angelegt.', 'tennis-pro' ), $created );
        } else {
            $parts = [];
            if ( $created > 0 ) {
                $parts[] = sprintf( __( '%d angelegt', 'tennis-pro' ), $created );
            }
            if ( ! empty( $skipped_detail ) ) {
                $parts[] = sprintf( __( '%d übersprungen (belegt/gesperrt)', 'tennis-pro' ), count( $skipped_detail ) );
            }
            if ( ! empty( $errors ) ) {
                $parts[] = implode( ' · ', $errors );
            }
            $notice = implode( ' · ', $parts );
            if ( ! empty( $skipped_detail ) ) {
                $notice .= '<br><details style="margin-top:6px"><summary style="cursor:pointer;font-weight:600">'
                    . esc_html__( 'Übersprungene Termine anzeigen', 'tennis-pro' ) . '</summary>'
                    . '<ul style="margin:.5em 0 0 1.2em;list-style:disc">'
                    . implode( '', array_map( fn( $s ) => '<li>' . esc_html( $s ) . '</li>', $skipped_detail ) )
                    . '</ul></details>';
            }
        }
    }

    /* ── Recurring series list (only series with at least one future booking) ── */
    $series_list = $wpdb->get_results(
        "SELECT sub.*, t.name AS trainer_name, cat.name AS cat_name, cat.color AS cat_color, cat.text_color AS cat_text,
                (SELECT MIN(b2.date) FROM {$wpdb->prefix}tennis_bookings b2
                  WHERE b2.recurring_id = sub.id AND b2.date >= CURDATE()) AS next_date
           FROM (
               SELECT rg.*, c.name AS court_name,
                      (SELECT COUNT(*) FROM {$wpdb->prefix}tennis_bookings b
                        WHERE b.recurring_id = rg.id AND b.date >= CURDATE()) AS future_count
                 FROM {$wpdb->prefix}tennis_recurring_groups rg
            LEFT JOIN {$wpdb->prefix}tennis_courts c ON c.id = rg.court_id
           ) AS sub
      LEFT JOIN {$wpdb->prefix}tennis_trainers t   ON t.id   = sub.trainer_id
      LEFT JOIN {$wpdb->prefix}tennis_categories cat ON cat.id = sub.category_id
          WHERE sub.future_count > 0
          ORDER BY sub.court_name ASC, sub.timeslot ASC
          LIMIT 100"
    );

    /* ── Build court list for filter ── */
    $series_courts = [];
    foreach ( $series_list as $s ) {
        $cn = $s->court_name ?? '–';
        if ( ! in_array( $cn, $series_courts, true ) ) $series_courts[] = $cn;
    }

    /* ── Category map for series ── */
    $cat_map_admin = [];
    foreach ( tennis_pro_get_categories() as $c ) $cat_map_admin[ (int) $c->id ] = $c;

    /* ── Upcoming blocked slots ── */
    $blocked_list = $wpdb->get_results(
        "SELECT bs.*, c.name AS court_name, cat.name AS cat_name, cat.color AS cat_color, cat.text_color AS cat_text
           FROM {$wpdb->prefix}tennis_blocked_slots bs
      LEFT JOIN {$wpdb->prefix}tennis_courts c ON c.id = bs.court_id
      LEFT JOIN {$wpdb->prefix}tennis_categories cat ON cat.id = bs.category_id
          WHERE bs.date >= CURDATE()
          ORDER BY bs.date ASC, bs.timeslot ASC
          LIMIT 100"
    );

    $day_names = [
        0 => __( 'So', 'tennis-pro' ), 1 => __( 'Mo', 'tennis-pro' ), 2 => __( 'Di', 'tennis-pro' ),
        3 => __( 'Mi', 'tennis-pro' ), 4 => __( 'Do', 'tennis-pro' ), 5 => __( 'Fr', 'tennis-pro' ),
        6 => __( 'Sa', 'tennis-pro' ),
    ];

    $today = gmdate( 'Y-m-d' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Buchung anlegen', 'tennis-pro' ); ?></h1>

        <?php if ( $notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo wp_kses( $notice, [
                'small'    => [],
                'br'       => [],
                'details'  => [ 'style' => [] ],
                'summary'  => [ 'style' => [] ],
                'ul'       => [ 'style' => [] ],
                'li'       => [],
            ] ); ?></p></div>
        <?php endif; ?>

        <div style="max-width:560px">

        <!-- ── Booking form ── -->
            <h2><?php esc_html_e( 'Neue Buchung / Sperrung', 'tennis-pro' ); ?></h2>
            <form method="POST" id="tnp-admin-booking-form">
                <?php wp_nonce_field( 'tennis_admin_booking_save', 'tennis_admin_booking_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Plätze', 'tennis-pro' ); ?></th>
                        <td>
                            <?php foreach ( $courts as $court ) : ?>
                                <label style="display:block;margin-bottom:4px">
                                    <input type="checkbox" name="court_ids[]" value="<?php echo (int) $court->id; ?>" checked>
                                    <?php echo esc_html( $court->name ); ?>
                                </label>
                            <?php endforeach; ?>
                            <?php if ( empty( $courts ) ) : ?>
                                <em><?php esc_html_e( 'Keine Plätze angelegt.', 'tennis-pro' ); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Datum', 'tennis-pro' ); ?></th>
                        <td><input type="date" name="date" value="<?php echo esc_attr( $today ); ?>" required class="regular-text"></td>
                    </tr>
                    <tr id="tnp-timeslot-row">
                        <th><?php esc_html_e( 'Startzeit', 'tennis-pro' ); ?></th>
                        <td>
                            <select name="timeslot" class="regular-text" id="tnp-timeslot-select">
                                <?php for ( $h = 7; $h <= 22; $h++ ) : foreach ( ['00','30'] as $m ) : ?>
                                    <option value="<?php printf( '%02d:%s', $h, $m ); ?>">
                                        <?php printf( '%02d:%s', $h, $m ); ?> Uhr
                                    </option>
                                <?php endforeach; endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="tnp-duration-row">
                        <th><?php esc_html_e( 'Dauer', 'tennis-pro' ); ?></th>
                        <td>
                            <select name="duration" class="regular-text" id="tnp-duration-select">
                                <?php for ( $d = 1; $d <= 48; $d++ ) : ?>
                                    <option value="<?php echo $d; ?>"><?php echo esc_html( tennis_pro_duration_label( $d ) ); ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Kategorie', 'tennis-pro' ); ?></th>
                        <td>
                            <select name="category_id" class="regular-text">
                                <option value="0"><?php esc_html_e( '– keine –', 'tennis-pro' ); ?></option>
                                <?php foreach ( $cats as $cat ) : ?>
                                    <option value="<?php echo (int) $cat->id; ?>"><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php if ( ! empty( $trainers ) ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Trainer', 'tennis-pro' ); ?></th>
                        <td>
                            <select name="trainer_id" class="regular-text">
                                <option value="0"><?php esc_html_e( '– kein Trainer –', 'tennis-pro' ); ?></option>
                                <?php foreach ( $trainers as $trainer ) : ?>
                                    <option value="<?php echo (int) $trainer->id; ?>">
                                        <?php echo esc_html( $trainer->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e( 'Name / Kommentar', 'tennis-pro' ); ?></th>
                        <td><input type="text" name="player_name" class="regular-text" maxlength="100"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Sperrung', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="block_mode" value="1" id="tnp-block-mode">
                                <?php esc_html_e( 'Als Sperrung anlegen (kein Nutzer, nicht buchbar)', 'tennis-pro' ); ?>
                            </label>
                            <div id="tnp-block-options" style="margin-top:8px;display:none;padding:10px;background:#f8f8f8;border-radius:4px;border:1px solid #ddd">
                                <label style="display:block;margin-bottom:6px">
                                    <input type="checkbox" name="all_day" value="1" id="tnp-all-day">
                                    <strong><?php esc_html_e( 'Ganztägig (gesamten Tag sperren)', 'tennis-pro' ); ?></strong>
                                </label>
                                <label style="display:block">
                                    <?php esc_html_e( 'Grund:', 'tennis-pro' ); ?><br>
                                    <input type="text" name="block_reason" class="regular-text"
                                           placeholder="<?php esc_attr_e( 'z.B. Turnier, Wartung…', 'tennis-pro' ); ?>" style="margin-top:4px">
                                </label>
                            </div>
                        </td>
                    </tr>
                </table>

                <!-- ── Recurring section ── -->
                <h3 style="margin-top:20px"><?php esc_html_e( 'Wiederkehrend', 'tennis-pro' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Wiederkehrend', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="recurring" value="1" id="tnp-recurring-toggle">
                                <?php esc_html_e( 'Aktivieren', 'tennis-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <div id="tnp-recurring-options" style="display:none">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php esc_html_e( 'Muster', 'tennis-pro' ); ?></th>
                            <td>
                                <label><input type="radio" name="rec_pattern" value="weekly" checked> <?php esc_html_e( 'Wöchentlich', 'tennis-pro' ); ?></label>
                                &nbsp;
                                <label><input type="radio" name="rec_pattern" value="daily"> <?php esc_html_e( 'Täglich', 'tennis-pro' ); ?></label>
                            </td>
                        </tr>
                        <tr id="tnp-dow-row">
                            <th><?php esc_html_e( 'Wochentag', 'tennis-pro' ); ?></th>
                            <td>
                                <select name="rec_day_of_week" class="regular-text">
                                    <?php foreach ( $day_names as $num => $label ) : ?>
                                        <option value="<?php echo $num; ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Enddatum', 'tennis-pro' ); ?></th>
                            <td><input type="date" name="rec_end_date" class="regular-text"
                                       value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '+3 months' ) ) ); ?>"></td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( __( 'Buchungen anlegen', 'tennis-pro' ), 'primary', 'submit_booking' ); ?>
            </form>
        </div><!-- /booking form -->

        <!-- ═══════════════════════════════════════════════════════
             AKTIVE SERIEN  (full width)
        ════════════════════════════════════════════════════════ -->
        <div style="margin-top:36px">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px">
                <h2 style="margin:0"><?php esc_html_e( 'Aktive Serien', 'tennis-pro' ); ?>
                    <?php if ( ! empty( $series_list ) ) : ?>
                        <span style="font-size:13px;font-weight:400;color:#666;margin-left:6px">(<?php echo count( $series_list ); ?>)</span>
                    <?php endif; ?>
                </h2>

                <?php if ( count( $series_courts ) > 1 ) : ?>
                <div id="tnp-series-filter" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <span style="font-size:12px;color:#666;font-weight:600"><?php esc_html_e( 'Platz:', 'tennis-pro' ); ?></span>
                    <button type="button" class="button button-small tnp-court-pill tnp-pill-active" data-court="__all__">
                        <?php esc_html_e( 'Alle', 'tennis-pro' ); ?>
                    </button>
                    <?php foreach ( $series_courts as $cn ) : ?>
                        <button type="button" class="button button-small tnp-court-pill" data-court="<?php echo esc_attr( $cn ); ?>">
                            🎾 <?php echo esc_html( $cn ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( empty( $series_list ) ) : ?>
                <p style="color:#666"><?php esc_html_e( 'Keine aktiven wiederkehrenden Buchungen.', 'tennis-pro' ); ?></p>
            <?php else :
                // Group by court
                $series_by_court = [];
                foreach ( $series_list as $s ) {
                    $cn = $s->court_name ?? '–';
                    $series_by_court[ $cn ][] = $s;
                }
            ?>
            <div id="tnp-series-list">
            <?php foreach ( $series_by_court as $court_name => $court_series ) : ?>

            <div class="tnp-series-court-block" data-court="<?php echo esc_attr( $court_name ); ?>"
                 style="margin-bottom:24px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                    <h3 style="margin:0;padding:5px 14px;background:#1d4b1e;color:#fff;border-radius:20px;font-size:13px;font-weight:700;letter-spacing:.3px">
                        🎾 <?php echo esc_html( $court_name ); ?>
                        <span style="font-size:11px;opacity:.8;margin-left:4px">(<?php echo count( $court_series ); ?>)</span>
                    </h3>
                </div>
                <div style="overflow-x:auto;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1)">
                <table class="widefat" style="min-width:580px;border-radius:6px;overflow:hidden">
                    <thead>
                        <tr style="background:#f8f8f8">
                            <th style="width:120px;padding:8px 12px"><?php esc_html_e( 'Zeit', 'tennis-pro' ); ?></th>
                            <th style="width:110px;padding:8px 12px"><?php esc_html_e( 'Rhythmus', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px"><?php esc_html_e( 'Kategorie / Name', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px"><?php esc_html_e( 'Trainer', 'tennis-pro' ); ?></th>
                            <th style="width:90px;padding:8px 12px"><?php esc_html_e( 'Nächster Termin', 'tennis-pro' ); ?></th>
                            <th style="width:90px;padding:8px 12px"><?php esc_html_e( 'Läuft bis', 'tennis-pro' ); ?></th>
                            <th style="width:46px;padding:8px 12px;text-align:center">
                                <abbr title="<?php esc_attr_e( 'Noch ausstehende Termine', 'tennis-pro' ); ?>">📅</abbr>
                            </th>
                            <th style="width:100px;padding:8px 12px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $court_series as $si => $s ) :
                        $pattern_label = $s->pattern === 'daily'
                            ? __( 'Täglich', 'tennis-pro' )
                            : sprintf( __( 'Wöchentl. %s', 'tennis-pro' ), $day_names[ (int) $s->day_of_week ] ?? '' );
                        $end_minutes  = 0;
                        $parts = explode( ':', $s->timeslot );
                        if ( count( $parts ) === 2 ) {
                            $end_minutes = (int)$parts[0] * 60 + (int)$parts[1] + (int)$s->duration * 30;
                        }
                        $end_time = sprintf( '%02d:%02d', intdiv( $end_minutes, 60 ), $end_minutes % 60 );
                        $row_bg   = $si % 2 === 0 ? '#ffffff' : '#f9f9f9';
                    ?>
                        <tr style="background:<?php echo $row_bg; ?>;transition:background .12s"
                            onmouseover="this.style.background='#f0f7f0'"
                            onmouseout="this.style.background='<?php echo $row_bg; ?>'">
                            <td style="padding:8px 12px;font-weight:700;white-space:nowrap">
                                <?php echo esc_html( $s->timeslot . ' – ' . $end_time ); ?> Uhr<br>
                                <span style="font-size:11px;font-weight:400;color:#888"><?php echo esc_html( tennis_pro_duration_label( (int) $s->duration ) ); ?></span>
                            </td>
                            <td style="padding:8px 12px;font-size:13px;color:#444">
                                🔁 <?php echo esc_html( $pattern_label ); ?>
                            </td>
                            <td style="padding:8px 12px">
                                <?php if ( $s->cat_name ) : ?>
                                    <span style="display:inline-block;background:<?php echo esc_attr( $s->cat_color ?: '#e0e0e0' ); ?>;color:<?php echo esc_attr( $s->cat_text ?: '#333' ); ?>;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;margin-bottom:3px"><?php echo esc_html( $s->cat_name ); ?></span><br>
                                <?php endif; ?>
                                <?php if ( $s->player_name ) : ?>
                                    <span style="font-size:12px;color:#555"><?php echo esc_html( $s->player_name ); ?></span>
                                <?php else : ?>
                                    <em style="font-size:12px;color:#aaa">–</em>
                                <?php endif; ?>
                            </td>
                            <td style="padding:8px 12px;font-size:12px;color:#444">
                                <?php echo $s->trainer_name
                                    ? '👤 ' . esc_html( $s->trainer_name )
                                    : '<em style="color:#aaa">–</em>'; ?>
                            </td>
                            <td style="padding:8px 12px;font-size:12px;white-space:nowrap">
                                <?php echo $s->next_date
                                    ? esc_html( date_i18n( 'j.n.Y', strtotime( $s->next_date ) ) )
                                    : '<em style="color:#aaa">–</em>'; ?>
                            </td>
                            <td style="padding:8px 12px;font-size:12px;white-space:nowrap;color:#666">
                                <?php echo esc_html( date_i18n( 'j.n.Y', strtotime( $s->end_date ) ) ); ?>
                            </td>
                            <td style="padding:8px 12px;text-align:center">
                                <span style="display:inline-block;background:#e8f5e9;color:#2e7d32;border-radius:12px;padding:2px 8px;font-size:12px;font-weight:700"><?php echo (int) $s->future_count; ?></span>
                            </td>
                            <td style="padding:8px 12px">
                                <button type="button" class="button button-small tnp-cancel-series"
                                        data-id="<?php echo (int) $s->id; ?>"
                                        style="color:#b32d2e;border-color:#b32d2e">
                                    🗑 <?php esc_html_e( 'Stornieren', 'tennis-pro' ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div><!-- overflow wrap -->
            </div><!-- court block -->

            <?php endforeach; ?>
            </div><!-- #tnp-series-list -->
            <?php endif; ?>
        </div><!-- /aktive serien -->

        <!-- ═══════════════════════════════════════════════════════
             AKTIVE SPERRUNGEN  (full width)
        ════════════════════════════════════════════════════════ -->
        <div style="margin-top:32px">
            <h2 style="margin-bottom:12px"><?php esc_html_e( 'Aktive Sperrungen', 'tennis-pro' ); ?>
                <?php if ( ! empty( $blocked_list ) ) : ?>
                    <span style="font-size:13px;font-weight:400;color:#666;margin-left:6px">(<?php echo count( $blocked_list ); ?>)</span>
                <?php endif; ?>
            </h2>
            <?php if ( empty( $blocked_list ) ) : ?>
                <p style="color:#666"><?php esc_html_e( 'Keine bevorstehenden Sperrungen.', 'tennis-pro' ); ?></p>
            <?php else : ?>
                <div style="overflow-x:auto;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1)">
                <table class="widefat" style="min-width:580px">
                    <thead>
                        <tr style="background:#f8f8f8">
                            <th style="padding:8px 12px"><?php esc_html_e( 'Platz', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px"><?php esc_html_e( 'Datum', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px"><?php esc_html_e( 'Zeit', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px"><?php esc_html_e( 'Dauer', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px"><?php esc_html_e( 'Kategorie', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px"><?php esc_html_e( 'Grund', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $blocked_list as $bi => $blk ) :
                        $court_label = (int) $blk->court_id === 0
                            ? __( 'Alle Plätze', 'tennis-pro' )
                            : $blk->court_name;
                        $time_label = $blk->timeslot === ''
                            ? __( 'Ganzer Tag', 'tennis-pro' )
                            : $blk->timeslot . ' Uhr';
                        $unblock_url = wp_nonce_url(
                            add_query_arg( [ 'page' => 'tennis-pro-booking', 'unblock_id' => (int) $blk->id ], admin_url( 'admin.php' ) ),
                            'tennis_unblock_' . (int) $blk->id
                        );
                        $blk_row_bg = $bi % 2 === 0 ? '#ffffff' : '#f9f9f9';
                    ?>
                        <tr style="background:<?php echo $blk_row_bg; ?>"
                            onmouseover="this.style.background='#fff8e1'"
                            onmouseout="this.style.background='<?php echo $blk_row_bg; ?>'">
                            <td style="padding:8px 12px;font-weight:600"><?php echo esc_html( $court_label ); ?></td>
                            <td style="padding:8px 12px;white-space:nowrap"><?php echo esc_html( date_i18n( 'j.n.Y', strtotime( $blk->date ) ) ); ?></td>
                            <td style="padding:8px 12px;white-space:nowrap"><?php echo esc_html( $time_label ); ?></td>
                            <td style="padding:8px 12px;font-size:12px;color:#666"><?php echo $blk->timeslot === '' ? '–' : esc_html( tennis_pro_duration_label( (int) $blk->duration ) ); ?></td>
                            <td style="padding:8px 12px">
                                <?php if ( $blk->cat_name ) :
                                    echo '<span style="background:' . esc_attr( $blk->cat_color ) . ';color:' . esc_attr( $blk->cat_text ) . ';padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700">' . esc_html( $blk->cat_name ) . '</span>';
                                else :
                                    echo '<em style="color:#aaa;font-size:12px">–</em>';
                                endif; ?>
                            </td>
                            <td style="padding:8px 12px;font-size:13px;color:#555"><?php echo $blk->reason ? esc_html( $blk->reason ) : '<em style="color:#aaa">–</em>'; ?></td>
                            <td style="padding:8px 12px">
                                <a href="<?php echo esc_url( $unblock_url ); ?>"
                                   class="button button-small"
                                   onclick="return confirm('<?php esc_attr_e( 'Sperrung wirklich aufheben?', 'tennis-pro' ); ?>')"
                                   style="color:#b32d2e;border-color:#b32d2e">
                                    🔓 <?php esc_html_e( 'Aufheben', 'tennis-pro' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div><!-- /aktive sperrungen -->

    </div><!-- .wrap -->

    <script>
    (function() {
        // Cache all 48 duration options on first load (value → label)
        const durSel = document.getElementById('tnp-duration-select');
        const ALL_DUR = durSel
            ? Array.from(durSel.options).map(function(o) { return {v: o.value, t: o.text}; })
            : [];

        function rebuildDuration(blockMode) {
            if (!durSel || !ALL_DUR.length) return;
            const prev = parseInt(durSel.value) || 1;
            const max  = blockMode ? 48 : 8;
            durSel.innerHTML = '';
            ALL_DUR.filter(function(o) { return parseInt(o.v) <= max; })
                   .forEach(function(o) { durSel.add(new Option(o.t, o.v)); });
            durSel.value = String(Math.min(prev, max));
            if (!durSel.value || durSel.selectedIndex < 0) durSel.selectedIndex = 0;
        }

        function tnpUpdateBlockMode() {
            const blockMode = document.getElementById('tnp-block-mode')?.checked;
            const allDay    = document.getElementById('tnp-all-day')?.checked;

            // Show/hide block options panel
            const blockOpts = document.getElementById('tnp-block-options');
            if (blockOpts) blockOpts.style.display = blockMode ? 'block' : 'none';

            // Show/hide timeslot + duration rows when all-day
            const tsRow  = document.getElementById('tnp-timeslot-row');
            const durRow = document.getElementById('tnp-duration-row');
            const hideSlot = blockMode && allDay;
            if (tsRow)  tsRow.style.display  = hideSlot ? 'none' : '';
            if (durRow) durRow.style.display = hideSlot ? 'none' : '';

            // Rebuild duration select options (block = 1-48, booking = 1-8)
            rebuildDuration(blockMode);
        }

        document.getElementById('tnp-block-mode')?.addEventListener('change', tnpUpdateBlockMode);
        document.getElementById('tnp-all-day')?.addEventListener('change', tnpUpdateBlockMode);

        // Toggle recurring options
        document.getElementById('tnp-recurring-toggle')?.addEventListener('change', function() {
            document.getElementById('tnp-recurring-options').style.display = this.checked ? 'block' : 'none';
        });

        // Toggle day-of-week row
        document.querySelectorAll('input[name="rec_pattern"]').forEach(function(r) {
            r.addEventListener('change', function() {
                document.getElementById('tnp-dow-row').style.display =
                    this.value === 'weekly' ? '' : 'none';
            });
        });

        // Init on load
        tnpUpdateBlockMode();

        // ── Court filter pills for Aktive Serien ──────────────────────
        const pills  = document.querySelectorAll('.tnp-court-pill');
        const blocks = document.querySelectorAll('.tnp-series-court-block');

        pills.forEach(function(pill) {
            pill.addEventListener('click', function() {
                const target = this.dataset.court;

                // Update active state
                pills.forEach(function(p) {
                    p.classList.remove('tnp-pill-active');
                    p.style.background = '';
                    p.style.color      = '';
                    p.style.borderColor= '';
                });
                this.classList.add('tnp-pill-active');
                this.style.background  = '#1d4b1e';
                this.style.color       = '#fff';
                this.style.borderColor = '#1d4b1e';

                // Show/hide court blocks
                blocks.forEach(function(block) {
                    if (target === '__all__' || block.dataset.court === target) {
                        block.style.display = '';
                    } else {
                        block.style.display = 'none';
                    }
                });
            });
        });

        // Style first pill (Alle) as active on init
        const firstPill = document.querySelector('.tnp-pill-active');
        if (firstPill) {
            firstPill.style.background  = '#1d4b1e';
            firstPill.style.color       = '#fff';
            firstPill.style.borderColor = '#1d4b1e';
        }
    })();
    </script>
    <?php
}
