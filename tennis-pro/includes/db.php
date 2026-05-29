<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Create / upgrade all plugin tables.
 * Safe to call multiple times (dbDelta is idempotent).
 */
function tennis_pro_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();

    dbDelta( "CREATE TABLE {$wpdb->prefix}tennis_categories (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL DEFAULT '',
        color      VARCHAR(7)   NOT NULL DEFAULT '#2e7d32',
        text_color VARCHAR(7)   NOT NULL DEFAULT '#ffffff',
        admin_only TINYINT      NOT NULL DEFAULT 0
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}tennis_courts (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL DEFAULT '',
        color      VARCHAR(7)   NOT NULL DEFAULT '#ffffff',
        bg_color   VARCHAR(7)   NOT NULL DEFAULT '#2e7d32',
        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0
    ) $c;" );

    // duration  = number of 30-min slots (1 = 30 min, 2 = 1 h, 3 = 1.5 h, 4 = 2 h)
    // recurring_id = FK to tennis_recurring_groups (0 = single booking)
    // No UNIQUE KEY – multi-slot overlap is checked in PHP before INSERT.
    dbDelta( "CREATE TABLE {$wpdb->prefix}tennis_trainers (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL DEFAULT '',
        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}tennis_bookings (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        court_id     INT UNSIGNED NOT NULL,
        date         DATE         NOT NULL,
        timeslot     VARCHAR(5)   NOT NULL,
        duration     TINYINT UNSIGNED NOT NULL DEFAULT 1,
        user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
        player_name  VARCHAR(100) NOT NULL DEFAULT '',
        category_id  INT UNSIGNED NOT NULL DEFAULT 0,
        trainer_id   INT UNSIGNED NOT NULL DEFAULT 0,
        recurring_id INT UNSIGNED NOT NULL DEFAULT 0,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_date_court (date, court_id),
        KEY idx_user (user_id),
        KEY idx_recurring (recurring_id)
    ) $c;" );

    // Court blocking (maintenance, tournament, etc.)
    // court_id = 0  →  all courts
    // timeslot = '' →  entire day
    dbDelta( "CREATE TABLE {$wpdb->prefix}tennis_blocked_slots (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        court_id    INT UNSIGNED NOT NULL DEFAULT 0,
        date        DATE         NOT NULL,
        timeslot    VARCHAR(5)   NOT NULL DEFAULT '',
        duration    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        reason      VARCHAR(255) NOT NULL DEFAULT '',
        category_id INT UNSIGNED NOT NULL DEFAULT 0,
        created_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_date (date),
        KEY idx_court_date (court_id, date)
    ) $c;" );

    // Waitlist entries per slot
    dbDelta( "CREATE TABLE {$wpdb->prefix}tennis_waitlist (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        court_id    INT UNSIGNED NOT NULL,
        date        DATE         NOT NULL,
        timeslot    VARCHAR(5)   NOT NULL,
        duration    TINYINT UNSIGNED NOT NULL DEFAULT 1,
        user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        email       VARCHAR(255) NOT NULL DEFAULT '',
        player_name VARCHAR(100) NOT NULL DEFAULT '',
        notified    TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_slot (court_id, date, timeslot),
        KEY idx_user (user_id)
    ) $c;" );

    // Recurring booking groups
    // pattern   : 'daily' or 'weekly'
    // day_of_week: 0-6 (Sun-Sat), only used for weekly
    dbDelta( "CREATE TABLE {$wpdb->prefix}tennis_recurring_groups (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        pattern      VARCHAR(10) NOT NULL DEFAULT 'weekly',
        day_of_week  TINYINT UNSIGNED NOT NULL DEFAULT 1,
        start_date   DATE        NOT NULL,
        end_date     DATE        NOT NULL,
        court_id     INT UNSIGNED NOT NULL,
        timeslot     VARCHAR(5)  NOT NULL,
        duration     TINYINT UNSIGNED NOT NULL DEFAULT 1,
        user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
        player_name  VARCHAR(100) NOT NULL DEFAULT '',
        category_id  INT UNSIGNED NOT NULL DEFAULT 0,
        trainer_id   INT UNSIGNED NOT NULL DEFAULT 0,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id)
    ) $c;" );
}

/* ══════════════════════════════════════════════════════════════════════════
   BASIC GETTERS
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_get_courts() {
    global $wpdb;
    return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tennis_courts ORDER BY sort_order, id" );
}

function tennis_pro_get_categories() {
    global $wpdb;
    return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tennis_categories ORDER BY id" );
}

function tennis_pro_get_bookings_for_date( string $date ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT b.*, u.display_name, t.name AS trainer_name
           FROM {$wpdb->prefix}tennis_bookings b
      LEFT JOIN {$wpdb->users} u ON u.ID = b.user_id
      LEFT JOIN {$wpdb->prefix}tennis_trainers t ON t.id = b.trainer_id
          WHERE b.date = %s",
        $date
    ) );
}

/** Return bookings for a date range (inclusive). */
function tennis_pro_get_bookings_for_range( string $start, string $end ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT b.*, u.display_name, t.name AS trainer_name
           FROM {$wpdb->prefix}tennis_bookings b
      LEFT JOIN {$wpdb->users} u ON u.ID = b.user_id
      LEFT JOIN {$wpdb->prefix}tennis_trainers t ON t.id = b.trainer_id
          WHERE b.date BETWEEN %s AND %s
          ORDER BY b.date, b.timeslot",
        $start, $end
    ) );
}

function tennis_pro_get_trainers(): array {
    global $wpdb;
    return (array) $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}tennis_trainers ORDER BY sort_order, name"
    );
}

function tennis_pro_get_blocked_for_date( string $date ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}tennis_blocked_slots WHERE date = %s",
        $date
    ) );
}

function tennis_pro_get_blocked_for_range( string $start, string $end ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}tennis_blocked_slots WHERE date BETWEEN %s AND %s",
        $start, $end
    ) );
}

function tennis_pro_get_waitlist_for_slot( int $court_id, string $date, string $timeslot ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}tennis_waitlist
          WHERE court_id=%d AND date=%s AND timeslot=%s
          ORDER BY created_at ASC",
        $court_id, $date, $timeslot
    ) );
}

function tennis_pro_get_user_bookings( int $user_id, string $from_date = '' ) {
    global $wpdb;
    $from = $from_date ?: gmdate( 'Y-m-d' );
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT b.*, c.name AS court_name, cat.name AS cat_name, cat.color, cat.text_color
           FROM {$wpdb->prefix}tennis_bookings b
      LEFT JOIN {$wpdb->prefix}tennis_courts c ON c.id = b.court_id
      LEFT JOIN {$wpdb->prefix}tennis_categories cat ON cat.id = b.category_id
          WHERE b.user_id = %d AND b.date >= %s
          ORDER BY b.date ASC, b.timeslot ASC",
        $user_id, $from
    ) );
}

/** Return future waitlist entries for a user (not yet notified). */
function tennis_pro_get_user_waitlist( int $user_id, string $from_date = '' ): array {
    global $wpdb;
    $from = $from_date ?: gmdate( 'Y-m-d' );
    return (array) $wpdb->get_results( $wpdb->prepare(
        "SELECT w.*, c.name AS court_name
           FROM {$wpdb->prefix}tennis_waitlist w
      LEFT JOIN {$wpdb->prefix}tennis_courts c ON c.id = w.court_id
          WHERE w.user_id = %d AND w.date >= %s AND w.notified = 0
          ORDER BY w.date ASC, w.timeslot ASC",
        $user_id, $from
    ) );
}

/* ══════════════════════════════════════════════════════════════════════════
   SETTINGS HELPER
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_get_settings(): array {
    global $tennis_pro_settings_cache;
    if ( isset( $tennis_pro_settings_cache ) ) return $tennis_pro_settings_cache;

    $defaults = [
        'booking_limit'        => 0,
        'booking_limit_period' => 'week',
        'booking_horizon'      => 0,    // max days in advance (0 = unlimited)
        'cancel_deadline'      => 0,    // min hours before slot to allow cancellation (0 = anytime)
        'email_notifications'  => 1,
        'email_from_name'      => get_bloginfo( 'name' ),
        'email_from_address'   => get_option( 'admin_email', '' ),
        'notify_admin'         => 0,
        'admin_notify_email'   => get_option( 'admin_email', '' ),
        'delete_on_uninstall'  => 0,
        // SMTP
        'smtp_enabled'         => 0,
        'smtp_host'            => '',
        'smtp_port'            => 587,
        'smtp_encryption'      => 'tls',
        'smtp_auth'            => 1,
        'smtp_user'            => '',
        'smtp_pass'            => '',
        // Free-slot appearance (alternating rows)
        'slot_free_odd_bg'     => '#f0f4ff',
        'slot_free_odd_text'   => '#aaaaaa',
        'slot_free_even_bg'    => '#e8f5e9',
        'slot_free_even_text'  => '#aaaaaa',
        // Time column colours
        'time_col_bg'          => '#1565c0',
        'time_col_text'        => '#ffffff',
        // CI / Vereinsfarben – Topbar (1. Zeile)
        'ci_topbar_bg'         => '#1b5e20',  // Header-Hintergrund (Farbverlauf Start)
        'ci_topbar_bg2'        => '#0d47a1',  // Header-Hintergrund (Farbverlauf Ende)
        'ci_topbar_text'       => '#ffffff',  // Header-Schriftfarbe
        'ci_primary'           => '#2e7d32',  // Primärfarbe (Buttons, Akzente)
        'ci_font_family'       => '',         // Schriftfamilie (leer = Theme-Standard)
        // CI / Vereinsfarben – Datebar (2. Zeile)
        'ci_datebar_bg'        => '#388e3c',  // Datumsleisten-Hintergrund Start
        'ci_datebar_bg2'       => '#1565c0',  // Datumsleisten-Hintergrund Ende
        'ci_datebar_text'      => '#ffffff',  // Datumsleisten-Schrift + Buttons
        // Legende
        'legend_position'      => 'bottom',   // 'top' oder 'bottom'
        'legend_default_open'  => 1,          // 1 = aufgeklappt, 0 = eingeklappt
        // Benutzer-Funktionen
        'show_register_btn'    => 0,    // Registrieren-Button im Frontend anzeigen
        'register_page_id'     => 0,    // ID der Seite mit [tennis_pro_register]
        'show_profile_btn'     => 0,    // Mein-Profil-Button im Frontend anzeigen
        'profile_page_id'      => 0,    // ID der Seite mit [tennis_pro_profile]
        'register_optin'       => 1,    // E-Mail-Bestätigung nach Registrierung erforderlich
        'privacy_page_id'      => 0,    // ID der Datenschutzseite
        // Waitlist
        'waitlist_sequential'  => 1,    // 1 = nur ersten Wartenden benachrichtigen
        // My Calendar integration
        'mycal_enabled'        => 0,
        'mycal_categories'     => '',   // comma-separated category IDs
        'mycal_horizon'        => 30,   // days ahead to look
        'mycal_courts'         => '',   // comma-separated court IDs; '' = all courts
    ];
    $saved                         = get_option( 'tennis_pro_settings', [] );
    $tennis_pro_settings_cache     = array_merge( $defaults, (array) $saved );
    return $tennis_pro_settings_cache;
}

/** Bust the in-memory settings cache (call after saving options). */
function tennis_pro_invalidate_settings_cache(): void {
    global $tennis_pro_settings_cache;
    unset( $tennis_pro_settings_cache );
}

/* ══════════════════════════════════════════════════════════════════════════
   TIME / SLOT HELPERS
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_validate_date( string $date ): string {
    return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : gmdate( 'Y-m-d' );
}

function tennis_pro_validate_timeslot( string $t ): bool {
    return (bool) preg_match( '/^\d{2}:(00|30)$/', $t );
}

/** Convert "HH:MM" to total minutes since midnight. */
function tennis_pro_slot_to_minutes( string $t ): int {
    [ $h, $m ] = explode( ':', $t );
    return (int) $h * 60 + (int) $m;
}

/** Convert minutes since midnight back to "HH:MM". */
function tennis_pro_minutes_to_slot( int $m ): string {
    return sprintf( '%02d:%02d', (int) ( $m / 60 ), $m % 60 );
}

/**
 * Return human-readable duration label.
 * 1 = "30 Min.", 2 = "1 Std.", 3 = "1,5 Std.", 4 = "2 Std.", etc.
 */
function tennis_pro_duration_label( int $slots ): string {
    $minutes = $slots * 30;
    if ( $minutes < 60 ) return $minutes . ' Min.';
    $h = $minutes / 60;
    $label = ( $h == (int) $h ) ? (int) $h : number_format( $h, 1, ',', '' );
    return $label . ' Std.';
}

/* ══════════════════════════════════════════════════════════════════════════
   OVERLAP / CONFLICT DETECTION
══════════════════════════════════════════════════════════════════════════ */

/**
 * Check if a proposed booking interval overlaps with existing BOOKINGS.
 *
 * @param int    $court_id
 * @param string $date
 * @param string $timeslot   Start slot, e.g. "10:00"
 * @param int    $duration   Number of 30-min slots
 * @param int    $exclude_id Booking ID to exclude (for updates)
 * @return bool true = conflict exists
 */
function tennis_pro_booking_conflict( int $court_id, string $date, string $timeslot, int $duration = 1, int $exclude_id = 0 ): bool {
    global $wpdb;
    $new_start = tennis_pro_slot_to_minutes( $timeslot );
    $new_end   = $new_start + $duration * 30;

    $sql = $wpdb->prepare(
        "SELECT id, timeslot, duration FROM {$wpdb->prefix}tennis_bookings
          WHERE court_id = %d AND date = %s",
        $court_id, $date
    );
    if ( $exclude_id ) {
        $sql .= $wpdb->prepare( ' AND id != %d', $exclude_id );
    }
    foreach ( (array) $wpdb->get_results( $sql ) as $b ) {
        $b_start = tennis_pro_slot_to_minutes( $b->timeslot );
        $b_end   = $b_start + (int) $b->duration * 30;
        if ( $new_start < $b_end && $b_start < $new_end ) {
            return true;
        }
    }
    return false;
}

/**
 * Check if a proposed booking interval is blocked by a blocked-slot entry.
 *
 * @return bool true = blocked
 */
function tennis_pro_slot_is_blocked( int $court_id, string $date, string $timeslot, int $duration = 1 ): bool {
    global $wpdb;
    $new_start = tennis_pro_slot_to_minutes( $timeslot );
    $new_end   = $new_start + $duration * 30;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT court_id, timeslot, duration FROM {$wpdb->prefix}tennis_blocked_slots
          WHERE date = %s AND (court_id = %d OR court_id = 0)",
        $date, $court_id
    ) );
    foreach ( $rows as $r ) {
        // Entire day block
        if ( $r->timeslot === '' ) return true;

        $b_start = tennis_pro_slot_to_minutes( $r->timeslot );
        $b_end   = $b_start + (int) $r->duration * 30;
        if ( $new_start < $b_end && $b_start < $new_end ) {
            return true;
        }
    }
    return false;
}

/**
 * Check if a proposed booking slot conflicts with any active My Calendar event.
 * Returns false immediately when My Calendar integration is disabled or unconfigured.
 *
 * Uses the same time-rounding logic as tennis_pro_build_mycal_block_map() so
 * the conflict detection is consistent with what the grid actually renders.
 *
 * @param int    $court_id
 * @param string $date
 * @param string $timeslot  Start slot "HH:MM"
 * @param int    $duration  Number of 30-min slots
 * @return bool  true = conflict
 */
function tennis_pro_slot_has_mycal_conflict( int $court_id, string $date, string $timeslot, int $duration = 1 ): bool {
    $s = tennis_pro_get_settings();
    if ( empty( $s['mycal_enabled'] ) ) return false;

    $cat_ids = array_filter( array_map( 'intval', explode( ',', $s['mycal_categories'] ?? '' ) ) );
    if ( empty( $cat_ids ) ) return false;

    // Honour the configured court filter (empty = all courts)
    $cid_filter = array_filter( array_map( 'intval', explode( ',', $s['mycal_courts'] ?? '' ) ) );
    if ( ! empty( $cid_filter ) && ! in_array( $court_id, $cid_filter, true ) ) return false;

    // Only look within the configured horizon
    $horizon     = max( 1, (int) ( $s['mycal_horizon'] ?? 30 ) );
    $horizon_end = gmdate( 'Y-m-d', strtotime( "+{$horizon} days" ) );
    if ( $date > $horizon_end ) return false;

    $events = tennis_pro_get_mycal_events_for_range( $date, $date, $cat_ids );
    if ( empty( $events ) ) return false;

    $new_start = tennis_pro_slot_to_minutes( $timeslot );
    $new_end   = $new_start + $duration * 30;

    foreach ( $events as $ev ) {
        $t_check = trim( (string) ( $ev->event_time ?? '' ) );
        $all_day = ( (int) ( $ev->event_allday ?? 0 ) === 1 )
                   || $t_check === '' || $t_check === '0'
                   || substr( $t_check, 0, 8 ) === '00:00:00'
                   || $t_check === '00:00';

        if ( $all_day ) return true;

        // Parse times (H:i or H:i:s), round start DOWN and end UP to 30-min boundaries
        $t_start_str = substr( (string) $ev->event_time,    0, 5 );
        $t_end_str   = substr( (string) $ev->event_endtime, 0, 5 );

        [ $sh, $sm ] = array_map( 'intval', explode( ':', $t_start_str ) );
        [ $eh, $em ] = array_map( 'intval', explode( ':', $t_end_str   ) );

        $ev_start   = $sh * 60 + ( $sm >= 30 ? 30 : 0 );
        $ev_end_raw = $eh * 60 + $em;
        $ev_end     = ( $ev_end_raw % 30 === 0 ) ? $ev_end_raw : ( (int) ( $ev_end_raw / 30 ) + 1 ) * 30;
        if ( $ev_end <= $ev_start ) $ev_end = $ev_start + 30;

        if ( $new_start < $ev_end && $ev_start < $new_end ) return true;
    }
    return false;
}

/**
 * Count active (future + today) bookings for a user within the period.
 */
function tennis_pro_count_user_bookings( int $user_id, string $period = 'week' ): int {
    global $wpdb;
    $today = gmdate( 'Y-m-d' );

    if ( $period === 'day' ) {
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tennis_bookings WHERE user_id=%d AND date=%s",
            $user_id, $today
        ) );
    }

    // week
    $end           = ( gmdate( 'N' ) == 7 ) ? $today : gmdate( 'Y-m-d', strtotime( 'sunday this week' ) );
    $start_of_week = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
    if ( $start_of_week > $today ) {
        $start_of_week = $today;
    }
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}tennis_bookings
          WHERE user_id = %d AND date BETWEEN %s AND %s",
        $user_id, $start_of_week, $end
    ) );
}

/* ══════════════════════════════════════════════════════════════════════════
   MAP BUILDERS
══════════════════════════════════════════════════════════════════════════ */

/**
 * Build booking map: [court_id][timeslot] = booking_row | 'cont' (continuation slot).
 * Multi-slot bookings mark their continuation slots as 'cont'.
 */
function tennis_pro_build_map( array $bookings ): array {
    $map = [];
    foreach ( $bookings as $b ) {
        $cid   = (int) $b->court_id;
        $start = tennis_pro_slot_to_minutes( $b->timeslot );
        $dur   = max( 1, (int) $b->duration );
        for ( $i = 0; $i < $dur; $i++ ) {
            $slot = tennis_pro_minutes_to_slot( $start + $i * 30 );
            $map[ $cid ][ $slot ] = ( $i === 0 ) ? $b : 'cont';
        }
    }
    return $map;
}

/**
 * Build blocked map: [court_id][timeslot] = blocked_row | 'cont' | 'all' (entire day).
 * court_id=0 means all courts.
 */
function tennis_pro_build_blocked_map( array $blocked_rows, array $courts ): array {
    $map = [];
    $court_ids = array_map( fn( $c ) => (int) $c->id, $courts );

    foreach ( $blocked_rows as $r ) {
        $targets = ( (int) $r->court_id === 0 ) ? $court_ids : [ (int) $r->court_id ];
        foreach ( $targets as $cid ) {
            if ( $r->timeslot === '' ) {
                // Entire day
                $map[ $cid ]['__day__'] = $r;
            } else {
                $start = tennis_pro_slot_to_minutes( $r->timeslot );
                $dur   = max( 1, (int) $r->duration );
                for ( $i = 0; $i < $dur; $i++ ) {
                    $slot = tennis_pro_minutes_to_slot( $start + $i * 30 );
                    $map[ $cid ][ $slot ] = ( $i === 0 ) ? $r : 'cont';
                }
            }
        }
    }
    return $map;
}

/* ══════════════════════════════════════════════════════════════════════════
   MY CALENDAR INTEGRATION
══════════════════════════════════════════════════════════════════════════ */

/**
 * Fetch My Calendar events for a date range filtered by category IDs.
 *
 * Supports both My Calendar 3.x (wp_my_calendar_dates occurrence table)
 * and older versions (direct event_begin/event_end on wp_my_calendar).
 *
 * Returns an array of stdClass rows with normalised fields:
 *   event_title, event_allday, event_date (Y-m-d),
 *   event_time (H:i:s|''), event_endtime (H:i:s|''),
 *   cat_color (#rrggbb), cat_name
 *
 * Returns [] if My Calendar is not installed or integration is disabled.
 */
function tennis_pro_get_mycal_events_for_range( string $start_date, string $end_date, array $cat_ids ): array {
    global $wpdb;

    if ( empty( $cat_ids ) ) return [];

    $tbl_events = $wpdb->prefix . 'my_calendar';

    // Gracefully skip if My Calendar is not installed
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl_events}'" ) !== $tbl_events ) {
        return [];
    }

    // Build safe IN clause (values are already cast to int)
    $ids_int  = array_map( 'intval', $cat_ids );
    $in_sql   = implode( ',', $ids_int );
    $tbl_cats = $wpdb->prefix . 'my_calendar_categories';

    // Try newer My Calendar 3.x dates table first
    $tbl_dates = $wpdb->prefix . 'my_calendar_dates';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $has_dates = ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl_dates}'" ) === $tbl_dates );

    if ( $has_dates ) {
        // My Calendar 3.x: use occurrence table; derive all-day from time == 00:00:00
        $sql = $wpdb->prepare(
            "SELECT e.event_id,
                    e.event_post,
                    d.occur_id,
                    e.event_title,
                    DATE(d.occur_begin)   AS event_date,
                    TIME(d.occur_begin)   AS event_time,
                    TIME(d.occur_end)     AS event_endtime,
                    CASE WHEN TIME(d.occur_begin) = '00:00:00' AND TIME(d.occur_end) = '00:00:00'
                         THEN 1 ELSE 0 END AS event_allday,
                    CONCAT('#', TRIM(LEADING '#' FROM COALESCE(NULLIF(c.category_color,''),'0277bd'))) AS cat_color,
                    COALESCE(c.category_name,'')         AS cat_name
               FROM {$tbl_dates} d
               JOIN {$tbl_events} e ON e.event_id = d.event_id
          LEFT JOIN {$tbl_cats}  c ON c.category_id = e.event_category
              WHERE (e.event_approved = 1 OR e.event_approved IS NULL)
                AND e.event_category IN ({$in_sql})
                AND DATE(d.occur_begin) BETWEEN %s AND %s
              ORDER BY d.occur_begin",
            $start_date,
            $end_date
        );
    } else {
        // My Calendar 2.x: no occurrence table, no event_allday column — derive all-day from event_time in PHP
        $sql = $wpdb->prepare(
            "SELECT e.event_id,
                    e.event_post,
                    e.event_title,
                    DATE(e.event_begin)   AS event_date,
                    e.event_time,
                    e.event_endtime,
                    CONCAT('#', TRIM(LEADING '#' FROM COALESCE(NULLIF(c.category_color,''),'0277bd'))) AS cat_color,
                    COALESCE(c.category_name,'')         AS cat_name
               FROM {$tbl_events} e
          LEFT JOIN {$tbl_cats}  c ON c.category_id = e.event_category
              WHERE (e.event_approved = 1 OR e.event_approved IS NULL)
                AND e.event_category IN ({$in_sql})
                AND DATE(e.event_begin) <= %s
                AND DATE(e.event_end)   >= %s
              ORDER BY e.event_begin, e.event_time",
            $end_date,
            $start_date
        );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
    return (array) $wpdb->get_results( $sql );
}

/**
 * Build a blocked-slot map from My Calendar events.
 *
 * Compatible with tennis_pro_build_blocked_map() output:
 *   [court_id][timeslot] = row | 'cont'
 *   [court_id]['__day__'] = row
 *
 * Each row has: timeslot, duration, reason, source='mycal', cat_color
 *
 * @param array $events  Output of tennis_pro_get_mycal_events_for_range()
 * @param array $courts  All court objects (to know which IDs to fill)
 * @param array $target_court_ids  Specific court IDs to apply, [] = all
 */
function tennis_pro_build_mycal_block_map( array $events, array $courts, array $target_court_ids = [] ): array {
    $map       = [];
    $court_ids = array_map( fn( $c ) => (int) $c->id, $courts );

    if ( ! empty( $target_court_ids ) ) {
        $target_int = array_map( 'intval', $target_court_ids );
        $court_ids  = array_values( array_intersect( $court_ids, $target_int ) );
    }

    foreach ( $events as $ev ) {
        // Build a fake "blocked row" that the renderer understands
        $row            = new stdClass();
        $row->id         = 0;
        $row->event_id   = (int) ( $ev->event_id  ?? 0 );
        $row->event_post = (int) ( $ev->event_post ?? 0 );
        $row->occur_id   = (int) ( $ev->occur_id  ?? 0 );
        $row->reason     = $ev->event_title ?? '';
        $row->source    = 'mycal';
        $row->cat_color = $ev->cat_color  ?? '#0277bd';
        $row->cat_name  = $ev->cat_name   ?? '';

        // event_allday may be absent (My Calendar 2.x): fall back to time-based detection
        $t_check  = trim( (string) ( $ev->event_time ?? '' ) );
        $all_day  = ( (int) ( $ev->event_allday ?? 0 ) === 1 )
                    || $t_check === ''
                    || $t_check === '0'
                    || substr( $t_check, 0, 8 ) === '00:00:00'
                    || $t_check === '00:00';

        foreach ( $court_ids as $cid ) {
            if ( $all_day ) {
                $row->timeslot = '';
                $row->duration = 1;
                if ( isset( $map[ $cid ]['__day__'] ) && is_object( $map[ $cid ]['__day__'] ) ) {
                    // Stack onto existing all-day entry
                    $map[ $cid ]['__day__']->extra_events[] = clone $row;
                } else {
                    $clone                = clone $row;
                    $clone->extra_events  = [];
                    $map[ $cid ]['__day__'] = $clone;
                }
            } else {
                // Parse start / end time from H:i:s
                $t_start_str = substr( $ev->event_time,    0, 5 ); // HH:MM
                $t_end_str   = substr( $ev->event_endtime, 0, 5 ); // HH:MM

                // Round start DOWN to nearest 30-min slot
                [ $sh, $sm ] = array_map( 'intval', explode( ':', $t_start_str ) );
                $start_min   = $sh * 60 + ( $sm >= 30 ? 30 : 0 );

                // Round end UP to nearest 30-min boundary
                [ $eh, $em ] = array_map( 'intval', explode( ':', $t_end_str ) );
                $end_min_raw = $eh * 60 + $em;
                $end_min     = ( $end_min_raw % 30 === 0 ) ? $end_min_raw : ( (int) ( $end_min_raw / 30 ) + 1 ) * 30;

                if ( $end_min <= $start_min ) $end_min = $start_min + 30;

                $slots         = (int) round( ( $end_min - $start_min ) / 30 );
                $row->timeslot = tennis_pro_minutes_to_slot( $start_min );
                $row->duration = $slots;
                $head_slot     = tennis_pro_minutes_to_slot( $start_min );

                // If the head slot is already 'cont' from a longer earlier event,
                // scan backwards to find the object that owns this range and stack there.
                $effective_head = $head_slot;
                if ( isset( $map[ $cid ][ $head_slot ] ) && $map[ $cid ][ $head_slot ] === 'cont' ) {
                    for ( $back = $start_min - 30; $back >= 0; $back -= 30 ) {
                        $bs = tennis_pro_minutes_to_slot( $back );
                        if ( ! isset( $map[ $cid ][ $bs ] ) ) break; // gap — stop
                        if ( is_object( $map[ $cid ][ $bs ] ) ) {
                            $effective_head = $bs;
                            break;
                        }
                    }
                }

                if ( isset( $map[ $cid ][ $effective_head ] ) && is_object( $map[ $cid ][ $effective_head ] ) ) {
                    // Stack onto the existing entry at the effective head slot
                    if ( ! isset( $map[ $cid ][ $effective_head ]->extra_events ) ) {
                        $map[ $cid ][ $effective_head ]->extra_events = [];
                    }
                    $map[ $cid ][ $effective_head ]->extra_events[] = clone $row;
                } else {
                    // New entry — claim the head slot and mark continuations
                    $clone               = clone $row;
                    $clone->extra_events = [];
                    $map[ $cid ][ $head_slot ] = $clone;
                    for ( $i = 1; $i < $slots; $i++ ) {
                        $slot_str = tennis_pro_minutes_to_slot( $start_min + $i * 30 );
                        if ( ! isset( $map[ $cid ][ $slot_str ] ) ) {
                            $map[ $cid ][ $slot_str ] = 'cont';
                        }
                    }
                }
            }
        }
    }

    return $map;
}

/**
 * Merge a My Calendar block map into an existing blk_by_date map.
 * My Calendar entries only fill empty slots (bookings/existing blocks take priority).
 *
 * @param array  $blk_by_date   Existing map [date][court_id][slot]
 * @param array  $mycal_events  All events (with event_date field)
 * @param array  $courts        All court objects
 * @param array  $target_cids   Specific court IDs; [] = all
 */
function tennis_pro_merge_mycal_into_blk_map( array &$blk_by_date, array $mycal_events, array $courts, array $target_cids = [] ): void {
    if ( empty( $mycal_events ) ) return;

    // Group events by date
    $by_date = [];
    foreach ( $mycal_events as $ev ) {
        $by_date[ $ev->event_date ][] = $ev;
    }

    foreach ( $by_date as $ev_date => $day_events ) {
        if ( ! isset( $blk_by_date[ $ev_date ] ) ) {
            $blk_by_date[ $ev_date ] = [];
        }
        $mycal_map = tennis_pro_build_mycal_block_map( $day_events, $courts, $target_cids );

        foreach ( $mycal_map as $cid => $slots ) {
            foreach ( $slots as $slot_key => $entry ) {
                // Don't overwrite existing bookings/blocks
                if ( ! isset( $blk_by_date[ $ev_date ][ $cid ][ $slot_key ] ) ) {
                    $blk_by_date[ $ev_date ][ $cid ][ $slot_key ] = $entry;
                }
            }
        }
    }
}

/**
 * Determine the base URL of the My Calendar calendar page.
 *
 * Tries four strategies in order and caches the result for one hour:
 *  1. My Calendar's own mc_get_uri() helper (most reliable when configured).
 *  2. mc_uri / mc_event_uri options (may store a page ID or a URL).
 *  3. DB search for a published page whose content contains [my_calendar].
 *
 * @return string  Full URL or '' if not determinable.
 */
function tennis_pro_mycal_calendar_url(): string {
    $cached = get_transient( 'tnp_mycal_page_url' );
    if ( $cached !== false ) return (string) $cached;

    $url = '';

    // 1. My Calendar's own helper (handles all internal option lookups)
    if ( ! $url && function_exists( 'mc_get_uri' ) ) {
        $url = (string) mc_get_uri();
    }

    // 2. mc_uri / mc_event_uri options; may store a page ID (3.x) or full URL (2.x)
    if ( ! $url ) {
        foreach ( [ 'mc_uri', 'mc_event_uri' ] as $opt ) {
            $raw = get_option( $opt, '' );
            if ( ! $raw ) continue;
            $base = is_numeric( $raw ) ? (string) get_permalink( (int) $raw ) : (string) $raw;
            if ( $base ) { $url = $base; break; }
        }
    }

    // 3. Search for a published page that contains the [my_calendar] shortcode
    if ( ! $url ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $page_id = (int) $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type   = 'page'
                AND post_status = 'publish'
                AND post_content LIKE '%[my_calendar%'
              LIMIT 1"
        );
        if ( $page_id > 0 ) {
            $url = (string) get_permalink( $page_id );
        }
    }

    set_transient( 'tnp_mycal_page_url', $url, HOUR_IN_SECONDS );
    return $url;
}

/**
 * Return the detail URL for a My Calendar event, or '' if not determinable.
 *
 * My Calendar 3.x stores events as WordPress Custom Post Types (post_type mc-events).
 * The event_post column in wp_my_calendar holds the corresponding WP post ID.
 * get_permalink(event_post) gives the correct CPT URL (/mc-events/slug/) directly,
 * without going through the ?mc_id= query-string (which uses WP post IDs, not
 * event_ids from wp_my_calendar, and thus causes wrong-event redirects).
 *
 * @param int $occur_id    Occurrence ID from wp_my_calendar_dates (0 for 2.x).
 * @param int $event_id    Master event ID from wp_my_calendar.
 * @param int $event_post  WordPress post ID of the CPT event (from event_post column).
 */
function tennis_pro_mycal_event_url( int $occur_id, int $event_id = 0, int $event_post = 0 ): string {
    // Primary: direct CPT permalink via event_post (avoids mc_id confusion entirely)
    if ( $event_post > 0 ) {
        $url = get_permalink( $event_post );
        if ( $url ) return (string) $url;
    }

    // Fallback: mc_get_permalink knows how to resolve event_id → event_post internally
    if ( $event_id > 0 && function_exists( 'mc_get_permalink' ) ) {
        $url = mc_get_permalink( $event_id );
        if ( $url ) return (string) $url;
    }

    // Last resort: ?mc_id= on the calendar page (may not work with all MC versions)
    $mc_id = $occur_id > 0 ? $occur_id : $event_id;
    if ( $mc_id <= 0 ) return '';
    $base = tennis_pro_mycal_calendar_url();
    if ( ! $base ) return '';
    return add_query_arg( 'mc_id', $mc_id, $base );
}

/** Return associative map [cat_id] => category_row */
function tennis_pro_cat_map( array $cats ): array {
    $map = [];
    foreach ( $cats as $c ) {
        $map[ (int) $c->id ] = $c;
    }
    return $map;
}

/* ══════════════════════════════════════════════════════════════════════════
   RECURRING BOOKING HELPERS
══════════════════════════════════════════════════════════════════════════ */

/**
 * Generate all dates for a recurring group between start and end (inclusive).
 * Returns an array of 'Y-m-d' strings.
 */
function tennis_pro_recurring_dates( string $pattern, int $day_of_week, string $start_date, string $end_date ): array {
    $dates  = [];
    $cursor = strtotime( $start_date );
    $end    = strtotime( $end_date );

    // PHP: 0=Sun,1=Mon,...6=Sat  (same as our day_of_week)
    while ( $cursor <= $end ) {
        if ( $pattern === 'daily' ) {
            $dates[] = gmdate( 'Y-m-d', $cursor );
            $cursor  = strtotime( '+1 day', $cursor );
        } elseif ( $pattern === 'weekly' ) {
            if ( (int) gmdate( 'w', $cursor ) === $day_of_week ) {
                $dates[] = gmdate( 'Y-m-d', $cursor );
            }
            $cursor = strtotime( '+1 day', $cursor );
        }
    }
    return $dates;
}
