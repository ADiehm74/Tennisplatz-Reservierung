<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'tennis_pro_admin_menu' );

function tennis_pro_admin_menu() {
    add_menu_page(
        __( 'Tennisplatz-Reservierung Pro', 'tennis-pro' ),
        __( 'Tennisplatz', 'tennis-pro' ),
        'tennis_manage',
        'tennis-pro',
        'tennis_pro_admin_page',
        'dashicons-awards',
        30
    );
    // Rename the auto-generated duplicate first entry to "Übersicht"
    add_submenu_page( 'tennis-pro', __( 'Übersicht', 'tennis-pro' ), __( 'Übersicht', 'tennis-pro' ),
        'tennis_manage', 'tennis-pro', 'tennis_pro_admin_page' );
    // Einstellungen = Plätze, Kategorien, Trainer (priority 10 = registers right after Übersicht)
    add_submenu_page( 'tennis-pro', __( 'Plätze & Kategorien', 'tennis-pro' ), __( 'Einstellungen', 'tennis-pro' ),
        'tennis_manage', 'tennis-pro-settings', 'tennis_pro_settings_page' );
}

/* ══════════════════════════════════════════════════════════════════════════
   TENNISPLATZ-ADMIN: REDIRECT + MENU RESTRICTION
══════════════════════════════════════════════════════════════════════════ */

/**
 * After login, redirect tennis_backend_admin users to the frontend booking page
 * (not into the WP backend). Global admins are not affected.
 */
add_filter( 'login_redirect', 'tennis_pro_login_redirect', 10, 3 );
function tennis_pro_login_redirect( string $redirect_to, string $request, $user ): string {
    if ( ! is_wp_error( $user ) && $user instanceof WP_User ) {
        if ( ! $user->has_cap( 'manage_options' ) && $user->has_cap( 'tennis_manage' ) ) {
            return tennis_pro_get_frontend_url();
        }
    }
    return $redirect_to;
}

/**
 * Find the URL of the page/post that contains the [tennis_booking] shortcode.
 * Falls back to home_url() when no such page is found.
 */
function tennis_pro_get_frontend_url(): string {
    global $wpdb;
    $page = $wpdb->get_row(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_content LIKE '%[tennis_booking%'
           AND post_status = 'publish'
         LIMIT 1"
    );
    if ( $page ) {
        $url = get_permalink( (int) $page->ID );
        if ( $url ) return $url;
    }
    return home_url( '/' );
}

/**
 * Redirect any non-tennis admin page access back to the tennis overview
 * for users with only tennis_manage capability.
 */
add_action( 'admin_init', 'tennis_pro_restrict_backend_access' );
function tennis_pro_restrict_backend_access(): void {
    if ( wp_doing_ajax() ) return;
    if ( current_user_can( 'manage_options' ) ) return;   // WP admins: no restriction
    if ( ! current_user_can( 'tennis_manage' ) ) return;  // non-tennis users: WP handles it

    global $pagenow;

    // Build list of allowed tennis admin page slugs
    $allowed = [ 'tennis-pro', 'tennis-pro-settings', 'tennis-pro-options', 'tennis-pro-export' ];

    $current_page = sanitize_key( $_GET['page'] ?? '' );
    $is_tennis    = $pagenow === 'admin.php' && in_array( $current_page, $allowed, true );
    $is_profile   = in_array( $pagenow, [ 'profile.php' ], true );
    $is_media     = $pagenow === 'async-upload.php';   // WP media uploader

    if ( ! $is_tennis && ! $is_profile && ! $is_media ) {
        wp_safe_redirect( admin_url( 'admin.php?page=tennis-pro' ) );
        exit;
    }
}

/**
 * Remove all standard WordPress admin menu items for tennis_backend_admin users.
 * Runs at very high priority so it fires after everything else has registered.
 */
add_action( 'admin_menu', 'tennis_pro_restrict_admin_menu', 9999 );
function tennis_pro_restrict_admin_menu(): void {
    if ( current_user_can( 'manage_options' ) ) return;
    if ( ! current_user_can( 'tennis_manage' ) ) return;

    $remove = [
        'index.php',                    // Dashboard
        'edit.php',                     // Beiträge
        'upload.php',                   // Medien
        'edit.php?post_type=page',      // Seiten
        'edit-comments.php',            // Kommentare
        'themes.php',                   // Design
        'plugins.php',                  // Plugins
        'users.php',                    // Benutzer
        'tools.php',                    // Werkzeuge
        'options-general.php',          // Einstellungen (WP)
        'profile.php',                  // Profil (varies)
    ];
    foreach ( $remove as $slug ) {
        remove_menu_page( $slug );
    }

    // Hide the admin bar "WP logo" menu & "New" shortcut
    add_action( 'wp_before_admin_bar_render', static function() {
        global $wp_admin_bar;
        $wp_admin_bar->remove_menu( 'wp-logo' );
        $wp_admin_bar->remove_menu( 'new-content' );
        $wp_admin_bar->remove_menu( 'updates' );
        $wp_admin_bar->remove_menu( 'comments' );
    } );
}

/* ══════════════════════════════════════════════════════════════════════════
   SHARED GRID RENDERER
   Used by both day view and week view.
══════════════════════════════════════════════════════════════════════════ */

/**
 * Render the booking grid for a given set of column headers, booking map,
 * blocked map, categories map, courts and timeslot range.
 *
 * Uses the same tnp-grid / tnp-slot CSS classes as the frontend for a
 * consistent visual appearance. Adds checkbox + delete link to each cell.
 *
 * @param array  $columns  Assoc array ['label','date','court_id','bg_color','text_color']
 * @param array  $bmap     tennis_pro_build_map() result, keyed [date][cid][slot]
 * @param array  $blk_map  tennis_pro_build_blocked_map() result
 * @param array  $cat_map
 * @param int    $start_h
 * @param int    $end_h
 * @param string $view     'day' | 'week'
 */
function tennis_pro_admin_render_grid( array $columns, array $bmap, array $blk_map, array $cat_map, int $start_h = 8, int $end_h = 22, string $view = 'day' ): void {
    // Colour settings (same as frontend)
    $s_opts         = tennis_pro_get_settings();
    $slot_odd_bg    = esc_attr( $s_opts['slot_free_odd_bg']    ?: '#f0f4ff' );
    $slot_odd_tc    = esc_attr( $s_opts['slot_free_odd_text']  ?: '#aaaaaa' );
    $slot_even_bg   = esc_attr( $s_opts['slot_free_even_bg']   ?: '#e8f5e9' );
    $slot_even_tc   = esc_attr( $s_opts['slot_free_even_text'] ?: '#aaaaaa' );
    $time_col_bg    = esc_attr( $s_opts['time_col_bg']   ?: '#1565c0' );
    $time_col_text  = esc_attr( $s_opts['time_col_text'] ?: '#ffffff' );
    $time_style     = "background:{$time_col_bg};color:{$time_col_text};";
    ?>
    <div class="tnp-wrap" style="padding:0;margin:0">
    <div class="tnp-grid-wrap" style="overflow-x:auto">
    <table class="tnp-grid" style="width:auto;min-width:400px">
        <thead>
            <tr>
                <th class="tnp-grid__time-head" style="<?php echo $time_style; ?>;min-width:80px">
                    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:5px">
                        <input type="checkbox" id="cb-all"> <?php esc_html_e( 'Zeit', 'tennis-pro' ); ?>
                    </label>
                </th>
                <?php foreach ( $columns as $col ) :
                    $ch_bg  = esc_attr( $col['bg_color']    ?? '#2e7d32' );
                    $ch_col = esc_attr( $col['text_color']  ?? '#ffffff' );
                ?>
                    <th class="tnp-grid__court-head" style="background:<?php echo $ch_bg; ?>;color:<?php echo $ch_col; ?>">
                        <?php echo esc_html( $col['label'] ); ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php
        $row_idx = 0;
        for ( $h = $start_h; $h <= $end_h; $h++ ) :
            foreach ( [ '00', '30' ] as $m ) :
                $t = sprintf( '%02d:%s', $h, $m );
                $row_idx++;
                $is_even_row = ( $row_idx % 2 === 0 );
                $free_row_bg = $is_even_row ? $slot_even_bg : $slot_odd_bg;
                $free_row_tc = $is_even_row ? $slot_even_tc : $slot_odd_tc;
        ?>
            <tr class="tnp-grid__row">
                <td class="tnp-grid__time" style="<?php echo $time_style; ?>"><?php echo esc_html( $t ); ?></td>
                <?php foreach ( $columns as $col ) :
                    $cid      = (int) $col['court_id'];
                    $col_date = $col['date'];
                    $b        = $bmap[ $col_date ][ $cid ][ $t ]    ?? null;
                    $blk      = $blk_map[ $col_date ][ $cid ][ $t ] ?? ( $blk_map[ $col_date ][ $cid ]['__day__'] ?? null );

                    if ( $b === 'cont' || $blk === 'cont' ) { continue; }

                    $rowspan   = 1;
                    if ( $b && $b !== 'cont' )
                        $rowspan = max( 1, (int) $b->duration );
                    elseif ( $blk && $blk !== 'cont' && $blk->timeslot !== '' )
                        $rowspan = max( 1, (int) $blk->duration );

                    $is_booked  = ( $b && $b !== 'cont' );
                    $is_blocked = ( ! $is_booked && $blk && $blk !== 'cont' );
                    $is_mycal   = ( $is_blocked && isset( $blk->source ) && $blk->source === 'mycal' );

                    $cat     = $is_booked  ? ( $cat_map[ (int) $b->category_id ] ?? null )   : null;
                    $blk_cat = ( $is_blocked && ! $is_mycal && isset( $blk->category_id ) && (int) $blk->category_id > 0 )
                               ? ( $cat_map[ (int) $blk->category_id ] ?? null ) : null;
                    if ( $is_mycal ) {
                        $bg = esc_attr( $blk->cat_color ?? '#0277bd' );
                        $tc = '#000000';
                    } else {
                        $bg = $cat ? $cat->color      : ( $blk_cat ? $blk_cat->color      : ( $is_blocked ? '#e8e8e8' : $free_row_bg ) );
                        $tc = $cat ? $cat->text_color : ( $blk_cat ? $blk_cat->text_color : ( $is_blocked ? '#777'    : $free_row_tc ) );
                    }

                    $classes  = 'tnp-slot';
                    if ( $is_booked )                    $classes .= ' tnp-slot--booked';
                    if ( $is_blocked )                   $classes .= ' tnp-slot--blocked';
                    if ( ! $is_booked && ! $is_blocked ) $classes .= ' tnp-slot--free tnp-admin-bookable';

                    $rs = $rowspan > 1 ? " rowspan=\"{$rowspan}\"" : '';
                ?>
                <td<?php echo $rs; ?> class="<?php echo esc_attr( $classes ); ?>"
                    style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $tc ); ?>;position:relative;vertical-align:top<?php echo ( ! $is_booked && ! $is_blocked ) ? ';cursor:pointer' : ''; ?>"
                    data-court="<?php echo $cid; ?>"
                    data-date="<?php echo esc_attr( $col_date ); ?>"
                    data-time="<?php echo esc_attr( $t ); ?>">

                    <?php if ( $is_booked ) :
                        $end_min      = tennis_pro_slot_to_minutes( $t ) + (int) $b->duration * 30;
                        $end_time     = tennis_pro_minutes_to_slot( $end_min );
                        $cat_label    = $cat->name ?? '';
                        $player_label = trim( $b->player_name ?? '' );
                        $show_player  = ( $player_label !== '' && $player_label !== $cat_label );
                    ?>
                        <label style="display:block;cursor:pointer;padding-right:20px">
                            <input type="checkbox" name="ids[]" value="<?php echo (int) $b->id; ?>"
                                   style="float:left;margin:2px 5px 0 0">
                            <span class="tnp-slot__time"><?php echo esc_html( $t . ' – ' . $end_time ); ?></span>
                            <?php if ( $cat_label !== '' ) : ?>
                                <span class="tnp-slot__cat"><?php echo esc_html( $cat_label ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $b->trainer_name ) ) : ?>
                                <span class="tnp-slot__trainer">👤 <?php echo esc_html( $b->trainer_name ); ?></span>
                            <?php endif; ?>
                            <?php if ( $show_player ) : ?>
                                <span class="tnp-slot__label"><?php echo esc_html( $player_label ); ?></span>
                            <?php elseif ( $cat_label === '' ) : ?>
                                <span class="tnp-slot__label"><?php echo esc_html( $b->player_name ?: __( 'Belegt', 'tennis-pro' ) ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $b->display_name ) ) : ?>
                                <span class="tnp-slot__cat" style="opacity:.65;font-size:.68rem"><?php echo esc_html( $b->display_name ); ?></span>
                            <?php endif; ?>
                            <?php if ( (int) $b->recurring_id ) : ?>
                                <span class="tnp-slot__recur">🔁</span>
                            <?php endif; ?>
                        </label>
                        <?php
                        $del_href = esc_url( wp_nonce_url(
                            add_query_arg( [ 'page' => 'tennis-pro', 'date' => $col_date, 'delete_id' => $b->id ], admin_url( 'admin.php' ) ),
                            'tennis_delete_' . $b->id
                        ) );
                        $del_style = 'position:absolute;top:4px;right:5px;font-size:12px;font-weight:700;color:' . esc_attr( $tc ) . ';background:none;border:none;cursor:pointer;line-height:1;opacity:.7;padding:0';
                        if ( (int) $b->recurring_id > 0 ) : ?>
                        <button type="button"
                                class="tnp-admin-del-btn"
                                data-id="<?php echo (int) $b->id; ?>"
                                data-recurring-id="<?php echo (int) $b->recurring_id; ?>"
                                data-href="<?php echo $del_href; ?>"
                                title="<?php esc_attr_e( 'Löschen', 'tennis-pro' ); ?>"
                                style="<?php echo $del_style; ?>">✕</button>
                        <?php else : ?>
                        <button type="button"
                                class="tnp-admin-del-btn"
                                data-id="<?php echo (int) $b->id; ?>"
                                data-recurring-id="0"
                                data-href="<?php echo $del_href; ?>"
                                title="<?php esc_attr_e( 'Löschen', 'tennis-pro' ); ?>"
                                style="<?php echo $del_style; ?>">✕</button>
                        <?php endif; ?>

                    <?php elseif ( $is_blocked ) : ?>
                        <?php if ( $is_mycal ) :
                            $all_mycal = array_merge( [ $blk ], $blk->extra_events ?? [] );
                            foreach ( $all_mycal as $mi => $me ) :
                                $me_url = tennis_pro_mycal_event_url( (int) ( $me->event_id ?? 0 ) );
                        ?>
                            <?php if ( $mi > 0 ) : ?>
                                <div style="margin-top:3px;padding-top:3px;border-top:1px solid rgba(0,0,0,.2)"></div>
                            <?php endif; ?>
                            <?php if ( $me->cat_name ?? '' ) : ?>
                                <span class="tnp-slot__cat" style="opacity:.85"><?php echo esc_html( $me->cat_name ); ?></span>
                            <?php endif; ?>
                            <span class="tnp-slot__blocked" style="font-size:.8rem">
                                <?php if ( $me_url ) : ?>
                                    <a href="<?php echo esc_url( $me_url ); ?>" style="color:inherit;text-decoration:underline;font-weight:inherit" target="_blank" rel="noopener">📅 <?php echo esc_html( $me->reason ); ?></a>
                                <?php else : ?>
                                    📅 <?php echo esc_html( $me->reason ); ?>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                        <span style="position:absolute;top:4px;right:5px;font-size:10px;opacity:.6" title="<?php esc_attr_e('My Calendar','tennis-pro'); ?>">MC</span>
                        <?php else : ?>
                            <?php if ( $blk_cat ) : ?>
                                <span class="tnp-slot__label"><?php echo esc_html( $blk_cat->name ); ?></span>
                            <?php endif; ?>
                            <span class="tnp-slot__blocked" style="font-size:.8rem">🔒<?php echo $blk->reason ? ' ' . esc_html( $blk->reason ) : ''; ?></span>
                            <a href="<?php echo esc_url( wp_nonce_url(
                                add_query_arg( [ 'page' => 'tennis-pro', 'unblock_id' => $blk->id ], admin_url( 'admin.php' ) ),
                                'tennis_unblock_' . $blk->id
                            ) ); ?>"
                               onclick="return confirm('<?php esc_attr_e( 'Sperrung aufheben?', 'tennis-pro' ); ?>')"
                               title="<?php esc_attr_e( 'Sperrung aufheben', 'tennis-pro' ); ?>"
                               style="position:absolute;top:4px;right:5px;font-size:12px;font-weight:700;color:<?php echo esc_attr( $tc ); ?>;text-decoration:none;line-height:1;opacity:.7">✕</a>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="tnp-slot__free" style="opacity:.35">+</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; endfor; ?>
        </tbody>
    </table>
    </div>
    </div>
    <?php
}

/* ══════════════════════════════════════════════════════════════════════════
   MAIN ADMIN PAGE
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_admin_page() {
    if ( ! current_user_can( 'tennis_manage' ) ) wp_die( __( 'Zugriff verweigert.', 'tennis-pro' ) );

    // Load frontend styles so the tnp-grid / tnp-slot classes render correctly here too.
    wp_enqueue_style( 'tennis-pro', TENNIS_PRO_URL . 'assets/frontend.css', [], TENNIS_PRO_VER );

    global $wpdb;

    /* ── Handle bulk delete ── */
    if (
        isset( $_POST['tennis_admin_nonce'], $_POST['bulk_delete'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tennis_admin_nonce'] ) ), 'tennis_admin_bulk' ) &&
        ! empty( $_POST['ids'] ) && is_array( $_POST['ids'] )
    ) {
        foreach ( $_POST['ids'] as $bid ) {
            $b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tennis_bookings WHERE id=%d", (int) $bid ) );
            if ( $b ) {
                $court_row = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}tennis_courts WHERE id=%d", (int) $b->court_id ) );
                tennis_pro_mail_booking_cancelled( $b, $court_row->name ?? '' );
                tennis_pro_mail_waitlist_notify( (int) $b->court_id, $b->date, $b->timeslot );
                $wpdb->delete( $wpdb->prefix . 'tennis_bookings', [ 'id' => (int) $bid ], [ '%d' ] );
            }
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Buchungen gelöscht.', 'tennis-pro' ) . '</p></div>';
    }

    /* ── Handle single delete via GET ── */
    if (
        isset( $_GET['delete_id'], $_GET['_wpnonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tennis_delete_' . (int) $_GET['delete_id'] )
    ) {
        $b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tennis_bookings WHERE id=%d", (int) $_GET['delete_id'] ) );
        if ( $b ) {
            $court_row = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}tennis_courts WHERE id=%d", (int) $b->court_id ) );
            tennis_pro_mail_booking_cancelled( $b, $court_row->name ?? '' );
            tennis_pro_mail_waitlist_notify( (int) $b->court_id, $b->date, $b->timeslot );
            $wpdb->delete( $wpdb->prefix . 'tennis_bookings', [ 'id' => (int) $_GET['delete_id'] ], [ '%d' ] );
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Buchung gelöscht.', 'tennis-pro' ) . '</p></div>';
    }

    /* ── Handle unblock via GET ── */
    if (
        isset( $_GET['unblock_id'], $_GET['_wpnonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tennis_unblock_' . (int) $_GET['unblock_id'] )
    ) {
        $wpdb->delete( $wpdb->prefix . 'tennis_blocked_slots', [ 'id' => (int) $_GET['unblock_id'] ], [ '%d' ] );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sperrung aufgehoben.', 'tennis-pro' ) . '</p></div>';
    }

    $view     = in_array( $_GET['view'] ?? 'day', [ 'day', 'week' ], true ) ? sanitize_key( $_GET['view'] ?? 'day' ) : 'day';
    $date     = tennis_pro_validate_date( sanitize_text_field( wp_unslash( $_GET['date'] ?? gmdate( 'Y-m-d' ) ) ) );
    $courts   = tennis_pro_get_courts();
    $cats     = tennis_pro_get_categories();
    $cat_map  = tennis_pro_cat_map( $cats );
    $trainers = tennis_pro_get_trainers();

    /* ── Active recurring series for overview ── */
    $series_list = $wpdb->get_results(
        "SELECT sub.*, t.name AS trainer_name,
                cat.name AS cat_name, cat.color AS cat_color, cat.text_color AS cat_text,
                (SELECT MIN(b2.date) FROM {$wpdb->prefix}tennis_bookings b2
                  WHERE b2.recurring_id = sub.id AND b2.date >= CURDATE()) AS next_date
           FROM (
               SELECT rg.*,
                      c.bg_color AS court_bg, c.color AS court_tc,
                      (SELECT COUNT(*) FROM {$wpdb->prefix}tennis_bookings b
                        WHERE b.recurring_id = rg.id AND b.date >= CURDATE()) AS future_count
                 FROM {$wpdb->prefix}tennis_recurring_groups rg
            LEFT JOIN {$wpdb->prefix}tennis_courts c ON c.id = rg.court_id
           ) AS sub
      LEFT JOIN {$wpdb->prefix}tennis_trainers t     ON t.id   = sub.trainer_id
      LEFT JOIN {$wpdb->prefix}tennis_categories cat ON cat.id = sub.category_id
          WHERE sub.future_count > 0
          LIMIT 300"
    );

    /* Sort: court order → DOW Mo-first → timeslot → category */
    $court_idx = [];
    foreach ( $courts as $_ci => $_cc ) { $court_idx[ (int) $_cc->id ] = $_ci; }
    usort( $series_list, static function ( $a, $b ) use ( $court_idx ) {
        $ca = $court_idx[ (int) $a->court_id ] ?? 999;
        $cb = $court_idx[ (int) $b->court_id ] ?? 999;
        if ( $ca !== $cb ) return $ca - $cb;
        $da = ( (int) $a->day_of_week + 6 ) % 7;  // Mo=0 … So=6
        $db = ( (int) $b->day_of_week + 6 ) % 7;
        if ( $da !== $db ) return $da - $db;
        $ts = strcmp( $a->timeslot, $b->timeslot );
        if ( $ts !== 0 ) return $ts;
        return strcmp( $a->cat_name ?? '', $b->cat_name ?? '' );
    } );

    /* Group by court */
    $series_by_court = [];
    foreach ( $courts as $_cc ) {
        $series_by_court[ (int) $_cc->id ] = [ 'court' => $_cc, 'series' => [] ];
    }
    foreach ( $series_list as $_s ) {
        $cid_s = (int) $_s->court_id;
        if ( isset( $series_by_court[ $cid_s ] ) ) {
            $series_by_court[ $cid_s ]['series'][] = $_s;
        }
    }
    $series_by_court = array_filter( $series_by_court, static fn( $g ) => ! empty( $g['series'] ) );

    $ov_dow_names = [
        0 => __( 'Sonntag',    'tennis-pro' ),
        1 => __( 'Montag',     'tennis-pro' ),
        2 => __( 'Dienstag',   'tennis-pro' ),
        3 => __( 'Mittwoch',   'tennis-pro' ),
        4 => __( 'Donnerstag', 'tennis-pro' ),
        5 => __( 'Freitag',    'tennis-pro' ),
        6 => __( 'Samstag',    'tennis-pro' ),
    ];

    // Week view: selected court (or first court)
    $sel_court_id = (int) ( $_GET['court_id'] ?? ( $courts[0]->id ?? 0 ) );

    // Date navigation
    if ( $view === 'week' ) {
        // Find Monday of the selected week
        $day_of_week = (int) gmdate( 'N', strtotime( $date ) ); // 1=Mon
        $monday      = gmdate( 'Y-m-d', strtotime( $date . ' -' . ( $day_of_week - 1 ) . ' days' ) );
        $prev_nav    = gmdate( 'Y-m-d', strtotime( $monday . ' -7 days' ) );
        $next_nav    = gmdate( 'Y-m-d', strtotime( $monday . ' +7 days' ) );
        $week_dates  = [];
        for ( $i = 0; $i < 7; $i++ ) {
            $week_dates[] = gmdate( 'Y-m-d', strtotime( $monday . " +{$i} days" ) );
        }
    } else {
        $prev_nav   = gmdate( 'Y-m-d', strtotime( $date . ' -1 day' ) );
        $next_nav   = gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) );
        $week_dates = [ $date ];
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Tennisplatz – Buchungsübersicht', 'tennis-pro' ); ?></h1>

        <!-- ── View toggle + date navigation ── -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap">
            <a class="button <?php echo $view === 'day' ? 'button-primary' : ''; ?>"
               href="<?php echo esc_url( add_query_arg( [ 'page' => 'tennis-pro', 'view' => 'day',  'date' => $date ], admin_url( 'admin.php' ) ) ); ?>">
               <?php esc_html_e( 'Tag', 'tennis-pro' ); ?>
            </a>
            <a class="button <?php echo $view === 'week' ? 'button-primary' : ''; ?>"
               href="<?php echo esc_url( add_query_arg( [ 'page' => 'tennis-pro', 'view' => 'week', 'date' => $date ], admin_url( 'admin.php' ) ) ); ?>">
               <?php esc_html_e( 'Woche', 'tennis-pro' ); ?>
            </a>

            <span style="margin:0 4px;color:#ccc">|</span>

            <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'tennis-pro', 'view' => $view, 'date' => $prev_nav, 'court_id' => $sel_court_id ], admin_url( 'admin.php' ) ) ); ?>">←</a>

            <form method="GET" style="display:inline-flex;align-items:center;gap:4px">
                <input type="hidden" name="page" value="tennis-pro">
                <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
                <?php if ( $view === 'week' ) : ?>
                    <input type="hidden" name="court_id" value="<?php echo (int) $sel_court_id; ?>">
                <?php endif; ?>
                <input type="date" name="date" value="<?php echo esc_attr( $date ); ?>" style="padding:3px 6px">
                <button type="submit" class="button"><?php esc_html_e( 'Zeigen', 'tennis-pro' ); ?></button>
            </form>

            <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'tennis-pro', 'view' => $view, 'date' => $next_nav, 'court_id' => $sel_court_id ], admin_url( 'admin.php' ) ) ); ?>">→</a>

            <?php if ( $view === 'week' && count( $courts ) > 1 ) : ?>
                <span style="margin:0 4px;color:#ccc">|</span>
                <?php foreach ( $courts as $court ) : ?>
                    <a class="button <?php echo (int) $court->id === $sel_court_id ? 'button-primary' : ''; ?>"
                       href="<?php echo esc_url( add_query_arg( [ 'page' => 'tennis-pro', 'view' => 'week', 'date' => $date, 'court_id' => $court->id ], admin_url( 'admin.php' ) ) ); ?>">
                       <?php echo esc_html( $court->name ); ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ( empty( $courts ) ) : ?>
            <div class="notice notice-warning"><p>
                <?php printf(
                    esc_html__( 'Keine Plätze angelegt. %s', 'tennis-pro' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=tennis-pro-settings' ) ) . '">' . esc_html__( 'Jetzt anlegen →', 'tennis-pro' ) . '</a>'
                ); ?>
            </p></div>
        <?php else : ?>

        <form method="POST">
            <?php wp_nonce_field( 'tennis_admin_bulk', 'tennis_admin_nonce' ); ?>
            <?php
            /* ── My Calendar: prepare shared params ──────────────────── */
            $adm_s           = tennis_pro_get_settings();
            $adm_mycal_on    = (int) $adm_s['mycal_enabled'];
            $adm_mycal_cats  = $adm_mycal_on
                ? array_filter( array_map( 'intval', explode( ',', $adm_s['mycal_categories'] ?? '' ) ) )
                : [];
            $adm_mycal_cids  = array_filter( array_map( 'intval', explode( ',', $adm_s['mycal_courts'] ?? '' ) ) );
            $adm_mycal_hor   = max( 1, (int) ( $adm_s['mycal_horizon'] ?? 30 ) );
            $adm_horizon_end = gmdate( 'Y-m-d', strtotime( "+{$adm_mycal_hor} days" ) );

            if ( $view === 'week' ) {
                // Week view: one column per day (single selected court)
                $start = $week_dates[0];
                $end   = end( $week_dates );
                $all_bookings = tennis_pro_get_bookings_for_range( $start, $end );
                $all_blocked  = tennis_pro_get_blocked_for_range( $start, $end );

                // Group by date
                $bmap_week = [];
                $blk_week  = [];
                foreach ( $week_dates as $wd ) {
                    $day_bookings = array_filter( $all_bookings, fn($b) => $b->date === $wd );
                    $day_blocked  = array_filter( $all_blocked,  fn($b) => $b->date === $wd );
                    $bmap_week[ $wd ] = tennis_pro_build_map( array_values( $day_bookings ) );
                    $blk_week[ $wd ]  = tennis_pro_build_blocked_map( array_values( $day_blocked ), $courts );
                }

                // Resolve selected court's colors for week-view column headers
                $sel_court_obj = null;
                foreach ( $courts as $c ) {
                    if ( (int) $c->id === $sel_court_id ) { $sel_court_obj = $c; break; }
                }
                $wk_bg  = $sel_court_obj ? ( $sel_court_obj->bg_color ?: '#2e7d32' ) : '#2e7d32';
                $wk_col = $sel_court_obj ? ( $sel_court_obj->color    ?: '#ffffff' ) : '#ffffff';

                // Build week columns for selected court
                $today_date = gmdate( 'Y-m-d' );
                $columns = [];
                foreach ( $week_dates as $wd ) {
                    $columns[] = [
                        'label'      => date_i18n( 'D j.n.', strtotime( $wd ) ),
                        'date'       => $wd,
                        'court_id'   => $sel_court_id,
                        'bg_color'   => $wd === $today_date ? '#00695c' : $wk_bg,
                        'text_color' => $wk_col,
                    ];
                }
                // Merge maps to work with the renderer
                $merged_bmap = [];
                $merged_blk  = [];
                foreach ( $week_dates as $wd ) {
                    $merged_bmap[ $wd ] = $bmap_week[ $wd ];
                    $merged_blk[ $wd ]  = $blk_week[ $wd ];
                }

                // My Calendar merge (week view)
                if ( $adm_mycal_on && ! empty( $adm_mycal_cats ) && $start <= $adm_horizon_end ) {
                    $eff_end_wk   = min( $end, $adm_horizon_end );
                    $mycal_ev_wk  = tennis_pro_get_mycal_events_for_range( $start, $eff_end_wk, $adm_mycal_cats );
                    tennis_pro_merge_mycal_into_blk_map( $merged_blk, $mycal_ev_wk, $courts, $adm_mycal_cids );
                }

                tennis_pro_admin_render_grid( $columns, $merged_bmap, $merged_blk, $cat_map, 8, 22, 'week' );
            } else {
                // Day view: one column per court
                $bookings = tennis_pro_get_bookings_for_date( $date );
                $blocked  = tennis_pro_get_blocked_for_date( $date );
                $bmap     = [ $date => tennis_pro_build_map( $bookings ) ];
                $blk_map  = [ $date => tennis_pro_build_blocked_map( $blocked, $courts ) ];

                $columns = [];
                foreach ( $courts as $court ) {
                    $columns[] = [
                        'label'      => $court->name,
                        'date'       => $date,
                        'court_id'   => (int) $court->id,
                        'bg_color'   => $court->bg_color  ?: '#2e7d32',
                        'text_color' => $court->color     ?: '#ffffff',
                    ];
                }
                // My Calendar merge (day view)
                if ( $adm_mycal_on && ! empty( $adm_mycal_cats ) && $date <= $adm_horizon_end ) {
                    $mycal_ev_day = tennis_pro_get_mycal_events_for_range( $date, $date, $adm_mycal_cats );
                    tennis_pro_merge_mycal_into_blk_map( $blk_map, $mycal_ev_day, $courts, $adm_mycal_cids );
                }

                tennis_pro_admin_render_grid( $columns, $bmap, $blk_map, $cat_map, 8, 22, 'day' );
            }
            ?>

            <p style="margin-top:12px">
                <button type="submit" name="bulk_delete" class="button button-secondary"
                    onclick="return confirm('<?php esc_attr_e( 'Ausgewählte Buchungen wirklich löschen?', 'tennis-pro' ); ?>')">
                    🗑 <?php esc_html_e( 'Ausgewählte löschen', 'tennis-pro' ); ?>
                </button>
            </p>
        </form>

        <?php endif; ?>

        <!-- ══ Aktive Serien ════════════════════════════════════════════════ -->
        <div style="margin-top:36px">
            <h2 style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                🔁 <?php esc_html_e( 'Aktive Serien', 'tennis-pro' ); ?>
                <?php if ( ! empty( $series_list ) ) : ?>
                    <span style="font-size:13px;font-weight:400;color:#666">(<?php echo count( $series_list ); ?>)</span>
                <?php endif; ?>
            </h2>

            <?php if ( empty( $series_list ) ) : ?>
                <p style="color:#666"><?php esc_html_e( 'Keine aktiven wiederkehrenden Buchungen.', 'tennis-pro' ); ?></p>
            <?php else : ?>

            <!-- Tab-Leiste -->
            <div id="tnp-ov-tabs" style="display:flex;flex-wrap:wrap;gap:0;border-bottom:2px solid #ddd;margin-bottom:0">
                <?php $ov_first_tab = true; foreach ( $series_by_court as $ov_cid => $ov_group ) :
                    $ov_c    = $ov_group['court'];
                    $ov_bg   = esc_attr( $ov_c->bg_color ?: '#2e7d32' );
                    $ov_col  = esc_attr( $ov_c->color    ?: '#ffffff' );
                    $ov_cnt  = count( $ov_group['series'] );
                ?>
                <button type="button" class="tnp-ov-tab-btn"
                        data-tab="tnp-ov-panel-<?php echo (int) $ov_cid; ?>"
                        data-bg="<?php echo $ov_bg; ?>"
                        data-tc="<?php echo $ov_col; ?>"
                        style="padding:7px 16px;font-size:13px;font-weight:600;border:2px solid #ddd;border-bottom:none;cursor:pointer;border-radius:6px 6px 0 0;margin-right:4px;transition:background .12s,color .12s;background:<?php echo $ov_first_tab ? $ov_bg : '#f0f0f0'; ?>;color:<?php echo $ov_first_tab ? $ov_col : '#444'; ?>">
                    🎾 <?php echo esc_html( $ov_c->name ); ?>
                    <span style="font-size:11px;opacity:.75;margin-left:3px">(<?php echo $ov_cnt; ?>)</span>
                </button>
                <?php $ov_first_tab = false; endforeach; ?>
            </div>

            <!-- Tab-Panels -->
            <?php $ov_first_panel = true; foreach ( $series_by_court as $ov_cid => $ov_group ) : ?>
            <div id="tnp-ov-panel-<?php echo (int) $ov_cid; ?>" class="tnp-ov-tab-panel"
                 style="<?php echo $ov_first_panel ? '' : 'display:none;'; ?>border:2px solid #ddd;border-top:none;border-radius:0 0 6px 6px;overflow-x:auto;margin-bottom:24px">
                <table class="widefat" style="min-width:640px;border-radius:0">
                    <thead>
                        <tr style="background:#f8f8f8">
                            <th style="padding:8px 12px;width:100px"><?php esc_html_e( 'Wochentag', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px;width:130px"><?php esc_html_e( 'Zeit', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px;width:105px"><?php esc_html_e( 'Rhythmus', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px"><?php esc_html_e( 'Kategorie / Name', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px;width:90px"><?php esc_html_e( 'Trainer', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px;width:88px"><?php esc_html_e( 'Nächster', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px;width:80px"><?php esc_html_e( 'Bis', 'tennis-pro' ); ?></th>
                            <th style="padding:8px 12px;width:36px;text-align:center">
                                <abbr title="<?php esc_attr_e( 'Noch ausstehende Termine', 'tennis-pro' ); ?>">📅</abbr>
                            </th>
                            <th style="padding:8px 12px;width:96px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $ov_group['series'] as $ov_si => $ov_s ) :
                        $ov_dow_label  = $ov_dow_names[ (int) $ov_s->day_of_week ] ?? '';
                        $ov_pat_label  = $ov_s->pattern === 'daily'
                            ? __( 'Täglich', 'tennis-pro' )
                            : __( 'Wöchentl.', 'tennis-pro' );
                        $ov_end_min    = tennis_pro_slot_to_minutes( $ov_s->timeslot ) + (int) $ov_s->duration * 30;
                        $ov_end_time   = tennis_pro_minutes_to_slot( $ov_end_min );
                        $ov_row_bg     = $ov_si % 2 === 0 ? '#ffffff' : '#f9f9f9';
                        $ov_del_label  = esc_attr( $ov_dow_label . ' · ' . $ov_s->timeslot . ' Uhr' );
                    ?>
                    <tr style="background:<?php echo $ov_row_bg; ?>"
                        onmouseover="this.style.background='#f0f7f0'"
                        onmouseout="this.style.background='<?php echo $ov_row_bg; ?>'">
                        <td style="padding:8px 12px;font-size:13px;font-weight:600"><?php echo esc_html( $ov_dow_label ); ?></td>
                        <td style="padding:8px 12px;font-weight:700;white-space:nowrap">
                            <?php echo esc_html( $ov_s->timeslot . ' – ' . $ov_end_time ); ?> Uhr<br>
                            <span style="font-size:11px;font-weight:400;color:#888"><?php echo esc_html( tennis_pro_duration_label( (int) $ov_s->duration ) ); ?></span>
                        </td>
                        <td style="padding:8px 12px;font-size:13px;color:#444">🔁 <?php echo esc_html( $ov_pat_label ); ?></td>
                        <td style="padding:8px 12px">
                            <?php if ( $ov_s->cat_name ) : ?>
                                <span style="display:inline-block;background:<?php echo esc_attr( $ov_s->cat_color ?: '#e0e0e0' ); ?>;color:<?php echo esc_attr( $ov_s->cat_text ?: '#333' ); ?>;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;margin-bottom:2px"><?php echo esc_html( $ov_s->cat_name ); ?></span><br>
                            <?php endif; ?>
                            <?php if ( $ov_s->player_name ) : ?>
                                <span style="font-size:12px;color:#555"><?php echo esc_html( $ov_s->player_name ); ?></span>
                            <?php else : ?>
                                <em style="font-size:12px;color:#aaa">–</em>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px 12px;font-size:12px">
                            <?php echo $ov_s->trainer_name
                                ? '👤 ' . esc_html( $ov_s->trainer_name )
                                : '<em style="color:#aaa">–</em>'; ?>
                        </td>
                        <td style="padding:8px 12px;font-size:12px;white-space:nowrap">
                            <?php echo $ov_s->next_date
                                ? esc_html( date_i18n( 'j.n.Y', strtotime( $ov_s->next_date ) ) )
                                : '<em style="color:#aaa">–</em>'; ?>
                        </td>
                        <td style="padding:8px 12px;font-size:12px;white-space:nowrap;color:#666">
                            <?php echo esc_html( date_i18n( 'j.n.Y', strtotime( $ov_s->end_date ) ) ); ?>
                        </td>
                        <td style="padding:8px 12px;text-align:center">
                            <span style="display:inline-block;background:#e8f5e9;color:#2e7d32;border-radius:12px;padding:2px 8px;font-size:12px;font-weight:700"><?php echo (int) $ov_s->future_count; ?></span>
                        </td>
                        <td style="padding:8px 12px">
                            <button type="button"
                                    class="button button-small tnp-ov-cancel-series"
                                    data-id="<?php echo (int) $ov_s->id; ?>"
                                    data-label="<?php echo $ov_del_label; ?>"
                                    style="color:#b32d2e;border-color:#b32d2e">
                                🗑 <?php esc_html_e( 'Stornieren', 'tennis-pro' ); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $ov_first_panel = false; endforeach; ?>

            <?php endif; ?>
        </div><!-- /aktive serien -->

        <!-- ══ Delete confirmation modal ══════════════════════════════════ -->
        <div id="tnp-admin-del-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99998;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:8px;padding:28px 24px;max-width:380px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25);text-align:center">
                <div style="font-size:28px;margin-bottom:6px">🗑</div>
                <h3 style="margin:0 0 8px;font-size:16px"><?php esc_html_e( 'Buchung löschen', 'tennis-pro' ); ?></h3>
                <p id="tnp-del-info" style="margin:0 0 20px;color:#555;font-size:13px;min-height:1.4em"></p>
                <!-- Series: two action buttons -->
                <div id="tnp-del-series-btns" style="display:none;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:10px">
                    <button id="tnp-del-single-btn" class="button button-primary"><?php esc_html_e( 'Nur diesen Termin', 'tennis-pro' ); ?></button>
                    <button id="tnp-del-whole-btn"  class="button" style="border-color:#b32d2e;color:#b32d2e"><?php esc_html_e( 'Gesamte Serie', 'tennis-pro' ); ?></button>
                </div>
                <!-- Single booking: one confirm button -->
                <div id="tnp-del-simple-btns" style="display:none;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:10px">
                    <button id="tnp-del-confirm-btn" class="button button-primary"><?php esc_html_e( 'Ja, löschen', 'tennis-pro' ); ?></button>
                </div>
                <button id="tnp-del-abort-btn" class="button"><?php esc_html_e( 'Abbrechen', 'tennis-pro' ); ?></button>
            </div>
        </div>

        <!-- ══ Quick booking modal ═════════════════════════════════════════ -->
        <div id="tnp-admin-book-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99998;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:8px;padding:24px;max-width:460px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto">
                <h3 style="margin:0 0 4px;text-align:center">🎾 <?php esc_html_e( 'Buchung anlegen', 'tennis-pro' ); ?></h3>
                <p id="tnp-admin-book-meta" style="margin:0 0 14px;color:#555;font-size:13px;text-align:center"></p>

                <table class="form-table" role="presentation" style="margin:0">
                    <tr>
                        <th style="padding:5px 12px 5px 0;font-size:13px;width:120px;vertical-align:top;padding-top:8px"><?php esc_html_e( 'Platz / Plätze', 'tennis-pro' ); ?></th>
                        <td style="padding:5px 0">
                            <div id="tnp-admin-book-courts" style="display:flex;flex-direction:column;gap:5px">
                                <?php foreach ( $courts as $court ) : ?>
                                <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;font-weight:normal">
                                    <input type="checkbox" class="tnp-admin-court-cb" value="<?php echo (int) $court->id; ?>">
                                    <?php echo esc_html( $court->name ); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:5px 12px 5px 0;font-size:13px"><?php esc_html_e( 'Dauer', 'tennis-pro' ); ?></th>
                        <td style="padding:5px 0">
                            <select id="tnp-admin-book-dur" class="regular-text">
                                <?php for ( $dd = 1; $dd <= 8; $dd++ ) : ?>
                                    <option value="<?php echo $dd; ?>"><?php echo esc_html( tennis_pro_duration_label( $dd ) ); ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:5px 12px 5px 0;font-size:13px"><?php esc_html_e( 'Kategorie', 'tennis-pro' ); ?></th>
                        <td style="padding:5px 0">
                            <select id="tnp-admin-book-cat" class="regular-text">
                                <option value="0"><?php esc_html_e( '– keine –', 'tennis-pro' ); ?></option>
                                <?php foreach ( $cats as $cat ) : ?>
                                    <option value="<?php echo (int) $cat->id; ?>"><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php if ( ! empty( $trainers ) ) : ?>
                    <tr>
                        <th style="padding:5px 12px 5px 0;font-size:13px"><?php esc_html_e( 'Trainer', 'tennis-pro' ); ?></th>
                        <td style="padding:5px 0">
                            <select id="tnp-admin-book-trainer" class="regular-text">
                                <option value="0"><?php esc_html_e( '– kein –', 'tennis-pro' ); ?></option>
                                <?php foreach ( $trainers as $tr ) : ?>
                                    <option value="<?php echo (int) $tr->id; ?>"><?php echo esc_html( $tr->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th style="padding:5px 12px 5px 0;font-size:13px"><?php esc_html_e( 'Name / Kommentar', 'tennis-pro' ); ?></th>
                        <td style="padding:5px 0">
                            <input type="text" id="tnp-admin-book-name" class="regular-text" maxlength="100"
                                   placeholder="<?php esc_attr_e( 'Optional…', 'tennis-pro' ); ?>">
                        </td>
                    </tr>
                    <!-- ── Wiederkehrend ── -->
                    <tr>
                        <th style="padding:5px 12px 5px 0;font-size:13px"><?php esc_html_e( 'Wiederkehrend', 'tennis-pro' ); ?></th>
                        <td style="padding:5px 0">
                            <label style="font-size:13px">
                                <input type="checkbox" id="tnp-admin-book-recurring">
                                <?php esc_html_e( 'Serienbuchung anlegen', 'tennis-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr id="tnp-admin-rec-row-pattern" style="display:none">
                        <th style="padding:5px 12px 5px 0;font-size:13px"><?php esc_html_e( 'Rhythmus', 'tennis-pro' ); ?></th>
                        <td style="padding:5px 0">
                            <select id="tnp-admin-rec-pattern" class="regular-text">
                                <option value="weekly"><?php esc_html_e( 'Wöchentlich', 'tennis-pro' ); ?></option>
                                <option value="daily"><?php esc_html_e( 'Täglich', 'tennis-pro' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr id="tnp-admin-rec-row-dow" style="display:none">
                        <th style="padding:5px 12px 5px 0;font-size:13px"><?php esc_html_e( 'Wochentag', 'tennis-pro' ); ?></th>
                        <td style="padding:5px 0"><em id="tnp-admin-rec-dow-label" style="font-size:13px;color:#555"></em></td>
                    </tr>
                    <tr id="tnp-admin-rec-row-end" style="display:none">
                        <th style="padding:5px 12px 5px 0;font-size:13px"><?php esc_html_e( 'Enddatum', 'tennis-pro' ); ?></th>
                        <td style="padding:5px 0">
                            <input type="date" id="tnp-admin-rec-end" class="regular-text">
                        </td>
                    </tr>
                </table>

                <p id="tnp-admin-book-error" style="display:none;color:#b32d2e;margin:10px 0 0;font-size:13px"></p>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap">
                    <button id="tnp-admin-book-submit" class="button button-primary">✅ <?php esc_html_e( 'Buchen', 'tennis-pro' ); ?></button>
                    <button id="tnp-admin-book-close"  class="button"><?php esc_html_e( 'Abbrechen', 'tennis-pro' ); ?></button>
                </div>
            </div>
        </div>

    </div><!-- .wrap -->

    <script>
    /* ── Select-all checkbox ── */
    document.getElementById('cb-all')?.addEventListener('change', function() {
        document.querySelectorAll('input[name="ids[]"]').forEach(function(cb) { cb.checked = this.checked; }, this);
    });

    (function() {
        'use strict';
        var AJAX_URL   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var AJAX_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'tennis_frontend_nonce' ) ); ?>;

        function tnpPost(action, data) {
            var params = Object.assign({ action: action, nonce: AJAX_NONCE }, data);
            return fetch(AJAX_URL, {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : new URLSearchParams(params).toString(),
            }).then(function(r) { return r.json(); });
        }

        /* ════════════════════════════════════════
           DELETE MODAL
        ════════════════════════════════════════ */
        var delOverlay    = document.getElementById('tnp-admin-del-overlay');
        var delInfo       = document.getElementById('tnp-del-info');
        var delSeriesBtns = document.getElementById('tnp-del-series-btns');
        var delSimpleBtns = document.getElementById('tnp-del-simple-btns');
        var pendingHref   = '';
        var pendingRecur  = 0;

        function openDelModal(info, recurId, href) {
            if (!delOverlay) return;
            if (delInfo) delInfo.textContent = info;
            pendingHref  = href;
            pendingRecur = recurId;
            // "Nur diesen Termin" only when there is a specific booking URL
            var singleBtn = document.getElementById('tnp-del-single-btn');
            if (singleBtn) singleBtn.style.display = (recurId > 0 && href !== '') ? '' : 'none';
            if (delSeriesBtns) delSeriesBtns.style.display = recurId > 0 ? 'flex' : 'none';
            if (delSimpleBtns) delSimpleBtns.style.display = (recurId === 0 && href !== '') ? 'flex' : 'none';
            delOverlay.style.display = 'flex';
        }

        function closeDelModal() {
            if (delOverlay) delOverlay.style.display = 'none';
            pendingHref  = '';
            pendingRecur = 0;
        }

        // Abbrechen always aborts everything
        document.getElementById('tnp-del-abort-btn')?.addEventListener('click', closeDelModal);
        if (delOverlay) delOverlay.addEventListener('click', function(e) {
            if (e.target === delOverlay) closeDelModal();
        });

        // Non-series: "Ja, löschen"
        document.getElementById('tnp-del-confirm-btn')?.addEventListener('click', function() {
            var href = pendingHref; closeDelModal();
            if (href) window.location.href = href;
        });

        // Series: only this booking
        document.getElementById('tnp-del-single-btn')?.addEventListener('click', function() {
            var href = pendingHref; closeDelModal();
            if (href) window.location.href = href;
        });

        // Series: whole series via AJAX
        document.getElementById('tnp-del-whole-btn')?.addEventListener('click', function() {
            var recurId = pendingRecur; closeDelModal();
            if (!recurId) return;
            tnpPost('tennis_cancel_recurring', { recurring_id: recurId })
                .then(function(res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        alert((res.data && res.data.message) || <?php echo wp_json_encode( __( 'Fehler beim Stornieren.', 'tennis-pro' ) ); ?>);
                    }
                }).catch(function() {
                    alert(<?php echo wp_json_encode( __( 'Netzwerkfehler.', 'tennis-pro' ) ); ?>);
                });
        });

        // Wire delete buttons in the grid
        document.querySelectorAll('.tnp-admin-del-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation(); // don't bubble up to the td (booking dialog)
                var td      = this.closest('td');
                var recurId = parseInt(this.dataset.recurringId || 0, 10);
                var href    = this.dataset.href || '';
                var date    = (td && td.dataset.date) || '';
                var time    = (td && td.dataset.time) || '';
                var info    = date + ' · ' + time + ' Uhr' + (recurId > 0 ? '  🔁' : '');
                openDelModal(info, recurId, href);
            });
        });

        /* ════════════════════════════════════════
           BOOKING MODAL (click on free slot)
        ════════════════════════════════════════ */
        var bookOverlay = document.getElementById('tnp-admin-book-overlay');
        var bookDate    = '';
        var bookTime    = '';

        var DOW_NAMES = <?php echo wp_json_encode( [
            __( 'Sonntag',    'tennis-pro' ),
            __( 'Montag',     'tennis-pro' ),
            __( 'Dienstag',   'tennis-pro' ),
            __( 'Mittwoch',   'tennis-pro' ),
            __( 'Donnerstag', 'tennis-pro' ),
            __( 'Freitag',    'tennis-pro' ),
            __( 'Samstag',    'tennis-pro' ),
        ] ); ?>;

        function closeBookModal() {
            if (bookOverlay) bookOverlay.style.display = 'none';
            var err = document.getElementById('tnp-admin-book-error');
            if (err) { err.style.display = 'none'; err.textContent = ''; }
        }

        function showRecurringRows(show) {
            ['tnp-admin-rec-row-pattern', 'tnp-admin-rec-row-end'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.style.display = show ? '' : 'none';
            });
            var pat    = document.getElementById('tnp-admin-rec-pattern');
            var dowRow = document.getElementById('tnp-admin-rec-row-dow');
            if (dowRow) dowRow.style.display = (show && pat && pat.value === 'weekly') ? '' : 'none';
        }

        document.getElementById('tnp-admin-book-close')?.addEventListener('click', closeBookModal);
        if (bookOverlay) bookOverlay.addEventListener('click', function(e) {
            if (e.target === bookOverlay) closeBookModal();
        });

        // Recurring toggle
        document.getElementById('tnp-admin-book-recurring')?.addEventListener('change', function() {
            showRecurringRows(this.checked);
            if (this.checked) {
                var endEl = document.getElementById('tnp-admin-rec-end');
                if (endEl && !endEl.value && bookDate) {
                    var d = new Date(bookDate + 'T00:00:00');
                    d.setMonth(d.getMonth() + 3);
                    endEl.value = d.toISOString().substring(0, 10);
                }
            }
        });

        // Pattern change → show/hide DOW row
        document.getElementById('tnp-admin-rec-pattern')?.addEventListener('change', function() {
            var dowRow = document.getElementById('tnp-admin-rec-row-dow');
            if (dowRow) dowRow.style.display = (this.value === 'weekly') ? '' : 'none';
        });

        // Free cell click → open booking modal
        document.querySelectorAll('.tnp-admin-bookable').forEach(function(cell) {
            cell.addEventListener('click', function() {
                var courtId = this.dataset.court || '';
                bookDate    = this.dataset.date  || '';
                bookTime    = this.dataset.time  || '';

                var meta = document.getElementById('tnp-admin-book-meta');
                if (meta) meta.textContent = bookDate + ' · ' + bookTime + ' Uhr';

                // Pre-check clicked court, uncheck all others
                document.querySelectorAll('.tnp-admin-court-cb').forEach(function(cb) {
                    cb.checked = (cb.value === courtId);
                });

                // Reset form
                var dur   = document.getElementById('tnp-admin-book-dur');
                var cat   = document.getElementById('tnp-admin-book-cat');
                var trSel = document.getElementById('tnp-admin-book-trainer');
                var name  = document.getElementById('tnp-admin-book-name');
                var recur = document.getElementById('tnp-admin-book-recurring');
                var endEl = document.getElementById('tnp-admin-rec-end');
                var patEl = document.getElementById('tnp-admin-rec-pattern');
                if (dur)   dur.value       = '1';
                if (cat)   cat.value       = '0';
                if (trSel) trSel.value     = '0';
                if (name)  name.value      = '';
                if (recur) recur.checked   = false;
                if (endEl) endEl.value     = '';
                if (patEl) patEl.value     = 'weekly';
                showRecurringRows(false);

                // Update DOW label for clicked date
                if (bookDate) {
                    var d   = new Date(bookDate + 'T00:00:00');
                    var dowLabel = document.getElementById('tnp-admin-rec-dow-label');
                    if (dowLabel) dowLabel.textContent = DOW_NAMES[d.getDay()] || '';
                }

                if (bookOverlay) bookOverlay.style.display = 'flex';
            });
        });

        // Submit booking (single or multi-court)
        document.getElementById('tnp-admin-book-submit')?.addEventListener('click', function() {
            if (!bookDate || !bookTime) return;
            var btn = this;
            btn.disabled = true;
            var origHTML = btn.innerHTML;
            btn.innerHTML = '⏳';

            // Collect checked courts
            var checkedCbs  = Array.from(document.querySelectorAll('.tnp-admin-court-cb:checked'));
            var courtIds    = checkedCbs.map(function(cb) { return cb.value; });
            var errEl       = document.getElementById('tnp-admin-book-error');

            if (courtIds.length === 0) {
                btn.disabled = false; btn.innerHTML = origHTML;
                if (errEl) { errEl.textContent = <?php echo wp_json_encode( __( 'Bitte mindestens einen Platz auswählen.', 'tennis-pro' ) ); ?>; errEl.style.display = 'block'; }
                return;
            }

            // Build court-name map for readable error messages
            var courtNameMap = {};
            document.querySelectorAll('.tnp-admin-court-cb').forEach(function(cb) {
                var lbl = cb.closest('label');
                courtNameMap[cb.value] = lbl ? lbl.textContent.trim() : cb.value;
            });

            var isRecurring = document.getElementById('tnp-admin-book-recurring')?.checked;

            var baseParams = {
                date       : bookDate,
                timeslot   : bookTime,
                duration   : document.getElementById('tnp-admin-book-dur')?.value     || '1',
                category_id: document.getElementById('tnp-admin-book-cat')?.value     || '0',
                trainer_id : document.getElementById('tnp-admin-book-trainer')?.value || '0',
                player_name: document.getElementById('tnp-admin-book-name')?.value    || '',
                recurring  : isRecurring ? '1' : '',
            };

            if (isRecurring) {
                var pat = document.getElementById('tnp-admin-rec-pattern')?.value || 'weekly';
                var d   = new Date(bookDate + 'T00:00:00');
                baseParams.rec_pattern     = pat;
                baseParams.rec_day_of_week = String(d.getDay());
                baseParams.rec_end_date    = document.getElementById('tnp-admin-rec-end')?.value || '';
            }

            // Fire one request per court, then consolidate
            Promise.all(courtIds.map(function(cid) {
                return tnpPost('tennis_save', Object.assign({ court_id: cid }, baseParams));
            })).then(function(results) {
                btn.disabled = false;
                btn.innerHTML = origHTML;

                var anySuccess   = false;
                var allSkipped   = [];
                var totalCreated = 0;
                var errors       = [];

                results.forEach(function(res, i) {
                    if (res.success) {
                        anySuccess = true;
                        if (res.data.recurring) {
                            totalCreated += (res.data.created || 0);
                            var slots = Array.isArray(res.data.skipped_slots) ? res.data.skipped_slots : [];
                            slots.forEach(function(s) { allSkipped.push(s); });
                        }
                    } else {
                        var cName = courtNameMap[courtIds[i]] || courtIds[i];
                        errors.push(cName + ': ' + ((res.data && res.data.message) || 'Fehler'));
                    }
                });

                if (anySuccess) {
                    // Build optional summary alert for recurring / partial errors
                    var needAlert = isRecurring && (allSkipped.length > 0 || errors.length > 0);
                    if (!isRecurring && errors.length > 0) needAlert = true;
                    if (needAlert) {
                        var msg = isRecurring
                            ? (<?php echo wp_json_encode( __( 'Serie angelegt', 'tennis-pro' ) ); ?> + ': ' + totalCreated + ' ' + <?php echo wp_json_encode( __( 'Termin(e).', 'tennis-pro' ) ); ?>)
                            : <?php echo wp_json_encode( __( 'Teilweise gespeichert.', 'tennis-pro' ) ); ?>;
                        if (errors.length > 0)
                            msg += '\n\n' + <?php echo wp_json_encode( __( 'Fehler:', 'tennis-pro' ) ); ?> + '\n' + errors.join('\n');
                        if (allSkipped.length > 0) {
                            var lines = allSkipped.map(function(s) {
                                return '• ' + (s.date_fmt || s.date) + ' · ' + s.timeslot + ' Uhr · ' + (s.court || '');
                            });
                            msg += '\n\n' + <?php echo wp_json_encode( __( 'Folgende Termine wurden übersprungen (bereits belegt oder gesperrt):', 'tennis-pro' ) ); ?> + '\n' + lines.join('\n');
                        }
                        alert(msg);
                    }
                    closeBookModal();
                    window.location.reload();
                } else {
                    if (errEl) {
                        errEl.textContent = errors.join(' · ') || 'Fehler.';
                        errEl.style.display = 'block';
                    }
                }
            }).catch(function() {
                btn.disabled = false;
                btn.innerHTML = origHTML;
                if (errEl) {
                    errEl.textContent = <?php echo wp_json_encode( __( 'Netzwerkfehler.', 'tennis-pro' ) ); ?>;
                    errEl.style.display = 'block';
                }
            });
        });
        /* ════════════════════════════════════════
           AKTIVE SERIEN – Tabs + Cancel
        ════════════════════════════════════════ */

        // Tab switching
        var ovTabBtns   = document.querySelectorAll('.tnp-ov-tab-btn');
        var ovTabPanels = document.querySelectorAll('.tnp-ov-tab-panel');

        function activateOvTab(targetId) {
            ovTabBtns.forEach(function(b) {
                var isActive = b.dataset.tab === targetId;
                b.style.background = isActive ? b.dataset.bg   : '#f0f0f0';
                b.style.color      = isActive ? b.dataset.tc   : '#444';
                b.style.borderColor = isActive ? b.dataset.bg  : '#ddd';
                b.style.borderBottomColor = isActive ? b.dataset.bg : '#ddd';
            });
            ovTabPanels.forEach(function(p) {
                p.style.display = p.id === targetId ? '' : 'none';
            });
        }

        ovTabBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                activateOvTab(this.dataset.tab);
            });
        });

        // Activate first tab on load
        if (ovTabBtns.length > 0) activateOvTab(ovTabBtns[0].dataset.tab);

        // Cancel series from overview
        document.querySelectorAll('.tnp-ov-cancel-series').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var seriesId = parseInt(this.dataset.id || 0, 10);
                var label    = this.dataset.label || '';
                openDelModal(label, seriesId, ''); // href='' → series-only mode
            });
        });

    })();
    </script>
    <?php
}

/* ══════════════════════════════════════════════════════════════════════════
   SETTINGS PAGE (courts + categories)
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_settings_page() {
    if ( ! current_user_can( 'tennis_manage' ) ) wp_die( __( 'Zugriff verweigert.', 'tennis-pro' ) );
    global $wpdb;

    $nonce = sanitize_text_field( wp_unslash( $_POST['tennis_settings_nonce'] ?? '' ) );
    $valid = wp_verify_nonce( $nonce, 'tennis_settings_action' );

    if ( $valid ) {
        if ( isset( $_POST['add_court'] ) ) {
            $name = sanitize_text_field( wp_unslash( $_POST['court_name'] ?? '' ) );
            if ( $name !== '' ) $wpdb->insert( $wpdb->prefix . 'tennis_courts', [
                'name'     => $name,
                'color'    => sanitize_hex_color( wp_unslash( $_POST['court_color']    ?? '#ffffff' ) ) ?: '#ffffff',
                'bg_color' => sanitize_hex_color( wp_unslash( $_POST['court_bg_color'] ?? '#2e7d32' ) ) ?: '#2e7d32',
            ], [ '%s', '%s', '%s' ] );
        }
        if ( isset( $_POST['update_court'] ) ) {
            $wpdb->update( $wpdb->prefix . 'tennis_courts',
                [
                    'name'     => sanitize_text_field( wp_unslash( $_POST['court_name'] ?? '' ) ),
                    'color'    => sanitize_hex_color( wp_unslash( $_POST['court_color']    ?? '#ffffff' ) ) ?: '#ffffff',
                    'bg_color' => sanitize_hex_color( wp_unslash( $_POST['court_bg_color'] ?? '#2e7d32' ) ) ?: '#2e7d32',
                ],
                [ 'id' => (int) ( $_POST['court_id'] ?? 0 ) ], [ '%s', '%s', '%s' ], [ '%d' ] );
        }
        if ( isset( $_POST['delete_court'] ) ) {
            $wpdb->delete( $wpdb->prefix . 'tennis_courts', [ 'id' => (int) ( $_POST['court_id'] ?? 0 ) ], [ '%d' ] );
        }
        if ( isset( $_POST['add_cat'] ) ) {
            $wpdb->insert( $wpdb->prefix . 'tennis_categories', [
                'name'       => sanitize_text_field( wp_unslash( $_POST['cat_name']  ?? '' ) ),
                'color'      => sanitize_hex_color( wp_unslash( $_POST['cat_color']  ?? '#2e7d32' ) ) ?: '#2e7d32',
                'text_color' => sanitize_hex_color( wp_unslash( $_POST['cat_text']   ?? '#ffffff' ) ) ?: '#ffffff',
                'admin_only' => isset( $_POST['cat_admin_only'] ) ? 1 : 0,
            ], [ '%s', '%s', '%s', '%d' ] );
        }
        if ( isset( $_POST['update_cat'] ) ) {
            $wpdb->update( $wpdb->prefix . 'tennis_categories',
                [
                    'name'       => sanitize_text_field( wp_unslash( $_POST['cat_name']  ?? '' ) ),
                    'color'      => sanitize_hex_color( wp_unslash( $_POST['cat_color']  ?? '#2e7d32' ) ) ?: '#2e7d32',
                    'text_color' => sanitize_hex_color( wp_unslash( $_POST['cat_text']   ?? '#ffffff' ) ) ?: '#ffffff',
                    'admin_only' => isset( $_POST['cat_admin_only'] ) ? 1 : 0,
                ],
                [ 'id' => (int) ( $_POST['cat_id'] ?? 0 ) ], [ '%s', '%s', '%s', '%d' ], [ '%d' ] );
        }
        if ( isset( $_POST['delete_cat'] ) ) {
            $wpdb->delete( $wpdb->prefix . 'tennis_categories', [ 'id' => (int) ( $_POST['cat_id'] ?? 0 ) ], [ '%d' ] );
        }
        if ( isset( $_POST['add_trainer'] ) ) {
            $tname = sanitize_text_field( wp_unslash( $_POST['trainer_name'] ?? '' ) );
            if ( $tname !== '' ) $wpdb->insert( $wpdb->prefix . 'tennis_trainers',
                [ 'name' => $tname ], [ '%s' ] );
        }
        if ( isset( $_POST['delete_trainer'] ) ) {
            $wpdb->delete( $wpdb->prefix . 'tennis_trainers', [ 'id' => (int) ( $_POST['trainer_id'] ?? 0 ) ], [ '%d' ] );
        }
    }

    $courts   = tennis_pro_get_courts();
    $cats     = tennis_pro_get_categories();
    $trainers = tennis_pro_get_trainers();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Tennisplatz – Einstellungen', 'tennis-pro' ); ?></h1>
        <div class="notice notice-info is-dismissible"><p><?php
            printf( esc_html__( 'Shortcodes: %1$s (Buchungsansicht) · %2$s (Meine Buchungen)', 'tennis-pro' ),
                '<code>[tennis_booking]</code>', '<code>[tennis_my_bookings]</code>' );
        ?></p></div>

        <!-- ══ TAB NAVIGATION ══════════════════════════════════════════════ -->
        <style>
        /* Tab styles – identical to options page for visual consistency */
        .tnp-stabs { display:flex; gap:0; margin:16px 0 0; border-bottom:1px solid #c3c4c7; flex-wrap:wrap; }
        .tnp-stab-btn {
            background:#f0f0f1; border:1px solid #c3c4c7; border-bottom:none;
            padding:8px 16px; cursor:pointer; font-size:13px; font-weight:500;
            border-radius:3px 3px 0 0; color:#2c3338; margin-bottom:-1px;
            position:relative; transition:background .1s;
        }
        .tnp-stab-btn:hover { background:#fff; color:#2271b1; }
        .tnp-stab-btn.is-active { background:#fff; color:#1d2327; font-weight:600; border-bottom-color:#fff; z-index:1; }
        .tnp-stab-panel { display:none; padding:20px 0 0; max-width:720px; }
        .tnp-stab-panel.is-active { display:block; }
        </style>

        <nav class="tnp-stabs" id="tnp-cfg-tabs" role="tablist">
            <button type="button" class="tnp-stab-btn" data-tab="courts"   role="tab">🎾 <?php esc_html_e( 'Plätze',     'tennis-pro' ); ?></button>
            <button type="button" class="tnp-stab-btn" data-tab="cats"     role="tab">🏷 <?php esc_html_e( 'Kategorien', 'tennis-pro' ); ?></button>
            <button type="button" class="tnp-stab-btn" data-tab="trainers" role="tab">👤 <?php esc_html_e( 'Trainer',    'tennis-pro' ); ?></button>
        </nav>

        <!-- ══ TAB: PLÄTZE ══════════════════════════════════════════════════ -->
        <div class="tnp-stab-panel" id="tnp-stab-courts">
            <h2>🎾 <?php esc_html_e( 'Plätze', 'tennis-pro' ); ?></h2>
            <?php if ( $courts ) : ?>
            <div style="border:1px solid #c3c4c7;border-radius:3px;margin-bottom:16px;background:#fff">
                <?php foreach ( $courts as $i => $court ) : ?>
                <form method="POST" style="display:flex;align-items:center;gap:8px;padding:10px 14px;flex-wrap:wrap;<?php echo $i > 0 ? 'border-top:1px solid #c3c4c7;' : ''; ?>">
                    <?php wp_nonce_field( 'tennis_settings_action', 'tennis_settings_nonce' ); ?>
                    <input type="hidden" name="court_id" value="<?php echo (int) $court->id; ?>">
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:4px;background:<?php echo esc_attr( $court->bg_color ?: '#2e7d32' ); ?>;color:<?php echo esc_attr( $court->color ?: '#ffffff' ); ?>;font-size:12px;font-weight:600;white-space:nowrap"><?php echo esc_html( $court->name ); ?></span>
                    <input type="text" name="court_name" value="<?php echo esc_attr( $court->name ); ?>" class="regular-text" style="flex:1;min-width:160px">
                    <label title="<?php esc_attr_e( 'Textfarbe', 'tennis-pro' ); ?>" style="display:flex;align-items:center;gap:4px;font-size:12px">
                        <span><?php esc_html_e( 'Text', 'tennis-pro' ); ?></span>
                        <input type="color" name="court_color" value="<?php echo esc_attr( $court->color ?: '#ffffff' ); ?>">
                    </label>
                    <label title="<?php esc_attr_e( 'Hintergrundfarbe', 'tennis-pro' ); ?>" style="display:flex;align-items:center;gap:4px;font-size:12px">
                        <span><?php esc_html_e( 'HG', 'tennis-pro' ); ?></span>
                        <input type="color" name="court_bg_color" value="<?php echo esc_attr( $court->bg_color ?: '#2e7d32' ); ?>">
                    </label>
                    <button type="submit" name="update_court" class="button button-small" title="<?php esc_attr_e( 'Speichern', 'tennis-pro' ); ?>">💾 <?php esc_html_e( 'Speichern', 'tennis-pro' ); ?></button>
                    <button type="submit" name="delete_court" class="button button-small" title="<?php esc_attr_e( 'Löschen', 'tennis-pro' ); ?>"
                            onclick="return confirm('<?php esc_attr_e( 'Platz wirklich löschen?', 'tennis-pro' ); ?>')" style="color:#b32d2e;border-color:#b32d2e">🗑</button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <h3><?php esc_html_e( 'Neuen Platz anlegen', 'tennis-pro' ); ?></h3>
            <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:12px 14px">
                <?php wp_nonce_field( 'tennis_settings_action', 'tennis_settings_nonce' ); ?>
                <input type="text" name="court_name" placeholder="<?php esc_attr_e( 'Name des Platzes…', 'tennis-pro' ); ?>" class="regular-text" required style="flex:1;min-width:160px">
                <label style="display:flex;align-items:center;gap:4px;font-size:12px">
                    <span><?php esc_html_e( 'Text', 'tennis-pro' ); ?></span>
                    <input type="color" name="court_color" value="#ffffff">
                </label>
                <label style="display:flex;align-items:center;gap:4px;font-size:12px">
                    <span><?php esc_html_e( 'HG', 'tennis-pro' ); ?></span>
                    <input type="color" name="court_bg_color" value="#2e7d32">
                </label>
                <button type="submit" name="add_court" class="button button-primary">+ <?php esc_html_e( 'Hinzufügen', 'tennis-pro' ); ?></button>
            </form>
        </div>

        <!-- ══ TAB: KATEGORIEN ══════════════════════════════════════════════ -->
        <div class="tnp-stab-panel" id="tnp-stab-cats">
            <h2>🏷 <?php esc_html_e( 'Kategorien', 'tennis-pro' ); ?></h2>
            <p class="description" style="margin-bottom:12px"><?php esc_html_e( 'Kategorien werden bei der Buchung ausgewählt und färben den Slot im Raster ein. Admins können Kategorien als "nur Admin" markieren.', 'tennis-pro' ); ?></p>
            <?php if ( $cats ) : ?>
            <div style="border:1px solid #c3c4c7;border-radius:3px;margin-bottom:16px;background:#fff">
                <?php foreach ( $cats as $i => $cat ) : ?>
                <form method="POST" style="display:flex;align-items:center;gap:8px;padding:10px 14px;flex-wrap:wrap;<?php echo $i > 0 ? 'border-top:1px solid #c3c4c7;' : ''; ?>">
                    <?php wp_nonce_field( 'tennis_settings_action', 'tennis_settings_nonce' ); ?>
                    <input type="hidden" name="cat_id" value="<?php echo (int) $cat->id; ?>">
                    <span style="display:inline-block;padding:3px 10px;border-radius:12px;background:<?php echo esc_attr( $cat->color ); ?>;color:<?php echo esc_attr( $cat->text_color ); ?>;font-size:11px;font-weight:700;white-space:nowrap"><?php echo esc_html( $cat->name ); ?></span>
                    <input type="text" name="cat_name" value="<?php echo esc_attr( $cat->name ); ?>" class="regular-text" style="flex:1;min-width:160px">
                    <label title="<?php esc_attr_e( 'Hintergrundfarbe', 'tennis-pro' ); ?>" style="display:flex;align-items:center;gap:4px;font-size:12px">
                        <span><?php esc_html_e( 'HG', 'tennis-pro' ); ?></span>
                        <input type="color" name="cat_color" value="<?php echo esc_attr( $cat->color ); ?>">
                    </label>
                    <label title="<?php esc_attr_e( 'Textfarbe', 'tennis-pro' ); ?>" style="display:flex;align-items:center;gap:4px;font-size:12px">
                        <span><?php esc_html_e( 'Text', 'tennis-pro' ); ?></span>
                        <input type="color" name="cat_text" value="<?php echo esc_attr( $cat->text_color ); ?>">
                    </label>
                    <label title="<?php esc_attr_e( 'Nur Admins können diese Kategorie wählen', 'tennis-pro' ); ?>" style="white-space:nowrap;font-size:12px;display:flex;align-items:center;gap:4px">
                        <input type="checkbox" name="cat_admin_only" value="1" <?php checked( (int) $cat->admin_only ); ?>>
                        <?php esc_html_e( 'Nur Admin', 'tennis-pro' ); ?>
                    </label>
                    <button type="submit" name="update_cat" class="button button-small" title="<?php esc_attr_e( 'Speichern', 'tennis-pro' ); ?>">💾 <?php esc_html_e( 'Speichern', 'tennis-pro' ); ?></button>
                    <button type="submit" name="delete_cat" class="button button-small" title="<?php esc_attr_e( 'Löschen', 'tennis-pro' ); ?>"
                            onclick="return confirm('<?php esc_attr_e( 'Kategorie wirklich löschen?', 'tennis-pro' ); ?>')" style="color:#b32d2e;border-color:#b32d2e">🗑</button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <h3><?php esc_html_e( 'Neue Kategorie anlegen', 'tennis-pro' ); ?></h3>
            <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:12px 14px">
                <?php wp_nonce_field( 'tennis_settings_action', 'tennis_settings_nonce' ); ?>
                <input type="text" name="cat_name" placeholder="<?php esc_attr_e( 'Kategoriename…', 'tennis-pro' ); ?>" class="regular-text" required style="flex:1;min-width:160px">
                <label style="display:flex;align-items:center;gap:4px;font-size:12px">
                    <span><?php esc_html_e( 'HG', 'tennis-pro' ); ?></span>
                    <input type="color" name="cat_color" value="#2e7d32">
                </label>
                <label style="display:flex;align-items:center;gap:4px;font-size:12px">
                    <span><?php esc_html_e( 'Text', 'tennis-pro' ); ?></span>
                    <input type="color" name="cat_text" value="#ffffff">
                </label>
                <label style="font-size:12px;white-space:nowrap;display:flex;align-items:center;gap:4px">
                    <input type="checkbox" name="cat_admin_only" value="1">
                    <?php esc_html_e( 'Nur Admin', 'tennis-pro' ); ?>
                </label>
                <button type="submit" name="add_cat" class="button button-primary">+ <?php esc_html_e( 'Hinzufügen', 'tennis-pro' ); ?></button>
            </form>
        </div>

        <!-- ══ TAB: TRAINER ═════════════════════════════════════════════════ -->
        <div class="tnp-stab-panel" id="tnp-stab-trainers">
            <h2>👤 <?php esc_html_e( 'Trainer', 'tennis-pro' ); ?></h2>
            <p class="description" style="margin-bottom:12px"><?php esc_html_e( 'Trainer können beim Anlegen einer Buchung im Admin-Backend ausgewählt werden. Normale Nutzer sehen diese Auswahl nicht.', 'tennis-pro' ); ?></p>
            <?php if ( $trainers ) : ?>
            <div style="border:1px solid #c3c4c7;border-radius:3px;margin-bottom:16px;background:#fff">
                <?php foreach ( $trainers as $i => $tr ) : ?>
                <form method="POST" style="display:flex;align-items:center;gap:8px;padding:10px 14px;<?php echo $i > 0 ? 'border-top:1px solid #c3c4c7;' : ''; ?>">
                    <?php wp_nonce_field( 'tennis_settings_action', 'tennis_settings_nonce' ); ?>
                    <input type="hidden" name="trainer_id" value="<?php echo (int) $tr->id; ?>">
                    <span style="flex:1;font-size:13px">👤 <?php echo esc_html( $tr->name ); ?></span>
                    <button type="submit" name="delete_trainer" class="button button-small" title="<?php esc_attr_e( 'Löschen', 'tennis-pro' ); ?>"
                            onclick="return confirm('<?php esc_attr_e( 'Trainer wirklich löschen?', 'tennis-pro' ); ?>')" style="color:#b32d2e;border-color:#b32d2e">🗑 <?php esc_html_e( 'Löschen', 'tennis-pro' ); ?></button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php else : ?>
                <p style="color:#888;margin-bottom:16px"><?php esc_html_e( 'Noch keine Trainer angelegt.', 'tennis-pro' ); ?></p>
            <?php endif; ?>

            <h3><?php esc_html_e( 'Neuen Trainer anlegen', 'tennis-pro' ); ?></h3>
            <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:12px 14px">
                <?php wp_nonce_field( 'tennis_settings_action', 'tennis_settings_nonce' ); ?>
                <input type="text" name="trainer_name" placeholder="<?php esc_attr_e( 'Name…', 'tennis-pro' ); ?>" class="regular-text" required style="flex:1;min-width:200px">
                <button type="submit" name="add_trainer" class="button button-primary">+ <?php esc_html_e( 'Hinzufügen', 'tennis-pro' ); ?></button>
            </form>
        </div>

    </div><!-- .wrap -->

    <script>
    (function() {
        var STORAGE_KEY = 'tnp_cfg_tab';
        var tabBtns   = document.querySelectorAll('#tnp-cfg-tabs .tnp-stab-btn');
        var tabPanels = document.querySelectorAll('.tnp-stab-panel');

        function activateTab(tabId) {
            tabBtns.forEach(function(btn) {
                var active = btn.dataset.tab === tabId;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            tabPanels.forEach(function(p) {
                p.classList.toggle('is-active', p.id === 'tnp-stab-' + tabId);
            });
            try { localStorage.setItem(STORAGE_KEY, tabId); } catch(e) {}
        }

        tabBtns.forEach(function(btn) {
            btn.addEventListener('click', function() { activateTab(btn.dataset.tab); });
        });

        var validIds = Array.from(tabBtns).map(function(b) { return b.dataset.tab; });
        var saved;
        try { saved = localStorage.getItem(STORAGE_KEY); } catch(e) {}
        activateTab(validIds.indexOf(saved) !== -1 ? saved : validIds[0]);
    })();
    </script>
    <?php
}
