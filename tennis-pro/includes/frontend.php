<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'tennis_booking',     'tennis_pro_shortcode'          );
add_shortcode( 'tennis_my_bookings', 'tennis_pro_my_bookings_shortcode' );
add_action( 'wp_enqueue_scripts',   'tennis_pro_enqueue_styles'      );

/* ── Admin-Bar: nur für Admins und Tennis-Backend-Admins anzeigen ─────── */
add_filter( 'show_admin_bar', 'tennis_pro_maybe_hide_admin_bar' );

/**
 * Blendet die WordPress-Adminleiste für normale Mitglieder (Subscriber u.ä.)
 * aus. Admins und Nutzer mit der Capability tennis_manage sehen sie weiterhin.
 *
 * @param bool $show
 * @return bool
 */
function tennis_pro_maybe_hide_admin_bar( bool $show ): bool {
    if ( ! $show ) return false;
    if ( ! is_user_logged_in() ) return false;
    return current_user_can( 'tennis_manage' );
}

function tennis_pro_enqueue_styles(): void {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && (
        has_shortcode( $post->post_content, 'tennis_booking' )      ||
        has_shortcode( $post->post_content, 'tennis_my_bookings' )  ||
        has_shortcode( $post->post_content, 'tennis_pro_register' ) ||
        has_shortcode( $post->post_content, 'tennis_pro_profile' )
    ) ) {
        wp_enqueue_style( 'tennis-pro', TENNIS_PRO_URL . 'assets/frontend.css', [], TENNIS_PRO_VER );
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   MAIN BOOKING SHORTCODE
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_shortcode( $atts ): string {
    $atts = shortcode_atts( [
        'start_hour' => 8,
        'end_hour'   => 22,
        'view'       => 'day',   // 'day', 'week', or 'both'
    ], $atts );

    $start_h     = max( 0, min( 23, (int) $atts['start_hour'] ) );
    $end_h       = max( $start_h, min( 23, (int) $atts['end_hour'] ) );
    $default_view = in_array( $atts['view'], [ 'day', 'week', 'both' ], true ) ? $atts['view'] : 'day';

    $is_logged_in = is_user_logged_in();
    $current_uid  = get_current_user_id();
    $is_admin     = current_user_can( 'tennis_manage' );
    $today        = gmdate( 'Y-m-d' );
    $nonce        = $is_logged_in ? wp_create_nonce( 'tennis_frontend_nonce' ) : '';

    // Active view (day/week)
    $active_view = sanitize_key( $_GET['tnp_view'] ?? ( $default_view === 'week' ? 'week' : 'day' ) );
    if ( ! in_array( $active_view, [ 'day', 'week' ], true ) ) $active_view = 'day';

    // Active date
    $date = tennis_pro_validate_date( sanitize_text_field( wp_unslash( $_GET['date'] ?? $today ) ) );

    // Week view: selected court
    $courts       = tennis_pro_get_courts();
    $sel_court_id = (int) ( $_GET['tnp_court'] ?? ( $courts[0]->id ?? 0 ) );

    $cats     = tennis_pro_get_categories();
    $cat_map  = tennis_pro_cat_map( $cats );
    $trainers = tennis_pro_get_trainers();

    // Build date range
    if ( $active_view === 'week' ) {
        $dow     = (int) gmdate( 'N', strtotime( $date ) ); // 1=Mon
        $monday  = gmdate( 'Y-m-d', strtotime( $date . ' -' . ( $dow - 1 ) . ' days' ) );
        $week_dates = [];
        for ( $i = 0; $i < 7; $i++ ) {
            $week_dates[] = gmdate( 'Y-m-d', strtotime( $monday . " +{$i} days" ) );
        }
        $prev_nav = gmdate( 'Y-m-d', strtotime( $monday . ' -7 days' ) );
        $next_nav = gmdate( 'Y-m-d', strtotime( $monday . ' +7 days' ) );
        // Load all bookings for the week
        $all_bookings = tennis_pro_get_bookings_for_range( $week_dates[0], end( $week_dates ) );
        $all_blocked  = tennis_pro_get_blocked_for_range( $week_dates[0], end( $week_dates ) );
        $bmap_by_date = [];
        $blk_by_date  = [];
        foreach ( $week_dates as $wd ) {
            $day_b = array_filter( $all_bookings, fn($b) => $b->date === $wd );
            $day_bl= array_filter( $all_blocked,  fn($b) => $b->date === $wd );
            $bmap_by_date[$wd] = tennis_pro_build_map( array_values($day_b) );
            $blk_by_date[$wd]  = tennis_pro_build_blocked_map( array_values($day_bl), $courts );
        }
    } else {
        $week_dates   = [ $date ];
        $prev_nav     = gmdate( 'Y-m-d', strtotime( $date . ' -1 day' ) );
        $next_nav     = gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) );
        $bookings     = tennis_pro_get_bookings_for_date( $date );
        $blocked      = tennis_pro_get_blocked_for_date( $date );
        $bmap_by_date = [ $date => tennis_pro_build_map( $bookings ) ];
        $blk_by_date  = [ $date => tennis_pro_build_blocked_map( $blocked, $courts ) ];
    }

    // Settings (colours + My Calendar)
    $s_opts         = tennis_pro_get_settings();

    /* ── My Calendar integration ─────────────────────────────────────── */
    if ( (int) $s_opts['mycal_enabled'] ) {
        $mycal_cat_ids  = array_filter( array_map( 'intval', explode( ',', $s_opts['mycal_categories'] ?? '' ) ) );
        $mycal_horizon  = max( 1, (int) ( $s_opts['mycal_horizon'] ?? 30 ) );
        $mycal_cids     = array_filter( array_map( 'intval', explode( ',', $s_opts['mycal_courts'] ?? '' ) ) );
        $horizon_end    = gmdate( 'Y-m-d', strtotime( "+{$mycal_horizon} days" ) );

        if ( ! empty( $mycal_cat_ids ) ) {
            // Determine the range we actually need
            if ( $active_view === 'week' ) {
                $range_start = $week_dates[0];
                $range_end   = end( $week_dates );
            } else {
                $range_start = $date;
                $range_end   = $date;
            }

            // Only query if within the horizon
            if ( $range_start <= $horizon_end ) {
                $eff_end      = min( $range_end, $horizon_end );
                $mycal_events = tennis_pro_get_mycal_events_for_range( $range_start, $eff_end, $mycal_cat_ids );
                tennis_pro_merge_mycal_into_blk_map( $blk_by_date, $mycal_events, $courts, $mycal_cids );
            }
        }
    }
    /* ── End My Calendar ─────────────────────────────────────────────── */
    $slot_odd_bg    = esc_attr( $s_opts['slot_free_odd_bg']    ?: '#f0f4ff' );
    $slot_odd_tc    = esc_attr( $s_opts['slot_free_odd_text']  ?: '#aaaaaa' );
    $slot_even_bg   = esc_attr( $s_opts['slot_free_even_bg']   ?: '#e8f5e9' );
    $slot_even_tc   = esc_attr( $s_opts['slot_free_even_text'] ?: '#aaaaaa' );
    // Time-column colours
    $time_col_bg    = esc_attr( $s_opts['time_col_bg']   ?: '#1565c0' );
    $time_col_text  = esc_attr( $s_opts['time_col_text'] ?: '#ffffff' );
    $time_col_style = "background:{$time_col_bg};color:{$time_col_text};";

    $login_url  = wp_login_url( get_permalink() . '?date=' . $date );
    $logout_url = wp_logout_url( get_permalink() . '?date=' . $date );
    $ical_url   = $is_logged_in ? wp_nonce_url( add_query_arg( 'tennis_ical', '1', get_permalink() ?: home_url() ), 'tennis_ical_user' ) : '';

    /* ── Enqueue JS ── */
    wp_enqueue_script( 'tennis-pro-frontend', TENNIS_PRO_URL . 'assets/frontend.js', [], TENNIS_PRO_VER, true );
    // Build trainer list for JS
    $trainers_js = [];
    foreach ( $trainers as $tr ) {
        $trainers_js[] = [ 'id' => (int) $tr->id, 'name' => $tr->name ];
    }

    $current_user_name = $is_logged_in ? wp_get_current_user()->display_name : '';

    wp_localize_script( 'tennis-pro-frontend', 'TennisPro', [
        'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
        'nonce'              => $nonce,
        'date'               => $date,
        'loggedIn'           => $is_logged_in ? '1' : '0',
        'isAdmin'            => $is_admin     ? '1' : '0',
        'currentUserName'    => $current_user_name,
        'cancelDeadlineHours'=> (int) ( $s_opts['cancel_deadline'] ?? 0 ),
        'bookingHorizonDays' => (int) ( $s_opts['booking_horizon'] ?? 0 ),
        'trainers'           => $trainers_js,
        'i18n'               => [
            'confirmDelete'  => __( 'Buchung wirklich löschen?',            'tennis-pro' ),
            'confirmSeries'  => __( 'Gesamte Serie stornieren?',            'tennis-pro' ),
            'networkError'   => __( 'Netzwerkfehler.',                      'tennis-pro' ),
            'waitlistJoined' => __( 'Du stehst jetzt auf der Warteliste.',  'tennis-pro' ),
            'waitlistLeft'   => __( 'Von Warteliste entfernt.',             'tennis-pro' ),
        ],
    ] );

    // CI: inject custom properties so CSS can pick them up without inline styles everywhere
    $ci_topbar_bg    = esc_attr( $s_opts['ci_topbar_bg']    ?? '#1b5e20' );
    $ci_topbar_bg2   = esc_attr( $s_opts['ci_topbar_bg2']   ?? '#0d47a1' );
    $ci_topbar_text  = esc_attr( $s_opts['ci_topbar_text']  ?? '#ffffff' );
    $ci_primary      = esc_attr( $s_opts['ci_primary']      ?? '#2e7d32' );
    $ci_datebar_bg   = esc_attr( $s_opts['ci_datebar_bg']   ?? '#388e3c' );
    $ci_datebar_bg2  = esc_attr( $s_opts['ci_datebar_bg2']  ?? '#1565c0' );
    $ci_datebar_text = esc_attr( $s_opts['ci_datebar_text'] ?? '#ffffff' );
    $ci_font         = $s_opts['ci_font_family'] ?? '';

    $legend_pos    = ( ( $s_opts['legend_position'] ?? 'bottom' ) === 'top' ) ? 'top' : 'bottom';
    $legend_open   = (bool) ( $s_opts['legend_default_open'] ?? 1 );

    ob_start();
    ?>
    <style>
    #tnp-app {
        --tnp-ci-topbar-bg    : <?php echo $ci_topbar_bg; ?>;
        --tnp-ci-topbar-bg2   : <?php echo $ci_topbar_bg2; ?>;
        --tnp-ci-topbar-text  : <?php echo $ci_topbar_text; ?>;
        --tnp-ci-primary      : <?php echo $ci_primary; ?>;
        --tnp-ci-datebar-bg   : <?php echo $ci_datebar_bg; ?>;
        --tnp-ci-datebar-bg2  : <?php echo $ci_datebar_bg2; ?>;
        --tnp-ci-datebar-text : <?php echo $ci_datebar_text; ?>;
        <?php if ( $ci_font ) : ?>
        --tnp-ci-font         : <?php echo esc_attr( $ci_font ); ?>;
        <?php endif; ?>
    }
    </style>
    <div class="tnp-wrap" id="tnp-app" data-date="<?php echo esc_attr( $date ); ?>">

        <!-- ── Top bar ── -->
        <div class="tnp-topbar">
            <div class="tnp-topbar__left">
                <span class="tnp-logo">🎾 <?php esc_html_e( 'Tennisplatz-Reservierung', 'tennis-pro' ); ?></span>
            </div>
            <div class="tnp-topbar__right">
                <?php if ( $is_logged_in ) : ?>
                    <span class="tnp-user">👤 <?php echo esc_html( wp_get_current_user()->display_name ); ?></span>
                    <?php if ( ! empty( $s_opts['show_profile_btn'] ) && ! empty( $s_opts['profile_page_id'] ) ) : ?>
                        <a href="<?php echo esc_url( get_permalink( (int) $s_opts['profile_page_id'] ) ); ?>" class="tnp-btn tnp-btn--sm tnp-btn--outline" title="<?php esc_attr_e( 'Meine Daten bearbeiten', 'tennis-pro' ); ?>">👤 <span class="tnp-btn-label"><?php esc_html_e( 'Mein Profil', 'tennis-pro' ); ?></span></a>
                    <?php endif; ?>
                    <?php if ( $ical_url ) : ?>
                        <a href="<?php echo esc_url( $ical_url ); ?>" class="tnp-btn tnp-btn--sm tnp-btn--outline" title="<?php esc_attr_e( 'Meine Buchungen als iCal exportieren', 'tennis-pro' ); ?>">📅 <span class="tnp-btn-label">iCal</span></a>
                    <?php endif; ?>
                    <?php if ( $is_admin ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tennis-pro' ) ); ?>" class="tnp-btn tnp-btn--sm tnp-btn--outline" title="<?php esc_attr_e( 'Admin-Bereich', 'tennis-pro' ); ?>">⚙ <span class="tnp-btn-label"><?php esc_html_e( 'Admin', 'tennis-pro' ); ?></span></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $logout_url ); ?>" class="tnp-btn tnp-btn--sm tnp-btn--outline" title="<?php esc_attr_e( 'Abmelden', 'tennis-pro' ); ?>">⇤ <span class="tnp-btn-label"><?php esc_html_e( 'Abmelden', 'tennis-pro' ); ?></span></a>
                <?php else : ?>
                    <?php if ( ! empty( $s_opts['show_register_btn'] ) && ! empty( $s_opts['register_page_id'] ) ) : ?>
                        <a href="<?php echo esc_url( get_permalink( (int) $s_opts['register_page_id'] ) ); ?>" class="tnp-btn tnp-btn--sm tnp-btn--outline" title="<?php esc_attr_e( 'Neues Konto erstellen', 'tennis-pro' ); ?>">📝 <span class="tnp-btn-label"><?php esc_html_e( 'Registrieren', 'tennis-pro' ); ?></span></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $login_url ); ?>" class="tnp-btn tnp-btn--sm tnp-btn--primary">🔐 <span class="tnp-btn-label"><?php esc_html_e( 'Einloggen', 'tennis-pro' ); ?></span></a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── View toggle + date nav ── -->
        <div class="tnp-datebar">
            <a href="<?php echo esc_url( add_query_arg( [ 'date' => $date, 'tnp_view' => 'day' ] ) ); ?>"
               class="tnp-btn tnp-btn--sm <?php echo $active_view === 'day' ? 'tnp-btn--primary' : 'tnp-btn--outline'; ?>">
               <?php esc_html_e( 'Tag', 'tennis-pro' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( [ 'date' => $date, 'tnp_view' => 'week' ] ) ); ?>"
               class="tnp-btn tnp-btn--sm <?php echo $active_view === 'week' ? 'tnp-btn--primary' : 'tnp-btn--outline'; ?>">
               <?php esc_html_e( 'Woche', 'tennis-pro' ); ?>
            </a>

            <a href="<?php echo esc_url( add_query_arg( [ 'date' => $prev_nav, 'tnp_view' => $active_view ] ) ); ?>" class="tnp-btn tnp-btn--nav">‹</a>

            <form method="GET" class="tnp-datepicker-form">
                <?php foreach ( $_GET as $k => $v ) :
                    if ( $k === 'date' ) continue;
                    echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
                endforeach; ?>
                <input type="date" name="date" value="<?php echo esc_attr( $date ); ?>" class="tnp-datepicker" onchange="this.form.submit()">
            </form>

            <a href="<?php echo esc_url( add_query_arg( [ 'date' => ( $date === $today ? $next_nav : $today ), 'tnp_view' => $active_view ] ) ); ?>"
               class="tnp-btn tnp-btn--sm <?php echo $date === $today ? 'tnp-btn--outline' : 'tnp-btn--accent'; ?>">
               <?php echo $date === $today ? esc_html__( 'Morgen →', 'tennis-pro' ) : esc_html__( 'Heute', 'tennis-pro' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( [ 'date' => $next_nav, 'tnp_view' => $active_view ] ) ); ?>" class="tnp-btn tnp-btn--nav">›</a>

            <?php if ( $active_view === 'week' && count( $courts ) > 1 ) : ?>
                <span class="tnp-datebar__sep">|</span>
                <?php foreach ( $courts as $court ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'date' => $date, 'tnp_view' => 'week', 'tnp_court' => $court->id ] ) ); ?>"
                       class="tnp-btn tnp-btn--sm <?php echo (int) $court->id === $sel_court_id ? 'tnp-btn--primary' : 'tnp-btn--outline'; ?>">
                       <?php echo esc_html( $court->name ); ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <span class="tnp-datebar__label">
                <?php
                if ( $active_view === 'week' ) {
                    echo esc_html( date_i18n( 'j. F', strtotime( $week_dates[0] ) ) . ' – ' . date_i18n( 'j. F Y', strtotime( end( $week_dates ) ) ) );
                } else {
                    echo esc_html( date_i18n( 'l, j. F Y', strtotime( $date ) ) );
                }
                ?>
            </span>
        </div>

        <?php if ( ! empty( $cats ) && $legend_pos === 'top' ) : ?>
        <!-- ── Legend (top position) ── -->
        <details class="tnp-legend-wrap" <?php echo $legend_open ? 'open' : ''; ?>>
            <summary class="tnp-legend-toggle"><?php esc_html_e( 'Legende', 'tennis-pro' ); ?> <span class="tnp-legend-toggle__arrow" aria-hidden="true"></span></summary>
            <div class="tnp-legend">
                <?php foreach ( $cats as $cat ) : ?>
                    <span class="tnp-legend__item" style="background:<?php echo esc_attr($cat->color); ?>;color:<?php echo esc_attr($cat->text_color); ?>"><?php echo esc_html($cat->name); ?></span>
                <?php endforeach; ?>
                <span class="tnp-legend__item tnp-legend--free"><?php esc_html_e( 'Frei', 'tennis-pro' ); ?></span>
                <span class="tnp-legend__item tnp-legend--blocked">🔒 <?php esc_html_e( 'Gesperrt', 'tennis-pro' ); ?></span>
            </div>
        </details>
        <?php endif; ?>

        <!-- ── Grid ── -->
        <?php if ( empty( $courts ) ) : ?>
            <div class="tnp-empty"><?php esc_html_e( 'Keine Plätze angelegt.', 'tennis-pro' ); ?></div>
        <?php else : ?>
        <div class="tnp-grid-wrap">
            <table class="tnp-grid" id="tnp-grid">
                <thead>
                    <tr>
                        <th class="tnp-grid__time-head" style="<?php echo $time_col_style; ?>"><?php esc_html_e( 'Zeit', 'tennis-pro' ); ?></th>
                        <?php if ( $active_view === 'week' ) :
                            // Week view: find the selected court for its colors
                            $sel_court_obj = null;
                            foreach ( $courts as $c ) {
                                if ( (int) $c->id === $sel_court_id ) { $sel_court_obj = $c; break; }
                            }
                            $wk_bg  = $sel_court_obj ? esc_attr( $sel_court_obj->bg_color ?: '#2e7d32' ) : '#2e7d32';
                            $wk_col = $sel_court_obj ? esc_attr( $sel_court_obj->color    ?: '#ffffff' ) : '#ffffff';
                            foreach ( $week_dates as $wd ) : ?>
                                <th class="tnp-grid__court-head <?php echo $wd === $today ? 'tnp-today' : ''; ?>"
                                    style="background:<?php echo $wk_bg; ?>;color:<?php echo $wk_col; ?>">
                                    <?php echo esc_html( date_i18n( 'D j.n.', strtotime( $wd ) ) ); ?>
                                </th>
                            <?php endforeach;
                        else :
                            foreach ( $courts as $court ) :
                                $ch_bg  = esc_attr( $court->bg_color ?: '#2e7d32' );
                                $ch_col = esc_attr( $court->color    ?: '#ffffff' );
                            ?>
                                <th class="tnp-grid__court-head"
                                    style="background:<?php echo $ch_bg; ?>;color:<?php echo $ch_col; ?>">
                                    <?php echo esc_html($court->name); ?>
                                </th>
                            <?php endforeach;
                        endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $row_idx = 0;
                for ( $h = $start_h; $h <= $end_h; $h++ ) :
                    foreach ( [ '00', '30' ] as $m ) :
                        $t = sprintf( '%02d:%s', $h, $m );
                        $row_idx++;
                        $is_even_row  = ( $row_idx % 2 === 0 );
                        $free_row_bg  = $is_even_row ? $slot_even_bg  : $slot_odd_bg;
                        $free_row_tc  = $is_even_row ? $slot_even_tc  : $slot_odd_tc;
                ?>
                    <tr class="tnp-grid__row">
                        <td class="tnp-grid__time" style="<?php echo $time_col_style; ?>"><?php echo esc_html($t); ?></td>
                        <?php
                        // Build column list
                        if ( $active_view === 'week' ) {
                            $col_items = array_map( fn($wd) => [ 'cid' => $sel_court_id, 'date' => $wd ], $week_dates );
                        } else {
                            $col_items = array_map( fn($c) => [ 'cid' => (int)$c->id, 'date' => $date ], $courts );
                        }

                        foreach ( $col_items as $col ) :
                            $cid      = $col['cid'];
                            $col_date = $col['date'];
                            $bmap     = $bmap_by_date[ $col_date ] ?? [];
                            $blk_map  = $blk_by_date[ $col_date ]  ?? [];

                            $b   = $bmap[ $cid ][ $t ]   ?? null;
                            $blk = $blk_map[ $cid ][ $t ] ?? ( $blk_map[ $cid ]['__day__'] ?? null );

                            // Skip continuation slots
                            if ( $b === 'cont' || $blk === 'cont' ) { continue; }

                            $rowspan  = 1;
                            if ( $b && $b !== 'cont' )               $rowspan = max(1,(int)$b->duration);
                            elseif ( $blk && $blk->timeslot !== '' )  $rowspan = max(1,(int)$blk->duration);

                            $is_booked   = ( $b && $b !== 'cont' );
                            $is_blocked  = ( ! $is_booked && $blk );
                            $is_own      = $is_booked && ( (int)$b->user_id === $current_uid );
                            $can_edit    = $is_booked && ( $is_own || $is_admin );
                            $is_past     = ( $col_date < $today );

                            $cat     = $is_booked ? ( $cat_map[ (int)$b->category_id ] ?? null ) : null;
                            // Blocked slot can have its own category for color
                            $blk_cat = ( $is_blocked && $blk && isset( $blk->category_id ) && (int)$blk->category_id > 0 )
                                       ? ( $cat_map[ (int)$blk->category_id ] ?? null ) : null;
                            $is_mycal = ( $is_blocked && isset( $blk->source ) && $blk->source === 'mycal' );
                            if ( $is_mycal ) {
                                $bg = esc_attr( $blk->cat_color ?? '#0277bd' );
                                $tc = '#000000';
                            } else {
                                $bg = $cat ? $cat->color      : ( $blk_cat ? $blk_cat->color      : ( $is_blocked ? '#e8e8e8' : $free_row_bg ) );
                                $tc = $cat ? $cat->text_color : ( $blk_cat ? $blk_cat->text_color : ( $is_blocked ? '#777'    : $free_row_tc ) );
                            }
                            $lbl    = $is_booked ? ( $b->player_name ?: ( $cat->name ?? __('Belegt','tennis-pro') ) ) : '';

                            $classes  = 'tnp-slot';
                            if ( $is_booked )                                  $classes .= ' tnp-slot--booked';
                            if ( $is_blocked )                                 $classes .= ' tnp-slot--blocked';
                            if ( ! $is_booked && ! $is_blocked )               $classes .= ' tnp-slot--free';
                            if ( $can_edit )                                   $classes .= ' tnp-slot--editable';
                            if ( $is_logged_in && ! $is_booked && ! $is_blocked && ! $is_past ) $classes .= ' tnp-slot--bookable';
                            // Heute-Spalte in Wochenansicht hervorheben
                            if ( $active_view === 'week' && $col_date === $today ) $classes .= ' tnp-today-col';

                            // Waitlist check for current user
                            $on_waitlist = false;
                            if ( $is_logged_in && $is_booked ) {
                                global $wpdb;
                                $on_waitlist = (bool) $wpdb->get_var( $wpdb->prepare(
                                    "SELECT id FROM {$wpdb->prefix}tennis_waitlist WHERE court_id=%d AND date=%s AND timeslot=%s AND user_id=%d",
                                    $cid, $col_date, $t, $current_uid
                                ) );
                            }

                            $rs_attr = $rowspan > 1 ? " rowspan=\"{$rowspan}\"" : '';
                        ?>
                        <td<?php echo $rs_attr; ?>
                            class="<?php echo esc_attr($classes); ?>"
                            style="<?php echo $bg ? "background:{$bg};color:{$tc};" : ''; ?>"
                            data-id="<?php echo $is_booked ? (int)$b->id : ''; ?>"
                            data-court="<?php echo esc_attr((string)$cid); ?>"
                            data-time="<?php echo esc_attr($t); ?>"
                            data-date="<?php echo esc_attr($col_date); ?>"
                            data-name="<?php echo esc_attr($lbl); ?>"
                            data-cat="<?php echo $is_booked ? (int)$b->category_id : ''; ?>"
                            data-duration="<?php echo $is_booked ? (int)$b->duration : 1; ?>"
                            data-recurring="<?php echo $is_booked ? (int)$b->recurring_id : 0; ?>"
                            data-trainer="<?php echo $is_booked ? (int)$b->trainer_id : ''; ?>"
                            data-own="<?php echo $is_own ? '1' : '0'; ?>"
                            data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>"
                            data-on-waitlist="<?php echo $on_waitlist ? '1' : '0'; ?>">

                            <?php if ( $is_booked ) :
                                $end_minutes  = tennis_pro_slot_to_minutes( $t ) + (int)$b->duration * 30;
                                $end_time     = tennis_pro_minutes_to_slot( $end_minutes );
                                $cat_label    = $cat->name ?? '';
                                $player_label = trim( $b->player_name ?? '' );
                                $show_player  = ( $player_label !== '' && $player_label !== $cat_label );
                            ?>
                                <span class="tnp-slot__time"><?php echo esc_html($t . ' – ' . $end_time); ?></span>
                                <?php if ( $cat_label !== '' ) : ?>
                                    <span class="tnp-slot__cat"><?php echo esc_html($cat_label); ?></span>
                                <?php endif; ?>
                                <?php if ( ! empty( $b->trainer_name ) ) : ?>
                                    <span class="tnp-slot__trainer">👤 <?php echo esc_html($b->trainer_name); ?></span>
                                <?php endif; ?>
                                <?php if ( $show_player ) : ?>
                                    <span class="tnp-slot__label"><?php echo esc_html($player_label); ?></span>
                                <?php elseif ( $cat_label === '' ) : ?>
                                    <span class="tnp-slot__label"><?php esc_html_e('Belegt','tennis-pro'); ?></span>
                                <?php endif; ?>
                                <?php if ( (int)$b->recurring_id ) : ?>
                                    <span class="tnp-slot__recur">🔁</span>
                                <?php endif; ?>
                            <?php elseif ( $is_blocked ) : ?>
                                <?php if ( $is_mycal ) :
                                    // Render primary + any stacked extra events
                                    $all_mycal = array_merge( [ $blk ], $blk->extra_events ?? [] );
                                    foreach ( $all_mycal as $mi => $me ) :
                                        $me_url = tennis_pro_mycal_event_url( (int) ( $me->occur_id ?? 0 ), (int) ( $me->event_id ?? 0 ), (int) ( $me->event_post ?? 0 ) );
                                ?>
                                    <?php if ( $mi > 0 ) : ?>
                                        <div style="margin-top:3px;padding-top:3px;border-top:1px solid rgba(0,0,0,.2)"></div>
                                    <?php endif; ?>
                                    <?php if ( $me->cat_name ?? '' ) : ?>
                                        <span class="tnp-slot__cat" style="opacity:.85"><?php echo esc_html( $me->cat_name ); ?></span>
                                    <?php endif; ?>
                                    <span class="tnp-slot__blocked">
                                        <?php if ( $me_url ) : ?>
                                            <a href="<?php echo esc_url( $me_url ); ?>" style="color:inherit;text-decoration:underline;font-weight:inherit" target="_blank" rel="noopener">📅 <?php echo esc_html( $me->reason ); ?></a>
                                        <?php else : ?>
                                            📅 <?php echo esc_html( $me->reason ); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php else : ?>
                                    <?php if ( $blk_cat ) : ?>
                                        <span class="tnp-slot__label"><?php echo esc_html( $blk_cat->name ); ?></span>
                                    <?php endif; ?>
                                    <span class="tnp-slot__blocked">🔒<?php echo $blk->reason ? ' ' . esc_html( $blk->reason ) : ''; ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="tnp-slot__free">+</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; endfor; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $cats ) && $legend_pos === 'bottom' ) : ?>
        <!-- ── Legend (bottom position) ── -->
        <details class="tnp-legend-wrap" <?php echo $legend_open ? 'open' : ''; ?>>
            <summary class="tnp-legend-toggle"><?php esc_html_e( 'Legende', 'tennis-pro' ); ?> <span class="tnp-legend-toggle__arrow" aria-hidden="true"></span></summary>
            <div class="tnp-legend">
                <?php foreach ( $cats as $cat ) : ?>
                    <span class="tnp-legend__item" style="background:<?php echo esc_attr($cat->color); ?>;color:<?php echo esc_attr($cat->text_color); ?>"><?php echo esc_html($cat->name); ?></span>
                <?php endforeach; ?>
                <span class="tnp-legend__item tnp-legend--free"><?php esc_html_e( 'Frei', 'tennis-pro' ); ?></span>
                <span class="tnp-legend__item tnp-legend--blocked">🔒 <?php esc_html_e( 'Gesperrt', 'tennis-pro' ); ?></span>
            </div>
        </details>
        <?php endif; ?>

        <!-- ── Popup ── -->
        <div id="tnp-popup" class="tnp-popup" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Buchung','tennis-pro'); ?>" hidden>
            <div class="tnp-popup__backdrop" id="tnp-backdrop"></div>
            <div class="tnp-popup__box">
                <button class="tnp-popup__close" id="tnp-close" aria-label="<?php esc_attr_e('Schließen','tennis-pro'); ?>">✕</button>

                <!-- Guest -->
                <div id="tnp-panel-guest" hidden>
                    <div class="tnp-popup__icon">🔒</div>
                    <h3><?php esc_html_e('Anmeldung erforderlich','tennis-pro'); ?></h3>
                    <p><?php esc_html_e('Um Plätze zu reservieren, musst du eingeloggt sein.','tennis-pro'); ?></p>
                    <a href="<?php echo esc_url($login_url); ?>" class="tnp-btn tnp-btn--primary tnp-btn--block">🔐 <?php esc_html_e('Jetzt einloggen','tennis-pro'); ?></a>
                    <?php if ( ! empty( $s_opts['show_register_btn'] ) && ! empty( $s_opts['register_page_id'] ) ) : ?>
                    <a href="<?php echo esc_url( get_permalink( (int) $s_opts['register_page_id'] ) ); ?>" class="tnp-btn tnp-btn--outline tnp-btn--block">📝 <?php esc_html_e('Jetzt Registrieren','tennis-pro'); ?></a>
                    <?php endif; ?>
                    <button class="tnp-btn tnp-btn--outline tnp-btn--block" id="tnp-cancel-guest"><?php esc_html_e('Abbrechen','tennis-pro'); ?></button>
                </div>

                <!-- View (booked, not own) -->
                <div id="tnp-panel-view" hidden>
                    <div class="tnp-popup__icon">📋</div>
                    <h3><?php esc_html_e('Slot belegt','tennis-pro'); ?></h3>
                    <p id="tnp-view-info"></p>
                    <button class="tnp-btn tnp-btn--accent tnp-btn--block" id="tnp-waitlist-join-btn"><?php esc_html_e('Auf Warteliste setzen','tennis-pro'); ?></button>
                    <button class="tnp-btn tnp-btn--outline tnp-btn--block" id="tnp-waitlist-leave-btn" style="display:none"><?php esc_html_e('Von Warteliste entfernen','tennis-pro'); ?></button>
                    <button class="tnp-btn tnp-btn--outline tnp-btn--block" id="tnp-cancel-view"><?php esc_html_e('Schließen','tennis-pro'); ?></button>
                    <p class="tnp-msg" id="tnp-view-msg" hidden></p>
                </div>

                <!-- Book new slot -->
                <div id="tnp-panel-book" hidden>
                    <div class="tnp-popup__icon">🎾</div>
                    <h3><?php esc_html_e('Platz reservieren','tennis-pro'); ?></h3>
                    <p class="tnp-popup__meta" id="tnp-book-meta"></p>

                    <?php if ( $is_admin && count( $courts ) > 1 ) : ?>
                    <div class="tnp-field">
                        <span><?php esc_html_e('Platz / Plätze','tennis-pro'); ?></span>
                        <div id="tnp-book-courts" style="display:flex;flex-direction:column;gap:5px;margin-top:2px">
                            <?php foreach ( $courts as $court ) : ?>
                            <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:normal">
                                <input type="checkbox" class="tnp-book-court-cb" value="<?php echo (int) $court->id; ?>">
                                <?php echo esc_html( $court->name ); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <label class="tnp-field">
                        <span><?php esc_html_e('Dauer','tennis-pro'); ?></span>
                        <select id="tnp-duration">
                            <?php
                            $max_dur_book = $is_admin ? 8 : 6; // Users max 3h (6×30min)
                            for ( $d = 1; $d <= $max_dur_book; $d++ ) : ?>
                                <option value="<?php echo $d; ?>"><?php echo esc_html(tennis_pro_duration_label($d)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>

                    <label class="tnp-field">
                        <span><?php esc_html_e('Kategorie','tennis-pro'); ?></span>
                        <select id="tnp-cat">
                            <option value=""><?php esc_html_e('– keine –','tennis-pro'); ?></option>
                            <?php foreach ( $cats as $cat ) :
                                if ( (int) $cat->admin_only && ! $is_admin ) continue; ?>
                                <option value="<?php echo (int)$cat->id; ?>" data-color="<?php echo esc_attr($cat->color); ?>" data-text="<?php echo esc_attr($cat->text_color); ?>"><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php if ( $is_admin && ! empty( $trainers ) ) : ?>
                    <label class="tnp-field">
                        <span><?php esc_html_e('Trainer (optional)','tennis-pro'); ?></span>
                        <select id="tnp-trainer">
                            <option value=""><?php esc_html_e('– kein Trainer –','tennis-pro'); ?></option>
                            <?php foreach ( $trainers as $tr ) : ?>
                                <option value="<?php echo (int)$tr->id; ?>"><?php echo esc_html($tr->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>

                    <label class="tnp-field">
                        <span><?php esc_html_e('Name / Kommentar','tennis-pro'); ?></span>
                        <input type="text" id="tnp-name" placeholder="<?php esc_attr_e('Optional…','tennis-pro'); ?>" maxlength="100">
                    </label>

                    <!-- Recurring toggle – admins only -->
                    <?php if ( $is_admin ) : ?>
                    <label class="tnp-field" style="flex-direction:row;align-items:center;gap:8px">
                        <input type="checkbox" id="tnp-recurring-toggle">
                        <span><?php esc_html_e('Wiederkehrend','tennis-pro'); ?></span>
                    </label>
                    <div id="tnp-recurring-opts" style="display:none">
                        <label class="tnp-field">
                            <span><?php esc_html_e('Muster','tennis-pro'); ?></span>
                            <select id="tnp-rec-pattern">
                                <option value="weekly"><?php esc_html_e('Wöchentlich','tennis-pro'); ?></option>
                                <option value="daily"><?php esc_html_e('Täglich','tennis-pro'); ?></option>
                            </select>
                        </label>
                        <label class="tnp-field" id="tnp-rec-dow-label">
                            <span><?php esc_html_e('Wochentag','tennis-pro'); ?></span>
                            <select id="tnp-rec-dow">
                                <?php
                                $dow_names = [
                                    0 => __('Sonntag','tennis-pro'), 1 => __('Montag','tennis-pro'),
                                    2 => __('Dienstag','tennis-pro'), 3 => __('Mittwoch','tennis-pro'),
                                    4 => __('Donnerstag','tennis-pro'), 5 => __('Freitag','tennis-pro'),
                                    6 => __('Samstag','tennis-pro'),
                                ];
                                foreach ( $dow_names as $num => $dn ) :
                                ?>
                                    <option value="<?php echo $num; ?>"><?php echo esc_html($dn); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="tnp-field">
                            <span><?php esc_html_e('Enddatum','tennis-pro'); ?></span>
                            <input type="date" id="tnp-rec-end" value="<?php echo esc_attr( gmdate('Y-m-d', strtotime('+3 months')) ); ?>">
                        </label>
                    </div>
                    <?php endif; // is_admin – recurring toggle ?>

                    <div class="tnp-popup__actions">
                        <button class="tnp-btn tnp-btn--primary" id="tnp-save-btn">✅ <?php esc_html_e('Reservieren','tennis-pro'); ?></button>
                        <button class="tnp-btn tnp-btn--outline" id="tnp-cancel-book"><?php esc_html_e('Abbrechen','tennis-pro'); ?></button>
                    </div>
                    <p class="tnp-error" id="tnp-save-error" hidden></p>
                </div>

                <!-- Edit existing slot -->
                <div id="tnp-panel-edit" hidden>
                    <div class="tnp-popup__icon">✏️</div>
                    <h3><?php esc_html_e('Buchung bearbeiten','tennis-pro'); ?></h3>
                    <p class="tnp-popup__meta" id="tnp-edit-meta"></p>

                    <label class="tnp-field">
                        <span><?php esc_html_e('Startzeit','tennis-pro'); ?></span>
                        <select id="tnp-edit-timeslot">
                            <?php for ( $h = $start_h; $h <= $end_h; $h++ ) : foreach ( ['00','30'] as $m ) :
                                $ts = sprintf('%02d:%s', $h, $m); ?>
                                <option value="<?php echo esc_attr($ts); ?>"><?php echo esc_html($ts); ?> Uhr</option>
                            <?php endforeach; endfor; ?>
                        </select>
                    </label>

                    <label class="tnp-field">
                        <span><?php esc_html_e('Dauer','tennis-pro'); ?></span>
                        <select id="tnp-edit-duration">
                            <?php
                            $max_dur_edit = $is_admin ? 8 : 6;
                            for ( $d = 1; $d <= $max_dur_edit; $d++ ) : ?>
                                <option value="<?php echo $d; ?>"><?php echo esc_html(tennis_pro_duration_label($d)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>

                    <label class="tnp-field">
                        <span><?php esc_html_e('Kategorie','tennis-pro'); ?></span>
                        <select id="tnp-edit-cat">
                            <option value=""><?php esc_html_e('– keine –','tennis-pro'); ?></option>
                            <?php foreach ( $cats as $cat ) :
                                if ( (int) $cat->admin_only && ! $is_admin ) continue; ?>
                                <option value="<?php echo (int)$cat->id; ?>" data-color="<?php echo esc_attr($cat->color); ?>" data-text="<?php echo esc_attr($cat->text_color); ?>"><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php if ( $is_admin && ! empty( $trainers ) ) : ?>
                    <label class="tnp-field">
                        <span><?php esc_html_e('Trainer (optional)','tennis-pro'); ?></span>
                        <select id="tnp-edit-trainer">
                            <option value=""><?php esc_html_e('– kein Trainer –','tennis-pro'); ?></option>
                            <?php foreach ( $trainers as $tr ) : ?>
                                <option value="<?php echo (int)$tr->id; ?>"><?php echo esc_html($tr->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>

                    <label class="tnp-field">
                        <span><?php esc_html_e('Name / Kommentar','tennis-pro'); ?></span>
                        <input type="text" id="tnp-edit-name" maxlength="100">
                    </label>

                    <div class="tnp-popup__actions">
                        <button class="tnp-btn tnp-btn--primary"  id="tnp-update-btn">💾 <?php esc_html_e('Speichern','tennis-pro'); ?></button>
                        <button class="tnp-btn tnp-btn--danger"   id="tnp-delete-btn">🗑 <?php esc_html_e('Löschen','tennis-pro'); ?></button>
                        <button class="tnp-btn tnp-btn--outline"  id="tnp-cancel-edit"><?php esc_html_e('Abbrechen','tennis-pro'); ?></button>
                    </div>
                    <div id="tnp-recurring-delete-opts" style="display:none;margin-top:8px">
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px">
                            <input type="checkbox" id="tnp-cancel-series">
                            <?php esc_html_e('Gesamte Serie stornieren','tennis-pro'); ?>
                        </label>
                    </div>
                    <p class="tnp-error" id="tnp-edit-error" hidden></p>
                </div>

            </div>
        </div>

    </div><!-- /#tnp-app -->
    <?php
    return ob_get_clean();
}

/* ══════════════════════════════════════════════════════════════════════════
   MY BOOKINGS SHORTCODE
══════════════════════════════════════════════════════════════════════════ */

function tennis_pro_my_bookings_shortcode( $atts ): string {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Du musst eingeloggt sein, um deine Buchungen zu sehen.', 'tennis-pro' ) . '</p>';
    }

    $user_id  = get_current_user_id();
    $bookings = tennis_pro_get_user_bookings( $user_id );
    $waitlist = tennis_pro_get_user_waitlist( $user_id );
    $ical_url = wp_nonce_url( add_query_arg( 'tennis_ical', '1', get_permalink() ?: home_url() ), 'tennis_ical_user' );

    // Group bookings by ISO week (YYYY-WNN)
    $weeks = [];
    foreach ( $bookings as $b ) {
        $ts     = strtotime( $b->date );
        $dow    = (int) gmdate( 'N', $ts );            // 1=Mon … 7=Sun
        $monday = gmdate( 'Y-m-d', strtotime( $b->date . ' -' . ( $dow - 1 ) . ' days' ) );
        $sunday = gmdate( 'Y-m-d', strtotime( $monday . ' +6 days' ) );
        $key    = gmdate( 'Y', $ts ) . '-W' . gmdate( 'W', $ts );
        if ( ! isset( $weeks[ $key ] ) ) {
            $weeks[ $key ] = [
                'label'    => 'KW ' . gmdate( 'W', $ts ) . ' · '
                              . date_i18n( 'j. M', strtotime( $monday ) ) . ' – '
                              . date_i18n( 'j. M Y', strtotime( $sunday ) ),
                'bookings' => [],
            ];
        }
        $weeks[ $key ]['bookings'][] = $b;
    }

    $total = count( $bookings );

    ob_start();
    ?>
    <div class="tnp-my-bookings tnp-wrap">

        <!-- ── Ebene 1: Haupt-Toggle ── -->
        <details class="tnp-acc tnp-acc--main">
            <summary class="tnp-acc__summary">
                <span class="tnp-acc__title">📋 <?php esc_html_e( 'Meine Buchungen', 'tennis-pro' ); ?></span>
                <?php if ( $total > 0 ) : ?>
                    <span class="tnp-acc__badge"><?php echo (int) $total; ?></span>
                <?php endif; ?>
                <span class="tnp-acc__chevron" aria-hidden="true"></span>
            </summary>

            <div class="tnp-acc__body">

                <!-- iCal-Export + Hinweis -->
                <div class="tnp-my-bookings__toolbar">
                    <?php if ( $total > 0 ) : ?>
                        <a href="<?php echo esc_url( $ical_url ); ?>" class="tnp-btn tnp-btn--sm tnp-btn--accent">📅 <?php esc_html_e( 'Alle als iCal exportieren', 'tennis-pro' ); ?></a>
                    <?php endif; ?>
                </div>

                <?php if ( empty( $bookings ) ) : ?>
                    <p class="tnp-my-bookings__empty"><?php esc_html_e( 'Keine bevorstehenden Buchungen.', 'tennis-pro' ); ?></p>
                <?php else : ?>

                    <!-- ── Ebene 2: Wochengruppen ── -->
                    <?php foreach ( $weeks as $week ) :
                        $wcount = count( $week['bookings'] );
                    ?>
                    <details class="tnp-acc tnp-acc--week">
                        <summary class="tnp-acc__summary">
                            <span class="tnp-acc__title"><?php echo esc_html( $week['label'] ); ?></span>
                            <span class="tnp-acc__badge tnp-acc__badge--sm"><?php echo (int) $wcount; ?></span>
                            <span class="tnp-acc__chevron" aria-hidden="true"></span>
                        </summary>

                        <div class="tnp-acc__body">
                            <!-- ── Ebene 3: einzelne Buchungen ── -->
                            <?php foreach ( $week['bookings'] as $b ) :
                                $end_min   = tennis_pro_slot_to_minutes( $b->timeslot ) + (int) $b->duration * 30;
                                $end_time  = tennis_pro_minutes_to_slot( $end_min );
                                $dur_label = tennis_pro_duration_label( (int) $b->duration );
                                $bg        = $b->color      ? esc_attr( $b->color )      : '#e8f5e9';
                                $tc        = $b->text_color ? esc_attr( $b->text_color ) : '#1b5e20';
                            ?>
                            <div class="tnp-booking-card" style="border-left-color:<?php echo $bg; ?>">
                                <span class="tnp-booking-card__date"><?php echo esc_html( date_i18n( 'l, j. F Y', strtotime( $b->date ) ) ); ?></span>
                                <span class="tnp-booking-card__sep">·</span>
                                <?php echo esc_html( $b->timeslot . ' – ' . $end_time ); ?> Uhr
                                <span class="tnp-booking-card__sep">·</span>
                                <?php echo esc_html( $b->court_name ); ?>
                                <?php if ( $b->player_name ) : ?>
                                    <span class="tnp-booking-card__sep">·</span>
                                    <em><?php echo esc_html( $b->player_name ); ?></em>
                                <?php endif; ?>
                                <?php if ( $b->cat_name ) : ?>
                                    <span class="tnp-booking-card__badge" style="background:<?php echo $bg; ?>;color:<?php echo $tc; ?>"><?php echo esc_html( $b->cat_name ); ?></span>
                                <?php endif; ?>
                                <?php if ( (int) $b->recurring_id ) : ?>
                                    <span class="tnp-booking-card__series">🔁 <?php esc_html_e( 'Serie', 'tennis-pro' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endforeach; ?>

                <?php endif; ?>

                <!-- ── Warteliste ── -->
                <?php if ( ! empty( $waitlist ) ) : ?>
                <details class="tnp-acc tnp-acc--week tnp-acc--waitlist">
                    <summary class="tnp-acc__summary">
                        <span class="tnp-acc__title">⏳ <?php esc_html_e( 'Warteliste', 'tennis-pro' ); ?></span>
                        <span class="tnp-acc__badge tnp-acc__badge--sm tnp-acc__badge--warn"><?php echo count( $waitlist ); ?></span>
                        <span class="tnp-acc__chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="tnp-acc__body">
                        <?php foreach ( $waitlist as $w ) :
                            $end_min  = tennis_pro_slot_to_minutes( $w->timeslot ) + (int) $w->duration * 30;
                            $end_time = tennis_pro_minutes_to_slot( $end_min );
                        ?>
                        <div class="tnp-waitlist-card"
                             data-wl-court="<?php echo (int) $w->court_id; ?>"
                             data-wl-date="<?php echo esc_attr( $w->date ); ?>"
                             data-wl-timeslot="<?php echo esc_attr( $w->timeslot ); ?>">
                            <span class="tnp-waitlist-badge">⏳ <?php esc_html_e( 'Warteliste', 'tennis-pro' ); ?></span>
                            <span class="tnp-booking-card__date"><?php echo esc_html( date_i18n( 'l, j. F Y', strtotime( $w->date ) ) ); ?></span>
                            <span class="tnp-booking-card__sep">·</span>
                            <?php echo esc_html( $w->timeslot . ' – ' . $end_time ); ?> Uhr
                            <span class="tnp-booking-card__sep">·</span>
                            <?php echo esc_html( $w->court_name ); ?>
                            <?php if ( $w->player_name ) : ?>
                                <span class="tnp-booking-card__sep">·</span>
                                <em><?php echo esc_html( $w->player_name ); ?></em>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <p class="tnp-my-bookings__hint"><?php esc_html_e( 'Du erhältst eine E-Mail, sobald der Slot frei wird. Es gilt: wer zuerst bucht, bekommt den Slot.', 'tennis-pro' ); ?></p>
                    </div>
                </details>
                <?php endif; ?>

            </div><!-- /.tnp-acc__body -->
        </details><!-- /.tnp-acc--main -->

    </div>
    <?php
    return ob_get_clean();
}
