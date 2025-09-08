<?php
/**
 * Plugin Name: RightWin QR Portal
 * Description: QR portal with dynamic aliases, analytics, consent (Terms + Privacy), content types (Link, Text, vCard, File URL, Catalogue, Price, Social, Google Review PlaceID, Q/A Form), user dashboard & admin controls.
 * Version: 1.6.1
 * Author: RightWin Medias
 * Requires PHP: 8.0
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

final class RightWin_QR_Portal {
    const VERSION = '1.6.1';
    const CPT = 'rwqr_code';
    const OPTION_SETTINGS = 'rwqr_settings';
    const DB_SCANS = 'rwqr_scans';

    // query vars
    const QV_ALIAS = 'rwqr_alias';     // /qr/{alias}
    const QV_VIEW  = 'rwqr_view_id';   // /qrview/{id}
    const QV_FORM  = 'rwqr_form_id';   // /qrform/{id}

    // content types
    private static $CONTENT_TYPES = [
        'link'       => 'Link URL',
        'text'       => 'Plain Text',
        'vcard'      => 'vCard',
        'file'       => 'File URL',
        'catalogue'  => 'Catalogue (list)',
        'price'      => 'Price Card',
        'social'     => 'Social Links',
        'googlerev'  => 'Google Review (Place ID)',
        'qaform'     => 'Q/A Form (collect replies)',
    ];

    public function __construct(){
        // Core
        add_action('init', [$this,'register_cpt']);
        add_action('init', [$this,'add_rewrite_rules']);
        add_filter('query_vars', [$this,'register_query_vars']);
        add_action('template_redirect', [$this,'maybe_handle_front_routes']);
        add_action('wp_enqueue_scripts', [$this,'enqueue_assets']);

        // Admin
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_action('add_meta_boxes', [$this,'add_meta_boxes']);
        add_action('save_post_'.self::CPT, [$this,'save_meta'], 10, 3);
        add_filter('manage_'.self::CPT.'_posts_columns', [$this,'admin_cols']);
        add_action('manage_'.self::CPT.'_posts_custom_column', [$this,'admin_coldata'], 10, 2);
        add_filter('post_row_actions', [$this,'admin_row_toggle_action'], 10, 2);

        // Shortcodes
        add_shortcode('rwqr_portal', [$this,'sc_portal']);      // login/register + router
        add_shortcode('rwqr_dashboard', [$this,'sc_dashboard']); // user dashboard

        // Footer consent line
        add_action('wp_footer', [$this,'footer_disclaimer']);

        // Activation / Deactivation
        register_activation_hook(__FILE__, [__CLASS__,'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__,'deactivate']);
    }

    /** Activation: create scans table + default options + flush rewrites */
    public static function activate(){
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . self::DB_SCANS;
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

        if (!get_option(self::OPTION_SETTINGS)){
            add_option(self::OPTION_SETTINGS, [
                'max_logo_mb'   => 2,
                'contact_html'  => 'Powered by RIGHT WIN MEDIAS — Contact: +91-00000 00000 | info@rightwinmedias.com',
                'email_handler' => 'mailto', // mailto|gmail|outlook|yahoo
            ]);
        }

        // add rewrites and flush
        self::add_rewrite_rules_static();
        flush_rewrite_rules();
    }
    public static function deactivate(){
        flush_rewrite_rules();
    }

    /** Settings page */
    public function admin_menu(){
        add_options_page('RightWin QR', 'RightWin QR', 'manage_options', 'rwqr-settings', [$this,'admin_settings']);
    }
    public function register_settings(){
        register_setting('rwqr-group', self::OPTION_SETTINGS);
    }
    public function admin_settings(){
        if (!current_user_can('manage_options')) return;

        if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('rwqr_save_settings')){
            $max = max(0, floatval($_POST['max_logo_mb'] ?? 2));
            $contact = wp_kses_post($_POST['contact_html'] ?? '');
            $handler = in_array(($_POST['email_handler'] ?? 'mailto'), ['mailto','gmail','outlook','yahoo'], true) ? $_POST['email_handler'] : 'mailto';
            update_option(self::OPTION_SETTINGS, [
                'max_logo_mb'   => $max,
                'contact_html'  => $contact,
                'email_handler' => $handler,
            ]);
            echo '<div class="updated"><p>Saved.</p></div>';
        }

        $s = get_option(self::OPTION_SETTINGS, [
            'max_logo_mb'=>2,
            'contact_html'=>'Powered by RIGHT WIN MEDIAS — Contact: +91-00000 00000 | info@rightwinmedias.com',
            'email_handler'=>'mailto'
        ]);
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
                                <option value="mailto"  <?php selected($s['email_handler'],'mailto');  ?>>System mail app (mailto:)</option>
                                <option value="gmail"   <?php selected($s['email_handler'],'gmail');   ?>>Gmail (web)</option>
                                <option value="outlook" <?php selected($s['email_handler'],'outlook'); ?>>Outlook.com (web)</option>
                                <option value="yahoo"   <?php selected($s['email_handler'],'yahoo');   ?>>Yahoo Mail (web)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p><button class="button button-primary">Save Changes</button></p>
            </form>
        </div>
        <?php
    }

    /** Assets */
    public function enqueue_assets(){
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('rwqr-portal', $base.'assets/portal.css', [], self::VERSION);
        wp_enqueue_script('rwqr-portal', $base.'assets/portal.js', [], self::VERSION, true);
    }

    /** Footer consent reminder */
    public function footer_disclaimer(){
        $terms  = esc_url(site_url('/terms'));
        $privacy= esc_url(site_url('/privacy-policy'));
        echo '<div class="rwqr-consent-footer">By continuing you agree to our '
            .'<a href="'.$terms.'" target="_blank" rel="noopener">Terms &amp; Conditions</a> and '
            .'<a href="'.$privacy.'" target="_blank" rel="noopener">Privacy Policy</a>.</div>';
    }

    /** Rewrites */
    public function add_rewrite_rules(){ self::add_rewrite_rules_static(); }
    private static function add_rewrite_rules_static(){
        add_rewrite_rule('^qr/([^/]+)/?$',     'index.php?'.self::QV_ALIAS.'=$matches[1]', 'top'); // alias
        add_rewrite_rule('^qrview/([0-9]+)/?$', 'index.php?'.self::QV_VIEW.'=$matches[1]', 'top'); // landing
        add_rewrite_rule('^qrform/([0-9]+)/?$', 'index.php?'.self::QV_FORM.'=$matches[1]', 'top'); // Q/A form
    }
    public function register_query_vars($vars){
        $vars[] = self::QV_ALIAS;
        $vars[] = self::QV_VIEW;
        $vars[] = self::QV_FORM;
        return $vars;
    }

    /** Router for /qrview/{id} and /qrform/{id} */
    public function maybe_handle_front_routes(){
        if ($id = absint(get_query_var(self::QV_VIEW))){ $this->render_landing($id); exit; }
        if ($id = absint(get_query_var(self::QV_FORM))){ $this->render_qa_form($id); exit; }
        if ($alias = get_query_var(self::QV_ALIAS)){ $this->handle_alias_redirect($alias); exit; }
    }
    /* -----------------------------
     * CPT and Meta Boxes (Content)
     * ----------------------------- */

    /** Custom Post Type: QR Codes */
    public function register_cpt(){
        register_post_type(self::CPT, [
            'label' => 'QR Codes',
            'public' => false,
            'show_ui' => true,
            'map_meta_cap' => true,
            'capability_type' => 'post',
            'supports' => ['title','author'],
            'menu_icon' => 'dashicons-qrcode',
        ]);
    }

    /** Add meta boxes */
    public function add_meta_boxes(){
        add_meta_box('rwqr_content', 'Content Type & Data', [$this,'mb_content'], self::CPT, 'normal', 'default');
        add_meta_box('rwqr_status', 'Status & Limits',     [$this,'mb_status'],  self::CPT, 'side',   'default');
        add_meta_box('rwqr_design', 'Design (Top/Bottom Labels)', [$this,'mb_design'], self::CPT, 'side', 'default');
    }

    /** Content Type & Data meta box */
    public function mb_content($post){
        $ctype = get_post_meta($post->ID, '_content_type', true) ?: 'link';
        $link  = get_post_meta($post->ID, '_link_url', true) ?: '';
        $text  = get_post_meta($post->ID, '_text_content', true) ?: '';

        // vCard
        $v_name = get_post_meta($post->ID, '_vcard_name', true) ?: '';
        $v_org  = get_post_meta($post->ID, '_vcard_org', true) ?: '';
        $v_title= get_post_meta($post->ID, '_vcard_title', true) ?: '';
        $v_phone= get_post_meta($post->ID, '_vcard_phone', true) ?: '';
        $v_email= get_post_meta($post->ID, '_vcard_email', true) ?: '';
        $v_site = get_post_meta($post->ID, '_vcard_website', true) ?: '';
        $v_addr = get_post_meta($post->ID, '_vcard_address', true) ?: '';

        // File URL
        $file = get_post_meta($post->ID, '_file_url', true) ?: '';

        // Catalogue JSON
        $catalogue = get_post_meta($post->ID, '_catalogue_json', true) ?: '[{"title":"Item 1","desc":"Description","price":"199"}]';

        // Price Card
        $price_curr = get_post_meta($post->ID, '_price_currency', true) ?: '₹';
        $price_amt  = get_post_meta($post->ID, '_price_amount', true) ?: '0';

        // Social links JSON
        $social = get_post_meta($post->ID, '_social_json', true) ?: '{"facebook":"","instagram":"","twitter":"","youtube":"","website":""}';

        // Google Review
        $place_id = get_post_meta($post->ID, '_goog_place_id', true) ?: '';

        // Q/A Form
        $qa_question = get_post_meta($post->ID, '_qa_question', true) ?: 'What is your feedback?';

        // Alias (short) and computed target
        $alias  = get_post_meta($post->ID, '_alias', true) ?: '';
        $target = get_post_meta($post->ID, '_target', true) ?: '';

        wp_nonce_field('rwqr_save_meta','_rwqr_meta');
        ?>
        <p><label>Content Type<br>
            <select name="rwqr_content_type" class="widefat" id="rwqr_content_type">
                <?php foreach (self::$CONTENT_TYPES as $k=>$label): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($ctype,$k); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label></p>

        <div class="rwqr-ct rwqr-ct-link"   style="<?php echo $ctype==='link'?'':'display:none'; ?>">
            <p><label>Link URL<br><input type="url" name="rwqr_link_url" class="widefat" value="<?php echo esc_attr($link); ?>" placeholder="https://example.com"></label></p>
        </div>

        <div class="rwqr-ct rwqr-ct-text"   style="<?php echo $ctype==='text'?'':'display:none'; ?>">
            <p><label>Plain Text<br><textarea name="rwqr_text_content" class="widefat" rows="4" placeholder="Your text..."><?php echo esc_textarea($text); ?></textarea></label></p>
        </div>

        <div class="rwqr-ct rwqr-ct-vcard"  style="<?php echo $ctype==='vcard'?'':'display:none'; ?>">
            <p><label>Name<br><input type="text" name="rwqr_vcard_name" class="widefat" value="<?php echo esc_attr($v_name); ?>"></label></p>
            <p><label>Organisation<br><input type="text" name="rwqr_vcard_org" class="widefat" value="<?php echo esc_attr($v_org); ?>"></label></p>
            <p><label>Title/Role<br><input type="text" name="rwqr_vcard_title" class="widefat" value="<?php echo esc_attr($v_title); ?>"></label></p>
            <p><label>Phone<br><input type="text" name="rwqr_vcard_phone" class="widefat" value="<?php echo esc_attr($v_phone); ?>"></label></p>
            <p><label>Email<br><input type="email" name="rwqr_vcard_email" class="widefat" value="<?php echo esc_attr($v_email); ?>"></label></p>
            <p><label>Website<br><input type="url" name="rwqr_vcard_website" class="widefat" value="<?php echo esc_attr($v_site); ?>"></label></p>
            <p><label>Address<br><input type="text" name="rwqr_vcard_address" class="widefat" value="<?php echo esc_attr($v_addr); ?>"></label></p>
        </div>

        <div class="rwqr-ct rwqr-ct-file"   style="<?php echo $ctype==='file'?'':'display:none'; ?>">
            <p><label>File URL (PDF/Image/Doc)<br><input type="url" name="rwqr_file_url" class="widefat" value="<?php echo esc_attr($file); ?>" placeholder="https://example.com/file.pdf"></label></p>
        </div>

        <div class="rwqr-ct rwqr-ct-catalogue" style="<?php echo $ctype==='catalogue'?'':'display:none'; ?>">
            <p><label>Catalogue JSON (array of items)<br>
                <textarea name="rwqr_catalogue_json" class="widefat" rows="5">"<?php echo esc_textarea($catalogue); ?>"</textarea></label>
            </p>
            <small>Example: [{"title":"Item 1","desc":"Details","price":"199"}]</small>
        </div>

        <div class="rwqr-ct rwqr-ct-price"  style="<?php echo $ctype==='price'?'':'display:none'; ?>">
            <p><label>Currency Symbol<br><input type="text" name="rwqr_price_currency" class="small-text" value="<?php echo esc_attr($price_curr); ?>"></label></p>
            <p><label>Amount<br><input type="text" name="rwqr_price_amount" class="small-text" value="<?php echo esc_attr($price_amt); ?>"></label></p>
        </div>

        <div class="rwqr-ct rwqr-ct-social" style="<?php echo $ctype==='social'?'':'display:none'; ?>">
            <p><label>Social Links JSON<br>
                <textarea name="rwqr_social_json" class="widefat" rows="5">"<?php echo esc_textarea($social); ?>"</textarea></label>
            </p>
            <small>Example: {"facebook":"...","instagram":"...","twitter":"...","youtube":"...","website":"..."}</small>
        </div>

        <div class="rwqr-ct rwqr-ct-googlerev" style="<?php echo $ctype==='googlerev'?'':'display:none'; ?>">
            <p><label>Google Place ID<br><input type="text" name="rwqr_goog_place_id" class="widefat" value="<?php echo esc_attr($place_id); ?>" placeholder="ChIJ..."></label></p>
            <small>Find your Place ID via Google Place ID Finder, paste here.</small>
        </div>

        <div class="rwqr-ct rwqr-ct-qaform"   style="<?php echo $ctype==='qaform'?'':'display:none'; ?>">
            <p><label>Question (will be shown on the form)<br><input type="text" name="rwqr_qa_question" class="widefat" value="<?php echo esc_attr($qa_question); ?>"></label></p>
        </div>

        <hr>
        <p><label>Alias (short link)<br>
            <input type="text" name="rwqr_alias" class="regular-text" value="<?php echo esc_attr($alias); ?>" placeholder="my-brand"></label>
            <small>Short URL: <?php echo esc_html( home_url('/qr/') ); ?><em>alias</em></small>
        </p>
        <p><label>Computed Target (read-only; auto-set for dynamic content)<br>
            <input type="text" class="widefat" value="<?php echo esc_attr($target); ?>" readonly></label></p>

        <script>
        (function(){
          var sel = document.getElementById('rwqr_content_type');
          if(!sel) return;
          function show(type){
            document.querySelectorAll('.rwqr-ct').forEach(function(el){ el.style.display='none'; });
            var box = document.querySelector('.rwqr-ct-' + type);
            if(box) box.style.display = '';
          }
          sel.addEventListener('change', function(){ show(this.value); });
        })();
        </script>
        <?php
    }

    /** Status box */
    public function mb_status($post){
        $active = get_post_meta($post->ID, '_active', true);
        $active = ($active === '' ? '1' : $active);
        $limit  = intval(get_post_meta($post->ID, '_scan_limit', true) ?: 0);
        $start  = get_post_meta($post->ID, '_start', true) ?: '';
        $end    = get_post_meta($post->ID, '_end', true) ?: '';
        $scans  = intval(get_post_meta($post->ID, '_scan_count', true) ?: 0);
        ?>
        <p><label>Status<br>
            <select name="rwqr_active">
                <option value="1" <?php selected($active,'1'); ?>>Active</option>
                <option value="0" <?php selected($active,'0'); ?>>Paused</option>
            </select></label>
        </p>
        <p><label>Scan limit (0 = unlimited)<br><input type="number" name="rwqr_scan_limit" min="0" value="<?php echo esc_attr($limit); ?>" class="small-text"></label></p>
        <p><label>Start (YYYY-MM-DD)<br><input type="date" name="rwqr_start" value="<?php echo esc_attr($start); ?>"></label></p>
        <p><label>End (YYYY-MM-DD)<br><input type="date" name="rwqr_end" value="<?php echo esc_attr($end); ?>"></label></p>
        <p><strong>Total scans:</strong> <?php echo esc_html($scans); ?></p>
        <?php
    }

    /** Design box */
    public function mb_design($post){
        $top = get_post_meta($post->ID, '_top', true) ?: '';
        $bottom = get_post_meta($post->ID, '_bottom', true) ?: '';
        ?>
        <p><label>Top text<br><input type="text" name="rwqr_top" value="<?php echo esc_attr($top); ?>" class="widefat" placeholder="e.g., Restaurant Name"></label></p>
        <p><label>Bottom text<br><input type="text" name="rwqr_bottom" value="<?php echo esc_attr($bottom); ?>" class="widefat" placeholder="e.g., Since 1999"></label></p>
        <?php
    }

    /** Save meta */
    public function save_meta($post_id, $post, $update){
        if (!isset($_POST['_rwqr_meta']) || !wp_verify_nonce($_POST['_rwqr_meta'],'rwqr_save_meta')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Status/limits/design
        $active = (isset($_POST['rwqr_active']) && $_POST['rwqr_active']=='0') ? '0' : '1';
        $limit  = max(0, intval($_POST['rwqr_scan_limit'] ?? 0));
        $start  = preg_replace('/[^0-9\-]/','', $_POST['rwqr_start'] ?? '');
        $end    = preg_replace('/[^0-9\-]/','', $_POST['rwqr_end'] ?? '');
        $top    = sanitize_text_field($_POST['rwqr_top'] ?? '');
        $bottom = sanitize_text_field($_POST['rwqr_bottom'] ?? '');
        update_post_meta($post_id, '_active', $active);
        update_post_meta($post_id, '_scan_limit', $limit);
        update_post_meta($post_id, '_start', $start);
        update_post_meta($post_id, '_end', $end);
        update_post_meta($post_id, '_top', $top);
        update_post_meta($post_id, '_bottom', $bottom);

        // Alias
        $alias = sanitize_title($_POST['rwqr_alias'] ?? '');
        update_post_meta($post_id, '_alias', $alias);

        // Content
        $ctype = sanitize_key($_POST['rwqr_content_type'] ?? 'link');
        if (!isset(self::$CONTENT_TYPES[$ctype])) $ctype = 'link';
        update_post_meta($post_id, '_content_type', $ctype);

        switch ($ctype){
            case 'link':
                $link = esc_url_raw($_POST['rwqr_link_url'] ?? '');
                update_post_meta($post_id, '_link_url', $link);
                // direct target = link
                update_post_meta($post_id, '_target', $link);
                break;

            case 'text':
                $text = wp_kses_post($_POST['rwqr_text_content'] ?? '');
                update_post_meta($post_id, '_text_content', $text);
                // render landing page
                update_post_meta($post_id, '_target', home_url('/qrview/'.$post_id));
                break;

            case 'vcard':
                $v = [
                    'name'   => sanitize_text_field($_POST['rwqr_vcard_name'] ?? ''),
                    'org'    => sanitize_text_field($_POST['rwqr_vcard_org'] ?? ''),
                    'title'  => sanitize_text_field($_POST['rwqr_vcard_title'] ?? ''),
                    'phone'  => sanitize_text_field($_POST['rwqr_vcard_phone'] ?? ''),
                    'email'  => sanitize_email($_POST['rwqr_vcard_email'] ?? ''),
                    'website'=> esc_url_raw($_POST['rwqr_vcard_website'] ?? ''),
                    'addr'   => sanitize_text_field($_POST['rwqr_vcard_address'] ?? ''),
                ];
                foreach($v as $k=>$val){ update_post_meta($post_id, '_vcard_'.$k, $val); }
                update_post_meta($post_id, '_target', home_url('/qrview/'.$post_id));
                break;

            case 'file':
                $file = esc_url_raw($_POST['rwqr_file_url'] ?? '');
                update_post_meta($post_id, '_file_url', $file);
                update_post_meta($post_id, '_target', $file);
                break;

            case 'catalogue':
                $json = wp_unslash($_POST['rwqr_catalogue_json'] ?? '[]');
                // store raw string; validate during render
                update_post_meta($post_id, '_catalogue_json', $json);
                update_post_meta($post_id, '_target', home_url('/qrview/'.$post_id));
                break;

            case 'price':
                $cur = sanitize_text_field($_POST['rwqr_price_currency'] ?? '₹');
                $amt = sanitize_text_field($_POST['rwqr_price_amount'] ?? '0');
                update_post_meta($post_id, '_price_currency', $cur);
                update_post_meta($post_id, '_price_amount', $amt);
                update_post_meta($post_id, '_target', home_url('/qrview/'.$post_id));
                break;

            case 'social':
                $social = wp_unslash($_POST['rwqr_social_json'] ?? '{}');
                update_post_meta($post_id, '_social_json', $social);
                update_post_meta($post_id, '_target', home_url('/qrview/'.$post_id));
                break;

            case 'googlerev':
                $pid = sanitize_text_field($_POST['rwqr_goog_place_id'] ?? '');
                update_post_meta($post_id, '_goog_place_id', $pid);
                // target is Google review compose link
                $url = $pid ? 'https://search.google.com/local/writereview?placeid='.rawurlencode($pid) : '';
                update_post_meta($post_id, '_target', $url ?: home_url('/qrview/'.$post_id));
                break;

            case 'qaform':
                $q = sanitize_text_field($_POST['rwqr_qa_question'] ?? 'What is your feedback?');
                update_post_meta($post_id, '_qa_question', $q);
                // dedicated route to form
                update_post_meta($post_id, '_target', home_url('/qrform/'.$post_id));
                break;
        }

        // Ensure scan counter exists
        if (get_post_meta($post_id, '_scan_count', true) === '') update_post_meta($post_id, '_scan_count', 0);
    }
    /* -----------------------------------------
     * Front routes: landing & Q/A form renderers
     * ----------------------------------------- */

    /** Render landing for content types that need a view (/qrview/{id}) */
    private function render_landing($id){
        $post = get_post($id);
        if (!$post || $post->post_type !== self::CPT || $post->post_status !== 'publish'){
            status_header(404); wp_die('QR not found');
        }

        // basic guards (active, dates, limits)
        $active = get_post_meta($id,'_active',true)==='1';
        $limit  = intval(get_post_meta($id,'_scan_limit',true) ?: 0);
        $count  = intval(get_post_meta($id,'_scan_count',true) ?: 0);
        $start  = get_post_meta($id,'_start',true) ?: '';
        $end    = get_post_meta($id,'_end',true) ?: '';
        $today  = date('Y-m-d');
        if (!$active || ($start && $today < $start) || ($end && $today > $end) || ($limit>0 && $count >= $limit)){
            status_header(403); wp_die('This QR is not active.');
        }

        $ctype = get_post_meta($id,'_content_type', true) ?: 'link';
        $top   = get_post_meta($id,'_top', true) ?: '';
        $bottom= get_post_meta($id,'_bottom', true) ?: '';
        $title = get_the_title($id);

        // Simple, theme-independent page
        @header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($title); ?> — QR</title>
            <style>
                body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f8fafc;color:#111}
                .wrap{max-width:760px;margin:24px auto;padding:0 16px}
                .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
                h1{font-size:22px;margin:0 0 8px}
                .muted{color:#6b7280;font-size:13px;margin:2px 0 14px}
                .btn{display:inline-block;padding:10px 14px;border-radius:10px;background:#111;color:#fff;text-decoration:none;border:1px solid #111}
                .list{display:flex;flex-direction:column;gap:8px}
                .item{border:1px solid #e5e7eb;border-radius:10px;padding:10px}
                .price{font-weight:700}
                .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
                .row{margin:8px 0}
                .label{font-weight:600}
                .qrimg{width:180px;height:180px;object-fit:contain;border:1px solid #e5e7eb;border-radius:10px;background:#fff;margin:8px 0}
                .footer{margin-top:20px;color:#6b7280;font-size:12px}
                .social a{display:inline-block;margin-right:10px}
                .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
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
                        echo '<div class="row">'.wpautop(wp_kses_post($text)).'</div>';
                        break;

                    case 'vcard':
                        $name  = get_post_meta($id,'_vcard_name',true);
                        $org   = get_post_meta($id,'_vcard_org',true);
                        $role  = get_post_meta($id,'_vcard_title',true);
                        $phone = get_post_meta($id,'_vcard_phone',true);
                        $email = get_post_meta($id,'_vcard_email',true);
                        $site  = get_post_meta($id,'_vcard_website',true);
                        $addr  = get_post_meta($id,'_vcard_address',true);
                        // show a compact card + download link
                        echo '<div class="grid">';
                        echo '<div><div class="row"><span class="label">Name:</span> '.esc_html($name).'</div>';
                        echo '<div class="row"><span class="label">Org:</span> '.esc_html($org).'</div>';
                        echo '<div class="row"><span class="label">Title:</span> '.esc_html($role).'</div></div>';
                        echo '<div><div class="row"><span class="label">Phone:</span> '.esc_html($phone).'</div>';
                        echo '<div class="row"><span class="label">Email:</span> <a href="mailto:'.esc_attr($email).'">'.esc_html($email).'</a></div>';
                        echo '<div class="row"><span class="label">Website:</span> <a href="'.esc_url($site).'" target="_blank">'.esc_html($site).'</a></div></div>';
                        echo '</div>';
                        $vcard = $this->build_vcard($name,$org,$role,$phone,$email,$site,$addr);
                        $dl = 'data:text/vcard;charset=utf-8,'.rawurlencode($vcard);
                        echo '<p class="row"><a class="btn" download="contact.vcf" href="'.$dl.'">Download vCard</a></p>';
                        break;

                    case 'catalogue':
                        $raw = get_post_meta($id,'_catalogue_json',true) ?: '[]';
                        $items = json_decode($raw,true);
                        if (!is_array($items)) $items = [];
                        echo '<div class="list">';
                        foreach ($items as $it){
                            $t = isset($it['title']) ? $it['title'] : '';
                            $d = isset($it['desc']) ? $it['desc'] : '';
                            $p = isset($it['price']) ? $it['price'] : '';
                            echo '<div class="item"><div><strong>'.esc_html($t).'</strong></div>';
                            if ($d) echo '<div class="muted">'.esc_html($d).'</div>';
                            if ($p) echo '<div class="price">'.esc_html($p).'</div>';
                            echo '</div>';
                        }
                        echo '</div>';
                        break;

                    case 'price':
                        $cur = get_post_meta($id,'_price_currency',true) ?: '₹';
                        $amt = get_post_meta($id,'_price_amount',true) ?: '0';
                        echo '<p class="row"><span class="price" style="font-size:28px">'.esc_html($cur).' '.esc_html($amt).'</span></p>';
                        break;

                    case 'social':
                        $raw = get_post_meta($id,'_social_json',true) ?: '{}';
                        $obj = json_decode($raw,true);
                        if (!is_array($obj)) $obj = [];
                        echo '<p class="social">';
                        foreach ($obj as $k=>$url){
                            if (!$url) continue;
                            $label = ucfirst($k);
                            echo '<a class="btn" href="'.esc_url($url).'" target="_blank" rel="noopener">'.$label.'</a> ';
                        }
                        echo '</p>';
                        break;

                    case 'googlerev':
                        $pid = get_post_meta($id,'_goog_place_id',true) ?: '';
                        $url = $pid ? 'https://search.google.com/local/writereview?placeid='.rawurlencode($pid) : '';
                        if ($url){
                            echo '<p class="row">Tap the button to leave a Google Review.</p>';
                            echo '<p><a class="btn" href="'.esc_url($url).'" target="_blank" rel="noopener">Write a Review</a></p>';
                        } else {
                            echo '<p class="row">Place ID missing.</p>';
                        }
                        break;

                    default:
                        // 'link' and 'file' should directly redirect via /qr/{alias} path
                        $target = get_post_meta($id,'_target',true);
                        if ($target){
                            echo '<p class="row">Opening: <span class="mono">'.esc_html($target).'</span></p>';
                            echo '<p><a class="btn" href="'.esc_url($target).'" target="_blank" rel="noopener">Open Link</a></p>';
                        } else {
                            echo '<p class="row">No content.</p>';
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
        // guards
        $active = get_post_meta($id,'_active',true)==='1';
        $limit  = intval(get_post_meta($id,'_scan_limit',true) ?: 0);
        $count  = intval(get_post_meta($id,'_scan_count',true) ?: 0);
        $start  = get_post_meta($id,'_start',true) ?: '';
        $end    = get_post_meta($id,'_end',true) ?: '';
        $today  = date('Y-m-d');
        if (!$active || ($start && $today < $start) || ($end && $today > $end) || ($limit>0 && $count >= $limit)){
            status_header(403); wp_die('This QR is not active.');
        }

        $question = get_post_meta($id,'_qa_question',true) ?: 'What is your feedback?';
        $success = false; $msg = '';

        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['_rwqr_qa']) && wp_verify_nonce($_POST['_rwqr_qa'],'rwqr_qa_'.$id)){
            $name = sanitize_text_field($_POST['qa_name'] ?? '');
            $email= sanitize_email($_POST['qa_email'] ?? '');
            $ans  = wp_kses_post($_POST['qa_answer'] ?? '');
            // Store as comment for easy admin review
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
                // Notify post author & admin
                $author = get_user_by('id', $post->post_author);
                $to = array_filter([get_option('admin_email'), $author ? $author->user_email : null]);
                wp_mail($to, 'New QR Form Reply', "QR: ".get_the_title($id)."\n\n{$c['comment_content']}\n\nFrom: {$name} <{$email}>");
                $success = true;
                $msg = 'Thank you! Your response has been received.';
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
                body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f8fafc;color:#111}
                .wrap{max-width:720px;margin:24px auto;padding:0 16px}
                .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
                h1{font-size:22px;margin:0 0 8px}
                .muted{color:#6b7280;font-size:13px;margin:2px 0 14px}
                .row{margin:10px 0}
                input,textarea{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px}
                .btn{display:inline-block;padding:10px 14px;border-radius:10px;background:#111;color:#fff;text-decoration:none;border:1px solid #111;cursor:pointer}
                .note{font-size:13px;color:#6b7280}
                .success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;padding:10px;border-radius:10px;margin:10px 0;}
                .error{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px;border-radius:10px;margin:10px 0;}
            </style>
        </head>
        <body>
        <div class="wrap">
            <div class="card">
                <h1><?php echo esc_html(get_the_title($id)); ?></h1>
                <div class="muted"><?php echo esc_html($question); ?></div>

                <?php if ($msg): ?>
                    <div class="<?php echo $success?'success':'error'; ?>"><?php echo esc_html($msg); ?></div>
                <?php endif; ?>

                <?php if (!$success): ?>
                <form method="post">
                    <?php wp_nonce_field('rwqr_qa_'.$id, '_rwqr_qa'); ?>
                    <div class="row"><input type="text" name="qa_name" placeholder="Your name (optional)"></div>
                    <div class="row"><input type="email" name="qa_email" placeholder="Your email (optional)"></div>
                    <div class="row"><textarea name="qa_answer" rows="6" placeholder="Type your answer..." required></textarea></div>
                    <div class="row"><button class="btn">Submit</button></div>
                    <div class="note">Your response will be sent to the QR owner and may be reviewed by admin.</div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        </body>
        </html>
        <?php
    }

    /* -----------------------------------------
     * Alias redirect and analytics logging
     * ----------------------------------------- */

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

        if (!$active || ($start && $today < $start) || ($end && $today > $end) || ($limit>0 && $count >= $limit)){
            status_header(403); wp_die('This QR is not active.');
        }

        // Log scan; increment counter (admin + user dashboard will reflect)
        $this->log_scan($id, sanitize_title($alias));
        update_post_meta($id,'_scan_count', $count + 1);

        // Fallback if target is empty, send to landing
        if (!$target) $target = home_url('/qrview/'.$id);

        wp_redirect( esc_url_raw($target), 301 );
        exit;
    }

    private function get_qr_by_alias($alias){
        $q = new WP_Query([
            'post_type'=> self::CPT,
            'post_status'=>'publish',
            'meta_key'=>'_alias',
            'meta_value'=> sanitize_title($alias),
            'posts_per_page'=>1,
            'no_found_rows'=>true,
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
            'qr_id' => $qr_id,
            'alias' => $alias,
            'ip'    => $ip,
            'ua'    => $ua,
            'referrer'=>$ref,
            'created_at'=> current_time('mysql')
        ]);
    }

    /* -----------------------------------------
     * Admin list: columns and quick toggle
     * ----------------------------------------- */

    public function admin_cols($cols){
        $cols['alias']  = 'Alias';
        $cols['target'] = 'Target';
        $cols['scans']  = 'Scans';
        $cols['status'] = 'Status';
        return $cols;
    }

    public function admin_coldata($col, $post_id){
        if ($col==='alias'){
            $a = get_post_meta($post_id,'_alias',true);
            echo esc_html($a);
        } elseif ($col==='target'){
            $t = get_post_meta($post_id,'_target',true);
            echo $t ? '<a href="'.esc_url($t).'" target="_blank" rel="noopener">'.esc_html($t).'</a>' : '-';
        } elseif ($col==='scans'){
            echo intval(get_post_meta($post_id,'_scan_count',true) ?: 0);
        } elseif ($col==='status'){
            $active = get_post_meta($post_id,'_active',true)==='1';
            echo $active ? '<span style="color:#16a34a;font-weight:600">Active</span>' : '<span style="color:#ef4444;font-weight:600">Paused</span>';
        }
    }

    public function admin_row_toggle_action($actions, $post){
        if ($post->post_type !== self::CPT) return $actions;
        $url = wp_nonce_url(add_query_arg(['rwqr_toggle'=>$post->ID], admin_url('edit.php?post_type='.self::CPT)), 'rwqr_toggle_'.$post->ID);
        $actions['rwqr_toggle'] = '<a href="'.esc_url($url).'">Toggle Active/Pause</a>';

        // Handle toggle when the listing reloads
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

    /* -----------------------------------------
     * Helper: vCard text generator
     * ----------------------------------------- */
    private function build_vcard($name,$org,$title,$tel,$email,$url,$addr){
        $name = trim($name);
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:'.$name,
        ];
        if ($org)   $lines[] = 'ORG:'.$org;
        if ($title) $lines[] = 'TITLE:'.$title;
        if ($tel)   $lines[] = 'TEL;TYPE=CELL:'.$tel;
        if ($email) $lines[] = 'EMAIL;TYPE=INTERNET:'.$email;
        if ($url)   $lines[] = 'URL:'.$url;
        if ($addr)  $lines[] = 'ADR;TYPE=WORK:;;'.$addr;
        $lines[] = 'END:VCARD';
        return implode("\r\n", $lines)."\r\n";
    }
    /* -----------------------------------------
     * Shortcodes
     * ----------------------------------------- */

    /** Portal: shows Login/Register (when logged out) or routes to Dashboard (when logged in) */
    public function sc_portal($atts=[]){
        if (is_user_logged_in()){
            return $this->sc_dashboard();
        }

        $out = '<div class="rwqr-grid">';

        // --- Login card ---
        $out .= '<div class="rwqr-card"><h3>Login</h3><form method="post">'
            .wp_nonce_field('rwqr_login','_rwqr_login',true,false)
            .'<p><label>Username or Email<br><input type="text" name="log" required></label></p>'
            .'<p><label>Password<br><input type="password" name="pwd" required></label></p>'
            .'<p><button class="rwqr-btn">Login</button></p>'
            .'</form></div>';

        // --- Register card (with dual consent) ---
        $out .= '<div class="rwqr-card"><h3>Register</h3><form method="post" class="rwqr-register-form">'
            .wp_nonce_field('rwqr_register','_rwqr_register',true,false)
            .'<p><label>Username<br><input type="text" name="user_login" required></label></p>'
            .'<p><label>Email<br><input type="email" name="user_email" required></label></p>'
            .'<p><label>Password<br><input type="password" name="user_pass" required></label></p>'
            .'<div class="rwqr-consent-row" role="group" aria-label="Consent">'
                .'<label class="rwqr-consent-item">'
                    .'<input type="checkbox" name="accept_terms" value="1" required>'
                    .'<span>I accept the <a href="'.esc_url(site_url('/terms')).'" target="_blank" rel="noopener">Terms &amp; Conditions</a></span>'
                .'</label>'
                .'<label class="rwqr-consent-item">'
                    .'<input type="checkbox" name="accept_privacy" value="1" required>'
                    .'<span>I accept the <a href="'.esc_url(site_url('/privacy-policy')).'" target="_blank" rel="noopener">Privacy Policy</a></span>'
                .'</label>'
            .'</div>'
            .'<p><button class="rwqr-btn">Register</button></p>'
            .'</form></div>';

        // Handle login
        if (isset($_POST['_rwqr_login']) && wp_verify_nonce($_POST['_rwqr_login'],'rwqr_login')){
            $creds = [
                'user_login'    => sanitize_text_field($_POST['log'] ?? ''),
                'user_password' => $_POST['pwd'] ?? '',
                'remember'      => true
            ];
            $user = wp_signon($creds, is_ssl());
            if (!is_wp_error($user)){
                wp_safe_redirect( get_permalink() ); exit;
            } else {
                $out = '<div class="rwqr-error">'.esc_html($user->get_error_message()).'</div>'.$out;
            }
        }

        // Handle register
        if (isset($_POST['_rwqr_register']) && wp_verify_nonce($_POST['_rwqr_register'],'rwqr_register')){
            if (empty($_POST['accept_terms'])){
                return '<div class="rwqr-error">You must accept the Terms &amp; Conditions.</div>'.$out;
            }
            if (empty($_POST['accept_privacy'])){
                return '<div class="rwqr-error">You must accept the Privacy Policy.</div>'.$out;
            }
            $login = sanitize_user($_POST['user_login'] ?? '');
            $email = sanitize_email($_POST['user_email'] ?? '');
            $pass  = $_POST['user_pass'] ?? '';
            $uid = wp_create_user($login, $pass, $email);
            if (!is_wp_error($uid)){
                $u = new WP_User($uid);
                $u->set_role('author');
                update_user_meta($uid,'rwqr_terms_accepted', current_time('mysql'));
                update_user_meta($uid,'rwqr_privacy_accepted', current_time('mysql'));
                return '<div class="rwqr-success">Registered successfully. Please login.</div>'.$out;
            } else {
                return '<div class="rwqr-error">'.esc_html($uid->get_error_message()).'</div>'.$out;
            }
        }

        $out .= '</div>'; // grid
        return $out;
    }

    /** Dashboard: create QR + list/manage user QR codes */
    public function sc_dashboard($atts=[]){
        if (!is_user_logged_in()){
            return '<div class="rwqr-card">Please <a href="'.esc_url(wp_login_url()).'">login</a>.</div>';
        }

        // Consent enforcement
        $terms_ok   = (bool) get_user_meta(get_current_user_id(), 'rwqr_terms_accepted', true);
        $privacy_ok = (bool) get_user_meta(get_current_user_id(), 'rwqr_privacy_accepted', true);
        if (!$terms_ok || !$privacy_ok){
            return '<div class="rwqr-card rwqr-error">You must accept our '
                .'<a href="'.esc_url(site_url('/terms')).'" target="_blank">Terms &amp; Conditions</a> and '
                .'<a href="'.esc_url(site_url('/privacy-policy')).'" target="_blank">Privacy Policy</a> to continue.</div>';
        }

        $out = '';

        /* -------------------------
         * Create QR (all types)
         * ------------------------- */
        $out .= '<div class="rwqr-card"><h3>Create QR</h3>'
            .'<form method="post" id="rwqr-create-form">'
            .wp_nonce_field('rwqr_create','_rwqr_create',true,false)
            .'<p><label>QR Name (title)<br><input type="text" name="qr_title" required></label></p>'
            .'<p><label>Alias (short link)<br><input type="text" name="qr_alias" placeholder="my-brand" required></label> '
                .'<small>Short URL will be '.esc_html(home_url('/qr/')).'<em>alias</em></small></p>'

            .'<p><label>Content Type<br><select name="qr_ctype" id="qr_ctype">'
                .'<option value="link">Link URL</option>'
                .'<option value="text">Plain Text</option>'
                .'<option value="vcard">vCard</option>'
                .'<option value="file">File URL</option>'
                .'<option value="catalogue">Catalogue (list)</option>'
                .'<option value="price">Price Card</option>'
                .'<option value="social">Social Links</option>'
                .'<option value="googlerev">Google Review (Place ID)</option>'
                .'<option value="qaform">Q/A Form</option>'
            .'</select></label></p>'

            // Link
            .'<div class="rwqr-ct rwqr-ct-link"><p><label>Link URL<br><input type="url" name="qr_link" placeholder="https://example.com"></label></p></div>'

            // Text
            .'<div class="rwqr-ct rwqr-ct-text" style="display:none"><p><label>Plain Text<br><textarea name="qr_text" rows="4"></textarea></label></p></div>'

            // vCard
            .'<div class="rwqr-ct rwqr-ct-vcard" style="display:none">'
                .'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">'
                .'<p><label>Name<br><input type="text" name="qr_v_name"></label></p>'
                .'<p><label>Organisation<br><input type="text" name="qr_v_org"></label></p>'
                .'<p><label>Title/Role<br><input type="text" name="qr_v_title"></label></p>'
                .'<p><label>Phone<br><input type="text" name="qr_v_phone"></label></p>'
                .'<p><label>Email<br><input type="email" name="qr_v_email"></label></p>'
                .'<p><label>Website<br><input type="url" name="qr_v_site"></label></p>'
                .'<p style="grid-column:1/-1"><label>Address<br><input type="text" name="qr_v_addr"></label></p>'
                .'</div>'
            .'</div>'

            // File URL
            .'<div class="rwqr-ct rwqr-ct-file" style="display:none"><p><label>File URL (PDF/Image/Doc)<br><input type="url" name="qr_file" placeholder="https://example.com/file.pdf"></label></p></div>'

            // Catalogue JSON
            .'<div class="rwqr-ct rwqr-ct-catalogue" style="display:none">'
                .'<p><label>Catalogue JSON (array of items)<br><textarea name="qr_catalogue" rows="5">[{"title":"Item 1","desc":"Description","price":"199"}]</textarea></label></p>'
                .'<small>Example: [{"title":"Item 1","desc":"Details","price":"199"}]</small>'
            .'</div>'

            // Price
            .'<div class="rwqr-ct rwqr-ct-price" style="display:none">'
                .'<p><label>Currency Symbol<br><input type="text" name="qr_price_cur" value="₹" class="small-text"></label></p>'
                .'<p><label>Amount<br><input type="text" name="qr_price_amt" value="0" class="small-text"></label></p>'
            .'</div>'

            // Social JSON
            .'<div class="rwqr-ct rwqr-ct-social" style="display:none">'
                .'<p><label>Social Links JSON<br><textarea name="qr_social" rows="5">{"facebook":"","instagram":"","twitter":"","youtube":"","website":""}</textarea></label></p>'
            .'</div>'

            // Google Review
            .'<div class="rwqr-ct rwqr-ct-googlerev" style="display:none"><p><label>Google Place ID<br><input type="text" name="qr_placeid" placeholder="ChIJ..."></label></p></div>'

            // Q/A form
            .'<div class="rwqr-ct rwqr-ct-qaform" style="display:none"><p><label>Question<br><input type="text" name="qr_question" value="What is your feedback?"></label></p></div>'

            // Design + limits
            .'<hr><p><label>Top text<br><input type="text" name="qr_top"></label></p>'
            .'<p><label>Bottom text<br><input type="text" name="qr_bottom"></label></p>'
            .'<p><label>Type<br><select name="qr_type"><option value="dynamic">Dynamic (trackable)</option><option value="static">Static (non-trackable)</option></select></label></p>'
            .'<p><label>Scan limit (0=unlimited)<br><input type="number" name="qr_limit" value="0" min="0"></label></p>'
            .'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">'
                .'<p><label>Start<br><input type="date" name="qr_start"></label></p>'
                .'<p><label>End<br><input type="date" name="qr_end"></label></p>'
            .'</div>'
            .'<p><button class="rwqr-btn">Create</button></p>'
            .'</form></div>';

        // Small toggler JS (scoped)
        $out .= '<script>(function(){var s=document.getElementById("qr_ctype");if(!s)return;function sh(v){document.querySelectorAll(".rwqr-ct").forEach(function(e){e.style.display="none"});var el=document.querySelector(".rwqr-ct-"+v);if(el)el.style.display=""}sh(s.value);s.addEventListener("change",function(){sh(this.value)})})();</script>';

        // Help link
        $out .= '<p style="margin:8px 0 16px;font-size:13px;">Need help? '
            .'<a href="'.esc_url(site_url('/how-to-use-qr-portal')).'" target="_blank" rel="noopener">View the guide</a></p>';

        // Handle CREATE
        if (isset($_POST['_rwqr_create']) && wp_verify_nonce($_POST['_rwqr_create'],'rwqr_create')){
            $title = sanitize_text_field($_POST['qr_title'] ?? '');
            $alias = sanitize_title($_POST['qr_alias'] ?? '');
            $ctype = sanitize_key($_POST['qr_ctype'] ?? 'link');
            if (!isset(self::$CONTENT_TYPES[$ctype])) $ctype = 'link';
            $type  = ($_POST['qr_type'] ?? 'dynamic')==='static' ? 'static' : 'dynamic';
            $top   = sanitize_text_field($_POST['qr_top'] ?? '');
            $bottom= sanitize_text_field($_POST['qr_bottom'] ?? '');
            $limit = max(0, intval($_POST['qr_limit'] ?? 0));
            $start = preg_replace('/[^0-9\-]/','', $_POST['qr_start'] ?? '');
            $end   = preg_replace('/[^0-9\-]/','', $_POST['qr_end'] ?? '');

            // Insert post
            $pid = wp_insert_post([
                'post_type'=> self::CPT,
                'post_status'=>'publish',
                'post_title'=> $title,
                'post_author'=> get_current_user_id(),
            ]);
            if ($pid){
                // common
                update_post_meta($pid,'_alias',$alias);
                update_post_meta($pid,'_content_type',$ctype);
                update_post_meta($pid,'_top',$top);
                update_post_meta($pid,'_bottom',$bottom);
                update_post_meta($pid,'_scan_limit',$limit);
                update_post_meta($pid,'_start',$start);
                update_post_meta($pid,'_end',$end);
                update_post_meta($pid,'_active','1');
                update_post_meta($pid,'_scan_count',0);

                // by type
                switch ($ctype){
                    case 'link':
                        $link = esc_url_raw($_POST['qr_link'] ?? '');
                        update_post_meta($pid,'_link_url',$link);
                        update_post_meta($pid,'_target',$link);
                        break;
                    case 'text':
                        $text = wp_kses_post($_POST['qr_text'] ?? '');
                        update_post_meta($pid,'_text_content',$text);
                        update_post_meta($pid,'_target', home_url('/qrview/'.$pid));
                        break;
                    case 'vcard':
                        $v = [
                            'name'   => sanitize_text_field($_POST['qr_v_name'] ?? ''),
                            'org'    => sanitize_text_field($_POST['qr_v_org'] ?? ''),
                            'title'  => sanitize_text_field($_POST['qr_v_title'] ?? ''),
                            'phone'  => sanitize_text_field($_POST['qr_v_phone'] ?? ''),
                            'email'  => sanitize_email($_POST['qr_v_email'] ?? ''),
                            'website'=> esc_url_raw($_POST['qr_v_site'] ?? ''),
                            'addr'   => sanitize_text_field($_POST['qr_v_addr'] ?? ''),
                        ];
                        foreach($v as $k=>$val){ update_post_meta($pid, '_vcard_'.$k, $val); }
                        update_post_meta($pid,'_target', home_url('/qrview/'.$pid));
                        break;
                    case 'file':
                        $file = esc_url_raw($_POST['qr_file'] ?? '');
                        update_post_meta($pid,'_file_url',$file);
                        update_post_meta($pid,'_target',$file);
                        break;
                    case 'catalogue':
                        $json = wp_unslash($_POST['qr_catalogue'] ?? '[]');
                        update_post_meta($pid,'_catalogue_json',$json);
                        update_post_meta($pid,'_target', home_url('/qrview/'.$pid));
                        break;
                    case 'price':
                        $cur = sanitize_text_field($_POST['qr_price_cur'] ?? '₹');
                        $amt = sanitize_text_field($_POST['qr_price_amt'] ?? '0');
                        update_post_meta($pid,'_price_currency',$cur);
                        update_post_meta($pid,'_price_amount',$amt);
                        update_post_meta($pid,'_target', home_url('/qrview/'.$pid));
                        break;
                    case 'social':
                        $sj = wp_unslash($_POST['qr_social'] ?? '{}');
                        update_post_meta($pid,'_social_json',$sj);
                        update_post_meta($pid,'_target', home_url('/qrview/'.$pid));
                        break;
                    case 'googlerev':
                        $pidg = sanitize_text_field($_POST['qr_placeid'] ?? '');
                        update_post_meta($pid,'_goog_place_id',$pidg);
                        $url = $pidg ? 'https://search.google.com/local/writereview?placeid='.rawurlencode($pidg) : '';
                        update_post_meta($pid,'_target', $url ?: home_url('/qrview/'.$pid));
                        break;
                    case 'qaform':
                        $q = sanitize_text_field($_POST['qr_question'] ?? 'What is your feedback?');
                        update_post_meta($pid,'_qa_question',$q);
                        update_post_meta($pid,'_target', home_url('/qrform/'.$pid));
                        break;
                }

                $out = '<div class="rwqr-success">QR created.</div>'.$out;
            } else {
                $out = '<div class="rwqr-error">Failed to create QR.</div>'.$out;
            }
        }

        /* -------------------------
         * List user QRs (with actions)
         * ------------------------- */
        $q = new WP_Query([
            'post_type'=> self::CPT,
            'author'   => get_current_user_id(),
            'post_status'=>'publish',
            'posts_per_page'=>50,
            'orderby'=>'date','order'=>'DESC'
        ]);

        $out .= '<div class="rwqr-card"><h3>Your QR Codes</h3>';
        if ($q->have_posts()){
            $out .= '<div class="rwqr-list">';
            while ($q->have_posts()){ $q->the_post();
                $id     = get_the_ID();
                $alias  = get_post_meta($id,'_alias',true);
                $short  = home_url('/qr/'.$alias);
                $target = get_post_meta($id,'_target',true);
                $ctype  = get_post_meta($id,'_content_type',true) ?: 'link';
                $active = get_post_meta($id,'_active',true)==='1';
                $scans  = intval(get_post_meta($id,'_scan_count',true) ?: 0);

                // Share buttons
                $wh_text = rawurlencode('Scan this QR: '.$short);
                $subject = 'Your QR: '.get_the_title($id);
                $body    = "Scan this QR: ".$short."\r\n\r\n(If the button doesn’t open your mail app, copy this link.)";
                $su = rawurlencode($subject); $bo = rawurlencode($body);
                $s = get_option(self::OPTION_SETTINGS, []);
                $handler = $s['email_handler'] ?? 'mailto';
                switch ($handler){
                    case 'gmail':   $mailto = 'https://mail.google.com/mail/?view=cm&fs=1&tf=1&to=&su='.$su.'&body='.$bo; break;
                    case 'outlook': $mailto = 'https://outlook.live.com/mail/0/deeplink/compose?to=&subject='.$su.'&body='.$bo; break;
                    case 'yahoo':   $mailto = 'https://compose.mail.yahoo.com/?to=&subject='.$su.'&body='.$bo; break;
                    default:        $mailto = 'mailto:?subject='.$su.'&body='.$bo;
                }

                $qr_img = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='.rawurlencode($short);
                $badge = $active ? '' : '<span class="rwqr-badge-paused">PAUSED</span>';

                $out .= '<div class="rwqr-item">'
                    .'<div class="rwqr-item-main">'
                        .'<img class="rwqr-thumb" src="'.esc_url($qr_img).'" alt="QR">'
                        .'<div class="rwqr-meta">'
                            .'<div class="rwqr-title">'.esc_html(get_the_title()).' '.$badge.'</div>'
                            .'<div class="rwqr-line"><strong>Short:</strong> <a href="'.esc_url($short).'" target="_blank">'.esc_html($short).'</a></div>'
                            .'<div class="rwqr-line"><strong>Target:</strong> '.($target?'<a href="'.esc_url($target).'" target="_blank">'.esc_html($target).'</a>':'-').'</div>'
                            .'<div class="rwqr-line"><strong>Type:</strong> '.esc_html(self::$CONTENT_TYPES[$ctype] ?? ucfirst($ctype)).'</div>'
                            .'<div class="rwqr-line"><strong>Scans:</strong> '.$scans.'</div>'
                        .'</div>'
                    .'</div>'
                    .'<div class="rwqr-actions">'
                        .'<a class="rwqr-btn" target="_blank" rel="noopener" href="https://api.whatsapp.com/send?text='.$wh_text.'">WhatsApp</a> '
                        .'<button type="button" class="rwqr-btn rwqr-mailto" data-mailto="'.esc_attr($mailto).'" onclick="return window.rwqrOpenMail && window.rwqrOpenMail(this);">Email</button> '
                        .'<a class="rwqr-btn" href="'.esc_url(get_edit_post_link($id)).'">Edit</a> '
                        .($active
                            ? '<a class="rwqr-btn rwqr-muted" href="'.esc_url( wp_nonce_url(add_query_arg(['rwqr_user_toggle'=>$id]), 'rwqr_user_toggle_'.$id) ).'">Pause</a> '
                            : '<a class="rwqr-btn" href="'.esc_url( wp_nonce_url(add_query_arg(['rwqr_user_toggle'=>$id]), 'rwqr_user_toggle_'.$id) ).'">Start</a> ')
                        .'<a class="rwqr-btn rwqr-danger" href="'.esc_url( wp_nonce_url(add_query_arg(['rwqr_user_delete'=>$id]), 'rwqr_user_delete_'.$id) ).'" onclick="return confirm(\'Delete this QR?\')">Delete</a> '
                    .'</div>'
                .'</div>';
            }
            wp_reset_postdata();
            $out .= '</div>';
        } else {
            $out .= '<p>No QR codes yet.</p>';
        }
        $out .= '</div>';

        // User actions (toggle/delete)
        if (isset($_GET['rwqr_user_toggle'])){
            $id = intval($_GET['rwqr_user_toggle']);
            if (current_user_can('edit_post',$id) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'rwqr_user_toggle_'.$id)){
                $cur = get_post_meta($id,'_active',true)==='1' ? '1':'0';
                update_post_meta($id,'_active', $cur==='1' ? '0' : '1');
                wp_safe_redirect( remove_query_arg(['rwqr_user_toggle','_wpnonce']) );
                exit;
            } else {
                $out = '<div class="rwqr-error">No permission to toggle.</div>'.$out;
            }
        }
        if (isset($_GET['rwqr_user_delete'])){
            $id = intval($_GET['rwqr_user_delete']);
            if (current_user_can('delete_post',$id) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'rwqr_user_delete_'.$id)){
                wp_trash_post($id);
                wp_safe_redirect( remove_query_arg(['rwqr_user_delete','_wpnonce']) );
                exit;
            } else {
                $out = '<div class="rwqr-error">No permission to delete.</div>'.$out;
            }
        }

        return $out;
    }
    // --- end of class methods above ---
} // end class RightWin_QR_Portal

// Boot the plugin
new RightWin_QR_Portal();
