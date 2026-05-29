<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ══════════════════════════════════════════════════════════════════════════
   MENU REGISTRATION  – only visible to WP administrators
══════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_menu', 'tennis_pro_users_menu', 30 );

function tennis_pro_users_menu(): void {
    add_submenu_page(
        'tennis-pro',
        __( 'Benutzerverwaltung', 'tennis-pro' ),
        __( 'Benutzer', 'tennis-pro' ),
        'manage_options',          // WP-Admins only
        'tennis-pro-users',
        'tennis_pro_users_page'
    );
}

/* ══════════════════════════════════════════════════════════════════════════
   AJAX: save role change
══════════════════════════════════════════════════════════════════════════ */

add_action( 'wp_ajax_tennis_set_user_role', 'tennis_pro_ajax_set_user_role' );

function tennis_pro_ajax_set_user_role(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Keine Berechtigung.', 'tennis-pro' ) ], 403 );
    }
    check_ajax_referer( 'tennis_users_nonce', 'nonce' );

    $user_id  = (int) ( $_POST['user_id']     ?? 0 );
    $new_role = sanitize_key( $_POST['tennis_role'] ?? '' );

    if ( $user_id <= 0 || $user_id === get_current_user_id() ) {
        wp_send_json_error( [ 'message' => __( 'Ungültige Benutzer-ID.', 'tennis-pro' ) ], 400 );
    }

    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        wp_send_json_error( [ 'message' => __( 'Benutzer nicht gefunden.', 'tennis-pro' ) ], 404 );
    }

    // WP administrators cannot be changed from here
    if ( in_array( 'administrator', (array) $user->roles, true ) ) {
        wp_send_json_error( [ 'message' => __( 'Administratoren können hier nicht geändert werden.', 'tennis-pro' ) ], 403 );
    }

    if ( $new_role === 'tennis_backend_admin' ) {
        $user->set_role( 'tennis_backend_admin' );
        $label = __( 'Tennisplatz-Admin', 'tennis-pro' );
    } else {
        $user->set_role( 'subscriber' );
        $label = __( 'Benutzer (Frontend)', 'tennis-pro' );
    }

    wp_send_json_success( [
        'message' => sprintf( __( 'Rolle geändert: %s', 'tennis-pro' ), $label ),
        'role'    => $new_role === 'tennis_backend_admin' ? 'tennis_backend_admin' : 'subscriber',
        'label'   => $label,
    ] );
}

/* ══════════════════════════════════════════════════════════════════════════
   PAGE
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_users_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Zugriff verweigert.', 'tennis-pro' ) );
    }

    $current_uid = get_current_user_id();
    $users       = get_users( [ 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 500 ] );
    $nonce       = wp_create_nonce( 'tennis_users_nonce' );
    $ajax_url    = admin_url( 'admin-ajax.php' );

    // Count per role for summary badges
    $count_admin  = 0;
    $count_tnp    = 0;
    $count_user   = 0;
    foreach ( $users as $u ) {
        if ( in_array( 'administrator', (array) $u->roles, true ) )         $count_admin++;
        elseif ( in_array( 'tennis_backend_admin', (array) $u->roles, true ) ) $count_tnp++;
        else                                                                   $count_user++;
    }
    ?>
    <div class="wrap">
        <h1>🎾 <?php esc_html_e( 'Benutzerverwaltung', 'tennis-pro' ); ?></h1>
        <p style="color:#555;max-width:760px;margin-bottom:20px">
            <?php esc_html_e( 'Verwalte hier den Tennisplatz-Zugang für alle WordPress-Benutzer. Weise jedem Nutzer eine Tennis-Rolle zu. Administratoren haben immer Vollzugriff und können hier nicht geändert werden.', 'tennis-pro' ); ?>
        </p>

        <!-- ── Role legend ── -->
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:28px">

            <div style="display:flex;align-items:center;gap:10px;padding:12px 18px;background:#fff;border:2px solid #1b5e20;border-radius:8px;min-width:200px">
                <span style="font-size:1.6rem">🛡️</span>
                <div>
                    <strong style="font-size:13px;color:#1b5e20"><?php esc_html_e( 'Administrator', 'tennis-pro' ); ?></strong>
                    <span style="display:inline-block;background:#1b5e20;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 8px;margin-left:4px"><?php echo (int) $count_admin; ?></span><br>
                    <span style="font-size:11px;color:#666"><?php esc_html_e( 'Vollzugriff WordPress + Tennis-Backend', 'tennis-pro' ); ?></span>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:10px;padding:12px 18px;background:#fff;border:2px solid #2e7d32;border-radius:8px;min-width:200px">
                <span style="font-size:1.6rem">🎾</span>
                <div>
                    <strong style="font-size:13px;color:#2e7d32"><?php esc_html_e( 'Tennisplatz-Admin', 'tennis-pro' ); ?></strong>
                    <span style="display:inline-block;background:#2e7d32;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 8px;margin-left:4px"><?php echo (int) $count_tnp; ?></span><br>
                    <span style="font-size:11px;color:#666"><?php esc_html_e( 'Nur Tennis-Backend + Frontend-Buchungen', 'tennis-pro' ); ?></span>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:10px;padding:12px 18px;background:#fff;border:2px solid #1565c0;border-radius:8px;min-width:200px">
                <span style="font-size:1.6rem">👤</span>
                <div>
                    <strong style="font-size:13px;color:#1565c0"><?php esc_html_e( 'Benutzer (Frontend)', 'tennis-pro' ); ?></strong>
                    <span style="display:inline-block;background:#1565c0;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 8px;margin-left:4px"><?php echo (int) $count_user; ?></span><br>
                    <span style="font-size:11px;color:#666"><?php esc_html_e( 'Nur Frontend-Buchungen (kein WP-Backend)', 'tennis-pro' ); ?></span>
                </div>
            </div>

        </div>

        <!-- ── Search ── -->
        <div style="margin-bottom:12px">
            <input type="text" id="tnp-user-search"
                   placeholder="<?php esc_attr_e( 'Name oder E-Mail suchen…', 'tennis-pro' ); ?>"
                   style="width:280px;padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px">
        </div>

        <!-- ── User table ── -->
        <div style="overflow-x:auto;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.1);max-width:920px">
        <table class="widefat" id="tnp-users-table" style="border-radius:8px;overflow:hidden">
            <thead>
                <tr style="background:#f0f0f0">
                    <th style="padding:10px 14px"><?php esc_html_e( 'Benutzer', 'tennis-pro' ); ?></th>
                    <th style="padding:10px 14px"><?php esc_html_e( 'E-Mail', 'tennis-pro' ); ?></th>
                    <th style="padding:10px 14px;width:160px"><?php esc_html_e( 'Tennis-Rolle', 'tennis-pro' ); ?></th>
                    <th style="padding:10px 14px;width:200px"><?php esc_html_e( 'Rolle ändern', 'tennis-pro' ); ?></th>
                    <th style="padding:10px 14px;width:80px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $users as $i => $u ) :
                $is_wp_admin  = in_array( 'administrator', (array) $u->roles, true );
                $is_tnp_admin = in_array( 'tennis_backend_admin', (array) $u->roles, true );
                $is_current   = $u->ID === $current_uid;
                $row_bg       = $i % 2 === 0 ? '#ffffff' : '#fafafa';

                if ( $is_wp_admin ) {
                    $badge_bg  = '#1b5e20';
                    $badge_ico = '🛡️';
                    $badge_txt = __( 'Administrator', 'tennis-pro' );
                    $role_val  = 'administrator';
                } elseif ( $is_tnp_admin ) {
                    $badge_bg  = '#2e7d32';
                    $badge_ico = '🎾';
                    $badge_txt = __( 'Tennisplatz-Admin', 'tennis-pro' );
                    $role_val  = 'tennis_backend_admin';
                } else {
                    $badge_bg  = '#1565c0';
                    $badge_ico = '👤';
                    $badge_txt = __( 'Benutzer (Frontend)', 'tennis-pro' );
                    $role_val  = 'subscriber';
                }
            ?>
                <tr data-user-id="<?php echo (int) $u->ID; ?>"
                    data-name="<?php echo esc_attr( strtolower( $u->display_name ) ); ?>"
                    data-email="<?php echo esc_attr( strtolower( $u->user_email ) ); ?>"
                    style="background:<?php echo $row_bg; ?>">
                    <td style="padding:9px 14px">
                        <strong><?php echo esc_html( $u->display_name ); ?></strong>
                        <?php if ( $is_current ) : ?>
                            <span style="font-size:10px;background:#e0e0e0;color:#555;padding:1px 6px;border-radius:8px;margin-left:4px"><?php esc_html_e( 'Du', 'tennis-pro' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:9px 14px;font-size:12px;color:#666"><?php echo esc_html( $u->user_email ); ?></td>
                    <td style="padding:9px 14px">
                        <span class="tnp-role-badge" style="display:inline-flex;align-items:center;gap:4px;background:<?php echo esc_attr( $badge_bg ); ?>;color:#fff;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;white-space:nowrap">
                            <?php echo $badge_ico; ?> <?php echo esc_html( $badge_txt ); ?>
                        </span>
                    </td>
                    <td style="padding:9px 14px">
                        <?php if ( ! $is_wp_admin && ! $is_current ) : ?>
                            <select class="tnp-role-select" data-user-id="<?php echo (int) $u->ID; ?>"
                                    style="font-size:12px;padding:4px 8px;border:1px solid #ccc;border-radius:4px;max-width:175px">
                                <option value="subscriber"           <?php selected( $role_val, 'subscriber' ); ?>>👤 Benutzer (Frontend)</option>
                                <option value="tennis_backend_admin" <?php selected( $role_val, 'tennis_backend_admin' ); ?>>🎾 Tennisplatz-Admin</option>
                            </select>
                        <?php elseif ( $is_wp_admin ) : ?>
                            <em style="font-size:11px;color:#aaa"><?php esc_html_e( 'nicht änderbar', 'tennis-pro' ); ?></em>
                        <?php else : ?>
                            <em style="font-size:11px;color:#aaa"><?php esc_html_e( 'eigene Rolle', 'tennis-pro' ); ?></em>
                        <?php endif; ?>
                    </td>
                    <td style="padding:9px 14px">
                        <?php if ( ! $is_wp_admin && ! $is_current ) : ?>
                            <button type="button" class="button button-small tnp-save-role"
                                    data-user-id="<?php echo (int) $u->ID; ?>"
                                    style="white-space:nowrap">
                                💾 <?php esc_html_e( 'Speichern', 'tennis-pro' ); ?>
                            </button>
                            <span class="tnp-role-msg" style="display:none;font-size:11px;margin-left:4px;font-weight:600"></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <p style="margin-top:12px;font-size:12px;color:#999">
            <?php printf( esc_html__( '%d Benutzer gesamt', 'tennis-pro' ), count( $users ) ); ?>
        </p>
    </div><!-- .wrap -->

    <script>
    (function() {
        var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
        var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

        // ── Save button ──────────────────────────────────────────────────
        document.querySelectorAll('.tnp-save-role').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var userId   = this.dataset.userId;
                var row      = this.closest('tr');
                var sel      = row.querySelector('.tnp-role-select');
                var badge    = row.querySelector('.tnp-role-badge');
                var msgEl    = row.querySelector('.tnp-role-msg');
                var newRole  = sel ? sel.value : '';

                btn.disabled = true;
                if (msgEl) { msgEl.style.display = 'none'; }

                var body = new URLSearchParams({
                    action      : 'tennis_set_user_role',
                    nonce       : nonce,
                    user_id     : userId,
                    tennis_role : newRole
                });

                fetch(ajaxUrl, {
                    method  : 'POST',
                    headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body    : body.toString()
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        // Update the badge
                        var icons = { subscriber: '👤', tennis_backend_admin: '🎾' };
                        var colors = { subscriber: '#1565c0', tennis_backend_admin: '#2e7d32' };
                        var role = res.data.role;
                        badge.innerHTML = (icons[role] || '👤') + ' ' + res.data.label;
                        badge.style.background = colors[role] || '#1565c0';

                        if (msgEl) {
                            msgEl.style.display = '';
                            msgEl.style.color   = '#2e7d32';
                            msgEl.textContent   = '✅ ' + res.data.message;
                            setTimeout(function() { msgEl.style.display = 'none'; }, 3000);
                        }
                    } else {
                        if (msgEl) {
                            msgEl.style.display = '';
                            msgEl.style.color   = '#b32d2e';
                            msgEl.textContent   = '❌ ' + (res.data?.message || 'Fehler');
                        }
                    }
                })
                .catch(function() {
                    if (msgEl) {
                        msgEl.style.display = '';
                        msgEl.style.color   = '#b32d2e';
                        msgEl.textContent   = '❌ Netzwerkfehler.';
                    }
                })
                .finally(function() { btn.disabled = false; });
            });
        });

        // ── Live search ──────────────────────────────────────────────────
        var searchInput = document.getElementById('tnp-user-search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                var q = this.value.toLowerCase().trim();
                document.querySelectorAll('#tnp-users-table tbody tr').forEach(function(row) {
                    var name  = row.dataset.name  || '';
                    var email = row.dataset.email || '';
                    row.style.display = ( !q || name.includes(q) || email.includes(q) ) ? '' : 'none';
                });
            });
        }
    })();
    </script>
    <?php
}
