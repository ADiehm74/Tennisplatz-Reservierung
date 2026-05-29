<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Central mailer for Tennis Pro.
 * All outgoing mail goes through tennis_pro_send_mail() so the From header
 * is always consistent and notifications can be toggled centrally.
 */

/* ── SMTP configuration via phpmailer_init ───────────────────────────── */

add_action( 'phpmailer_init', 'tennis_pro_configure_smtp' );

/**
 * Configure PHPMailer with plugin SMTP settings when enabled.
 * Fires before every wp_mail() call, including WordPress core emails.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
 */
function tennis_pro_configure_smtp( $phpmailer ): void {
    $s = tennis_pro_get_settings();
    if ( empty( $s['smtp_enabled'] ) || empty( $s['smtp_host'] ) ) return;

    $phpmailer->isSMTP();
    $phpmailer->Host     = $s['smtp_host'];
    $phpmailer->Port     = (int) ( $s['smtp_port'] ?: 587 );
    $phpmailer->SMTPAuth = ! empty( $s['smtp_auth'] );

    if ( $phpmailer->SMTPAuth ) {
        $phpmailer->Username = $s['smtp_user'];
        $phpmailer->Password = tennis_pro_smtp_decode( $s['smtp_pass'] );
    }

    switch ( $s['smtp_encryption'] ) {
        case 'ssl':
            $phpmailer->SMTPSecure = 'ssl';
            break;
        case 'tls':
            $phpmailer->SMTPSecure = 'tls';
            break;
        default:
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
    }

    // Suppress debug output in production
    $phpmailer->SMTPDebug = 0;
}

/**
 * Simple reversible encoding for the SMTP password stored in wp_options.
 * NOT encryption – kept on par with WP Mail SMTP and similar plugins.
 */
function tennis_pro_smtp_encode( string $pass ): string {
    return base64_encode( $pass );  // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}
function tennis_pro_smtp_decode( string $encoded ): string {
    $dec = base64_decode( $encoded, true );  // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
    return ( $dec !== false ) ? $dec : $encoded; // fallback: password stored plain
}

/**
 * Send a test e-mail and return true on success or a WP_Error on failure.
 *
 * @return true|WP_Error
 */
function tennis_pro_send_test_email( string $to ) {
    $last_error = null;

    $capture = static function ( \WP_Error $err ) use ( &$last_error ) {
        $last_error = $err;
    };
    add_action( 'wp_mail_failed', $capture );

    $result = wp_mail(
        $to,
        sprintf( __( '[Tennis Pro] Test-E-Mail von %s', 'tennis-pro' ), get_bloginfo( 'name' ) ),
        tennis_pro_wrap_email(
            __( 'Test erfolgreich', 'tennis-pro' ),
            '<p>' . __( 'Diese E-Mail bestätigt, dass deine Mail-Einstellungen korrekt konfiguriert sind.', 'tennis-pro' ) . '</p>'
        ),
        [ 'Content-Type: text/html; charset=UTF-8' ]
    );

    remove_action( 'wp_mail_failed', $capture );

    if ( $result ) {
        return true;
    }
    return $last_error instanceof \WP_Error
        ? $last_error
        : new \WP_Error( 'mail_failed', __( 'wp_mail() hat false zurückgegeben. Prüfe deine SMTP-Einstellungen.', 'tennis-pro' ) );
}

/* ── Low-level send helpers ───────────────────────────────────────────── */

function tennis_pro_send_mail( string $to, string $subject, string $body ): bool {
    $s = tennis_pro_get_settings();

    $from_name  = $s['email_from_name']    ?: get_bloginfo( 'name' );
    $from_addr  = $s['email_from_address'] ?: get_option( 'admin_email' );
    $headers    = [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$from_name} <{$from_addr}>",
    ];

    return wp_mail( $to, $subject, tennis_pro_wrap_email( $subject, $body ), $headers );
}

/**
 * Send a mail WITH an iCal (.ics) file as attachment.
 * The iCal string is attached via the phpmailer_init hook to avoid
 * writing temp files and to keep compatibility with all SMTP setups.
 */
function tennis_pro_send_mail_ical( string $to, string $subject, string $body, string $ical_string ): bool {
    $attach = static function ( $phpmailer ) use ( $ical_string ) {
        try {
            $phpmailer->addStringAttachment(
                $ical_string,
                'termin.ics',
                'base64',
                'text/calendar; method=REQUEST; charset=UTF-8'
            );
        } catch ( \Exception $e ) {
            // Silently ignore attachment errors – mail still goes out without it.
        }
    };
    add_action( 'phpmailer_init', $attach );
    $result = tennis_pro_send_mail( $to, $subject, $body );
    remove_action( 'phpmailer_init', $attach );
    return $result;
}

/**
 * Wrap a body in a minimal HTML email shell.
 */
function tennis_pro_wrap_email( string $title, string $body ): string {
    $site = esc_html( get_bloginfo( 'name' ) );
    $url  = esc_url( home_url() );
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;color:#222;max-width:600px;margin:0 auto;padding:20px">
        <h2 style="color:#2e7d32">' . esc_html( $title ) . '</h2>
        ' . $body . '
        <hr style="margin:32px 0;border:none;border-top:1px solid #ddd">
        <p style="font-size:12px;color:#888"><a href="' . $url . '">' . $site . '</a></p>
    </body></html>';
}

/* ── Booking confirmation ─────────────────────────────────────────────── */

function tennis_pro_mail_booking_confirmed( object $booking, string $court_name, string $cat_name = '' ): void {
    $s = tennis_pro_get_settings();
    if ( ! $s['email_notifications'] ) return;

    $user = get_user_by( 'id', (int) $booking->user_id );
    if ( ! $user || ! $user->user_email ) return;

    // Global WP administrators book for others – they don't get a personal confirmation.
    $is_wp_admin = in_array( 'administrator', (array) $user->roles, true );

    if ( ! $is_wp_admin ) {
        $end_min    = tennis_pro_slot_to_minutes( $booking->timeslot ) + (int) $booking->duration * 30;
        $end_time   = tennis_pro_minutes_to_slot( $end_min );
        $date_label = date_i18n( 'l, j. F Y', strtotime( $booking->date ) );
        $time_label = $booking->timeslot . ' – ' . $end_time . ' Uhr';
        $dur_label  = tennis_pro_duration_label( (int) $booking->duration );
        $name_label = esc_html( $booking->player_name ?: $user->display_name );

        $subject = sprintf(
            /* translators: %s = date */
            __( 'Reservierung bestätigt – %s', 'tennis-pro' ),
            esc_html( $date_label )
        );

        $body = '<p>' . sprintf( __( 'Hallo %s,', 'tennis-pro' ), esc_html( $user->display_name ) ) . '</p>
        <p>' . __( 'Deine Reservierung wurde erfolgreich angelegt:', 'tennis-pro' ) . '</p>
        <table style="border-collapse:collapse;width:100%;max-width:400px">
            <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Platz',     'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $court_name ) . '</td></tr>
            <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Datum',     'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $date_label ) . '</td></tr>
            <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Uhrzeit',  'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $time_label ) . '</td></tr>
            <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Dauer',     'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $dur_label ) . '</td></tr>
            ' . ( $cat_name   ? '<tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Kategorie', 'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $cat_name ) . '</td></tr>' : '' ) . '
            ' . ( $name_label ? '<tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Name',      'tennis-pro' ) . '</td><td style="padding:6px 12px">' . $name_label . '</td></tr>' : '' ) . '
        </table>
        <p style="margin-top:16px;font-size:13px;color:#555">' . __( 'Der Termin ist dieser E-Mail als Kalender-Datei (.ics) beigefügt – einfach öffnen zum Importieren.', 'tennis-pro' ) . '</p>';

        // Send with iCal attachment
        $ical = tennis_pro_build_ical_for_booking( $booking, $court_name, $cat_name );
        tennis_pro_send_mail_ical( $user->user_email, $subject, $body, $ical );
    }

    // Admin copy (plain text, no iCal)
    if ( $s['notify_admin'] && $s['admin_notify_email'] ) {
        $adm_date = date_i18n( 'l, j. F Y', strtotime( $booking->date ) );
        $adm_subj = sprintf( __( '[Tennis] Neue Buchung von %s', 'tennis-pro' ), $user->display_name );
        $adm_body = '<p>' . sprintf( __( 'Neue Buchung von <strong>%s</strong>:', 'tennis-pro' ), esc_html( $user->display_name ) ) . '</p>
        <table style="border-collapse:collapse;width:100%;max-width:400px">
            <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Platz',    'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $court_name ) . '</td></tr>
            <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Datum',    'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $adm_date ) . '</td></tr>
            <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Uhrzeit', 'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $booking->timeslot ) . ' Uhr</td></tr>
        </table>';
        tennis_pro_send_mail( $s['admin_notify_email'], $adm_subj, $adm_body );
    }
}

/* ── Booking cancelled ────────────────────────────────────────────────── */

function tennis_pro_mail_booking_cancelled( object $booking, string $court_name ): void {
    $s = tennis_pro_get_settings();
    if ( ! $s['email_notifications'] ) return;

    $user = get_user_by( 'id', (int) $booking->user_id );
    if ( ! $user || ! $user->user_email ) return;

    $date_label = esc_html( date_i18n( 'l, j. F Y', strtotime( $booking->date ) ) );
    $subject    = sprintf( __( 'Reservierung storniert – %s', 'tennis-pro' ), $date_label );

    $body = '<p>' . sprintf( __( 'Hallo %s,', 'tennis-pro' ), esc_html( $user->display_name ) ) . '</p>
    <p>' . __( 'Deine folgende Reservierung wurde storniert:', 'tennis-pro' ) . '</p>
    <table style="border-collapse:collapse;width:100%;max-width:400px">
        <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Platz', 'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $court_name ) . '</td></tr>
        <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Datum', 'tennis-pro' ) . '</td><td style="padding:6px 12px">' . $date_label . '</td></tr>
        <tr><td style="padding:6px 12px;background:#f5f5f5;font-weight:600">' . __( 'Uhrzeit', 'tennis-pro' ) . '</td><td style="padding:6px 12px">' . esc_html( $booking->timeslot ) . ' Uhr</td></tr>
    </table>';

    tennis_pro_send_mail( $user->user_email, $subject, $body );
}

/* ── Waitlist notification ────────────────────────────────────────────── */

/**
 * Notify waitlisted users for a slot that it has become available.
 *
 * Sequential mode (default): only the first (oldest) unnotified entry is
 * contacted. They have 30 minutes to book before the next person would be
 * informed (on the next cancellation/trigger). This prevents a stampede
 * where everyone races to click "Book" at the same time.
 *
 * Broadcast mode (waitlist_sequential = 0): all unnotified entries are
 * emailed simultaneously (legacy behaviour).
 */
function tennis_pro_mail_waitlist_notify( int $court_id, string $date, string $timeslot ): void {
    global $wpdb;
    $s = tennis_pro_get_settings();
    if ( ! $s['email_notifications'] ) return;

    $entries = tennis_pro_get_waitlist_for_slot( $court_id, $date, $timeslot );
    if ( empty( $entries ) ) return;

    $sequential = (bool) ( $s['waitlist_sequential'] ?? 1 );
    // In sequential mode only notify the first entry
    if ( $sequential ) {
        $entries = [ $entries[0] ];
    }

    $court = $wpdb->get_row( $wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}tennis_courts WHERE id=%d", $court_id
    ) );
    $court_name  = $court ? $court->name : __( 'Unbekannter Platz', 'tennis-pro' );
    $date_label  = date_i18n( 'l, j. F Y', strtotime( $date ) );
    $booking_url = add_query_arg( 'date', $date, get_permalink() ?: home_url() );

    $note_broadcast = '<p style="font-size:12px;color:#888">'
        . __( 'Diese Benachrichtigung geht an alle, die auf der Warteliste stehen. Es gilt: wer zuerst bucht, bekommt den Slot.', 'tennis-pro' )
        . '</p>';
    $note_sequential = '<p style="font-size:12px;color:#888">'
        . __( 'Du wurdest als Nächster auf der Warteliste benachrichtigt. Buche jetzt, um den Slot zu sichern.', 'tennis-pro' )
        . '</p>';

    foreach ( $entries as $entry ) {
        $u_obj = get_user_by( 'id', (int) $entry->user_id );
        $email = $entry->email ?: ( $u_obj ? $u_obj->user_email : '' );
        if ( ! $email ) continue;

        $name    = $entry->player_name ?: __( 'Spieler', 'tennis-pro' );
        $subject = sprintf( __( 'Slot verfügbar – %s %s Uhr', 'tennis-pro' ), $date_label, $timeslot );

        $body = '<p>' . sprintf( __( 'Hallo %s,', 'tennis-pro' ), esc_html( $name ) ) . '</p>
        <p>' . sprintf(
            /* translators: 1=court, 2=date, 3=time */
            __( 'Ein Slot auf <strong>%1$s</strong> am <strong>%2$s</strong> um <strong>%3$s Uhr</strong>, auf den du auf der Warteliste stehst, ist jetzt frei.', 'tennis-pro' ),
            esc_html( $court_name ), esc_html( $date_label ), esc_html( $timeslot )
        ) . '</p>
        <p><a href="' . esc_url( $booking_url ) . '" style="background:#2e7d32;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none">'
            . __( 'Jetzt buchen →', 'tennis-pro' ) . '</a></p>'
        . ( $sequential ? $note_sequential : $note_broadcast );

        tennis_pro_send_mail( $email, $subject, $body );

        // Mark as notified
        $wpdb->update(
            $wpdb->prefix . 'tennis_waitlist',
            [ 'notified' => 1 ],
            [ 'id' => (int) $entry->id ],
            [ '%d' ], [ '%d' ]
        );
    }
}
