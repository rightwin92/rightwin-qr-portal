<?php
/**
 * Plugin Name: RightWin QR Portal
 * Description: QR portal with static/dynamic QR, aliases, analytics, user dashboard & admin controls.
 * Version: 1.5.4
 * Author: RIGHT WIN MEDIAS
 * Requires PHP: 8.0
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

final class RightWin_QR_Portal {
    // ===== Versions & keys =====
    const VERSION         = '1.5.4';
    const CPT             = 'rwqr_code';
    const DB_SCANS        = 'rwqr_scans';
    const OPTION_SETTINGS = 'rwqr_settings';
    const OPTION_PAGES    = 'rwqr_pages';

    // Query vars (pretty routes)
    const QV_ALIAS = 'rwqr_alias';   // /qr/{alias}
    const QV_VIEW  = 'rwqr_view_id'; // /qrview/{id}

    public function __construct(){
        // Core
        add_action('init',               [$this,'register_cpt']);
        add_action('init',               [$this,'add_rewrite_rules']);
        add_filter('query_vars',         [$this,'register_query_vars']);
        add_action('template_redirect',  [$this,'maybe_handle_front_routes']);
        add_action('wp_enqueue_scripts', [$this,'enqueue_assets']);

        // Admin
        add_action('admin_menu',         [$this,'admin_menu']);
        add_action('admin_init',         [$this,'register_settings']);
        add_action('add_meta_boxes',     [$this,'add_meta_boxes']);
        add_action('save_post_'.self::CPT, [$this,'save_meta'], 10, 3);
        add_filter('manage_'.self::CPT.'_posts_columns',        [$this,'admin_cols']);
        add_action('manage_'.self::CPT.'_posts_custom_column',  [$this,'admin_coldata'], 10, 2);
        add_filter('post_row_actions',   [$this,'admin_row_toggle_action'], 10, 2);

        // Shortcodes
        add_shortcode('rwqr_portal',    [$this,'sc_portal']);     // Login/Register + notice + Register with Terms/Privacy
        add_shortcode('rwqr_dashboard', [$this,'sc_dashboard']);  // User dashboard (create/list/pause/start/delete)

        // Footer disclaimer (small)
        add_action('wp_footer',          [$this,'footer_disclaimer']);

        // Activation / Deactivation
        register_activation_hook(__FILE__, [__CLASS__,'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__,'deactivate']);
    }

    /* =========================
     * Activation / Deactivation
     * ========================= */
    public static function activate(){
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . self::DB_SCANS;

        // scans table (keeps simple logs)
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            qr_id BIGINT UNSIGNED NOT NULL,
            alias VARCHAR(190) NOT NULL,
            ip VARBINARY(16) NULL,
            ua TEXT NULL,
            referrer TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_qr (qr_id),
            KEY idx_alias (alias)
        ) $charset;";
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // default settings (including new admin limits)
        if (!get_option(self::OPTION_SETTINGS)){
            add_option(self::OPTION_SETTINGS, [
                'max_logo_mb'   => 2,
                'contact_html'  => 'Powered by RIGHT WIN MEDIAS — Contact: +91-00000 00000 | info@rightwinmedias.com',
                'email_handler' => 'mailto',

                // NEW in 1.5.4 — Admin limits
                'limit_max_qr_per_user'            => 0,  // 0 = unlimited
                'limit_window_days'                => 30, // rolling days for user scan quota
                'limit_max_scans_per_qr'           => 0,  // 0 = unlimited (admin cap per QR)
                'limit_max_scans_per_user_window'  => 0,  // 0 = unlimited (sum across user’s QRs in window)
            ]);
        }

        // Ensure essential pages exist (only if not present)
        $pages = get_option(self::OPTION_PAGES, []);
        if (!is_array($pages)) $pages = [];

        if (empty($pages['portal']) || !get_post($pages['portal'])){
            $portal_id = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'QR Portal',
                'post_name'    => 'qr-portal',
                'post_content' => '[rwqr_portal]'
            ]);
            if (!is_wp_error($portal_id)) $pages['portal'] = $portal_id;
        }
        if (empty($pages['terms']) || !get_post($pages['terms'])){
            $terms_id = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Terms & Conditions',
                'post_name'    => 'terms',
                'post_content' => 'Replace with your Terms & Conditions.'
            ]);
            if (!is_wp_error($terms_id)) $pages['terms'] = $terms_id;
        }
        if (empty($pages['privacy']) || !get_post($pages['privacy'])){
            $privacy_id = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'Privacy Policy',
                'post_name'    => 'privacy-policy',
                'post_content' => 'Replace with your Privacy Policy.'
            ]);
            if (!is_wp_error($privacy_id)) $pages['privacy'] = $privacy_id;
        }

        update_option(self::OPTION_PAGES, $pages);

        self::add_rewrite_rules_static();
        flush_rewrite_rules();
    }

    public static function deactivate(){
        flush_rewrite_rules();
    }

    /* ===== Settings helper with defaults (includes admin limits) ===== */
    private function settings(){
        $s = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($s)) $s = [];
        $defaults = [
            'max_logo_mb'   => 2,
            'contact_html'  => 'Powered by RIGHT WIN MEDIAS — Contact: +91-00000 00000 | info@rightwinmedias.com',
            'email_handler' => 'mailto',
            'limit_max_qr_per_user'            => 0,
            'limit_window_days'                => 30,
            'limit_max_scans_per_qr'           => 0,
            'limit_max_scans_per_user_window'  => 0,
        ];
        return array_merge($defaults, $s);
    }

    /* ===============
     * Admin Settings
     * =============== */
    public function admin_menu(){
        add_options_page('RightWin QR', 'RightWin QR', 'manage_options', 'rwqr-settings', [$this,'admin_settings']);
    }

    public function register_settings(){
        register_setting('rwqr-group', self::OPTION_SETTINGS);
    }

    public function admin_settings(){
        if (!current_user_can('manage_options')) return;

        // Save
        if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('rwqr_save_settings')){
            $max     = max(0, floatval($_POST['max_logo_mb'] ?? 2));
            $contact = wp_kses_post($_POST['contact_html'] ?? '');
            $handler = in_array(($_POST['email_handler'] ?? 'mailto'), ['mailto','gmail','outlook','yahoo'], true) ? $_POST['email_handler'] : 'mailto';

            // NEW: limits
            $limit_max_qr_per_user           = max(0, intval($_POST['limit_max_qr_per_user'] ?? 0));
            $limit_window_days               = max(1, intval($_POST['limit_window_days'] ?? 30));
            $limit_max_scans_per_qr          = max(0, intval($_POST['limit_max_scans_per_qr'] ?? 0));
            $limit_max_scans_per_user_window = max(0, intval($_POST['limit_max_scans_per_user_window'] ?? 0));

            update_option(self::OPTION_SETTINGS, [
                'max_logo_mb'   => $max,
                'contact_html'  => $contact,
                'email_handler' => $handler,
                'limit_max_qr_per_user'            => $limit_max_qr_per_user,
                'limit_window_days'                => $limit_window_days,
                'limit_max_scans_per_qr'           => $limit_max_scans_per_qr,
                'limit_max_scans_per_user_window'  => $limit_max_scans_per_user_window,
            ]);

            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $s = $this->settings();
        ?>
        <div class="wrap">
            <h1>RightWin QR — Settings</h1>
            <form method="post">
                <?php wp_nonce_field('rwqr_save_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="max_logo_mb">Max logo upload (MB)</label></th>
                        <td><input type="number" step="0.1" min="0" id="max_logo_mb" name="max_logo_mb" value="<?php echo esc_attr($s['max_logo_mb']); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="contact_html">Powered by / Contact (HTML)</label></th>
                        <td><textarea id="contact_html" name="contact_html" rows="3" class="large-text"><?php echo esc_textarea($s['contact_html']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="email_handler">Email share opens in</label></th>
                        <td>
                            <select id="email_handler" name="email_handler">
                                <option value="mailto"  <?php selected($s['email_handler'],'mailto');  ?>>System mail app</option>
                                <option value="gmail"   <?php selected($s['email_handler'],'gmail');   ?>>Gmail (web)</option>
                                <option value="outlook" <?php selected($s['email_handler'],'outlook'); ?>>Outlook.com</option>
                                <option value="yahoo"   <?php selected($s['email_handler'],'yahoo');   ?>>Yahoo Mail</option>
                            </select>
                        </td>
                    </tr>

                    <!-- NEW: Admin Limits -->
                    <tr>
                        <th><label for="limit_max_qr_per_user">Max QRs per user</label></th>
                        <td>
                            <input type="number" min="0" id="limit_max_qr_per_user" name="limit_max_qr_per_user" value="<?php echo esc_attr($s['limit_max_qr_per_user']); ?>">
                            <p class="description">0 = unlimited. Blocks dashboard creation when the user reaches this count.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="limit_window_days">Quota window (days)</label></th>
                        <td>
                            <input type="number" min="1" id="limit_window_days" name="limit_window_days" value="<?php echo esc_attr($s['limit_window_days']); ?>">
                            <p class="description">Rolling window used for per-user scan quotas.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="limit_max_scans_per_qr">Max scans per QR (admin cap)</label></th>
                        <td>
                            <input type="number" min="0" id="limit_max_scans_per_qr" name="limit_max_scans_per_qr" value="<?php echo esc_attr($s['limit_max_scans_per_qr']); ?>">
                            <p class="description">0 = unlimited. Effective limit is the minimum of the QR’s own limit and this admin cap.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="limit_max_scans_per_user_window">Max scans per user in window</label></th>
                        <td>
                            <input type="number" min="0" id="limit_max_scans_per_user_window" name="limit_max_scans_per_user_window" value="<?php echo esc_attr($s['limit_max_scans_per_user_window']); ?>">
                            <p class="description">0 = unlimited. Sum of scans across all QRs owned by the user within the window.</p>
                        </td>
                    </tr>
                </table>
                <p><button class="button button-primary">Save Changes</button></p>
            </form>
        </div>
        <?php
    }

    /* =========
     * Assets
     * ========= */
    public function enqueue_assets(){
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('rwqr-portal',  $base.'assets/portal.css', [], self::VERSION);
        wp_enqueue_script('rwqr-portal', $base.'assets/portal.js',  [], self::VERSION, true);
    }

    /* ===================
     * Footer disclaimer
     * =================== */
    public function footer_disclaimer(){
        $terms  = esc_url(site_url('/terms'));
        $privacy= esc_url(site_url('/privacy-policy'));
        echo '<div class="rwqr-consent-footer">By continuing you agree to our '
            .'<a href="'.$terms.'" target="_blank" rel="noopener">Terms &amp; Conditions</a> and '
            .'<a href="'.$privacy.'" target="_blank" rel="noopener">Privacy Policy</a>.</div>';
    }

    /* ============
     * Rewrites
     * ============ */
    public function add_rewrite_rules(){ self::add_rewrite_rules_static(); }
    private static function add_rewrite_rules_static(){
        add_rewrite_rule('^qr/([^/]+)/?$',      'index.php?'.self::QV_ALIAS.'=$matches[1]', 'top');
        add_rewrite_rule('^qrview/([0-9]+)/?$', 'index.php?'.self::QV_VIEW.'=$matches[1]',  'top');
    }
    public function register_query_vars($vars){
        $vars[] = self::QV_ALIAS;
        $vars[] = self::QV_VIEW;
        return $vars;
    }
    /* =========================
     * CPT: QR Codes + Meta Boxes
     * ========================= */

    /** Custom Post Type: QR Codes */
    public function register_cpt(){
        register_post_type(self::CPT, [
            'label'         => 'QR Codes',
            'public'        => false,
            'show_ui'       => true,
            'map_meta_cap'  => true,
            'capability_type'=>'post',
            'supports'      => ['title','author'],
            'menu_icon'     => 'dashicons-qrcode',
        ]);
    }

    /** Meta boxes */
    public function add_meta_boxes(){
        add_meta_box('rwqr_content', 'Content Type & Data', [$this,'mb_content'], self::CPT, 'normal', 'default');
        add_meta_box('rwqr_status',  'Status & Limits',     [$this,'mb_status'],  self::CPT, 'side',   'default');
        add_meta_box('rwqr_design',  'Design (Top/Bottom Labels)', [$this,'mb_design'], self::CPT, 'side', 'default');
    }

    /** Content Type box */
    public function mb_content($post){
        // meta
        $ctype = get_post_meta($post->ID,'_content_type',true) ?: 'link';
        $alias = get_post_meta($post->ID,'_alias',true) ?: '';
        $target= get_post_meta($post->ID,'_target',true) ?: '';

        // fields for types
        $link = get_post_meta($post->ID,'_link_url',true) ?: '';
        $text = get_post_meta($post->ID,'_text_content',true) ?: '';

        $v_name = get_post_meta($post->ID,'_vcard_name',true) ?: '';
        $v_org  = get_post_meta($post->ID,'_vcard_org',true) ?: '';
        $v_title= get_post_meta($post->ID,'_vcard_title',true) ?: '';
        $v_phone= get_post_meta($post->ID,'_vcard_phone',true) ?: '';
        $v_email= get_post_meta($post->ID,'_vcard_email',true) ?: '';
        $v_site = get_post_meta($post->ID,'_vcard_website',true) ?: '';
        $v_addr = get_post_meta($post->ID,'_vcard_address',true) ?: '';

        $file = get_post_meta($post->ID,'_file_url',true) ?: '';
        $catalogue = get_post_meta($post->ID,'_catalogue_json',true) ?: '[{"title":"Item 1","desc":"Description","price":"199"}]';
        $price_curr = get_post_meta($post->ID,'_price_currency',true) ?: '₹';
        $price_amt  = get_post_meta($post->ID,'_price_amount',true) ?: '0';
        $social = get_post_meta($post->ID,'_social_json',true) ?: '{"facebook":"","instagram":"","twitter":"","youtube":"","website":""}';
        $place_id = get_post_meta($post->ID,'_goog_place_id',true) ?: '';
        $qa_question = get_post_meta($post->ID,'_qa_question',true) ?: 'What is your feedback?';

        wp_nonce_field('rwqr_save_meta','_rwqr_meta');
        ?>
        <p><label>Alias (for short link /qr/alias)<br>
            <input type="text" name="rwqr_alias" class="widefat" value="<?php echo esc_attr($alias); ?>">
        </label></p>

        <p><label>Content Type<br>
            <select name="rwqr_content_type" id="rwqr_content_type" class="widefat">
                <option value="link"      <?php selected($ctype,'link'); ?>>Link URL</option>
                <option value="text"      <?php selected($ctype,'text'); ?>>Plain Text</option>
                <option value="vcard"     <?php selected($ctype,'vcard'); ?>>vCard</option>
                <option value="file"      <?php selected($ctype,'file'); ?>>File URL</option>
                <option value="catalogue" <?php selected($ctype,'catalogue'); ?>>Catalogue (JSON)</option>
                <option value="price"     <?php selected($ctype,'price'); ?>>Price Card</option>
                <option value="social"    <?php selected($ctype,'social'); ?>>Social Links (JSON)</option>
                <option value="googlerev" <?php selected($ctype,'googlerev'); ?>>Google Review (Place ID)</option>
                <option value="qaform"    <?php selected($ctype,'qaform'); ?>>Q/A Form (collect replies)</option>
            </select>
        </label></p>

        <!-- Link -->
        <div class="rwqr-ct rwqr-ct-link" style="<?php echo $ctype==='link'?'':'display:none'; ?>">
            <p><label>Link URL<br><input type="url" name="rwqr_link_url" class="widefat" value="<?php echo esc_attr($link); ?>"></label></p>
        </div>

        <!-- Text -->
        <div class="rwqr-ct rwqr-ct-text" style="<?php echo $ctype==='text'?'':'display:none'; ?>">
            <p><label>Plain Text<br><textarea name="rwqr_text_content" class="widefat" rows="4"><?php echo esc_textarea($text); ?></textarea></label></p>
        </div>

        <!-- vCard -->
        <div class="rwqr-ct rwqr-ct-vcard" style="<?php echo $ctype==='vcard'?'':'display:none'; ?>">
            <p><label>Name<br><input type="text" name="rwqr_vcard_name" class="widefat" value="<?php echo esc_attr($v_name); ?>"></label></p>
            <p><label>Organisation<br><input type="text" name="rwqr_vcard_org" class="widefat" value="<?php echo esc_attr($v_org); ?>"></label></p>
            <p><label>Title/Role<br><input type="text" name="rwqr_vcard_title" class="widefat" value="<?php echo esc_attr($v_title); ?>"></label></p>
            <p><label>Phone<br><input type="text" name="rwqr_vcard_phone" class="widefat" value="<?php echo esc_attr($v_phone); ?>"></label></p>
            <p><label>Email<br><input type="email" name="rwqr_vcard_email" class="widefat" value="<?php echo esc_attr($v_email); ?>"></label></p>
            <p><label>Website<br><input type="url" name="rwqr_vcard_website" class="widefat" value="<?php echo esc_attr($v_site); ?>"></label></p>
            <p><label>Address<br><input type="text" name="rwqr_vcard_address" class="widefat" value="<?php echo esc_attr($v_addr); ?>"></label></p>
        </div>

        <!-- File -->
        <div class="rwqr-ct rwqr-ct-file" style="<?php echo $ctype==='file'?'':'display:none'; ?>">
            <p><label>File URL<br><input type="url" name="rwqr_file_url" class="widefat" value="<?php echo esc_attr($file); ?>"></label></p>
        </div>

        <!-- Catalogue -->
        <div class="rwqr-ct rwqr-ct-catalogue" style="<?php echo $ctype==='catalogue'?'':'display:none'; ?>">
            <p><label>Catalogue JSON<br><textarea name="rwqr_catalogue_json" class="widefat" rows="5"><?php echo esc_textarea($catalogue); ?></textarea></label></p>
        </div>

        <!-- Price -->
        <div class="rwqr-ct rwqr-ct-price" style="<?php echo $ctype==='price'?'':'display:none'; ?>">
            <p><label>Currency<br><input type="text" name="rwqr_price_currency" class="small-text" value="<?php echo esc_attr($price_curr); ?>"></label></p>
            <p><label>Amount<br><input type="text" name="rwqr_price_amount" class="small-text" value="<?php echo esc_attr($price_amt); ?>"></label></p>
        </div>

        <!-- Social -->
        <div class="rwqr-ct rwqr-ct-social" style="<?php echo $ctype==='social'?'':'display:none'; ?>">
            <p><label>Social Links JSON<br><textarea name="rwqr_social_json" class="widefat" rows="5"><?php echo esc_textarea($social); ?></textarea></label></p>
        </div>

        <!-- Google Review -->
        <div class="rwqr-ct rwqr-ct-googlerev" style="<?php echo $ctype==='googlerev'?'':'display:none'; ?>">
            <p><label>Google Place ID<br><input type="text" name="rwqr_goog_place_id" class="widefat" value="<?php echo esc_attr($place_id); ?>"></label></p>
        </div>

        <!-- Q/A Form -->
        <div class="rwqr-ct rwqr-ct-qaform" style="<?php echo $ctype==='qaform'?'':'display:none'; ?>">
            <p><label>Question<br><input type="text" name="rwqr_qa_question" class="widefat" value="<?php echo esc_attr($qa_question); ?>"></label></p>
        </div>

        <p><label>Computed Target (read-only)<br>
            <input type="text" class="widefat" value="<?php echo esc_attr($target); ?>" readonly>
        </label></p>

        <script>
        (function(){
          var sel = document.getElementById('rwqr_content_type');
          function show(type){
            document.querySelectorAll('.rwqr-ct').forEach(function(el){ el.style.display='none'; });
            var box = document.querySelector('.rwqr-ct-' + type);
            if(box) box.style.display='';
          }
          if(sel){ show(sel.value); sel.addEventListener('change', function(){ show(sel.value); }); }
        })();
        </script>
        <?php
    }

    /** Status & Limits box */
    public function mb_status($post){
        $active = get_post_meta($post->ID,'_active',true);
        $active = ($active==='' ? '1' : $active);
        $limit  = intval(get_post_meta($post->ID,'_scan_limit',true) ?: 0);
        $start  = get_post_meta($post->ID,'_start',true) ?: '';
        $end    = get_post_meta($post->ID,'_end',true) ?: '';
        $scans  = intval(get_post_meta($post->ID,'_scan_count',true) ?: 0);
        ?>
        <p><label>Status<br>
            <select name="rwqr_active">
                <option value="1" <?php selected($active,'1'); ?>>Active</option>
                <option value="0" <?php selected($active,'0'); ?>>Paused</option>
            </select>
        </label></p>
        <p><label>Scan limit (0 = unlimited)<br>
            <input type="number" name="rwqr_scan_limit" value="<?php echo esc_attr($limit); ?>">
        </label></p>
        <p><label>Start date<br><input type="date" name="rwqr_start" value="<?php echo esc_attr($start); ?>"></label></p>
        <p><label>End date<br><input type="date" name="rwqr_end" value="<?php echo esc_attr($end); ?>"></label></p>
        <p><strong>Total scans:</strong> <?php echo esc_html($scans); ?></p>
        <?php
    }

    /** Design labels */
    public function mb_design($post){
        $top    = get_post_meta($post->ID,'_top',true) ?: '';
        $bottom = get_post_meta($post->ID,'_bottom',true) ?: '';
        ?>
        <p><label>Top text<br><input type="text" name="rwqr_top" class="widefat" value="<?php echo esc_attr($top); ?>"></label></p>
        <p><label>Bottom text<br><input type="text" name="rwqr_bottom" class="widefat" value="<?php echo esc_attr($bottom); ?>"></label></p>
        <?php
    }

    /** Save meta */
    public function save_meta($post_id, $post, $update){
        if (!isset($_POST['_rwqr_meta']) || !wp_verify_nonce($_POST['_rwqr_meta'],'rwqr_save_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post',$post_id)) return;

        $ctype = sanitize_key($_POST['rwqr_content_type'] ?? 'link');
        $alias = sanitize_title($_POST['rwqr_alias'] ?? '');
        update_post_meta($post_id,'_content_type',$ctype);
        update_post_meta($post_id,'_alias',$alias);

        // status/limits
        $active = ($_POST['rwqr_active'] ?? '1')==='1' ? '1' : '0';
        $limit  = max(0, intval($_POST['rwqr_scan_limit'] ?? 0));
        $start  = sanitize_text_field($_POST['rwqr_start'] ?? '');
        $end    = sanitize_text_field($_POST['rwqr_end'] ?? '');
        update_post_meta($post_id,'_active',$active);
        update_post_meta($post_id,'_scan_limit',$limit);
        update_post_meta($post_id,'_start',$start);
        update_post_meta($post_id,'_end',$end);

        // design
        update_post_meta($post_id,'_top', sanitize_text_field($_POST['rwqr_top'] ?? ''));
        update_post_meta($post_id,'_bottom', sanitize_text_field($_POST['rwqr_bottom'] ?? ''));

        // save fields per type + compute target
        $target = '';

        switch ($ctype){
            case 'link':
                $link = esc_url_raw($_POST['rwqr_link_url'] ?? '');
                update_post_meta($post_id,'_link_url',$link);
                $target = $link ?: '';
            break;

            case 'text':
                $text = wp_kses_post($_POST['rwqr_text_content'] ?? '');
                update_post_meta($post_id,'_text_content',$text);
                $target = home_url('/qrview/'.$post_id);
            break;

            case 'vcard':
                update_post_meta($post_id,'_vcard_name',    sanitize_text_field($_POST['rwqr_vcard_name'] ?? ''));
                update_post_meta($post_id,'_vcard_org',     sanitize_text_field($_POST['rwqr_vcard_org'] ?? ''));
                update_post_meta($post_id,'_vcard_title',   sanitize_text_field($_POST['rwqr_vcard_title'] ?? ''));
                update_post_meta($post_id,'_vcard_phone',   sanitize_text_field($_POST['rwqr_vcard_phone'] ?? ''));
                update_post_meta($post_id,'_vcard_email',   sanitize_email($_POST['rwqr_vcard_email'] ?? ''));
                update_post_meta($post_id,'_vcard_website', esc_url_raw($_POST['rwqr_vcard_website'] ?? ''));
                update_post_meta($post_id,'_vcard_address', sanitize_text_field($_POST['rwqr_vcard_address'] ?? ''));
                $target = home_url('/qrview/'.$post_id);
            break;

            case 'file':
                $file = esc_url_raw($_POST['rwqr_file_url'] ?? '');
                update_post_meta($post_id,'_file_url',$file);
                $target = $file ?: '';
            break;

            case 'catalogue':
                $cat = wp_unslash($_POST['rwqr_catalogue_json'] ?? '[]');
                update_post_meta($post_id,'_catalogue_json',$cat);
                $target = home_url('/qrview/'.$post_id);
            break;

            case 'price':
                update_post_meta($post_id,'_price_currency', sanitize_text_field($_POST['rwqr_price_currency'] ?? '₹'));
                update_post_meta($post_id,'_price_amount',   sanitize_text_field($_POST['rwqr_price_amount'] ?? '0'));
                $target = home_url('/qrview/'.$post_id);
            break;

            case 'social':
                $sj = wp_unslash($_POST['rwqr_social_json'] ?? '{}');
                update_post_meta($post_id,'_social_json',$sj);
                $target = home_url('/qrview/'.$post_id);
            break;

            case 'googlerev':
                $pid = sanitize_text_field($_POST['rwqr_goog_place_id'] ?? '');
                update_post_meta($post_id,'_goog_place_id',$pid);
                // open Google write review directly
                $target = $pid ? ('https://search.google.com/local/writereview?placeid='.rawurlencode($pid)) : '';
            break;

            case 'qaform':
                $q = sanitize_text_field($_POST['rwqr_qa_question'] ?? 'What is your feedback?');
                update_post_meta($post_id,'_qa_question',$q);
                $target = home_url('/qrview/'.$post_id);
            break;
        }

        update_post_meta($post_id,'_target',$target);
    }

    /* ============================
     * Admin list: columns & data
     * ============================ */
    public function admin_cols($cols){
        $cols['alias']  = 'Alias';
        $cols['target'] = 'Target';
        $cols['scans']  = 'Scans';
        $cols['status'] = 'Status';
        return $cols;
    }
    public function admin_coldata($col, $post_id){
        if ($col==='alias'){
            echo esc_html(get_post_meta($post_id,'_alias',true));
        } elseif ($col==='target'){
            $t = get_post_meta($post_id,'_target',true);
            echo $t ? '<a href="'.esc_url($t).'" target="_blank" rel="noopener">'.esc_html($t).'</a>' : '-';
        } elseif ($col==='scans'){
            echo intval(get_post_meta($post_id,'_scan_count',true) ?: 0);
        } elseif ($col==='status'){
            $active = get_post_meta($post_id,'_active',true)==='1';
            echo $active ? '<span style="color:#16a34a;font-weight:600">Active</span>'
                         : '<span style="color:#ef4444;font-weight:600">Paused</span>';
        }
    }
    /* ============================
     * Front: Landing page renderers
     * ============================ */

    /** Render landing for content types that need a view (/qrview/{id}) */
    private function render_landing($id){
        $post = get_post($id);
        if (!$post || $post->post_type !== self::CPT || $post->post_status !== 'publish'){
            status_header(404); wp_die('QR not found');
        }

        // guards
        $active = get_post_meta($id,'_active',true)==='1';
        $limit  = intval(get_post_meta($id,'_scan_limit',true) ?: 0);
        $count  = intval(get_post_meta($id,'_scan_count',true) ?: 0);
        $start  = get_post_meta($id,'_start',true) ?: '';
        $end    = get_post_meta($id,'_end',true) ?: '';
        $today  = date('Y-m-d');

        // also respect admin cap here (read-only page; we still gate by same rules)
        $cfg = $this->settings();
        $admin_cap_per_qr = intval($cfg['limit_max_scans_per_qr'] ?? 0);
        $effective_limit = $limit;
        if ($admin_cap_per_qr > 0){
            $effective_limit = ($effective_limit === 0) ? $admin_cap_per_qr : min($effective_limit, $admin_cap_per_qr);
        }

        if (!$active || ($start && $today < $start) || ($end && $today > $end) || ($effective_limit>0 && $count >= $effective_limit)){
            status_header(403); wp_die('This QR is not active.');
        }

        $ctype = get_post_meta($id,'_content_type', true) ?: 'link';
        $top   = get_post_meta($id,'_top', true) ?: '';
        $bottom= get_post_meta($id,'_bottom', true) ?: '';
        $title = get_the_title($id);

        @header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($title); ?> — QR</title>
            <style>
                :root{--ink:#111;--muted:#6b7280;--line:#e5e7eb;--bg:#f9fafb;--brand:#111}
                body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
                .wrap{max-width:760px;margin:24px auto;padding:0 16px}
                .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px}
                h1{font-size:22px;margin:0 0 8px}
                .muted{color:var(--muted);font-size:13px;margin:2px 0 14px}
                .btn{display:inline-block;padding:10px 14px;border-radius:10px;background:var(--brand);color:#fff;text-decoration:none}
                .list{display:flex;flex-direction:column;gap:8px}
                .item{border:1px solid var(--line);border-radius:10px;padding:10px}
                .price{font-weight:700}
                .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
                .footer{margin-top:20px;color:var(--muted);font-size:12px}
                .code{white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
            </style>
        </head>
        <body>
        <div class="wrap">
            <div class="card">
                <?php if($top): ?><div class="muted"><?php echo esc_html($top); ?></div><?php endif; ?>
                <h1><?php echo esc_html($title); ?></h1>
                <div class="muted">Type: <?php echo esc_html(ucfirst($ctype)); ?></div>

                <?php
                switch ($ctype){
                    case 'text':
                        $text = get_post_meta($id,'_text_content',true) ?: '';
                        echo '<div>'.wpautop(wp_kses_post($text)).'</div>';
                        break;

                    case 'vcard':
                        $name  = get_post_meta($id,'_vcard_name',true);
                        $org   = get_post_meta($id,'_vcard_org',true);
                        $role  = get_post_meta($id,'_vcard_title',true);
                        $phone = get_post_meta($id,'_vcard_phone',true);
                        $email = get_post_meta($id,'_vcard_email',true);
                        $site  = get_post_meta($id,'_vcard_website',true);
                        $addr  = get_post_meta($id,'_vcard_address',true);

                        $vtext = $this->build_vcard($name,$org,$role,$phone,$email,$site,$addr);
                        $data  = 'data:text/vcard;charset=utf-8,'.rawurlencode($vtext);

                        echo '<div class="grid">';
                        echo '<div><strong>Name:</strong> '.esc_html($name).'<br><strong>Org:</strong> '.esc_html($org).'<br><strong>Role:</strong> '.esc_html($role).'</div>';
                        echo '<div><strong>Phone:</strong> '.esc_html($phone).'<br><strong>Email:</strong> <a href="mailto:'.esc_attr($email).'">'.esc_html($email).'</a><br><strong>Website:</strong> <a href="'.esc_url($site).'" target="_blank" rel="noopener">'.esc_html($site).'</a></div>';
                        echo '</div>';
                        echo '<p style="margin-top:12px"><a class="btn" href="'.$data.'" download="contact.vcf">Download vCard</a></p>';
                        break;

                    case 'catalogue':
                        $raw = get_post_meta($id,'_catalogue_json',true) ?: '[]';
                        $items = json_decode($raw,true); if (!is_array($items)) $items=[];
                        echo '<div class="list">';
                        foreach ($items as $it){
                            echo '<div class="item"><strong>'.esc_html($it['title'] ?? '').'</strong>';
                            if(!empty($it['desc'])) echo '<div>'.esc_html($it['desc']).'</div>';
                            if(!empty($it['price'])) echo '<div class="price">'.esc_html($it['price']).'</div>';
                            echo '</div>';
                        }
                        echo '</div>';
                        break;

                    case 'price':
                        $cur = get_post_meta($id,'_price_currency',true) ?: '₹';
                        $amt = get_post_meta($id,'_price_amount',true) ?: '0';
                        echo '<p class="price" style="font-size:28px">'.esc_html($cur).' '.esc_html($amt).'</p>';
                        break;

                    case 'social':
                        $raw = get_post_meta($id,'_social_json',true) ?: '{}';
                        $obj = json_decode($raw,true); if(!is_array($obj)) $obj=[];
                        foreach($obj as $k=>$url){
                            if(!$url) continue;
                            echo '<p><a class="btn" href="'.esc_url($url).'" target="_blank" rel="noopener">'.ucfirst($k).'</a></p>';
                        }
                        break;

                    case 'googlerev':
                        $pid = get_post_meta($id,'_goog_place_id',true) ?: '';
                        if($pid){
                            $url = 'https://search.google.com/local/writereview?placeid='.rawurlencode($pid);
                            echo '<p><a class="btn" href="'.esc_url($url).'" target="_blank" rel="noopener">Write a Google Review</a></p>';
                        } else {
                            echo '<p class="muted">No Place ID configured.</p>';
                        }
                        break;

                    case 'qaform':
                        $form_url = home_url('/qrform/'.$id);
                        echo '<p><a class="btn" href="'.esc_url($form_url).'">Open Q/A Form</a></p>';
                        break;

                    default:
                        $target = get_post_meta($id,'_target',true);
                        if($target){
                            echo '<p><a class="btn" href="'.esc_url($target).'" target="_blank" rel="noopener">Open</a></p>';
                        } else {
                            echo '<p>No content.</p>';
                        }
                        break;
                }

                if ($bottom){ echo '<div class="footer">'.esc_html($bottom).'</div>'; }
                ?>
            </div>
        </div>
        </body>
        </html>
        <?php
    }

    /** Render /qrform/{id} Q/A form and handle submissions */
    private function render_qa_form($id){
        $post = get_post($id);
        if (!$post || $post->post_type !== self::CPT || $post->post_status !== 'publish'){
            status_header(404); wp_die('Form not found');
        }

        $active = get_post_meta($id,'_active',true)==='1';
        $limit  = intval(get_post_meta($id,'_scan_limit',true) ?: 0);
        $count  = intval(get_post_meta($id,'_scan_count',true) ?: 0);
        $start  = get_post_meta($id,'_start',true) ?: '';
        $end    = get_post_meta($id,'_end',true) ?: '';
        $today  = date('Y-m-d');

        // respect admin cap for view-gated content
        $cfg = $this->settings();
        $admin_cap_per_qr = intval($cfg['limit_max_scans_per_qr'] ?? 0);
        $effective_limit = $limit;
        if ($admin_cap_per_qr > 0){
            $effective_limit = ($effective_limit === 0) ? $admin_cap_per_qr : min($effective_limit, $admin_cap_per_qr);
        }

        if (!$active || ($start && $today < $start) || ($end && $today > $end) || ($effective_limit>0 && $count >= $effective_limit)){
            status_header(403); wp_die('This QR is not active.');
        }

        $question=false; $success=false; $msg='';
        $question = get_post_meta($id,'_qa_question',true) ?: 'What is your feedback?';

        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['_rwqr_qa']) && wp_verify_nonce($_POST['_rwqr_qa'],'rwqr_qa_'.$id)){
            $name = sanitize_text_field($_POST['qa_name'] ?? '');
            $email= sanitize_email($_POST['qa_email'] ?? '');
            $ans  = wp_kses_post($_POST['qa_answer'] ?? '');
            $c = [
                'comment_post_ID' => $id,
                'comment_author'  => $name ?: 'Anonymous',
                'comment_author_email' => $email,
                'comment_content' => "Q: {$question}\n\nA:\n{$ans}",
                'comment_type'    => 'rwqr_reply',
                'comment_approved'=> 0,
            ];
            $cid = wp_insert_comment($c);
            if ($cid){
                $author = get_user_by('id', $post->post_author);
                $to = array_filter([get_option('admin_email'), $author ? $author->user_email : null]);
                wp_mail($to, 'New QR Form Reply', "QR: ".get_the_title($id)."\n\n{$c['comment_content']}\n\nFrom: {$name} <{$email}>");
                $success = true; $msg = 'Thank you! Your response has been received.';
            } else {
                $msg = 'Could not save your response. Please try again.';
            }
        }

        @header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html(get_the_title($id)); ?> — Q/A</title>
            <style>
                body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f9fafb;color:#111}
                .wrap{max-width:720px;margin:24px auto;padding:16px}
                .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px}
                .msg{padding:10px;border-radius:8px;margin:10px 0}
            </style>
        </head>
        <body>
        <div class="wrap">
            <div class="card">
                <h1><?php echo esc_html(get_the_title($id)); ?></h1>
                <p><?php echo esc_html($question); ?></p>

                <?php if ($msg): ?>
                    <div class="msg" style="<?php echo $success?'background:#dcfce7;color:#166534;border:1px solid #bbf7d0':'background:#fee2e2;color:#991b1b;border:1px solid #fecaca'; ?>">
                        <?php echo esc_html($msg); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$success): ?>
                <form method="post">
                    <?php wp_nonce_field('rwqr_qa_'.$id, '_rwqr_qa'); ?>
                    <p><input type="text" name="qa_name" placeholder="Your name"></p>
                    <p><input type="email" name="qa_email" placeholder="Your email"></p>
                    <p><textarea name="qa_answer" rows="5" placeholder="Your answer..." required></textarea></p>
                    <p><button type="submit">Submit</button></p>
                </form>
                <?php endif; ?>
            </div>
        </div>
        </body>
        </html>
        <?php
    }

    /* ======================
     * Helper: vCard builder
     * ====================== */
    private function build_vcard($name,$org,$title,$tel,$email,$url,$addr){
        $lines = ['BEGIN:VCARD','VERSION:3.0','FN:'.$name];
        if ($org)   $lines[] = 'ORG:'.$org;
        if ($title) $lines[] = 'TITLE:'.$title;
        if ($tel)   $lines[] = 'TEL;TYPE=CELL:'.$tel;
        if ($email) $lines[] = 'EMAIL;TYPE=INTERNET:'.$email;
        if ($url)   $lines[] = 'URL:'.$url;
        if ($addr)  $lines[] = 'ADR;TYPE=WORK:;;'.$addr;
        $lines[] = 'END:VCARD';
        return implode("\r\n", $lines)."\r\n";
    }
    /* ======================
     * Front route dispatcher
     * ====================== */
    public function maybe_handle_front_routes(){
        // Pretty query vars first
        $alias = get_query_var(self::QV_ALIAS);
        $view  = get_query_var(self::QV_VIEW);

        if ($alias){
            $this->handle_alias_redirect($alias);
            return;
        }
        if ($view){
            $this->render_landing(intval($view));
            exit;
        }

        // Support /qrform/{id} without extra rewrite var (keeps 1.5.3 compatibility)
        $req = $_SERVER['REQUEST_URI'] ?? '';
        if ($req){
            $path = wp_parse_url($req, PHP_URL_PATH);
            if (preg_match('#/qrform/([0-9]+)/?$#', $path, $m)){
                $this->render_qa_form(intval($m[1]));
                exit;
            }
        }
    }

    /* ===========================================
     * Alias redirect + analytics + quota checks
     * =========================================== */
    private function handle_alias_redirect($alias){
        $post = $this->get_qr_by_alias($alias);
        if (!$post){
            status_header(404); wp_die('QR not found');
        }
        $id     = $post->ID;
        $active = get_post_meta($id,'_active',true)==='1';
        $limit  = intval(get_post_meta($id,'_scan_limit',true) ?: 0);
        $count  = intval(get_post_meta($id,'_scan_count',true) ?: 0);
        $start  = get_post_meta($id,'_start',true) ?: '';
        $end    = get_post_meta($id,'_end',true) ?: '';
        $target = get_post_meta($id,'_target',true) ?: '';
        $today  = date('Y-m-d');

        // Admin quotas
        $cfg = $this->settings();
        $admin_cap_per_qr = intval($cfg['limit_max_scans_per_qr'] ?? 0);
        $window_days       = max(1, intval($cfg['limit_window_days'] ?? 30));
        $owner_cap_window  = intval($cfg['limit_max_scans_per_user_window'] ?? 0);

        // Effective per-QR limit = min(user limit, admin cap) ignoring zeros (0 = unlimited)
        $effective_limit = $limit;
        if ($admin_cap_per_qr > 0){
            $effective_limit = ($effective_limit === 0) ? $admin_cap_per_qr : min($effective_limit, $admin_cap_per_qr);
        }

        // Base guards (status / window / per-QR cap)
        if (!$active || ($start && $today < $start) || ($end && $today > $end) || ($effective_limit>0 && $count >= $effective_limit)){
            status_header(403); wp_die('This QR is not active.');
        }

        // Per-owner rolling window cap (sum across all QRs for owner in N days)
        if ($owner_cap_window > 0){
            global $wpdb;
            $table = $wpdb->prefix . self::DB_SCANS;

            $author_id = intval($post->post_author);
            $ids_q = new WP_Query([
                'post_type'      => self::CPT,
                'post_status'    => 'publish',
                'author'         => $author_id,
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            ]);
            $ids = $ids_q->posts ?: [];
            if (!empty($ids)){
                $since = gmdate('Y-m-d H:i:s', time() - ($window_days * DAY_IN_SECONDS));
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $sql = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE qr_id IN ($placeholders) AND created_at >= %s",
                    array_merge($ids, [$since])
                );
                $total = intval($wpdb->get_var($sql));
                if ($total >= $owner_cap_window){
                    status_header(403); wp_die('This account has reached its scan quota. Please try again later.');
                }
            }
        }

        // Record the scan
        $this->log_scan($id, sanitize_title($alias));
        update_post_meta($id,'_scan_count', $count + 1);

        // Fallback target for view-based content
        if (!$target) $target = home_url('/qrview/'.$id);

        // Redirect
        wp_redirect( esc_url_raw($target), 301 );
        exit;
    }

    private function get_qr_by_alias($alias){
        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'meta_key'       => '_alias',
            'meta_value'     => sanitize_title($alias),
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'all',
        ]);
        return $q->have_posts() ? $q->posts[0] : null;
    }

    private function log_scan($qr_id, $alias){
        global $wpdb;
        $table = $wpdb->prefix . self::DB_SCANS;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? @inet_pton($_SERVER['REMOTE_ADDR']) : null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ref= $_SERVER['HTTP_REFERER'] ?? '';
        $wpdb->insert($table, [
            'qr_id'      => $qr_id,
            'alias'      => $alias,
            'ip'         => $ip,
            'ua'         => $ua,
            'referrer'   => $ref,
            'created_at' => current_time('mysql')
        ]);
    }

    /* ============================
     * Admin row: quick pause/start
     * ============================ */
    public function admin_row_toggle_action($actions, $post){
        if ($post->post_type !== self::CPT) return $actions;

        // Add action link
        $url = wp_nonce_url(
            add_query_arg(['rwqr_toggle'=>$post->ID], admin_url('edit.php?post_type='.self::CPT)),
            'rwqr_toggle_'.$post->ID
        );
        $actions['rwqr_toggle'] = '<a href="'.esc_url($url).'">'.(get_post_meta($post->ID,'_active',true)==='1'?'Pause':'Start').'</a>';

        // Handle toggle if present on this row load
        if (isset($_GET['rwqr_toggle']) && intval($_GET['rwqr_toggle']) === $post->ID){
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'rwqr_toggle_'.$post->ID) && current_user_can('edit_post',$post->ID)){
                $cur = get_post_meta($post->ID,'_active',true)==='1' ? '1' : '0';
                update_post_meta($post->ID,'_active', $cur==='1' ? '0' : '1');
                wp_safe_redirect( remove_query_arg(['rwqr_toggle','_wpnonce']) );
                exit;
            }
        }
        return $actions;
    }
    /* ======================
     * Shortcode: Portal page
     * ====================== */
    public function sc_portal($atts = []){
        if (is_user_logged_in()){
            // Already logged in → show dashboard
            return $this->sc_dashboard();
        }

        $out = '<div class="rwqr-grid">';

        // LOGIN
        $out .= '<div class="rwqr-card"><h3>Login</h3><form method="post">'
            .wp_nonce_field('rwqr_login','_rwqr_login',true,false)
            .'<p><label>Username or Email<br><input type="text" name="log" required></label></p>'
            .'<p><label>Password<br><input type="password" name="pwd" required></label></p>'
            .'<p><button class="rwqr-btn" type="submit">Login</button></p>'
            // Simple notice under Login
            .'<p style="font-size:13px;color:#555;margin:8px 0 0">By continuing, you agree to our '
                .'<a href="'.esc_url(site_url('/terms')).'" target="_blank" rel="noopener">Terms &amp; Conditions</a> and '
                .'<a href="'.esc_url(site_url('/privacy-policy')).'" target="_blank" rel="noopener">Privacy Policy</a>.'
            .'</p>'
            .'</form></div>';

        // REGISTER (with required Terms + Privacy)
        $out .= '<div class="rwqr-card"><h3>Register</h3><form method="post" class="rwqr-register-form">'
            .wp_nonce_field('rwqr_register','_rwqr_register',true,false)
            .'<p><label>Username<br><input type="text" name="user_login" required></label></p>'
            .'<p><label>Email<br><input type="email" name="user_email" required></label></p>'
            .'<p><label>Password<br><input type="password" name="user_pass" required></label></p>'

            // Required checkboxes
            .'<div style="margin:10px 0;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa">'
              .'<label style="display:block;margin:6px 0">'
                .'<input type="checkbox" name="accept_terms" value="1" required> '
                .'I accept the <a href="'.esc_url(site_url('/terms')).'" target="_blank" rel="noopener">Terms &amp; Conditions</a>.'
              .'</label>'
              .'<label style="display:block;margin:6px 0">'
                .'<input type="checkbox" name="accept_privacy" value="1" required> '
                .'I have read the <a href="'.esc_url(site_url('/privacy-policy')).'" target="_blank" rel="noopener">Privacy Policy</a>.'
              .'</label>'
            .'</div>'

            .'<p><button class="rwqr-btn" type="submit">Register</button></p>'
            .'</form></div>';

        /* Handle LOGIN */
        if (isset($_POST['_rwqr_login']) && wp_verify_nonce($_POST['_rwqr_login'],'rwqr_login')){
            $creds = [
                'user_login'    => sanitize_text_field($_POST['log'] ?? ''),
                'user_password' => (string)($_POST['pwd'] ?? ''),
                'remember'      => true,
            ];
            $user = wp_signon($creds, is_ssl());
            if (!is_wp_error($user)){
                wp_safe_redirect(get_permalink()); exit;
            } else {
                $out = '<div class="rwqr-error">'.esc_html($user->get_error_message()).'</div>'.$out;
            }
        }

        /* Handle REGISTER */
        if (isset($_POST['_rwqr_register']) && wp_verify_nonce($_POST['_rwqr_register'],'rwqr_register')){
            if (empty($_POST['accept_terms']) || empty($_POST['accept_privacy'])){
                return '<div class="rwqr-error">You must accept Terms &amp; Privacy.</div>'.$out;
            }
            $login = sanitize_user($_POST['user_login'] ?? '');
            $email = sanitize_email($_POST['user_email'] ?? '');
            $pass  = (string)($_POST['user_pass'] ?? '');

            if (!$login || !$email || !$pass){
                return '<div class="rwqr-error">All fields are required.</div>'.$out;
            }
            if (username_exists($login) || email_exists($email)){
                return '<div class="rwqr-error">Username or email already exists.</div>'.$out;
            }

            $uid = wp_create_user($login, $pass, $email);
            if (is_wp_error($uid)){
                return '<div class="rwqr-error">'.esc_html($uid->get_error_message()).'</div>'.$out;
            }

            $u = new WP_User($uid);
            if ($u && !is_wp_error($u)){
                $u->set_role('author');
                update_user_meta($uid,'rwqr_terms_accepted', current_time('mysql'));
                update_user_meta($uid,'rwqr_privacy_accepted', current_time('mysql'));
            }

            return '<div class="rwqr-success">Registered successfully. Please login.</div>'.$out;
        }

        return $out.'</div>';
    }

    /* ===========================
     * Shortcode: User Dashboard
     * =========================== */
    public function sc_dashboard($atts = []){
        if (!is_user_logged_in()){
            return $this->sc_portal();
        }

        $cfg = $this->settings();
        $out = '<div class="rwqr-card"><h3>Dashboard</h3><p><a href="'.esc_url(wp_logout_url()).'" class="rwqr-btn">Logout</a></p></div>';

        // Create QR form
        $out .= '<div class="rwqr-card"><h3>Create QR</h3><form method="post">'
            .wp_nonce_field('rwqr_create','_rwqr_create',true,false)
            .'<p><label>Title<br><input type="text" name="qr_title" required></label></p>'
            .'<p><label>Alias<br><input type="text" name="qr_alias" required></label></p>'
            .'<p><label>Content Type<br><select name="qr_ctype">'
                .'<option value="link">Link</option><option value="text">Text</option>'
                .'<option value="vcard">vCard</option><option value="file">File</option>'
                .'<option value="catalogue">Catalogue</option><option value="price">Price</option>'
                .'<option value="social">Social</option><option value="googlerev">Google Review</option>'
                .'<option value="qaform">Q/A Form</option></select></label></p>'
            .'<p><label>Link/Content (or JSON for catalogue/social)<br><textarea name="qr_content" rows="3"></textarea></label></p>'
            .'<p><label>Start (optional)<br><input type="date" name="qr_start"></label></p>'
            .'<p><label>End (optional)<br><input type="date" name="qr_end"></label></p>'
            .'<p><label>Scan limit (0 = unlimited)<br><input type="number" name="qr_limit" min="0" value="0"></label></p>'
            .'<p><button class="rwqr-btn" type="submit">Create</button></p></form></div>';

        /* Handle CREATE with admin max-QR-per-user enforcement */
        if (isset($_POST['_rwqr_create']) && wp_verify_nonce($_POST['_rwqr_create'],'rwqr_create')){
            $title = sanitize_text_field($_POST['qr_title'] ?? '');
            $alias = sanitize_title($_POST['qr_alias'] ?? '');
            $ctype = sanitize_key($_POST['qr_ctype'] ?? 'link');
            $content = wp_kses_post($_POST['qr_content'] ?? '');
            $start = sanitize_text_field($_POST['qr_start'] ?? '');
            $end   = sanitize_text_field($_POST['qr_end'] ?? '');
            $limit = max(0, intval($_POST['qr_limit'] ?? 0));

            // Admin limit: max QRs per user
            $max_qr = intval($cfg['limit_max_qr_per_user'] ?? 0);
            if ($max_qr > 0){
                $qcount = new WP_Query([
                    'post_type'      => self::CPT,
                    'post_status'    => 'publish',
                    'author'         => get_current_user_id(),
                    'fields'         => 'ids',
                    'no_found_rows'  => false,
                    'posts_per_page' => 1,
                ]);
                if (intval($qcount->found_posts) >= $max_qr){
                    $out .= '<div class="rwqr-error">You have reached the maximum number of QR codes allowed for your account.</div>';
                    return $out;
                }
            }

            // Insert post
            $pid = wp_insert_post([
                'post_type'=> self::CPT,
                'post_status'=>'publish',
                'post_title'=> $title ?: 'QR',
                'post_author'=> get_current_user_id(),
            ]);

            if ($pid){
                update_post_meta($pid,'_alias', $alias);
                update_post_meta($pid,'_content_type', $ctype);
                update_post_meta($pid,'_active','1');
                update_post_meta($pid,'_scan_count',0);
                update_post_meta($pid,'_scan_limit',$limit);
                update_post_meta($pid,'_start',$start);
                update_post_meta($pid,'_end',$end);

                // Save content + compute target
                $target = '';
                switch ($ctype){
                    case 'link':
                        $target = esc_url_raw($content);
                        update_post_meta($pid,'_link_url',$target);
                    break;
                    case 'text':
                        update_post_meta($pid,'_text_content',$content);
                        $target = home_url('/qrview/'.$pid);
                    break;
                    case 'vcard':
                        update_post_meta($pid,'_vcard_name','');
                        update_post_meta($pid,'_vcard_org','');
                        update_post_meta($pid,'_vcard_title','');
                        update_post_meta($pid,'_vcard_phone','');
                        update_post_meta($pid,'_vcard_email','');
                        update_post_meta($pid,'_vcard_website','');
                        update_post_meta($pid,'_vcard_address','');
                        $target = home_url('/qrview/'.$pid);
                    break;
                    case 'file':
                        $target = esc_url_raw($content);
                        update_post_meta($pid,'_file_url',$target);
                    break;
                    case 'catalogue':
                        update_post_meta($pid,'_catalogue_json', wp_unslash($content));
                        $target = home_url('/qrview/'.$pid);
                    break;
                    case 'price':
                        update_post_meta($pid,'_price_currency', '₹');
                        update_post_meta($pid,'_price_amount',   '0');
                        $target = home_url('/qrview/'.$pid);
                    break;
                    case 'social':
                        update_post_meta($pid,'_social_json', wp_unslash($content));
                        $target = home_url('/qrview/'.$pid);
                    break;
                    case 'googlerev':
                        $pidg = sanitize_text_field($content);
                        update_post_meta($pid,'_goog_place_id', $pidg);
                        $target = $pidg ? ('https://search.google.com/local/writereview?placeid='.rawurlencode($pidg)) : '';
                    break;
                    case 'qaform':
                        update_post_meta($pid,'_qa_question','What is your feedback?');
                        $target = home_url('/qrview/'.$pid);
                    break;
                }
                update_post_meta($pid,'_target',$target);

                $out .= '<div class="rwqr-success">QR created.</div>';
            } else {
                $out .= '<div class="rwqr-error">Failed to create QR.</div>';
            }
        }

        // List QRs
        $q = new WP_Query([
            'post_type'=> self::CPT,
            'author'=> get_current_user_id(),
            'post_status'=>'publish',
            'posts_per_page'=>50,
            'orderby'=>'date',
            'order'=>'DESC',
        ]);
        $out .= '<div class="rwqr-card"><h3>Your QRs</h3>';
        if ($q->have_posts()){
            while($q->have_posts()){ $q->the_post();
                $id=get_the_ID();
                $alias=get_post_meta($id,'_alias',true);
                $short=home_url('/qr/'.$alias);
                $active=get_post_meta($id,'_active',true)==='1';
                $scans=intval(get_post_meta($id,'_scan_count',true) ?: 0);
                $target=get_post_meta($id,'_target',true);

                $out .= '<div class="rwqr-item">'
                    .'<div class="rwqr-item-main">'
                        .'<div class="rwqr-meta">'
                            .'<div class="rwqr-title">'.esc_html(get_the_title()).($active?'':' <span class="rwqr-badge-paused">paused</span>').'</div>'
                            .'<div class="rwqr-line"><strong>Short:</strong> <a href="'.esc_url($short).'" target="_blank" rel="noopener">'.esc_html($short).'</a></div>'
                            .'<div class="rwqr-line"><strong>Target:</strong> '.($target?('<a href="'.esc_url($target).'" target="_blank" rel="noopener">'.esc_html($target).'</a>'):'-').'</div>'
                            .'<div class="rwqr-line"><strong>Scans:</strong> '.$scans.'</div>'
                        .'</div>'
                    .'</div>'
                    .'<div class="rwqr-actions">'
                        .'<a class="rwqr-btn" href="'.esc_url(wp_nonce_url(add_query_arg(['rwqr_user_toggle'=>$id]),'rwqr_user_toggle_'.$id)).'">'.($active?'Pause':'Start').'</a>'
                        .'<a class="rwqr-btn rwqr-danger" href="'.esc_url(wp_nonce_url(add_query_arg(['rwqr_user_delete'=>$id]),'rwqr_user_delete_'.$id)).'" onclick="return confirm(\'Delete this QR?\')">Delete</a>'
                        // Shares
                        .'<a class="rwqr-btn" href="https://wa.me/?text='.rawurlencode($short).'" target="_blank" rel="noopener">WhatsApp</a>'
                        .'<a class="rwqr-btn" href="mailto:?subject='.rawurlencode('My QR').'%20&body='.rawurlencode($short).'" onclick="return window.rwqrOpenMail ? rwqrOpenMail(this) : true;" data-mailto="mailto:?subject='.rawurlencode('My QR').'&body='.rawurlencode($short).'">Email</a>'
                        .'<a class="rwqr-btn" href="https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($short).'" target="_blank" rel="noopener">Facebook</a>'
                        .'<a class="rwqr-btn" href="https://twitter.com/intent/tweet?url='.rawurlencode($short).'" target="_blank" rel="noopener">X/Twitter</a>'
                    .'</div>'
                .'</div>';
            }
            wp_reset_postdata();
        } else {
            $out .= '<p>No QR codes yet.</p>';
        }
        $out .= '</div>';

        // Handle toggle/delete (after list render to allow redirect)
        if (isset($_GET['rwqr_user_toggle'])){
            $id=intval($_GET['rwqr_user_toggle']);
            if (current_user_can('edit_post',$id) && wp_verify_nonce($_GET['_wpnonce'],'rwqr_user_toggle_'.$id)){
                $cur=get_post_meta($id,'_active',true)==='1'?'1':'0';
                update_post_meta($id,'_active',$cur==='1'?'0':'1');
                wp_safe_redirect(remove_query_arg(['rwqr_user_toggle','_wpnonce'])); exit;
            }
        }
        if (isset($_GET['rwqr_user_delete'])){
            $id=intval($_GET['rwqr_user_delete']);
            if (current_user_can('delete_post',$id) && wp_verify_nonce($_GET['_wpnonce'],'rwqr_user_delete_'.$id)){
                wp_trash_post($id);
                wp_safe_redirect(remove_query_arg(['rwqr_user_delete','_wpnonce'])); exit;
            }
        }

        return $out;
    }
    /* ============================
     * Small utility: front router
     * ============================ */
    private function front_404($msg='Not found'){
        status_header(404); wp_die(esc_html($msg));
    }

} // <-- end class RightWin_QR_Portal

// Boot the plugin
new RightWin_QR_Portal();
