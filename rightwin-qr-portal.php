<?php
/**
 * Plugin Name: RightWin QR Portal
 * Description: QR portal with dynamic aliases, analytics, consent (Terms + Privacy), content types (Link, Text, vCard, File URL, Catalogue, Price, Social, Google Review PlaceID, Q/A Form), user dashboard & admin controls.
 * Version: 1.6.2
 * Author: RightWin Medias
 * Requires PHP: 8.0
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

final class RightWin_QR_Portal {
    const VERSION = '1.6.2';
    const CPT = 'rwqr_code';
    const OPTION_SETTINGS = 'rwqr_settings';
    const OPTION_PAGES = 'rwqr_pages';
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
        add_action('admin_notices', [$this,'admin_notices']);

        // Shortcodes
        add_shortcode('rwqr_portal', [$this,'sc_portal']);      // login/register + router
        add_shortcode('rwqr_dashboard', [$this,'sc_dashboard']); // user dashboard

        // Footer consent line
        add_action('wp_footer', [$this,'footer_disclaimer']);

        // Activation / Deactivation
        register_activation_hook(__FILE__, [__CLASS__,'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__,'deactivate']);
    }
    /** Activation: create scans table + default options + create pages + flush rewrites */
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

        // Auto-create essential pages
        $pages = get_option(self::OPTION_PAGES, []);
        $pages = is_array($pages) ? $pages : [];

        if (empty($pages['portal']) || !get_post($pages['portal'])){
            $portal_id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'QR Portal',
                'post_name'  => 'qr-portal',
                'post_content' => '[rwqr_portal]'
            ]);
            if (!is_wp_error($portal_id)) $pages['portal'] = $portal_id;
        }
        if (empty($pages['terms']) || !get_post($pages['terms'])){
            $terms_id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'Terms & Conditions',
                'post_name'  => 'terms',
                'post_content' => "Replace this with your Terms & Conditions."
            ]);
            if (!is_wp_error($terms_id)) $pages['terms'] = $terms_id;
        }
        if (empty($pages['privacy']) || !get_post($pages['privacy'])){
            $privacy_id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'Privacy Policy',
                'post_name'  => 'privacy-policy',
                'post_content' => "Replace this with your Privacy Policy."
            ]);
            if (!is_wp_error($privacy_id)) $pages['privacy'] = $privacy_id;
        }
        if (empty($pages['guide']) || !get_post($pages['guide'])){
            $guide_id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'How to use QR Portal',
                'post_name'  => 'how-to-use-qr-portal',
                'post_content' => "1) Go to the QR Portal page.\n2) Register and accept Terms & Privacy.\n3) Create QR codes from the dashboard."
            ]);
            if (!is_wp_error($guide_id)) $pages['guide'] = $guide_id;
        }
        update_option(self::OPTION_PAGES, $pages);

        self::add_rewrite_rules_static();
        flush_rewrite_rules();
    }
    public static function deactivate(){ flush_rewrite_rules(); }

    /** Admin notice after activation */
    public function admin_notices(){
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'dashboard') return;
        $pages = get_option(self::OPTION_PAGES, []);
        if (!empty($pages['portal'])){
            $url = get_permalink($pages['portal']);
            echo '<div class="notice notice-success is-dismissible"><p>'
                .'RightWin QR Portal is ready. Visit <a href="'.esc_url($url).'" target="_blank">QR Portal</a>. '
                .'If short links do not work, open <a href="'.esc_url(admin_url('options-permalink.php')).'">Settings → Permalinks</a> and click Save.'
                .'</p></div>';
        }
    }

    /** Settings page */
    public function admin_menu(){
        add_options_page('RightWin QR', 'RightWin QR', 'manage_options', 'rwqr-settings', [$this,'admin_settings']);
    }
    public function register_settings(){ register_setting('rwqr-group', self::OPTION_SETTINGS); }
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

        $s = get_option(self::OPTION_SETTINGS);
        ?>
        <div class="wrap">
            <h1>RightWin QR — Settings</h1>
            <form method="post">
                <?php wp_nonce_field('rwqr_save_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="max_logo_mb">Max logo upload (MB)</label></th>
                        <td><input type="number" step="0.1" min="0" id="max_logo_mb" name="max_logo_mb" value="<?php echo esc_attr($s['max_logo_mb']); ?>"></td></tr>
                    <tr><th><label for="contact_html">Powered by / Contact (HTML)</label></th>
                        <td><textarea id="contact_html" name="contact_html" rows="3" class="large-text"><?php echo esc_textarea($s['contact_html']); ?></textarea></td></tr>
                    <tr><th><label for="email_handler">Email share opens in</label></th>
                        <td><select id="email_handler" name="email_handler">
                            <option value="mailto"  <?php selected($s['email_handler'],'mailto');  ?>>System mail app</option>
                            <option value="gmail"   <?php selected($s['email_handler'],'gmail');   ?>>Gmail (web)</option>
                            <option value="outlook" <?php selected($s['email_handler'],'outlook'); ?>>Outlook.com</option>
                            <option value="yahoo"   <?php selected($s['email_handler'],'yahoo');   ?>>Yahoo Mail</option>
                        </select></td></tr>
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
            .'<a href="'.$terms.'" target="_blank">Terms & Conditions</a> and '
            .'<a href="'.$privacy.'" target="_blank">Privacy Policy</a>.</div>';
    }

    /** Rewrites */
    public function add_rewrite_rules(){ self::add_rewrite_rules_static(); }
    private static function add_rewrite_rules_static(){
        add_rewrite_rule('^qr/([^/]+)/?$',     'index.php?'.self::QV_ALIAS.'=$matches[1]', 'top');
        add_rewrite_rule('^qrview/([0-9]+)/?$', 'index.php?'.self::QV_VIEW.'=$matches[1]', 'top');
        add_rewrite_rule('^qrform/([0-9]+)/?$', 'index.php?'.self::QV_FORM.'=$matches[1]', 'top');
    }
    public function register_query_vars($vars){
        $vars[] = self::QV_ALIAS;
        $vars[] = self::QV_VIEW;
        $vars[] = self::QV_FORM;
        return $vars;
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

        // File
        $file = get_post_meta($post->ID, '_file_url', true) ?: '';

        // Catalogue JSON
        $catalogue = get_post_meta($post->ID, '_catalogue_json', true) ?: '[{"title":"Item 1","desc":"Description","price":"199"}]';

        // Price
        $price_curr = get_post_meta($post->ID, '_price_currency', true) ?: '₹';
        $price_amt  = get_post_meta($post->ID, '_price_amount', true) ?: '0';

        // Social
        $social = get_post_meta($post->ID, '_social_json', true) ?: '{"facebook":"","instagram":"","twitter":"","youtube":"","website":""}';

        // Google Review
        $place_id = get_post_meta($post->ID, '_goog_place_id', true) ?: '';

        // Q/A Form
        $qa_question = get_post_meta($post->ID, '_qa_question', true) ?: 'What is your feedback?';

        // Alias + target
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

        <hr>
        <p><label>Alias<br><input type="text" name="rwqr_alias" value="<?php echo esc_attr($alias); ?>" class="widefat"></label></p>
        <p><label>Target (auto)<br><input type="text" value="<?php echo esc_attr($target); ?>" class="widefat" readonly></label></p>

        <script>
        (function(){
          var sel = document.getElementById('rwqr_content_type');
          function show(type){
            document.querySelectorAll('.rwqr-ct').forEach(el=>el.style.display='none');
            var box = document.querySelector('.rwqr-ct-' + type);
            if(box) box.style.display='';
          }
          show(sel.value);
          sel.addEventListener('change',()=>show(sel.value));
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
            </select></label></p>
        <p><label>Scan limit (0=unlimited)<br><input type="number" name="rwqr_scan_limit" value="<?php echo esc_attr($limit); ?>"></label></p>
        <p><label>Start<br><input type="date" name="rwqr_start" value="<?php echo esc_attr($start); ?>"></label></p>
        <p><label>End<br><input type="date" name="rwqr_end" value="<?php echo esc_attr($end); ?>"></label></p>
        <p><strong>Total scans:</strong> <?php echo esc_html($scans); ?></p>
        <?php
    }

    /** Design box */
    public function mb_design($post){
        $top = get_post_meta($post->ID, '_top', true) ?: '';
        $bottom = get_post_meta($post->ID, '_bottom', true) ?: '';
        ?>
        <p><label>Top text<br><input type="text" name="rwqr_top" value="<?php echo esc_attr($top); ?>" class="widefat"></label></p>
        <p><label>Bottom text<br><input type="text" name="rwqr_bottom" value="<?php echo esc_attr($bottom); ?>" class="widefat"></label></p>
        <?php
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

        // basic guards
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

        @header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($title); ?> — QR</title>
            <style>
                body{font-family:sans-serif;margin:0;background:#f9fafb;color:#111}
                .wrap{max-width:760px;margin:24px auto;padding:0 16px}
                .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px}
                h1{font-size:22px;margin:0 0 8px}
                .muted{color:#6b7280;font-size:13px;margin:2px 0 14px}
                .btn{display:inline-block;padding:10px 14px;border-radius:10px;background:#111;color:#fff;text-decoration:none}
                .list{display:flex;flex-direction:column;gap:8px}
                .item{border:1px solid #e5e7eb;border-radius:10px;padding:10px}
                .price{font-weight:700}
                .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
                .footer{margin-top:20px;color:#6b7280;font-size:12px}
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

                        echo '<div class="grid">';
                        echo '<div><strong>Name:</strong> '.esc_html($name).'<br><strong>Org:</strong> '.esc_html($org).'<br><strong>Role:</strong> '.esc_html($role).'</div>';
                        echo '<div><strong>Phone:</strong> '.esc_html($phone).'<br><strong>Email:</strong> <a href="mailto:'.esc_attr($email).'">'.esc_html($email).'</a><br><strong>Website:</strong> <a href="'.esc_url($site).'">'.esc_html($site).'</a></div>';
                        echo '</div>';
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
                            echo '<p><a class="btn" href="'.esc_url($url).'" target="_blank">'.ucfirst($k).'</a></p>';
                        }
                        break;

                    case 'googlerev':
                        $pid = get_post_meta($id,'_goog_place_id',true) ?: '';
                        if($pid){
                            $url = 'https://search.google.com/local/writereview?placeid='.rawurlencode($pid);
                            echo '<p><a class="btn" href="'.esc_url($url).'" target="_blank">Write a Google Review</a></p>';
                        }
                        break;

                    default:
                        $target = get_post_meta($id,'_target',true);
                        if($target){
                            echo '<p><a class="btn" href="'.esc_url($target).'" target="_blank">Open</a></p>';
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
        if (!$active || ($start && $today < $start) || ($end && $today > $end) || ($limit>0 && $count >= $limit)){
            status_header(403); wp_die('This QR is not active.');
        }

        $question = get_post_meta($id,'_qa_question',true) ?: 'What is your feedback?';
        $success=false; $msg='';

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
        </head>
        <body>
        <div style="max-width:720px;margin:24px auto;padding:16px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">
            <h1><?php echo esc_html(get_the_title($id)); ?></h1>
            <p><?php echo esc_html($question); ?></p>

            <?php if ($msg): ?>
                <div style="padding:10px;border-radius:8px;<?php echo $success?'background:#dcfce7;color:#166534':'background:#fee2e2;color:#991b1b'; ?>">
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

        // Log scan
        $this->log_scan($id, sanitize_title($alias));
        update_post_meta($id,'_scan_count', $count + 1);

        // Fallback
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
            echo esc_html(get_post_meta($post_id,'_alias',true));
        } elseif ($col==='target'){
            $t = get_post_meta($post_id,'_target',true);
            echo $t ? '<a href="'.esc_url($t).'" target="_blank">'.esc_html($t).'</a>' : '-';
        } elseif ($col==='scans'){
            echo intval(get_post_meta($post_id,'_scan_count',true) ?: 0);
        } elseif ($col==='status'){
            $active = get_post_meta($post_id,'_active',true)==='1';
            echo $active ? '<span style="color:#16a34a;font-weight:600">Active</span>'
                         : '<span style="color:#ef4444;font-weight:600">Paused</span>';
        }
    }

    public function admin_row_toggle_action($actions, $post){
        if ($post->post_type !== self::CPT) return $actions;
        $url = wp_nonce_url(add_query_arg(['rwqr_toggle'=>$post->ID], admin_url('edit.php?post_type='.self::CPT)), 'rwqr_toggle_'.$post->ID);
        $actions['rwqr_toggle'] = '<a href="'.esc_url($url).'">Toggle Active/Pause</a>';

        // Handle toggle
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
    /* -----------------------------------------
     * Shortcodes
     * ----------------------------------------- */

    /** Portal shortcode: shows login/register or dashboard */
    public function sc_portal($atts=[]){
        if (is_user_logged_in()){
            return $this->sc_dashboard();
        }

        $out = '<div class="rwqr-grid">';

        // Login
        $out .= '<div class="rwqr-card"><h3>Login</h3><form method="post">'
            .wp_nonce_field('rwqr_login','_rwqr_login',true,false)
            .'<p><label>Username or Email<br><input type="text" name="log" required></label></p>'
            .'<p><label>Password<br><input type="password" name="pwd" required></label></p>'
            .'<p><button class="rwqr-btn">Login</button></p>'
            .'</form></div>';

        // Register
        $out .= '<div class="rwqr-card"><h3>Register</h3><form method="post" class="rwqr-register-form">'
            .wp_nonce_field('rwqr_register','_rwqr_register',true,false)
            .'<p><label>Username<br><input type="text" name="user_login" required></label></p>'
            .'<p><label>Email<br><input type="email" name="user_email" required></label></p>'
            .'<p><label>Password<br><input type="password" name="user_pass" required></label></p>'
            .'<label><input type="checkbox" name="accept_terms" value="1" required> Accept <a href="'.esc_url(site_url('/terms')).'" target="_blank">Terms</a></label><br>'
            .'<label><input type="checkbox" name="accept_privacy" value="1" required> Accept <a href="'.esc_url(site_url('/privacy-policy')).'" target="_blank">Privacy Policy</a></label>'
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
                wp_safe_redirect(get_permalink()); exit;
            } else {
                $out = '<div class="rwqr-error">'.esc_html($user->get_error_message()).'</div>'.$out;
            }
        }

        // Handle register
        if (isset($_POST['_rwqr_register']) && wp_verify_nonce($_POST['_rwqr_register'],'rwqr_register')){
            if (empty($_POST['accept_terms']) || empty($_POST['accept_privacy'])){
                return '<div class="rwqr-error">You must accept Terms & Privacy.</div>'.$out;
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

        return $out.'</div>';
    }

    /** Dashboard shortcode */
    public function sc_dashboard($atts=[]){
        if (!is_user_logged_in()){
            return $this->sc_portal();
        }

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
            .'<p><label>Link/Content<br><textarea name="qr_content"></textarea></label></p>'
            .'<p><button class="rwqr-btn">Create</button></p></form></div>';

        // Handle create
        if (isset($_POST['_rwqr_create']) && wp_verify_nonce($_POST['_rwqr_create'],'rwqr_create')){
            $title = sanitize_text_field($_POST['qr_title'] ?? '');
            $alias = sanitize_title($_POST['qr_alias'] ?? '');
            $ctype = sanitize_key($_POST['qr_ctype'] ?? 'link');
            $content = wp_kses_post($_POST['qr_content'] ?? '');

            $pid = wp_insert_post([
                'post_type'=> self::CPT,
                'post_status'=>'publish',
                'post_title'=> $title,
                'post_author'=> get_current_user_id(),
            ]);
            if ($pid){
                update_post_meta($pid,'_alias',$alias);
                update_post_meta($pid,'_content_type',$ctype);
                update_post_meta($pid,'_target',$content);
                update_post_meta($pid,'_active','1');
                update_post_meta($pid,'_scan_count',0);
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
            'posts_per_page'=>50
        ]);
        $out .= '<div class="rwqr-card"><h3>Your QRs</h3>';
        if ($q->have_posts()){
            while($q->have_posts()){ $q->the_post();
                $id=get_the_ID();
                $alias=get_post_meta($id,'_alias',true);
                $short=home_url('/qr/'.$alias);
                $active=get_post_meta($id,'_active',true)==='1';
                $scans=intval(get_post_meta($id,'_scan_count',true) ?: 0);

                $out .= '<div><strong>'.esc_html(get_the_title()).'</strong> — '
                    .'<a href="'.esc_url($short).'" target="_blank">'.esc_html($short).'</a> '
                    .'[Scans: '.$scans.'] '
                    .($active?'<span style="color:green">Active</span>':'<span style="color:red">Paused</span>')
                    .' <a href="'.esc_url(wp_nonce_url(add_query_arg(['rwqr_user_toggle'=>$id]),'rwqr_user_toggle_'.$id)).'">'.($active?'Pause':'Start').'</a>'
                    .' <a href="'.esc_url(wp_nonce_url(add_query_arg(['rwqr_user_delete'=>$id]),'rwqr_user_delete_'.$id)).'" onclick="return confirm(\'Delete?\')">Delete</a>'
                    .'</div>';
            }
            wp_reset_postdata();
        } else {
            $out .= '<p>No QR codes yet.</p>';
        }
        $out .= '</div>';

        // Handle toggle/delete
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
} // end class

// Boot
new RightWin_QR_Portal();
