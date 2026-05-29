<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'tennis_pro_export_menu', 25 );

function tennis_pro_export_menu() {
    add_submenu_page(
        'tennis-pro',
        __( 'Export / Import', 'tennis-pro' ),
        __( 'Export / Import', 'tennis-pro' ),
        'tennis_manage',
        'tennis-pro-export',
        'tennis_pro_export_page'
    );
}

/* ══════════════════════════════════════════════════════════════════════════
   ADMIN PAGE
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_export_page() {
    if ( ! current_user_can( 'tennis_manage' ) ) wp_die( __( 'Zugriff verweigert.', 'tennis-pro' ) );

    $notice = '';

    /* ── Handle CSV export ── */
    if ( isset( $_POST['export_csv'] ) && check_admin_referer( 'tennis_export_action', 'tennis_export_nonce' ) ) {
        // Clear any HTML already buffered by WordPress admin before sending file headers.
        while ( ob_get_level() ) { ob_end_clean(); }
        tennis_pro_output_csv_export(
            sanitize_text_field( wp_unslash( $_POST['export_from'] ?? '' ) ),
            sanitize_text_field( wp_unslash( $_POST['export_to']   ?? '' ) )
        );
        exit;
    }

    /* ── Handle iCal export ── */
    if ( isset( $_POST['export_ical'] ) && check_admin_referer( 'tennis_export_action', 'tennis_export_nonce' ) ) {
        while ( ob_get_level() ) { ob_end_clean(); }
        tennis_pro_output_ical_export(
            sanitize_text_field( wp_unslash( $_POST['export_from'] ?? '' ) ),
            sanitize_text_field( wp_unslash( $_POST['export_to']   ?? '' ) )
        );
        exit;
    }

    /* ── Handle CSV import ── */
    if ( isset( $_POST['import_csv'] ) && check_admin_referer( 'tennis_export_action', 'tennis_export_nonce' ) ) {
        $tmp_path = $_FILES['import_file']['tmp_name'] ?? '';
        if ( $tmp_path !== '' && is_uploaded_file( $tmp_path ) ) {
            $result = tennis_pro_process_csv_import( $tmp_path );
            $notice = sprintf(
                /* translators: 1=imported, 2=skipped */
                __( 'Import abgeschlossen: %1$d Buchungen importiert, %2$d übersprungen.', 'tennis-pro' ),
                $result['imported'],
                $result['skipped']
            );
            if ( ! empty( $result['errors'] ) ) {
                $notice .= '<br><small>' . esc_html( implode( ' | ', array_slice( $result['errors'], 0, 5 ) ) ) . '</small>';
            }
        }
    }

    $today  = gmdate( 'Y-m-d' );
    $in30   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Tennis Pro – Export / Import', 'tennis-pro' ); ?></h1>

        <?php if ( $notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo wp_kses( $notice, [ 'br' => [], 'small' => [] ] ); ?></p></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;max-width:900px">

            <!-- CSV / iCal Export -->
            <div>
                <h2><?php esc_html_e( 'Export', 'tennis-pro' ); ?></h2>
                <form method="POST">
                    <?php wp_nonce_field( 'tennis_export_action', 'tennis_export_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php esc_html_e( 'Von', 'tennis-pro' ); ?></th>
                            <td><input type="date" name="export_from" value="<?php echo esc_attr( $today ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Bis', 'tennis-pro' ); ?></th>
                            <td><input type="date" name="export_to" value="<?php echo esc_attr( $in30 ); ?>" class="regular-text"></td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" name="export_csv"  class="button button-primary">⬇ CSV</button>
                        <button type="submit" name="export_ical" class="button button-secondary" style="margin-left:8px">⬇ iCal (.ics)</button>
                    </p>
                </form>
            </div>

            <!-- CSV Import -->
            <div>
                <h2><?php esc_html_e( 'CSV-Import', 'tennis-pro' ); ?></h2>
                <p><?php esc_html_e( 'CSV-Format (erste Zeile = Header):', 'tennis-pro' ); ?></p>
                <code style="display:block;background:#f5f5f5;padding:6px 10px;font-size:11px">
                    court_name, date, timeslot, duration, player_name, category_name, trainer_name
                </code>
                <form method="POST" enctype="multipart/form-data" style="margin-top:12px">
                    <?php wp_nonce_field( 'tennis_export_action', 'tennis_export_nonce' ); ?>
                    <input type="file" name="import_file" accept=".csv" required style="margin-bottom:8px;display:block">
                    <button type="submit" name="import_csv" class="button button-primary">⬆ Importieren</button>
                </form>
            </div>

        </div>
    </div>
    <?php
}

/* ══════════════════════════════════════════════════════════════════════════
   CSV EXPORT
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_output_csv_export( string $from, string $to ): void {
    $from = tennis_pro_validate_date( $from ) ?: gmdate( 'Y-m-d' );
    $to   = tennis_pro_validate_date( $to )   ?: gmdate( 'Y-m-d', strtotime( '+30 days' ) );

    $bookings  = tennis_pro_get_bookings_for_range( $from, $to );
    $cats      = tennis_pro_cat_map( tennis_pro_get_categories() );
    $court_map = [];
    foreach ( tennis_pro_get_courts() as $c ) {
        $court_map[ (int) $c->id ] = $c;
    }

    $filename = 'tennis-buchungen-' . $from . '-bis-' . $to . '.csv';
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, [
        'id', 'court_name', 'date', 'timeslot', 'end_time',
        'duration', 'duration_label', 'player_name', 'display_name',
        'category_name', 'trainer_name', 'recurring_id', 'created_at',
    ], ';' );

    foreach ( $bookings as $b ) {
        $court_name  = isset( $court_map[ (int) $b->court_id ] ) ? $court_map[ (int) $b->court_id ]->name : '';
        $cat_name    = isset( $cats[ (int) $b->category_id ] )   ? $cats[ (int) $b->category_id ]->name   : '';
        $end_min     = tennis_pro_slot_to_minutes( $b->timeslot ) + (int) $b->duration * 30;
        $end_time    = tennis_pro_minutes_to_slot( $end_min );
        fputcsv( $out, [
            $b->id,
            $court_name,
            $b->date,
            $b->timeslot,
            $end_time,
            $b->duration,
            tennis_pro_duration_label( (int) $b->duration ),
            $b->player_name,
            $b->display_name    ?? '',
            $cat_name,
            $b->trainer_name    ?? '',
            $b->recurring_id    ?? '',
            $b->created_at,
        ], ';' );
    }
    fclose( $out );
}

/* ══════════════════════════════════════════════════════════════════════════
   ICAL HELPERS
══════════════════════════════════════════════════════════════════════════ */

/** Format minutes as HHMMSS for iCal. */
function tennis_pro_ical_time( int $minutes ): string {
    $h = (int) ( $minutes / 60 );
    $m = $minutes % 60;
    return sprintf( '%02d%02d00', $h, $m );
}

/** Escape a string value for iCal (RFC 5545). */
function tennis_pro_ical_escape( string $s ): string {
    return str_replace( [ '\\', ';', ',', "\n" ], [ '\\\\', '\\;', '\\,', '\\n' ], $s );
}

/**
 * Convert a local date + slot time to a UTC iCal timestamp string (e.g. 20260527T080000Z).
 * Using UTC avoids the VTIMEZONE requirement and works with Outlook, iOS, Google Calendar.
 */
function tennis_pro_ical_utc_stamp( string $date, int $minutes ): string {
    $h   = intdiv( $minutes, 60 );
    $m   = $minutes % 60;
    $tz  = wp_timezone();
    $dt  = new DateTimeImmutable( $date . ' ' . sprintf( '%02d:%02d:00', $h, $m ), $tz );
    return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
}

/**
 * Build a complete iCal string for a single booking object.
 * Used for both email attachment and export.
 */
function tennis_pro_build_ical_for_booking( object $b, string $court_name, string $cat_name = '' ): string {
    $start_min = tennis_pro_slot_to_minutes( $b->timeslot );
    $end_min   = $start_min + (int) $b->duration * 30;
    $start_utc = tennis_pro_ical_utc_stamp( $b->date, $start_min );
    $end_utc   = tennis_pro_ical_utc_stamp( $b->date, $end_min );

    $uid     = 'tennis-pro-' . $b->id . '@' . ( parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' );
    $stamp   = gmdate( 'Ymd\THis\Z' );
    $summary = $court_name;
    if ( $b->player_name ) $summary .= ' – ' . $b->player_name;
    if ( $cat_name )        $summary .= ' (' . $cat_name . ')';

    $desc = $court_name . ' · ' . date_i18n( 'l, j. F Y', strtotime( $b->date ) )
          . ' · ' . $b->timeslot . ' – ' . tennis_pro_minutes_to_slot( $end_min ) . ' Uhr';

    $site    = get_bloginfo( 'name' );
    $prod_id = '-//' . $site . '//Tennis Pro//DE';

    $out  = "BEGIN:VCALENDAR\r\n";
    $out .= "VERSION:2.0\r\n";
    $out .= "PRODID:{$prod_id}\r\n";
    $out .= "CALSCALE:GREGORIAN\r\n";
    $out .= "METHOD:REQUEST\r\n";
    $out .= "BEGIN:VEVENT\r\n";
    $out .= "UID:{$uid}\r\n";
    $out .= "DTSTAMP:{$stamp}\r\n";
    $out .= "DTSTART:{$start_utc}\r\n";
    $out .= "DTEND:{$end_utc}\r\n";
    $out .= 'SUMMARY:' . tennis_pro_ical_escape( $summary ) . "\r\n";
    $out .= 'LOCATION:' . tennis_pro_ical_escape( $court_name ) . "\r\n";
    $out .= 'DESCRIPTION:' . tennis_pro_ical_escape( $desc ) . "\r\n";
    $out .= "END:VEVENT\r\n";
    $out .= "END:VCALENDAR\r\n";
    return $out;
}

/* ══════════════════════════════════════════════════════════════════════════
   ICAL EXPORT (Admin bulk export)
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_output_ical_export( string $from, string $to ): void {
    $from     = tennis_pro_validate_date( $from ) ?: gmdate( 'Y-m-d' );
    $to       = tennis_pro_validate_date( $to )   ?: gmdate( 'Y-m-d', strtotime( '+30 days' ) );
    $bookings = tennis_pro_get_bookings_for_range( $from, $to );

    $court_map = [];
    foreach ( tennis_pro_get_courts() as $c ) {
        $court_map[ (int) $c->id ] = $c;
    }
    $cat_map = tennis_pro_cat_map( tennis_pro_get_categories() );

    $filename = 'tennis-' . $from . '-bis-' . $to . '.ics';
    header( 'Content-Type: text/calendar; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );

    $site    = get_bloginfo( 'name' );
    $prod_id = '-//' . $site . '//Tennis Pro//DE';

    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:{$prod_id}\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "METHOD:PUBLISH\r\n";
    echo 'X-WR-CALNAME:' . tennis_pro_ical_escape( $site . ' – Buchungen' ) . "\r\n";

    foreach ( $bookings as $b ) {
        $court_name = isset( $court_map[ (int) $b->court_id ] ) ? $court_map[ (int) $b->court_id ]->name : 'Platz';
        $cat_name   = isset( $cat_map[ (int) $b->category_id ] ) ? $cat_map[ (int) $b->category_id ]->name : '';
        $start_min  = tennis_pro_slot_to_minutes( $b->timeslot );
        $end_min    = $start_min + (int) $b->duration * 30;
        $start_utc  = tennis_pro_ical_utc_stamp( $b->date, $start_min );
        $end_utc    = tennis_pro_ical_utc_stamp( $b->date, $end_min );
        $summary    = $court_name . ( $b->player_name ? ' – ' . $b->player_name : '' );
        $uid        = 'tennis-pro-' . $b->id . '@' . ( parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' );
        $stamp      = gmdate( 'Ymd\THis\Z', $b->created_at ? (int) strtotime( $b->created_at ) : time() );
        $desc       = $court_name . ' · ' . date_i18n( 'l, j. F Y', strtotime( $b->date ) )
                    . ' · ' . $b->timeslot . ' – ' . tennis_pro_minutes_to_slot( $end_min ) . ' Uhr';

        echo "BEGIN:VEVENT\r\n";
        echo "UID:{$uid}\r\n";
        echo "DTSTAMP:{$stamp}\r\n";
        echo "DTSTART:{$start_utc}\r\n";
        echo "DTEND:{$end_utc}\r\n";
        echo 'SUMMARY:' . tennis_pro_ical_escape( $summary ) . "\r\n";
        echo 'LOCATION:' . tennis_pro_ical_escape( $court_name ) . "\r\n";
        echo 'DESCRIPTION:' . tennis_pro_ical_escape( $desc ) . "\r\n";
        if ( $cat_name ) echo 'CATEGORIES:' . tennis_pro_ical_escape( $cat_name ) . "\r\n";
        echo "END:VEVENT\r\n";
    }

    echo "END:VCALENDAR\r\n";
}

/* ══════════════════════════════════════════════════════════════════════════
   CSV IMPORT
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_process_csv_import( string $tmp_path ): array {
    global $wpdb;

    $result = [ 'imported' => 0, 'skipped' => 0, 'errors' => [] ];

    $handle = fopen( $tmp_path, 'r' );
    if ( ! $handle ) {
        $result['errors'][] = __( 'Datei konnte nicht geöffnet werden.', 'tennis-pro' );
        return $result;
    }

    // Build lookup maps
    $court_map = [];
    foreach ( tennis_pro_get_courts() as $c ) {
        $court_map[ strtolower( $c->name ) ] = (int) $c->id;
    }
    $cat_map_name = [];
    foreach ( tennis_pro_get_categories() as $c ) {
        $cat_map_name[ strtolower( $c->name ) ] = (int) $c->id;
    }
    $trainer_map = [];
    foreach ( tennis_pro_get_trainers() as $t ) {
        $trainer_map[ strtolower( $t->name ) ] = (int) $t->id;
    }

    $row_num  = 0;
    $header   = null;

    while ( ( $row = fgetcsv( $handle, 1000, ';' ) ) !== false ) {
        $row_num++;
        if ( $row_num === 1 ) {
            // Detect header row
            if ( isset( $row[0] ) && strtolower( trim( $row[0] ) ) === 'id' ) {
                $header = array_map( 'trim', $row );
                continue;
            }
            // No header – treat as data starting at row 1
        }

        // Map row by header or positional
        if ( $header ) {
            $data = array_combine( $header, array_pad( $row, count( $header ), '' ) );
        } else {
            $data = [
                'court_name'    => $row[0] ?? '',
                'date'          => $row[1] ?? '',
                'timeslot'      => $row[2] ?? '',
                'duration'      => $row[3] ?? 1,
                'player_name'   => $row[4] ?? '',
                'category_name' => $row[5] ?? '',
            ];
        }

        $court_name    = strtolower( trim( $data['court_name'] ?? '' ) );
        $date          = tennis_pro_validate_date( trim( $data['date'] ?? '' ) );
        $timeslot      = sanitize_text_field( trim( $data['timeslot'] ?? '' ) );
        $duration      = max( 1, min( 8, (int) ( $data['duration'] ?? 1 ) ) );
        $player_name   = sanitize_text_field( trim( $data['player_name'] ?? '' ) );
        $cat_name      = strtolower( trim( $data['category_name'] ?? '' ) );
        $trainer_name  = strtolower( trim( $data['trainer_name'] ?? '' ) );

        // Validate
        if ( ! isset( $court_map[ $court_name ] ) ) {
            $result['skipped']++;
            $result['errors'][] = "Zeile {$row_num}: Platz '{$court_name}' nicht gefunden.";
            continue;
        }
        if ( ! tennis_pro_validate_timeslot( $timeslot ) ) {
            $result['skipped']++;
            $result['errors'][] = "Zeile {$row_num}: Ungültige Uhrzeit '{$timeslot}'.";
            continue;
        }

        $court_id   = $court_map[ $court_name ];
        $cat_id     = $cat_map_name[ $cat_name ] ?? 0;
        $trainer_id = $trainer_map[ $trainer_name ] ?? 0;

        // Conflict check
        if ( tennis_pro_booking_conflict( $court_id, $date, $timeslot, $duration ) ) {
            $result['skipped']++;
            $result['errors'][] = "Zeile {$row_num}: Konflikt für {$court_name} / {$date} / {$timeslot}.";
            continue;
        }

        $ok = $wpdb->insert(
            $wpdb->prefix . 'tennis_bookings',
            [
                'court_id'    => $court_id,
                'date'        => $date,
                'timeslot'    => $timeslot,
                'duration'    => $duration,
                'player_name' => $player_name,
                'category_id' => $cat_id,
                'trainer_id'  => $trainer_id,
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%d', '%d' ]
        );
        $ok ? $result['imported']++ : $result['skipped']++;
    }

    fclose( $handle );
    return $result;
}

/* ══════════════════════════════════════════════════════════════════════════
   ICAL EXPORT FOR SINGLE USER (frontend)
   Triggered via ?tennis_ical=1 query parameter
══════════════════════════════════════════════════════════════════════════ */

add_action( 'init', 'tennis_pro_maybe_ical_download' );

function tennis_pro_maybe_ical_download(): void {
    if ( ! isset( $_GET['tennis_ical'] ) || ! is_user_logged_in() ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'tennis_ical_user' ) ) return;

    $user_id  = get_current_user_id();
    $bookings = tennis_pro_get_user_bookings( $user_id );

    $court_map = [];
    foreach ( tennis_pro_get_courts() as $c ) {
        $court_map[ (int) $c->id ] = $c;
    }

    $site    = get_bloginfo( 'name' );
    $prod_id = '-//' . $site . '//Tennis Pro//DE';

    // Clear any buffered page output before sending file headers.
    while ( ob_get_level() ) { ob_end_clean(); }
    header( 'Content-Type: text/calendar; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="meine-buchungen.ics"' );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );

    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:{$prod_id}\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "METHOD:PUBLISH\r\n";
    echo 'X-WR-CALNAME:' . tennis_pro_ical_escape( $site . ' – ' . __( 'Meine Buchungen', 'tennis-pro' ) ) . "\r\n";

    foreach ( $bookings as $b ) {
        $court_name = isset( $court_map[ (int) $b->court_id ] ) ? $court_map[ (int) $b->court_id ]->name : 'Platz';
        $start_min  = tennis_pro_slot_to_minutes( $b->timeslot );
        $end_min    = $start_min + (int) $b->duration * 30;
        $start_utc  = tennis_pro_ical_utc_stamp( $b->date, $start_min );
        $end_utc    = tennis_pro_ical_utc_stamp( $b->date, $end_min );
        $summary    = $court_name . ( $b->player_name ? ' – ' . $b->player_name : '' );
        $uid        = 'tennis-pro-' . $b->id . '@' . ( parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' );
        $stamp      = gmdate( 'Ymd\THis\Z', $b->created_at ? (int) strtotime( $b->created_at ) : time() );
        $desc       = $court_name . ' · ' . date_i18n( 'l, j. F Y', strtotime( $b->date ) )
                    . ' · ' . $b->timeslot . ' – ' . tennis_pro_minutes_to_slot( $end_min ) . ' Uhr';

        echo "BEGIN:VEVENT\r\n";
        echo "UID:{$uid}\r\n";
        echo "DTSTAMP:{$stamp}\r\n";
        echo "DTSTART:{$start_utc}\r\n";
        echo "DTEND:{$end_utc}\r\n";
        echo 'SUMMARY:' . tennis_pro_ical_escape( $summary ) . "\r\n";
        echo 'LOCATION:' . tennis_pro_ical_escape( $court_name ) . "\r\n";
        echo 'DESCRIPTION:' . tennis_pro_ical_escape( $desc ) . "\r\n";
        echo "END:VEVENT\r\n";
    }
    echo "END:VCALENDAR\r\n";
    exit;
}
