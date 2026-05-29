<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Plugin action link (Settings-Link in plugin list) ────────────────── */
add_filter( 'plugin_action_links_tennis-pro/tennis-pro.php', 'tennis_pro_action_links' );

function tennis_pro_action_links( array $links ): array {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=tennis-pro-options' ) ) . '">'
        . esc_html__( 'Einstellungen', 'tennis-pro' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

/* ── Register submenu ─────────────────────────────────────────────────── */
add_action( 'admin_menu', 'tennis_pro_options_menu', 15 );

function tennis_pro_options_menu() {
    add_submenu_page(
        'tennis-pro',
        __( 'Optionen', 'tennis-pro' ),
        __( 'Optionen', 'tennis-pro' ),
        'tennis_manage',
        'tennis-pro-options',
        'tennis_pro_options_page'
    );
}

/* ── AJAX: Test-E-Mail senden ─────────────────────────────────────────── */
add_action( 'wp_ajax_tennis_send_test_email', 'tennis_pro_ajax_test_email' );

function tennis_pro_ajax_test_email(): void {
    if ( ! current_user_can( 'tennis_manage' ) ) {
        wp_send_json_error( [ 'message' => __( 'Keine Berechtigung.', 'tennis-pro' ) ], 403 );
    }
    check_ajax_referer( 'tennis_test_email_nonce', 'nonce' );

    $to = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
    if ( ! is_email( $to ) ) {
        wp_send_json_error( [ 'message' => __( 'Ungültige E-Mail-Adresse.', 'tennis-pro' ) ], 400 );
    }

    $result = tennis_pro_send_test_email( $to );

    if ( $result === true ) {
        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s = email address */
                __( '✅ Test-E-Mail erfolgreich an %s gesendet.', 'tennis-pro' ),
                esc_html( $to )
            ),
        ] );
    } else {
        $msg = $result instanceof \WP_Error
            ? implode( ' | ', $result->get_error_messages() )
            : __( 'Unbekannter Fehler.', 'tennis-pro' );
        wp_send_json_error( [ 'message' => '❌ ' . $msg ], 500 );
    }
}

/* ── Options page ─────────────────────────────────────────────────────── */
function tennis_pro_options_page() {
    if ( ! current_user_can( 'tennis_manage' ) ) wp_die( __( 'Zugriff verweigert.', 'tennis-pro' ) );

    $saved = false;
    if (
        isset( $_POST['tennis_options_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tennis_options_nonce'] ) ), 'tennis_options_save' )
    ) {
        $opts = tennis_pro_get_settings();
        $opts['booking_limit']        = max( 0, (int) ( $_POST['booking_limit'] ?? 0 ) );
        $opts['booking_limit_period'] = in_array( $_POST['booking_limit_period'] ?? '', [ 'day', 'week' ], true )
            ? sanitize_text_field( wp_unslash( $_POST['booking_limit_period'] ) )
            : 'week';
        $opts['email_notifications']  = isset( $_POST['email_notifications'] ) ? 1 : 0;
        $opts['email_from_name']      = sanitize_text_field( wp_unslash( $_POST['email_from_name']     ?? '' ) );
        $opts['email_from_address']   = sanitize_email( wp_unslash( $_POST['email_from_address']       ?? '' ) );
        $opts['notify_admin']         = isset( $_POST['notify_admin'] ) ? 1 : 0;
        $opts['admin_notify_email']   = sanitize_email( wp_unslash( $_POST['admin_notify_email']       ?? '' ) );
        $opts['delete_on_uninstall']  = isset( $_POST['delete_on_uninstall'] ) ? 1 : 0;

        // SMTP
        $opts['smtp_enabled']    = isset( $_POST['smtp_enabled'] ) ? 1 : 0;
        $opts['smtp_host']       = sanitize_text_field( wp_unslash( $_POST['smtp_host']  ?? '' ) );
        $opts['smtp_port']       = max( 1, min( 65535, (int) ( $_POST['smtp_port'] ?? 587 ) ) );
        $opts['smtp_encryption'] = in_array( $_POST['smtp_encryption'] ?? '', [ 'none', 'tls', 'ssl' ], true )
            ? sanitize_text_field( wp_unslash( $_POST['smtp_encryption'] ) ) : 'tls';
        $opts['smtp_auth']       = isset( $_POST['smtp_auth'] ) ? 1 : 0;
        $opts['smtp_user']       = sanitize_text_field( wp_unslash( $_POST['smtp_user'] ?? '' ) );

        // Only overwrite password if a new one was typed (non-empty)
        $new_pass = wp_unslash( $_POST['smtp_pass'] ?? '' );
        if ( $new_pass !== '' ) {
            $opts['smtp_pass'] = tennis_pro_smtp_encode( $new_pass );
        }

        // Free-slot colours
        $opts['slot_free_odd_bg']    = sanitize_hex_color( wp_unslash( $_POST['slot_free_odd_bg']   ?? '#f0f4ff' ) ) ?: '#f0f4ff';
        $opts['slot_free_odd_text']  = sanitize_hex_color( wp_unslash( $_POST['slot_free_odd_text'] ?? '#aaaaaa' ) ) ?: '#aaaaaa';
        $opts['slot_free_even_bg']   = sanitize_hex_color( wp_unslash( $_POST['slot_free_even_bg']  ?? '#e8f5e9' ) ) ?: '#e8f5e9';
        $opts['slot_free_even_text'] = sanitize_hex_color( wp_unslash( $_POST['slot_free_even_text']?? '#aaaaaa' ) ) ?: '#aaaaaa';

        // Time-column colours
        $opts['time_col_bg']   = sanitize_hex_color( wp_unslash( $_POST['time_col_bg']   ?? '#1565c0' ) ) ?: '#1565c0';
        $opts['time_col_text'] = sanitize_hex_color( wp_unslash( $_POST['time_col_text'] ?? '#ffffff' ) ) ?: '#ffffff';

        // Buchungshorizont & Stornierungsfrist
        $opts['booking_horizon'] = max( 0, (int) ( $_POST['booking_horizon'] ?? 0 ) );
        $opts['cancel_deadline'] = max( 0, (int) ( $_POST['cancel_deadline'] ?? 0 ) );

        // CI / Vereinsfarben – Topbar
        $opts['ci_topbar_bg']   = sanitize_hex_color( wp_unslash( $_POST['ci_topbar_bg']   ?? '#1b5e20' ) ) ?: '#1b5e20';
        $opts['ci_topbar_bg2']  = sanitize_hex_color( wp_unslash( $_POST['ci_topbar_bg2']  ?? '#0d47a1' ) ) ?: '#0d47a1';
        $opts['ci_topbar_text'] = sanitize_hex_color( wp_unslash( $_POST['ci_topbar_text'] ?? '#ffffff' ) ) ?: '#ffffff';
        $opts['ci_primary']     = sanitize_hex_color( wp_unslash( $_POST['ci_primary']     ?? '#2e7d32' ) ) ?: '#2e7d32';
        // CI / Vereinsfarben – Datebar
        $opts['ci_datebar_bg']   = sanitize_hex_color( wp_unslash( $_POST['ci_datebar_bg']   ?? '#388e3c' ) ) ?: '#388e3c';
        $opts['ci_datebar_bg2']  = sanitize_hex_color( wp_unslash( $_POST['ci_datebar_bg2']  ?? '#1565c0' ) ) ?: '#1565c0';
        $opts['ci_datebar_text'] = sanitize_hex_color( wp_unslash( $_POST['ci_datebar_text'] ?? '#ffffff' ) ) ?: '#ffffff';
        // Legende
        $opts['legend_position']     = ( wp_unslash( $_POST['legend_position'] ?? '' ) === 'top' ) ? 'top' : 'bottom';
        $opts['legend_default_open'] = isset( $_POST['legend_default_open'] ) ? 1 : 0;
        $allowed_fonts = [ '', 'Arial, sans-serif', 'Verdana, sans-serif', 'Trebuchet MS, Trebuchet, sans-serif',
                           'Georgia, serif', "'Roboto', sans-serif", "'Open Sans', sans-serif", "'Lato', sans-serif" ];
        $raw_font                = wp_unslash( $_POST['ci_font_family'] ?? '' );
        $opts['ci_font_family']  = in_array( $raw_font, $allowed_fonts, true ) ? $raw_font : '';

        // Benutzer-Funktionen
        $opts['show_register_btn'] = isset( $_POST['show_register_btn'] ) ? 1 : 0;
        $opts['register_page_id']  = max( 0, (int) ( $_POST['register_page_id'] ?? 0 ) );
        $opts['show_profile_btn']  = isset( $_POST['show_profile_btn'] ) ? 1 : 0;
        $opts['profile_page_id']   = max( 0, (int) ( $_POST['profile_page_id'] ?? 0 ) );
        $opts['register_optin']    = isset( $_POST['register_optin'] ) ? 1 : 0;
        $opts['privacy_page_id']   = max( 0, (int) ( $_POST['privacy_page_id'] ?? 0 ) );

        // Warteliste
        $opts['waitlist_sequential'] = isset( $_POST['waitlist_sequential'] ) ? 1 : 0;

        // My Calendar integration
        $opts['mycal_enabled']    = isset( $_POST['mycal_enabled'] ) ? 1 : 0;
        $raw_cats = isset( $_POST['mycal_categories'] ) && is_array( $_POST['mycal_categories'] )
            ? array_map( 'intval', $_POST['mycal_categories'] ) : [];
        $opts['mycal_categories'] = implode( ',', array_filter( $raw_cats ) );
        $opts['mycal_horizon']    = max( 1, min( 365, (int) ( $_POST['mycal_horizon'] ?? 30 ) ) );
        $raw_courts = isset( $_POST['mycal_courts'] ) && is_array( $_POST['mycal_courts'] )
            ? array_map( 'intval', $_POST['mycal_courts'] ) : [];
        $opts['mycal_courts']     = implode( ',', array_filter( $raw_courts ) );

        update_option( 'tennis_pro_settings', $opts );
        tennis_pro_invalidate_settings_cache();
        $saved = true;
    }

    $s          = tennis_pro_get_settings();
    $test_nonce = wp_create_nonce( 'tennis_test_email_nonce' );

    // My Calendar categories & courts for the Kalender tab
    global $wpdb;
    $mycal_tbl  = $wpdb->prefix . 'my_calendar';
    $mycal_cats = [];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$mycal_tbl}'" ) === $mycal_tbl ) {
        $mycal_cat_tbl = $wpdb->prefix . 'my_calendar_categories';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$mycal_cat_tbl}'" ) === $mycal_cat_tbl ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $mycal_cats = (array) $wpdb->get_results( "SELECT category_id, category_name, category_color FROM {$mycal_cat_tbl} ORDER BY category_name" );
        }
    }
    $saved_mycal_cats   = array_filter( array_map( 'intval', explode( ',', $s['mycal_categories'] ?? '' ) ) );
    $saved_mycal_courts = array_filter( array_map( 'intval', explode( ',', $s['mycal_courts']     ?? '' ) ) );
    $all_courts_for_mcal = tennis_pro_get_courts();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Tennis Pro – Optionen', 'tennis-pro' ); ?></h1>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Einstellungen gespeichert.', 'tennis-pro' ); ?></p></div>
        <?php endif; ?>

        <!-- ══ TAB NAVIGATION ══════════════════════════════════════════════ -->
        <style>
        .tnp-stabs { display:flex; gap:0; margin:16px 0 0; border-bottom:1px solid #c3c4c7; flex-wrap:wrap; }
        .tnp-stab-btn {
            background:#f0f0f1; border:1px solid #c3c4c7; border-bottom:none;
            padding:8px 16px; cursor:pointer; font-size:13px; font-weight:500;
            border-radius:3px 3px 0 0; color:#2c3338; margin-bottom:-1px;
            position:relative; transition:background .1s;
        }
        .tnp-stab-btn:hover { background:#fff; color:#2271b1; }
        .tnp-stab-btn.is-active { background:#fff; color:#1d2327; font-weight:600; border-bottom-color:#fff; z-index:1; }
        .tnp-stab-panel { display:none; padding:20px 0 0; }
        .tnp-stab-panel.is-active { display:block; }
        </style>

        <nav class="tnp-stabs" id="tnp-settings-tabs" role="tablist">
            <button type="button" class="tnp-stab-btn" data-tab="buchung" role="tab">📋 <?php esc_html_e( 'Buchung', 'tennis-pro' ); ?></button>
            <button type="button" class="tnp-stab-btn" data-tab="design"  role="tab">🎨 <?php esc_html_e( 'Design', 'tennis-pro' ); ?></button>
            <button type="button" class="tnp-stab-btn" data-tab="benutzer" role="tab">👤 <?php esc_html_e( 'Benutzer', 'tennis-pro' ); ?></button>
            <button type="button" class="tnp-stab-btn" data-tab="email"   role="tab">📧 <?php esc_html_e( 'E-Mail & SMTP', 'tennis-pro' ); ?></button>
            <button type="button" class="tnp-stab-btn" data-tab="kalender" role="tab">📅 <?php esc_html_e( 'My Calendar', 'tennis-pro' ); ?></button>
            <button type="button" class="tnp-stab-btn" data-tab="system"   role="tab">⚙️ <?php esc_html_e( 'System', 'tennis-pro' ); ?></button>
        </nav>

        <form method="POST" style="max-width:720px" id="tnp-options-form">
            <?php wp_nonce_field( 'tennis_options_save', 'tennis_options_nonce' ); ?>

            <!-- ══ TAB: BUCHUNG ══════════════════════════════════════════════ -->
            <div class="tnp-stab-panel" id="tnp-tab-buchung">
                <h2><?php esc_html_e( 'Buchungsregeln', 'tennis-pro' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Max. Buchungen pro Nutzer', 'tennis-pro' ); ?></th>
                        <td>
                            <input type="number" name="booking_limit" min="0" value="<?php echo (int) $s['booking_limit']; ?>" class="small-text">
                            <p class="description"><?php esc_html_e( '0 = unbegrenzt. Admins sind immer ausgenommen.', 'tennis-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Zeitraum', 'tennis-pro' ); ?></th>
                        <td>
                            <select name="booking_limit_period">
                                <option value="day"  <?php selected( $s['booking_limit_period'], 'day' ); ?>><?php esc_html_e( 'Pro Tag', 'tennis-pro' ); ?></option>
                                <option value="week" <?php selected( $s['booking_limit_period'], 'week' ); ?>><?php esc_html_e( 'Pro Woche', 'tennis-pro' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Buchungshorizont', 'tennis-pro' ); ?></th>
                        <td>
                            <input type="number" name="booking_horizon" min="0" max="365" value="<?php echo (int) $s['booking_horizon']; ?>" class="small-text">
                            <?php esc_html_e( 'Tage im Voraus', 'tennis-pro' ); ?>
                            <p class="description"><?php esc_html_e( '0 = unbegrenzt. Mitglieder können nicht weiter als X Tage buchen. Admins sind ausgenommen.', 'tennis-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Stornierungsfrist', 'tennis-pro' ); ?></th>
                        <td>
                            <input type="number" name="cancel_deadline" min="0" max="168" value="<?php echo (int) $s['cancel_deadline']; ?>" class="small-text">
                            <?php esc_html_e( 'Stunden vor Termin', 'tennis-pro' ); ?>
                            <p class="description"><?php esc_html_e( '0 = jederzeit stornierbar. Admins sind ausgenommen.', 'tennis-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Warteliste – Benachrichtigung', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="waitlist_sequential" value="1" <?php checked( $s['waitlist_sequential'] ?? 1 ); ?>>
                                <?php esc_html_e( 'Sequenziell: nur den ersten Wartenden benachrichtigen (empfohlen)', 'tennis-pro' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Deaktiviert: alle Wartenden erhalten gleichzeitig eine E-Mail (Wettrennen-Effekt).', 'tennis-pro' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Einstellungen speichern', 'tennis-pro' ) ); ?>
            </div>

            <!-- ══ TAB: DESIGN ══════════════════════════════════════════════ -->
            <div class="tnp-stab-panel" id="tnp-tab-design">
                <h2><?php esc_html_e( 'Vereins-CI / Design', 'tennis-pro' ); ?></h2>
                <p class="description" style="margin-bottom:12px"><?php esc_html_e( 'Passen Sie Farben und Schrift des Buchungs-Widgets an die Corporate Identity Ihres Vereins an.', 'tennis-pro' ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Header – Farbverlauf', 'tennis-pro' ); ?></th>
                        <td style="display:flex;align-items:center;gap:24px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Farbe links', 'tennis-pro' ); ?></span>
                                <input type="color" name="ci_topbar_bg" value="<?php echo esc_attr( $s['ci_topbar_bg'] ?? '#1b5e20' ); ?>">
                            </label>
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Farbe rechts', 'tennis-pro' ); ?></span>
                                <input type="color" name="ci_topbar_bg2" value="<?php echo esc_attr( $s['ci_topbar_bg2'] ?? '#0d47a1' ); ?>">
                            </label>
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Schrift', 'tennis-pro' ); ?></span>
                                <input type="color" name="ci_topbar_text" value="<?php echo esc_attr( $s['ci_topbar_text'] ?? '#ffffff' ); ?>">
                            </label>
                            <span style="padding:8px 20px;border-radius:6px;font-size:13px;font-weight:700;background:linear-gradient(135deg,<?php echo esc_attr($s['ci_topbar_bg']??'#1b5e20'); ?> 0%,<?php echo esc_attr($s['ci_topbar_bg2']??'#0d47a1'); ?> 100%);color:<?php echo esc_attr($s['ci_topbar_text']??'#ffffff'); ?>">🎾 <?php esc_html_e( 'Vorschau Header', 'tennis-pro' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Primärfarbe (Buttons)', 'tennis-pro' ); ?></th>
                        <td style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:6px">
                                <input type="color" name="ci_primary" value="<?php echo esc_attr( $s['ci_primary'] ?? '#2e7d32' ); ?>">
                            </label>
                            <span style="padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;background:<?php echo esc_attr($s['ci_primary']??'#2e7d32'); ?>">✅ <?php esc_html_e( 'Reservieren', 'tennis-pro' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Schriftfamilie', 'tennis-pro' ); ?></th>
                        <td>
                            <select name="ci_font_family">
                                <option value=""                                        <?php selected( $s['ci_font_family'] ?? '', '' ); ?>><?php esc_html_e( '— Theme-Standard —', 'tennis-pro' ); ?></option>
                                <option value="Arial, sans-serif"                      <?php selected( $s['ci_font_family'] ?? '', 'Arial, sans-serif' ); ?>>Arial</option>
                                <option value="Verdana, sans-serif"                    <?php selected( $s['ci_font_family'] ?? '', 'Verdana, sans-serif' ); ?>>Verdana</option>
                                <option value="Trebuchet MS, Trebuchet, sans-serif"    <?php selected( $s['ci_font_family'] ?? '', 'Trebuchet MS, Trebuchet, sans-serif' ); ?>>Trebuchet MS</option>
                                <option value="Georgia, serif"                         <?php selected( $s['ci_font_family'] ?? '', 'Georgia, serif' ); ?>>Georgia</option>
                                <option value="'Roboto', sans-serif"                   <?php selected( $s['ci_font_family'] ?? '', "'Roboto', sans-serif" ); ?>>Roboto (Google Font)</option>
                                <option value="'Open Sans', sans-serif"                <?php selected( $s['ci_font_family'] ?? '', "'Open Sans', sans-serif" ); ?>>Open Sans (Google Font)</option>
                                <option value="'Lato', sans-serif"                     <?php selected( $s['ci_font_family'] ?? '', "'Lato', sans-serif" ); ?>>Lato (Google Font)</option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Google Fonts müssen separat im Theme eingebunden sein, damit sie hier wirken.', 'tennis-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Navigationsleiste (2. Zeile)', 'tennis-pro' ); ?></th>
                        <td style="display:flex;align-items:center;gap:24px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Farbe links', 'tennis-pro' ); ?></span>
                                <input type="color" name="ci_datebar_bg" value="<?php echo esc_attr( $s['ci_datebar_bg'] ?? '#388e3c' ); ?>">
                            </label>
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Farbe rechts', 'tennis-pro' ); ?></span>
                                <input type="color" name="ci_datebar_bg2" value="<?php echo esc_attr( $s['ci_datebar_bg2'] ?? '#1565c0' ); ?>">
                            </label>
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Schrift &amp; Buttons', 'tennis-pro' ); ?></span>
                                <input type="color" name="ci_datebar_text" value="<?php echo esc_attr( $s['ci_datebar_text'] ?? '#ffffff' ); ?>">
                            </label>
                            <span style="padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;background:linear-gradient(135deg,<?php echo esc_attr($s['ci_datebar_bg']??'#388e3c'); ?> 0%,<?php echo esc_attr($s['ci_datebar_bg2']??'#1565c0'); ?> 100%);color:<?php echo esc_attr($s['ci_datebar_text']??'#ffffff'); ?>">‹ Woche › &nbsp; Tag &nbsp; Heute</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Legende', 'tennis-pro' ); ?></th>
                        <td>
                            <fieldset>
                                <label style="display:block;margin-bottom:6px">
                                    <span><?php esc_html_e( 'Position:', 'tennis-pro' ); ?></span>
                                    <select name="legend_position" style="margin-left:6px">
                                        <option value="bottom" <?php selected( $s['legend_position'] ?? 'bottom', 'bottom' ); ?>><?php esc_html_e( 'Unterhalb der Tabelle', 'tennis-pro' ); ?></option>
                                        <option value="top"    <?php selected( $s['legend_position'] ?? 'bottom', 'top'    ); ?>><?php esc_html_e( 'Oberhalb der Tabelle', 'tennis-pro' ); ?></option>
                                    </select>
                                </label>
                                <label style="display:block">
                                    <input type="checkbox" name="legend_default_open" value="1" <?php checked( $s['legend_default_open'] ?? 1 ); ?>>
                                    <?php esc_html_e( 'Standardmäßig aufgeklappt anzeigen', 'tennis-pro' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Darstellung – Freie Slots', 'tennis-pro' ); ?></h2>
                <p class="description" style="margin-bottom:12px"><?php esc_html_e( 'Farben für freie Buchungsslots im Frontend. Ungerade/gerade Zeilen wechseln ab.', 'tennis-pro' ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Ungerade Zeile', 'tennis-pro' ); ?></th>
                        <td style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Hintergrund', 'tennis-pro' ); ?></span>
                                <input type="color" name="slot_free_odd_bg" value="<?php echo esc_attr( $s['slot_free_odd_bg'] ); ?>">
                            </label>
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Schrift', 'tennis-pro' ); ?></span>
                                <input type="color" name="slot_free_odd_text" value="<?php echo esc_attr( $s['slot_free_odd_text'] ); ?>">
                            </label>
                            <span style="padding:4px 14px;border-radius:4px;font-size:12px;background:<?php echo esc_attr($s['slot_free_odd_bg']); ?>;color:<?php echo esc_attr($s['slot_free_odd_text']); ?>">Vorschau +</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Gerade Zeile', 'tennis-pro' ); ?></th>
                        <td style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Hintergrund', 'tennis-pro' ); ?></span>
                                <input type="color" name="slot_free_even_bg" value="<?php echo esc_attr( $s['slot_free_even_bg'] ); ?>">
                            </label>
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Schrift', 'tennis-pro' ); ?></span>
                                <input type="color" name="slot_free_even_text" value="<?php echo esc_attr( $s['slot_free_even_text'] ); ?>">
                            </label>
                            <span style="padding:4px 14px;border-radius:4px;font-size:12px;background:<?php echo esc_attr($s['slot_free_even_bg']); ?>;color:<?php echo esc_attr($s['slot_free_even_text']); ?>">Vorschau +</span>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Darstellung – Uhrzeit-Spalte', 'tennis-pro' ); ?></h2>
                <p class="description" style="margin-bottom:12px"><?php esc_html_e( 'Hintergrund und Schriftfarbe der Uhrzeitspalte im Frontend.', 'tennis-pro' ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Uhrzeit-Spalte', 'tennis-pro' ); ?></th>
                        <td style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Hintergrund', 'tennis-pro' ); ?></span>
                                <input type="color" name="time_col_bg" value="<?php echo esc_attr( $s['time_col_bg'] ); ?>">
                            </label>
                            <label style="display:flex;align-items:center;gap:6px">
                                <span><?php esc_html_e( 'Schrift', 'tennis-pro' ); ?></span>
                                <input type="color" name="time_col_text" value="<?php echo esc_attr( $s['time_col_text'] ); ?>">
                            </label>
                            <span style="padding:4px 14px;border-radius:4px;font-size:12px;font-weight:600;background:<?php echo esc_attr($s['time_col_bg']); ?>;color:<?php echo esc_attr($s['time_col_text']); ?>">08:00</span>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Einstellungen speichern', 'tennis-pro' ) ); ?>
            </div>

            <!-- ══ TAB: BENUTZER ══════════════════════════════════════════════ -->
            <div class="tnp-stab-panel" id="tnp-tab-benutzer">
                <h2><?php esc_html_e( 'Benutzer-Funktionen', 'tennis-pro' ); ?></h2>
                <p class="description" style="margin-bottom:12px">
                    <?php esc_html_e( 'Erstellt Seiten mit den Shortcodes [tennis_pro_register] und [tennis_pro_profile], wählt sie unten aus und aktiviert dann die Buttons.', 'tennis-pro' ); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Registrieren-Button', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_register_btn" value="1" <?php checked( $s['show_register_btn'] ?? 0 ); ?>>
                                <?php esc_html_e( 'Im Frontend für nicht eingeloggte Nutzer anzeigen', 'tennis-pro' ); ?>
                            </label>
                            <p class="description" style="margin-top:8px"><?php esc_html_e( 'Registrierungsseite (muss Shortcode [tennis_pro_register] enthalten):', 'tennis-pro' ); ?></p>
                            <?php
                            wp_dropdown_pages( [
                                'name'              => 'register_page_id',
                                'selected'          => (int) ( $s['register_page_id'] ?? 0 ),
                                'show_option_none'  => __( '— Seite wählen —', 'tennis-pro' ),
                                'option_none_value' => 0,
                            ] );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Mein-Profil-Button', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_profile_btn" value="1" <?php checked( $s['show_profile_btn'] ?? 0 ); ?>>
                                <?php esc_html_e( 'Im Frontend für eingeloggte Nutzer anzeigen', 'tennis-pro' ); ?>
                            </label>
                            <p class="description" style="margin-top:8px"><?php esc_html_e( 'Profilseite (muss Shortcode [tennis_pro_profile] enthalten):', 'tennis-pro' ); ?></p>
                            <?php
                            wp_dropdown_pages( [
                                'name'              => 'profile_page_id',
                                'selected'          => (int) ( $s['profile_page_id'] ?? 0 ),
                                'show_option_none'  => __( '— Seite wählen —', 'tennis-pro' ),
                                'option_none_value' => 0,
                            ] );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'E-Mail-Opt-In', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="register_optin" value="1" <?php checked( $s['register_optin'] ?? 1 ); ?>>
                                <?php esc_html_e( 'Neue Nutzer müssen ihre E-Mail-Adresse per Bestätigungslink verifizieren (empfohlen)', 'tennis-pro' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Nach der Registrierung wird eine Bestätigungsmail versendet. Der Nutzer kann sich erst anmelden, nachdem er den Link geklickt hat. Nutzer, die vor Aktivierung dieser Option angelegt wurden, sind nicht betroffen.', 'tennis-pro' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Datenschutzseite', 'tennis-pro' ); ?></th>
                        <td>
                            <?php
                            wp_dropdown_pages( [
                                'name'              => 'privacy_page_id',
                                'selected'          => (int) ( $s['privacy_page_id'] ?? 0 ),
                                'show_option_none'  => __( '— Nicht verknüpfen —', 'tennis-pro' ),
                                'option_none_value' => 0,
                            ] );
                            ?>
                            <p class="description">
                                <?php esc_html_e( 'Wenn eine Seite ausgewählt ist, erscheint im Registrierungsformular eine Pflichtcheckbox mit Link zur Datenschutzerklärung. Leer lassen = kein Datenschutzhinweis im Formular.', 'tennis-pro' ); ?>
                            </p>
                            <?php
                            // Fallback hint: WordPress privacy page
                            $wp_priv = (int) get_option( 'wp_page_for_privacy_policy', 0 );
                            if ( ! (int) ( $s['privacy_page_id'] ?? 0 ) && $wp_priv > 0 ) :
                            ?>
                                <p class="description" style="color:#2271b1">
                                    <?php printf(
                                        /* translators: %s = page title */
                                        esc_html__( 'Hinweis: WordPress-Datenschutzseite „%s" ist vorhanden – oben auswählen, um sie zu verknüpfen.', 'tennis-pro' ),
                                        esc_html( get_the_title( $wp_priv ) )
                                    ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Einstellungen speichern', 'tennis-pro' ) ); ?>
            </div>

            <!-- ══ TAB: E-MAIL & SMTP ════════════════════════════════════════ -->
            <div class="tnp-stab-panel" id="tnp-tab-email">
                <h2><?php esc_html_e( 'E-Mail-Benachrichtigungen', 'tennis-pro' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Benachrichtigungen aktiv', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="email_notifications" value="1" <?php checked( $s['email_notifications'] ); ?>>
                                <?php esc_html_e( 'Buchungsbestätigung und Storno-Mail an Nutzer senden', 'tennis-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Absender Name', 'tennis-pro' ); ?></th>
                        <td><input type="text" name="email_from_name" value="<?php echo esc_attr( $s['email_from_name'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Absender E-Mail', 'tennis-pro' ); ?></th>
                        <td><input type="email" name="email_from_address" value="<?php echo esc_attr( $s['email_from_address'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Admin benachrichtigen', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="notify_admin" value="1" <?php checked( $s['notify_admin'] ); ?>>
                                <?php esc_html_e( 'Bei jeder neuen Buchung eine Kopie an Admin senden', 'tennis-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Admin E-Mail', 'tennis-pro' ); ?></th>
                        <td><input type="email" name="admin_notify_email" value="<?php echo esc_attr( $s['admin_notify_email'] ); ?>" class="regular-text"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'SMTP-Einstellungen', 'tennis-pro' ); ?></h2>
                <p class="description" style="margin-bottom:12px">
                    <?php esc_html_e( 'Wenn kein SMTP konfiguriert ist, verwendet WordPress die PHP-Funktion mail(), die auf vielen Servern geblockt ist. Mit SMTP werden E-Mails zuverlässig zugestellt.', 'tennis-pro' ); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'SMTP aktivieren', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="smtp_enabled" value="1" id="tnp-smtp-enabled" <?php checked( $s['smtp_enabled'] ); ?>>
                                <?php esc_html_e( 'Eigenen SMTP-Server verwenden', 'tennis-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr class="tnp-smtp-row">
                        <th scope="row"><?php esc_html_e( 'SMTP-Host', 'tennis-pro' ); ?></th>
                        <td>
                            <input type="text" name="smtp_host" value="<?php echo esc_attr( $s['smtp_host'] ); ?>" class="regular-text" placeholder="z.B. smtp.office365.com">
                            <p class="description">
                                <strong><?php esc_html_e( 'Microsoft 365 / Outlook:', 'tennis-pro' ); ?></strong> smtp.office365.com &nbsp;|&nbsp;
                                <strong><?php esc_html_e( 'Gmail:', 'tennis-pro' ); ?></strong> smtp.gmail.com &nbsp;|&nbsp;
                                <strong><?php esc_html_e( 'Eigener Server:', 'tennis-pro' ); ?></strong> mail.meindomain.de
                            </p>
                        </td>
                    </tr>
                    <tr class="tnp-smtp-row">
                        <th scope="row"><?php esc_html_e( 'Port', 'tennis-pro' ); ?></th>
                        <td>
                            <input type="number" name="smtp_port" min="1" max="65535" value="<?php echo (int) $s['smtp_port']; ?>" class="small-text">
                            <p class="description"><?php esc_html_e( '587 (STARTTLS, empfohlen) · 465 (SSL/TLS) · 25 (kein TLS, unsicher)', 'tennis-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr class="tnp-smtp-row">
                        <th scope="row"><?php esc_html_e( 'Verschlüsselung', 'tennis-pro' ); ?></th>
                        <td>
                            <select name="smtp_encryption">
                                <option value="tls"  <?php selected( $s['smtp_encryption'], 'tls' ); ?>><?php esc_html_e( 'STARTTLS (empfohlen, Port 587)', 'tennis-pro' ); ?></option>
                                <option value="ssl"  <?php selected( $s['smtp_encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL/TLS (Port 465)', 'tennis-pro' ); ?></option>
                                <option value="none" <?php selected( $s['smtp_encryption'], 'none' ); ?>><?php esc_html_e( 'Keine (nicht empfohlen)', 'tennis-pro' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="tnp-smtp-row">
                        <th scope="row"><?php esc_html_e( 'Authentifizierung', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="smtp_auth" value="1" <?php checked( $s['smtp_auth'] ); ?>>
                                <?php esc_html_e( 'SMTP AUTH (Username + Passwort)', 'tennis-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr class="tnp-smtp-row">
                        <th scope="row"><?php esc_html_e( 'Benutzername', 'tennis-pro' ); ?></th>
                        <td>
                            <input type="text" name="smtp_user" value="<?php echo esc_attr( $s['smtp_user'] ); ?>" class="regular-text" autocomplete="off"
                                   placeholder="<?php esc_attr_e( 'z.B. reservierung@meindomain.de', 'tennis-pro' ); ?>">
                        </td>
                    </tr>
                    <tr class="tnp-smtp-row">
                        <th scope="row"><?php esc_html_e( 'Passwort', 'tennis-pro' ); ?></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <input type="password" name="smtp_pass" id="tnp-smtp-pass" value="" class="regular-text" autocomplete="new-password"
                                       placeholder="<?php echo $s['smtp_pass'] ? esc_attr__( '(gespeichert – leer lassen zum Beibehalten)', 'tennis-pro' ) : esc_attr__( 'Passwort eingeben…', 'tennis-pro' ); ?>">
                                <button type="button" class="button button-small" id="tnp-toggle-pass" title="<?php esc_attr_e( 'Passwort anzeigen/verbergen', 'tennis-pro' ); ?>">👁</button>
                            </div>
                            <p class="description">
                                <?php esc_html_e( 'Leer lassen, um das gespeicherte Passwort beizubehalten.', 'tennis-pro' ); ?>
                                <?php esc_html_e( 'Für Microsoft 365: App-Passwort aus dem Microsoft-Konto verwenden.', 'tennis-pro' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr class="tnp-smtp-row">
                        <th scope="row"><?php esc_html_e( 'Hinweis Microsoft 365', 'tennis-pro' ); ?></th>
                        <td>
                            <details>
                                <summary style="cursor:pointer;color:#2271b1"><?php esc_html_e( 'Einrichtung für Microsoft 365 / Outlook anzeigen', 'tennis-pro' ); ?></summary>
                                <div style="margin-top:8px;padding:12px;background:#f6f7f7;border-left:3px solid #2271b1;font-size:13px">
                                    <ol style="margin:0 0 0 16px;padding:0">
                                        <li><?php esc_html_e( 'Microsoft-Konto → Einstellungen → Sicherheit → Erweiterte Sicherheitsoptionen', 'tennis-pro' ); ?></li>
                                        <li><?php esc_html_e( '"App-Passwörter" aktivieren und ein neues App-Passwort für "Tennis Plugin" anlegen', 'tennis-pro' ); ?></li>
                                        <li><?php esc_html_e( 'Host: smtp.office365.com · Port: 587 · Verschlüsselung: STARTTLS', 'tennis-pro' ); ?></li>
                                        <li><?php esc_html_e( 'Benutzername: deine vollständige E-Mail-Adresse · Passwort: das erzeugte App-Passwort', 'tennis-pro' ); ?></li>
                                    </ol>
                                    <p style="margin:8px 0 0;color:#888"><?php esc_html_e( 'Falls "Modern Authentication Only" aktiv ist, muss ein IT-Administrator SMTP AUTH für das Postfach im Exchange Admin Center aktivieren.', 'tennis-pro' ); ?></p>
                                </div>
                            </details>
                        </td>
                    </tr>
                </table>

                <!-- Test-E-Mail (innerhalb dieses Tabs) -->
                <h3 style="margin-top:24px"><?php esc_html_e( 'Test-E-Mail senden', 'tennis-pro' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Sendet eine Test-Mail mit den aktuell gespeicherten Einstellungen.', 'tennis-pro' ); ?></p>
                <div style="display:flex;align-items:center;gap:12px;margin-top:12px">
                    <input type="email" id="tnp-test-email-to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
                           class="regular-text" placeholder="<?php esc_attr_e( 'Empfänger…', 'tennis-pro' ); ?>">
                    <button type="button" class="button button-secondary" id="tnp-send-test-btn">
                        📧 <?php esc_html_e( 'Test senden', 'tennis-pro' ); ?>
                    </button>
                    <span id="tnp-test-spinner" class="spinner" style="float:none;margin:0;visibility:hidden"></span>
                </div>
                <p id="tnp-test-result" style="margin-top:10px;font-weight:600"></p>

                <?php submit_button( __( 'Einstellungen speichern', 'tennis-pro' ) ); ?>
            </div>

            <!-- ══ TAB: MY CALENDAR ══════════════════════════════════════════ -->
            <div class="tnp-stab-panel" id="tnp-tab-kalender">
                <h2><?php esc_html_e( 'My Calendar Integration', 'tennis-pro' ); ?></h2>
                <?php if ( empty( $mycal_cats ) && ! (int) $s['mycal_enabled'] ) : ?>
                    <div class="notice notice-warning inline" style="margin-left:0"><p>
                        <?php esc_html_e( 'Das Plugin "My Calendar" wurde nicht gefunden oder hat noch keine Kategorien. Bitte zunächst My Calendar installieren und Kategorien anlegen.', 'tennis-pro' ); ?>
                    </p></div>
                <?php else : ?>
                <p class="description" style="margin-bottom:12px">
                    <?php esc_html_e( 'My-Calendar-Termine werden automatisch als gesperrte Slots im Buchungsraster angezeigt. Nur Termine der ausgewählten Kategorien werden berücksichtigt.', 'tennis-pro' ); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Integration aktivieren', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mycal_enabled" value="1" id="tnp-mycal-enabled" <?php checked( $s['mycal_enabled'] ); ?>>
                                <?php esc_html_e( 'My-Calendar-Termine in Buchungsraster einblenden', 'tennis-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr class="tnp-mycal-row">
                        <th scope="row"><?php esc_html_e( 'Kategorien', 'tennis-pro' ); ?></th>
                        <td>
                            <?php if ( empty( $mycal_cats ) ) : ?>
                                <em style="color:#888"><?php esc_html_e( 'Keine Kategorien gefunden.', 'tennis-pro' ); ?></em>
                            <?php else : ?>
                            <div style="display:flex;flex-wrap:wrap;gap:8px">
                                <?php foreach ( $mycal_cats as $mc ) :
                                    $checked = in_array( (int) $mc->category_id, $saved_mycal_cats, true );
                                    $raw_col = trim( $mc->category_color ?? '' );
                                    $color   = $raw_col ? ( '#' . ltrim( $raw_col, '#' ) ) : '#0277bd';
                                ?>
                                <label style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;border:2px solid <?php echo esc_attr($color); ?>;cursor:pointer;font-size:13px">
                                    <input type="checkbox" name="mycal_categories[]" value="<?php echo (int) $mc->category_id; ?>" <?php checked( $checked ); ?>>
                                    <span style="width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr($color); ?>;display:inline-block"></span>
                                    <?php echo esc_html( $mc->category_name ); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description" style="margin-top:6px"><?php esc_html_e( 'Nur Termine dieser Kategorien werden automatisch gesperrt.', 'tennis-pro' ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="tnp-mycal-row">
                        <th scope="row"><?php esc_html_e( 'Zeitraum (Vorschau)', 'tennis-pro' ); ?></th>
                        <td>
                            <select name="mycal_horizon">
                                <?php foreach ( [ 7 => '7 Tage', 14 => '14 Tage', 30 => '1 Monat', 60 => '2 Monate', 90 => '3 Monate', 180 => '6 Monate', 365 => '1 Jahr' ] as $days => $label ) : ?>
                                    <option value="<?php echo $days; ?>" <?php selected( (int) $s['mycal_horizon'], $days ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'My-Calendar-Termine werden nur für diesen Zeitraum ab heute angezeigt.', 'tennis-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr class="tnp-mycal-row">
                        <th scope="row"><?php esc_html_e( 'Gilt für Plätze', 'tennis-pro' ); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:6px">
                                <input type="checkbox" id="tnp-mycal-allcourts" <?php checked( empty( $saved_mycal_courts ) ); ?>>
                                <strong><?php esc_html_e( 'Alle Plätze', 'tennis-pro' ); ?></strong>
                            </label>
                            <div id="tnp-mycal-courts-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;<?php echo empty( $saved_mycal_courts ) ? 'opacity:.4;pointer-events:none' : ''; ?>">
                                <?php foreach ( $all_courts_for_mcal as $court ) :
                                    $is_sel = in_array( (int) $court->id, $saved_mycal_courts, true );
                                ?>
                                <label style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;border:2px solid <?php echo esc_attr($court->bg_color ?: '#2e7d32'); ?>;cursor:pointer;font-size:13px">
                                    <input type="checkbox" name="mycal_courts[]" value="<?php echo (int) $court->id; ?>" <?php checked( $is_sel ); ?>>
                                    <?php echo esc_html( $court->name ); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description" style="margin-top:6px"><?php esc_html_e( 'Leer lassen = alle Plätze werden gesperrt.', 'tennis-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr class="tnp-mycal-row">
                        <th scope="row"><?php esc_html_e( 'Diagnose', 'tennis-pro' ); ?></th>
                        <td>
                            <button type="button" id="tnp-mycal-test" class="button button-secondary">
                                🔍 <?php esc_html_e( 'Verbindung testen', 'tennis-pro' ); ?>
                            </button>
                            <span id="tnp-mycal-test-spinner" style="display:none;margin-left:8px">⏳</span>
                            <div id="tnp-mycal-test-result" style="margin-top:8px;padding:10px;background:#f8f8f8;border:1px solid #ddd;border-radius:4px;font-family:monospace;font-size:12px;white-space:pre-wrap;display:none"></div>
                            <p class="description" style="margin-top:4px"><?php esc_html_e( 'Prüft die gespeicherten Einstellungen und zeigt gefundene My-Calendar-Termine an.', 'tennis-pro' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>
                <?php submit_button( __( 'Einstellungen speichern', 'tennis-pro' ) ); ?>
            </div>

            <!-- ══ TAB: SYSTEM ═══════════════════════════════════════════════ -->
            <div class="tnp-stab-panel" id="tnp-tab-system">
                <h2><?php esc_html_e( 'System', 'tennis-pro' ); ?></h2>
                <p class="description" style="margin-bottom:12px">
                    <?php esc_html_e( 'Systemeinstellungen und Deinstallationsoptionen.', 'tennis-pro' ); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Daten beim Löschen entfernen', 'tennis-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>>
                                <?php esc_html_e( 'Alle Tabellen und Einstellungen beim Deinstallieren des Plugins löschen', 'tennis-pro' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e">
                                <?php esc_html_e( '⚠️ Vorsicht: Diese Option löscht alle Buchungen, Plätze, Kategorien und Einstellungen unwiederbringlich, sobald das Plugin gelöscht wird. Standard: deaktiviert (Daten bleiben erhalten).', 'tennis-pro' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Plugin-Version', 'tennis-pro' ); ?></th>
                        <td>
                            <code><?php echo esc_html( TENNIS_PRO_VER ); ?></code>
                            &nbsp;·&nbsp;
                            <span style="color:#888"><?php printf( esc_html__( 'DB-Schema %s', 'tennis-pro' ), esc_html( TENNIS_PRO_DB_VER ) ); ?></span>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Einstellungen speichern', 'tennis-pro' ), 'secondary' ); ?>
            </div>

        </form>
    </div>

    <script>
    (function() {

        /* ── Tab navigation ─────────────────────────────────────────────── */
        const STORAGE_KEY = 'tnp_settings_tab';
        const tabBtns   = document.querySelectorAll('.tnp-stab-btn');
        const tabPanels = document.querySelectorAll('.tnp-stab-panel');

        function activateTab(tabId) {
            tabBtns.forEach(function(btn) {
                btn.classList.toggle('is-active', btn.dataset.tab === tabId);
                btn.setAttribute('aria-selected', btn.dataset.tab === tabId ? 'true' : 'false');
            });
            tabPanels.forEach(function(p) {
                p.classList.toggle('is-active', p.id === 'tnp-tab-' + tabId);
            });
            try { localStorage.setItem(STORAGE_KEY, tabId); } catch(e) {}
        }

        tabBtns.forEach(function(btn) {
            btn.addEventListener('click', function() { activateTab(btn.dataset.tab); });
        });

        // Restore saved tab, fall back to first tab
        var validIds = Array.from(tabBtns).map(function(b) { return b.dataset.tab; });
        var saved;
        try { saved = localStorage.getItem(STORAGE_KEY); } catch(e) {}
        activateTab(validIds.includes(saved) ? saved : validIds[0]);

        /* ── My Calendar: toggle enable checkbox ────────────────────────── */
        const mycalChk  = document.getElementById('tnp-mycal-enabled');
        const mycalRows = document.querySelectorAll('.tnp-mycal-row');
        function toggleMycal() {
            mycalRows.forEach(function(r) { r.style.display = mycalChk?.checked ? '' : 'none'; });
        }
        if (mycalChk) { toggleMycal(); mycalChk.addEventListener('change', toggleMycal); }

        /* ── My Calendar: "Alle Plätze" toggle ─────────────────────────── */
        const allCourtsChk = document.getElementById('tnp-mycal-allcourts');
        const courtsList   = document.getElementById('tnp-mycal-courts-list');
        function toggleCourts() {
            if (!courtsList) return;
            if (allCourtsChk?.checked) {
                courtsList.style.opacity       = '.4';
                courtsList.style.pointerEvents = 'none';
                courtsList.querySelectorAll('input[type=checkbox]').forEach(function(cb) { cb.checked = false; });
            } else {
                courtsList.style.opacity       = '1';
                courtsList.style.pointerEvents = '';
            }
        }
        if (allCourtsChk) { allCourtsChk.addEventListener('change', toggleCourts); }

        /* ── My Calendar: "Verbindung testen" ───────────────────────────── */
        const mycalTestBtn     = document.getElementById('tnp-mycal-test');
        const mycalTestResult  = document.getElementById('tnp-mycal-test-result');
        const mycalTestSpinner = document.getElementById('tnp-mycal-test-spinner');
        mycalTestBtn?.addEventListener('click', function() {
            if (!mycalTestResult) return;
            mycalTestBtn.disabled = true;
            mycalTestSpinner.style.display = 'inline';
            mycalTestResult.style.display  = 'none';
            const fd = new FormData();
            fd.append('action', 'tennis_test_mycal');
            fd.append('nonce',  '<?php echo wp_create_nonce( "tennis_test_mycal" ); ?>');
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    mycalTestResult.textContent   = json.data ?? JSON.stringify(json);
                    mycalTestResult.style.display = 'block';
                })
                .catch(function(e) {
                    mycalTestResult.textContent   = 'Netzwerkfehler: ' + e;
                    mycalTestResult.style.display = 'block';
                })
                .finally(function() {
                    mycalTestBtn.disabled = false;
                    mycalTestSpinner.style.display = 'none';
                });
        });

        /* ── SMTP: toggle rows ───────────────────────────────────────────── */
        const smtpChk  = document.getElementById('tnp-smtp-enabled');
        const smtpRows = document.querySelectorAll('.tnp-smtp-row');
        function toggleSmtp() {
            smtpRows.forEach(function(r) { r.style.display = smtpChk.checked ? '' : 'none'; });
        }
        if (smtpChk) { toggleSmtp(); smtpChk.addEventListener('change', toggleSmtp); }

        /* ── SMTP: toggle password visibility ───────────────────────────── */
        const passInput = document.getElementById('tnp-smtp-pass');
        document.getElementById('tnp-toggle-pass')?.addEventListener('click', function() {
            if (!passInput) return;
            passInput.type = passInput.type === 'password' ? 'text' : 'password';
        });

        /* ── Test-E-Mail ─────────────────────────────────────────────────── */
        const testBtn    = document.getElementById('tnp-send-test-btn');
        const testResult = document.getElementById('tnp-test-result');
        const spinner    = document.getElementById('tnp-test-spinner');

        testBtn?.addEventListener('click', function() {
            const to = document.getElementById('tnp-test-email-to')?.value.trim();
            if (!to) { testResult.textContent = '<?php echo esc_js( __( 'Bitte eine E-Mail-Adresse eingeben.', 'tennis-pro' ) ); ?>'; return; }

            testBtn.disabled           = true;
            spinner.style.visibility  = 'visible';
            testResult.textContent     = '';

            const body = new URLSearchParams({
                action : 'tennis_send_test_email',
                nonce  : '<?php echo esc_js( $test_nonce ); ?>',
                to     : to,
            });
            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : body.toString(),
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                testResult.style.color = res.success ? '#2e7d32' : '#b32d2e';
                testResult.textContent  = res.data?.message || (res.success ? '✅ OK' : '❌ Fehler');
            })
            .catch(function() {
                testResult.style.color = '#b32d2e';
                testResult.textContent = '<?php echo esc_js( __( 'Netzwerkfehler.', 'tennis-pro' ) ); ?>';
            })
            .finally(function() {
                testBtn.disabled = false;
                spinner.style.visibility = 'hidden';
            });
        });

    })();
    </script>
    <?php
}
