<?php
/**
 * Tennis Pro – Benutzer-Registrierung & Profil-Shortcodes
 *
 * [tennis_pro_register]  – Registrierungsformular mit Spam-Schutz & E-Mail-Opt-In
 * [tennis_pro_profile]   – Profil- & Datenverwaltung für eingeloggte Nutzer
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'tennis_pro_register', 'tennis_pro_register_shortcode' );
add_shortcode( 'tennis_pro_profile',  'tennis_pro_profile_shortcode'  );

/* ── E-Mail-Verifikation ──────────────────────────────────────────────── */

add_action( 'init', 'tennis_pro_handle_verification' );

/**
 * Handles the e-mail verification link: ?tnp_verify=TOKEN&uid=ID
 * Marks the user as verified and redirects to the registration page.
 */
function tennis_pro_handle_verification(): void {
    if ( empty( $_GET['tnp_verify'] ) || empty( $_GET['uid'] ) ) return;

    $uid    = (int) $_GET['uid'];
    $token  = sanitize_text_field( wp_unslash( $_GET['tnp_verify'] ) );
    $stored = (string) get_user_meta( $uid, 'tennis_pro_verify_token', true );

    if ( $stored !== '' && hash_equals( $stored, $token ) ) {
        update_user_meta( $uid, 'tennis_pro_verified', 1 );
        delete_user_meta( $uid, 'tennis_pro_verify_token' );

        $s        = tennis_pro_get_settings();
        $base_url = ! empty( $s['register_page_id'] )
            ? (string) get_permalink( (int) $s['register_page_id'] )
            : home_url( '/' );
        wp_safe_redirect( esc_url_raw( add_query_arg( 'tnp_verified', '1', $base_url ) ) );
        exit;
    }

    // Invalid or already-used token – redirect to homepage silently.
    wp_safe_redirect( home_url( '/' ) );
    exit;
}

/* ── Anmeldesperre für unverifizierte Nutzer ─────────────────────────── */

add_filter( 'wp_authenticate_user', 'tennis_pro_check_verified', 10, 2 );

/**
 * Blocks login for users who have not yet confirmed their e-mail address.
 * Only active when register_optin is enabled in plugin settings.
 *
 * @param WP_User|WP_Error $user
 * @param string           $password  (required by filter signature)
 * @return WP_User|WP_Error
 */
function tennis_pro_check_verified( $user, string $password ) {
    if ( is_wp_error( $user ) ) return $user;

    $s = tennis_pro_get_settings();
    if ( empty( $s['register_optin'] ) ) return $user;

    // Old users (registered before opt-in was enabled) have no token meta → allowed.
    if ( (string) get_user_meta( $user->ID, 'tennis_pro_verify_token', true ) !== '' ) {
        return new WP_Error(
            'not_verified',
            __( 'Bitte bestätigen Sie zunächst Ihre E-Mail-Adresse. Prüfen Sie Ihr Postfach – dort finden Sie einen Bestätigungslink.', 'tennis-pro' )
        );
    }
    return $user;
}

/**
 * Send an HTML verification e-mail to a newly registered user.
 */
function tennis_pro_send_verification_email( int $uid, string $token ): void {
    $user = get_userdata( $uid );
    if ( ! $user ) return;

    $site_name  = get_bloginfo( 'name' );
    $verify_url = add_query_arg( [ 'tnp_verify' => $token, 'uid' => $uid ], home_url( '/' ) );

    $subject = sprintf(
        /* translators: %s = site name */
        __( '[%s] Bitte bestätigen Sie Ihre E-Mail-Adresse', 'tennis-pro' ),
        $site_name
    );

    $body = '<p>' . sprintf(
        /* translators: %s = display name */
        esc_html__( 'Hallo %s,', 'tennis-pro' ),
        esc_html( $user->display_name )
    ) . '</p>'
    . '<p>' . sprintf(
        /* translators: %s = site name */
        esc_html__( 'vielen Dank für Ihre Registrierung bei %s!', 'tennis-pro' ),
        esc_html( $site_name )
    ) . '</p>'
    . '<p>' . esc_html__( 'Bitte bestätigen Sie Ihre E-Mail-Adresse, indem Sie auf den Button klicken:', 'tennis-pro' ) . '</p>'
    . '<p style="text-align:center;margin:28px 0">'
    . '<a href="' . esc_url( $verify_url ) . '" '
    . 'style="display:inline-block;padding:12px 28px;background:#2e7d32;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;font-size:15px">'
    . esc_html__( '✉️ E-Mail-Adresse bestätigen', 'tennis-pro' )
    . '</a></p>'
    . '<p style="color:#888;font-size:12px">'
    . esc_html__( 'Oder kopieren Sie diesen Link in Ihren Browser:', 'tennis-pro' ) . '<br>'
    . '<a href="' . esc_url( $verify_url ) . '" style="color:#2271b1">' . esc_html( $verify_url ) . '</a>'
    . '</p>'
    . '<p style="color:#888;font-size:12px">'
    . esc_html__( 'Falls Sie sich nicht registriert haben, können Sie diese E-Mail ignorieren.', 'tennis-pro' )
    . '</p>';

    tennis_pro_send_mail( $user->user_email, $subject, $body );
}

/* ══════════════════════════════════════════════════════════════════════════
   REGISTRIERUNG
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_register_shortcode(): string {

    if ( is_user_logged_in() ) {
        return '<div class="tnp-form-wrap"><p class="tnp-form-notice tnp-form-notice--info">' .
               esc_html__( 'Sie sind bereits angemeldet.', 'tennis-pro' ) . '</p></div>';
    }

    $s         = tennis_pro_get_settings();
    $error     = '';
    $success   = false;
    $optin     = false;
    $vals      = [ 'first_name' => '', 'last_name' => '', 'email' => '' ];

    // E-Mail successfully verified → show confirmation message.
    if ( ! empty( $_GET['tnp_verified'] ) ) {
        $success = true;
        $optin   = false; // show the "verified, login now" message
    } elseif ( ! empty( $_POST['tnp_reg_nonce'] ) ) {
        $result = tennis_pro_handle_register();
        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
            $vals  = (array) ( $result->get_error_data() ?: [] ) + $vals;
        } else {
            $success = true;
            $optin   = ( $result === 'optin' );
        }
    }

    /* Datenschutz-URL ermitteln */
    $privacy_url = '';
    if ( ! empty( $s['privacy_page_id'] ) ) {
        $privacy_url = (string) get_permalink( (int) $s['privacy_page_id'] );
    }
    if ( ! $privacy_url ) {
        $privacy_url = (string) get_privacy_policy_url();
    }

    ob_start();
    ?>
    <div class="tnp-form-wrap">
        <?php if ( $success ) : ?>
            <?php if ( ! empty( $_GET['tnp_verified'] ) ) : ?>
                <div class="tnp-form-notice tnp-form-notice--success">
                    <?php esc_html_e( '✅ Ihre E-Mail-Adresse wurde erfolgreich bestätigt! Sie können sich jetzt anmelden.', 'tennis-pro' ); ?>
                </div>
            <?php elseif ( $optin ) : ?>
                <div class="tnp-form-notice tnp-form-notice--success">
                    <?php esc_html_e( '✅ Registrierung fast abgeschlossen! Wir haben Ihnen eine Bestätigungsmail geschickt. Bitte klicken Sie auf den Link in der E-Mail, um Ihre Registrierung zu aktivieren.', 'tennis-pro' ); ?>
                </div>
            <?php else : ?>
                <div class="tnp-form-notice tnp-form-notice--success">
                    <?php esc_html_e( '✅ Registrierung erfolgreich! Sie können sich jetzt anmelden.', 'tennis-pro' ); ?>
                </div>
            <?php endif; ?>
        <?php else : ?>
            <?php if ( $error ) : ?>
                <div class="tnp-form-notice tnp-form-notice--error"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>

            <form class="tnp-form" method="post" novalidate>
                <?php wp_nonce_field( 'tnp_register_action', 'tnp_reg_nonce' ); ?>
                <input type="hidden" name="tnp_ts" value="<?php echo esc_attr( (string) time() ); ?>">

                <!-- Honeypot: muss leer bleiben -->
                <div class="tnp-hp" aria-hidden="true">
                    <label for="tnp-website">Website</label>
                    <input type="text" id="tnp-website" name="tnp_url" tabindex="-1" autocomplete="off" value="">
                </div>

                <div class="tnp-form__row tnp-form__row--half">
                    <div class="tnp-form__field">
                        <label for="tnp-rf-fn"><?php esc_html_e( 'Vorname', 'tennis-pro' ); ?> <span class="tnp-req">*</span></label>
                        <input type="text" id="tnp-rf-fn" name="first_name" required autocomplete="given-name"
                               value="<?php echo esc_attr( $vals['first_name'] ); ?>">
                    </div>
                    <div class="tnp-form__field">
                        <label for="tnp-rf-ln"><?php esc_html_e( 'Nachname', 'tennis-pro' ); ?> <span class="tnp-req">*</span></label>
                        <input type="text" id="tnp-rf-ln" name="last_name" required autocomplete="family-name"
                               value="<?php echo esc_attr( $vals['last_name'] ); ?>">
                    </div>
                </div>

                <div class="tnp-form__field">
                    <label for="tnp-rf-em"><?php esc_html_e( 'E-Mail-Adresse', 'tennis-pro' ); ?> <span class="tnp-req">*</span></label>
                    <input type="email" id="tnp-rf-em" name="email" required autocomplete="email"
                           value="<?php echo esc_attr( $vals['email'] ); ?>">
                </div>

                <div class="tnp-form__row tnp-form__row--half">
                    <div class="tnp-form__field">
                        <label for="tnp-rf-pw"><?php esc_html_e( 'Passwort', 'tennis-pro' ); ?> <span class="tnp-req">*</span></label>
                        <input type="password" id="tnp-rf-pw" name="password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="tnp-form__field">
                        <label for="tnp-rf-pw2"><?php esc_html_e( 'Passwort bestätigen', 'tennis-pro' ); ?> <span class="tnp-req">*</span></label>
                        <input type="password" id="tnp-rf-pw2" name="password2" required minlength="8" autocomplete="new-password">
                    </div>
                </div>

                <?php if ( $privacy_url ) : ?>
                <div class="tnp-form__field tnp-form__field--check">
                    <label class="tnp-form__check-label">
                        <input type="checkbox" name="tnp_privacy" value="1" required>
                        <?php
                        printf(
                            /* translators: %1$s opening <a> tag, %2$s closing </a> */
                            esc_html__( 'Ich habe die %1$sDatenschutzerklärung%2$s gelesen und stimme zu.', 'tennis-pro' ),
                            '<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">',
                            '</a>'
                        );
                        ?>
                        <span class="tnp-req">*</span>
                    </label>
                </div>
                <?php endif; ?>

                <button type="submit" class="tnp-form__submit">
                    📝 <?php esc_html_e( 'Jetzt registrieren', 'tennis-pro' ); ?>
                </button>
                <p class="tnp-form__hint">
                    <span class="tnp-req">*</span> <?php esc_html_e( 'Pflichtfelder · Passwort mindestens 8 Zeichen', 'tennis-pro' ); ?>
                </p>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Verarbeitet das Registrierungsformular.
 *
 * @return true|'optin'|WP_Error  true = sofort aktiv; 'optin' = Bestätigungs-Mail versendet; WP_Error = Fehler
 */
function tennis_pro_handle_register() {

    /* 1. Nonce */
    if ( ! wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['tnp_reg_nonce'] ?? '' ) ),
        'tnp_register_action'
    ) ) {
        return new WP_Error( 'nonce', __( 'Ungültige Anfrage. Bitte Seite neu laden.', 'tennis-pro' ) );
    }

    /* 2. Honeypot */
    if ( ! empty( $_POST['tnp_url'] ) ) {
        return new WP_Error( 'spam', __( 'Spam-Schutz hat angeschlagen. Bitte erneut versuchen.', 'tennis-pro' ) );
    }

    /* 3. Zeitprüfung: Bots schicken das Formular in Millisekunden ab */
    $ts = (int) ( $_POST['tnp_ts'] ?? 0 );
    if ( $ts > 0 && ( time() - $ts ) < 3 ) {
        return new WP_Error( 'toosoon', __( 'Formular zu schnell abgeschickt. Bitte kurz warten und erneut versuchen.', 'tennis-pro' ) );
    }

    /* 4. Felder einlesen & bereinigen */
    $first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
    $last  = sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) );
    $email = sanitize_email( wp_unslash( $_POST['email']           ?? '' ) );
    $pass  = (string) wp_unslash( $_POST['password']               ?? '' );
    $pass2 = (string) wp_unslash( $_POST['password2']              ?? '' );
    $vals  = [ 'first_name' => $first, 'last_name' => $last, 'email' => $email ];

    if ( ! $first || ! $last ) {
        return new WP_Error( 'name', __( 'Bitte Vor- und Nachname angeben.', 'tennis-pro' ), $vals );
    }
    if ( ! is_email( $email ) ) {
        return new WP_Error( 'email', __( 'Ungültige E-Mail-Adresse.', 'tennis-pro' ), $vals );
    }
    if ( email_exists( $email ) ) {
        return new WP_Error( 'taken', __( 'Diese E-Mail-Adresse ist bereits registriert.', 'tennis-pro' ), $vals );
    }
    if ( strlen( $pass ) < 8 ) {
        return new WP_Error( 'weakpw', __( 'Das Passwort muss mindestens 8 Zeichen lang sein.', 'tennis-pro' ), $vals );
    }
    if ( $pass !== $pass2 ) {
        return new WP_Error( 'mismatch', __( 'Die Passwörter stimmen nicht überein.', 'tennis-pro' ), $vals );
    }

    /* 5. Datenschutz-Checkbox prüfen */
    $s = tennis_pro_get_settings();
    $privacy_url = '';
    if ( ! empty( $s['privacy_page_id'] ) ) {
        $privacy_url = (string) get_permalink( (int) $s['privacy_page_id'] );
    }
    if ( ! $privacy_url ) {
        $privacy_url = (string) get_privacy_policy_url();
    }
    // Only validate if a privacy page is configured
    if ( $privacy_url && empty( $_POST['tnp_privacy'] ) ) {
        return new WP_Error( 'privacy', __( 'Bitte bestätigen Sie, dass Sie die Datenschutzerklärung gelesen haben.', 'tennis-pro' ), $vals );
    }

    /* 6. Eindeutigen Benutzernamen generieren */
    $base = strtolower( sanitize_user( remove_accents( $first . '.' . $last ), true ) );
    if ( ! $base ) {
        $base = strtolower( sanitize_user( strstr( $email, '@', true ), true ) );
    }
    $name = $base;
    $n    = 2;
    while ( username_exists( $name ) ) {
        $name = $base . $n++;
    }

    /* 7. Benutzer anlegen */
    $uid = wp_insert_user( [
        'user_login'   => $name,
        'user_email'   => $email,
        'user_pass'    => $pass,
        'first_name'   => $first,
        'last_name'    => $last,
        'display_name' => trim( $first . ' ' . $last ),
        'role'         => 'subscriber',
    ] );

    if ( is_wp_error( $uid ) ) {
        return new WP_Error( 'create', $uid->get_error_message(), $vals );
    }

    /* 8. Admin informieren */
    wp_new_user_notification( $uid, null, 'admin' );

    /* 9. E-Mail-Opt-In (wenn aktiv) */
    if ( ! empty( $s['register_optin'] ) ) {
        $token = bin2hex( random_bytes( 32 ) ); // 64-char hex token
        update_user_meta( $uid, 'tennis_pro_verify_token', $token );
        tennis_pro_send_verification_email( $uid, $token );
        return 'optin';
    }

    return true;
}

/* ══════════════════════════════════════════════════════════════════════════
   PROFIL / MEINE DATEN
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_profile_shortcode(): string {

    if ( ! is_user_logged_in() ) {
        return '<div class="tnp-form-wrap"><p class="tnp-form-notice tnp-form-notice--info">' .
               wp_kses(
                   sprintf(
                       /* translators: %s: Login-URL */
                       __( 'Bitte <a href="%s">anmelden</a>, um Ihre Daten zu bearbeiten.', 'tennis-pro' ),
                       esc_url( wp_login_url( get_permalink() ) )
                   ),
                   [ 'a' => [ 'href' => [] ] ]
               ) . '</p></div>';
    }

    $user    = wp_get_current_user();
    $msg     = '';
    $msg_cls = 'success';

    if ( ! empty( $_POST['tnp_prf_nonce'] ) ) {
        $result = tennis_pro_handle_profile( $user );
        if ( is_wp_error( $result ) ) {
            $msg     = $result->get_error_message();
            $msg_cls = 'error';
        } else {
            $msg  = __( '✅ Ihre Daten wurden gespeichert.', 'tennis-pro' );
            $user = wp_get_current_user(); // nach Speichern neu laden
        }
    }

    $phone = (string) get_user_meta( $user->ID, 'tennis_pro_phone', true );

    ob_start();
    ?>
    <div class="tnp-form-wrap">
        <h2 class="tnp-form__title">👤 <?php esc_html_e( 'Meine Daten', 'tennis-pro' ); ?></h2>

        <?php if ( $msg ) : ?>
            <div class="tnp-form-notice tnp-form-notice--<?php echo esc_attr( $msg_cls ); ?>">
                <?php echo esc_html( $msg ); ?>
            </div>
        <?php endif; ?>

        <form class="tnp-form" method="post">
            <?php wp_nonce_field( 'tnp_profile_action', 'tnp_prf_nonce' ); ?>

            <div class="tnp-form__row tnp-form__row--half">
                <div class="tnp-form__field">
                    <label for="tnp-pf-fn"><?php esc_html_e( 'Vorname', 'tennis-pro' ); ?></label>
                    <input type="text" id="tnp-pf-fn" name="first_name" autocomplete="given-name"
                           value="<?php echo esc_attr( $user->first_name ); ?>">
                </div>
                <div class="tnp-form__field">
                    <label for="tnp-pf-ln"><?php esc_html_e( 'Nachname', 'tennis-pro' ); ?></label>
                    <input type="text" id="tnp-pf-ln" name="last_name" autocomplete="family-name"
                           value="<?php echo esc_attr( $user->last_name ); ?>">
                </div>
            </div>

            <div class="tnp-form__field">
                <label for="tnp-pf-em"><?php esc_html_e( 'E-Mail-Adresse', 'tennis-pro' ); ?> <span class="tnp-req">*</span></label>
                <input type="email" id="tnp-pf-em" name="email" required autocomplete="email"
                       value="<?php echo esc_attr( $user->user_email ); ?>">
            </div>

            <div class="tnp-form__field">
                <label for="tnp-pf-ph"><?php esc_html_e( 'Telefon', 'tennis-pro' ); ?></label>
                <input type="tel" id="tnp-pf-ph" name="phone" autocomplete="tel"
                       value="<?php echo esc_attr( $phone ); ?>">
            </div>

            <div class="tnp-form__field">
                <label for="tnp-pf-dn"><?php esc_html_e( 'Anzeigename (wird im Kalender angezeigt)', 'tennis-pro' ); ?></label>
                <input type="text" id="tnp-pf-dn" name="display_name" autocomplete="nickname"
                       value="<?php echo esc_attr( $user->display_name ); ?>">
            </div>

            <fieldset class="tnp-form__fieldset">
                <legend><?php esc_html_e( 'Passwort ändern', 'tennis-pro' ); ?></legend>
                <p class="tnp-form__hint"><?php esc_html_e( 'Nur ausfüllen, wenn Sie Ihr Passwort ändern möchten.', 'tennis-pro' ); ?></p>
                <div class="tnp-form__field">
                    <label for="tnp-pf-cp"><?php esc_html_e( 'Aktuelles Passwort', 'tennis-pro' ); ?></label>
                    <input type="password" id="tnp-pf-cp" name="cur_pass" autocomplete="current-password">
                </div>
                <div class="tnp-form__row tnp-form__row--half">
                    <div class="tnp-form__field">
                        <label for="tnp-pf-np"><?php esc_html_e( 'Neues Passwort', 'tennis-pro' ); ?></label>
                        <input type="password" id="tnp-pf-np" name="new_pass" minlength="8" autocomplete="new-password">
                    </div>
                    <div class="tnp-form__field">
                        <label for="tnp-pf-np2"><?php esc_html_e( 'Neues Passwort bestätigen', 'tennis-pro' ); ?></label>
                        <input type="password" id="tnp-pf-np2" name="new_pass2" minlength="8" autocomplete="new-password">
                    </div>
                </div>
            </fieldset>

            <button type="submit" class="tnp-form__submit">
                💾 <?php esc_html_e( 'Speichern', 'tennis-pro' ); ?>
            </button>
        </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Verarbeitet das Profilformular.
 * Gibt true zurück bei Erfolg, WP_Error bei Fehler.
 */
function tennis_pro_handle_profile( WP_User $user ): true|WP_Error {

    if ( ! wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['tnp_prf_nonce'] ?? '' ) ),
        'tnp_profile_action'
    ) ) {
        return new WP_Error( 'nonce', __( 'Ungültige Anfrage.', 'tennis-pro' ) );
    }

    $first   = sanitize_text_field( wp_unslash( $_POST['first_name']   ?? '' ) );
    $last    = sanitize_text_field( wp_unslash( $_POST['last_name']    ?? '' ) );
    $email   = sanitize_email( wp_unslash( $_POST['email']             ?? '' ) );
    $phone   = sanitize_text_field( wp_unslash( $_POST['phone']        ?? '' ) );
    $display = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
    $curpw   = (string) wp_unslash( $_POST['cur_pass']  ?? '' );
    $newpw   = (string) wp_unslash( $_POST['new_pass']  ?? '' );
    $newpw2  = (string) wp_unslash( $_POST['new_pass2'] ?? '' );

    if ( ! is_email( $email ) ) {
        return new WP_Error( 'email', __( 'Ungültige E-Mail-Adresse.', 'tennis-pro' ) );
    }

    $existing = get_user_by( 'email', $email );
    if ( $existing && (int) $existing->ID !== (int) $user->ID ) {
        return new WP_Error( 'taken', __( 'Diese E-Mail-Adresse gehört bereits einem anderen Konto.', 'tennis-pro' ) );
    }

    /* Passwortänderung */
    if ( $newpw !== '' ) {
        if ( ! wp_check_password( $curpw, $user->user_pass, $user->ID ) ) {
            return new WP_Error( 'badpw', __( 'Das aktuelle Passwort ist falsch.', 'tennis-pro' ) );
        }
        if ( strlen( $newpw ) < 8 ) {
            return new WP_Error( 'weakpw', __( 'Das neue Passwort muss mindestens 8 Zeichen lang sein.', 'tennis-pro' ) );
        }
        if ( $newpw !== $newpw2 ) {
            return new WP_Error( 'mismatch', __( 'Die neuen Passwörter stimmen nicht überein.', 'tennis-pro' ) );
        }
    }

    $args = [
        'ID'           => $user->ID,
        'user_email'   => $email,
        'first_name'   => $first,
        'last_name'    => $last,
        'display_name' => $display ?: trim( $first . ' ' . $last ) ?: $user->display_name,
    ];
    if ( $newpw !== '' ) {
        $args['user_pass'] = $newpw;
    }

    $result = wp_update_user( $args );
    if ( is_wp_error( $result ) ) {
        return new WP_Error( 'save', $result->get_error_message() );
    }

    update_user_meta( $user->ID, 'tennis_pro_phone', $phone );
    return true;
}
