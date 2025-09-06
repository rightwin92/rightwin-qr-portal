<?php
/*
Plugin Name: RightWin QR Portal
Description: QR code portal with dynamic redirects, analytics, Elementor-safe shortcodes, quick-edit dashboard, admin user controls (pause/resume/delete), and settings.
Version: 1.4.0
Author: RIGHT WIN MEDIAS
Text Domain: rightwin-qr-portal
*/

if (!defined('ABSPATH')) exit;

class RightWin_QR_Portal {

    const VERSION = '1.4.0';
    const CPT = 'rwqr';
    const TABLE_SCANS = 'rwqr_scans';
    const OPTION_SETTINGS = 'rwqr_settings';
    const USER_PAUSED_META = 'rwqr_paused'; // 1/0

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        /* ===== Init / Routing ===== */
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'add_rewrite']);
        add_filter('query_vars', [$this, 'query_vars']);

        add_action('template_redirect', [$this, 'handle_redirect']);
        add_action('template_redirect', [$this, 'handle_pdf']);
        add_action('template_redirect', [$this, 'handle_view']); // landing page for text-like dynamic

        /* ===== Admin ===== */
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_rwqr_toggle', [$this, 'admin_toggle_qr']);
        add_action('admin_post_rwqr_delete', [$this, 'admin_delete_qr']);

        // NEW: Admin user actions
        add_action('admin_post_rwqr_user_toggle', [$this, 'admin_user_toggle']);
        add_action('admin_post_rwqr_user_delete', [$this, 'admin_user_delete']);

        /* ===== Editing / Meta ===== */
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::CPT, [$this, 'save_meta'], 10, 2);

        /* ===== Shortcodes ===== */
        add_shortcode('rwqr_portal', [$this, 'sc_portal']);       // login/register/forgot
        add_shortcode('rwqr_wizard', [$this, 'sc_wizard']);       // create wizard
        add_shortcode('rwqr_dashboard', [$this, 'sc_dashboard']); // user dashboard (with quick edit)

        /* ===== Assets & Footer ===== */
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('wp_footer', [$this, 'footer_disclaimer']);
    }

    /* ================= Activation & DB ================= */

    public function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SCANS;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            qr_id BIGINT UNSIGNED NOT NULL,
            alias VARCHAR(191) NULL,
            scanned_at DATETIME NOT NULL,
            ip VARCHAR(45) NULL,
            ua TEXT NULL,
            referrer TEXT NULL,
            PRIMARY KEY (id),
            KEY qr_id (qr_id),
            KEY alias (alias)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $this->add_rewrite();
        flush_rewrite_rules();

        // Optional: ensure authors have basic caps
        if ($role = get_role('author')) {
            $role->add_cap('upload_files');
            $role->add_cap('edit_posts');
            $role->add_cap('edit_published_posts');
        }
    }

    public function deactivate() { flush_rewrite_rules(); }

    /* ================= CPT & Rewrite ================= */

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'QR Codes',
                'singular_name' => 'QR Code'
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_admin_bar' => true,
            'menu_icon' => 'dashicons-qrcode',
            'supports' => ['title', 'thumbnail', 'author'],
            'capability_type' => 'post',
            'map_meta_cap' => true, // allow Authors to edit their own QR posts
        ]);
    }

    public function add_rewrite() {
        // add tag for query var and pretty rule
        add_rewrite_tag('%rwqr_alias%', '([^&]+)');
        add_rewrite_rule('^r/([^/]+)/?', 'index.php?rwqr_alias=$matches[1]', 'top');
    }

    public function query_vars($vars) {
        $vars[] = 'rwqr_alias';
        $vars[] = 'rwqr_pdf';
        $vars[] = 'rwqr_view'; // landing renderer id
        return $vars;
    }

    /* ================= Shortlink Builder & URL Normalizer ================= */

    // Subdirectory-safe builder: do NOT start with a leading slash
    private function build_shortlink($alias) {
        $alias = ltrim((string)$alias, '/');
        $pretty = get_option('permalink_structure');
        if (!empty($pretty)) {
            return home_url('r/' . $alias);
        }
        return add_query_arg('rwqr_alias', $alias, home_url());
    }

    // Ensure URLs have scheme; prefix https:// when user enters bare domain
    private function normalize_url($url) {
        $url = trim((string)$url);
        if ($url === '') return '';
        if (preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url)) return $url;     // already has scheme
        if (strpos($url, '//') === 0) return 'https:' . $url;               // protocol-relative
        return 'https://' . $url;                                           // default to https
    }

    // Check if a user is paused by admin
    private function is_user_paused($user_id) {
        return intval(get_user_meta($user_id, self::USER_PAUSED_META, true)) === 1;
    }
/* ================= Redirect, PDF, View ================= */

    public function handle_redirect() {
        $alias = get_query_var('rwqr_alias');
        if (!$alias && isset($_GET['rwqr_alias'])) {
            $alias = sanitize_title($_GET['rwqr_alias']);
        }
        if (!$alias) return;

        $qr = $this->get_qr_by_alias($alias);
        if (!$qr) { status_header(404); echo '<h1>QR Not Found</h1>'; exit; }

        $m = $this->get_qr_meta($qr->ID);

        // Block if user is paused
        if ($this->is_user_paused($qr->post_author)) {
            status_header(403); echo '<h1>User Paused by Admin</h1>'; exit;
        }

        if (($m['status'] ?? 'active') !== 'active') { status_header(410); echo '<h1>QR Paused</h1>'; exit; }

        $now = current_time('timestamp');
        if (!empty($m['start_at']) && $now < strtotime($m['start_at'])) { status_header(403); echo '<h1>QR Not Started</h1>'; exit; }
        if (!empty($m['end_at']) && $now > strtotime($m['end_at'])) { status_header(410); echo '<h1>QR Ended</h1>'; exit; }

        $limit = intval($m['scan_limit'] ?? 0);
        $count = intval(get_post_meta($qr->ID, 'scan_count', true));
        if ($limit > 0 && $count >= $limit) { status_header(429); echo '<h1>Scan Limit Reached</h1>'; exit; }

        // Record scan
        $this->record_scan($qr->ID, $alias);
        update_post_meta($qr->ID, 'scan_count', $count + 1);

        $target = $m['target_url'] ?? '';
        $target = $this->normalize_url($target);

        // If landing page, tag source to avoid double-count
        if (strpos($target, 'rwqr_view=') !== false) {
            $target = add_query_arg('rwqr_src', 'alias', $target);
        }

        if (!$target) { status_header(200); echo '<h1>Dynamic QR</h1><p>No target configured.</p>'; exit; }
        wp_redirect(esc_url_raw($target), 302); exit;
    }

    public function handle_pdf() {
        $id = absint(get_query_var('rwqr_pdf'));
        if (!$id) return;

        $qr = get_post($id);
        if (!$qr || $qr->post_type !== self::CPT) { status_header(404); echo 'Not found'; exit; }

        // Block if user is paused
        if ($this->is_user_paused($qr->post_author)) {
            status_header(403); echo 'User Paused by Admin'; exit;
        }

        $thumb_id = get_post_thumbnail_id($id);
        if (!$thumb_id) { status_header(404); echo 'No image'; exit; }
        $img_path = get_attached_file($thumb_id);
        if (!file_exists($img_path)) { status_header(404); echo 'File missing'; exit; }

        if (class_exists('Imagick')) {
            try {
                $im = new \Imagick();
                $im->readImage($img_path);
                $im->setImageFormat('pdf');
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="qr-'.$id.'.pdf"');
                echo $im->getImagesBlob();
                $im->clear(); $im->destroy(); exit;
            } catch (\Throwable $e) { /* fall back to PNG */ }
        }
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="qr-'.$id.'.png"');
        readfile($img_path); exit;
    }

    // Landing renderer for text/vcard/social/price in dynamic mode
    public function handle_view() {
        $view_id = absint(get_query_var('rwqr_view'));
        if (!$view_id) return;

        $qr = get_post($view_id);
        if (!$qr || $qr->post_type !== self::CPT) { status_header(404); echo 'Not found'; exit; }

        // Block if user is paused
        if ($this->is_user_paused($qr->post_author)) {
            status_header(403); echo 'User Paused by Admin'; exit;
        }

        $m = $this->get_qr_meta($qr->ID);
        $title = esc_html(get_the_title($qr));
        $payload = (string)($m['payload'] ?? '');
        $short = ($m['alias'] ? $this->build_shortlink($m['alias']) : '');
        $is_vcard = (stripos($payload, 'BEGIN:VCARD') !== false);

        // Count scan if direct access (not via alias redirect)
        $src = isset($_GET['rwqr_src']) ? sanitize_text_field($_GET['rwqr_src']) : '';
        if ($src !== 'alias') {
            $this->record_scan($qr->ID, get_post_meta($qr->ID, 'alias', true));
            $count = intval(get_post_meta($qr->ID, 'scan_count', true));
            update_post_meta($qr->ID, 'scan_count', $count + 1);
        }

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>'. $title .'</title></head><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; padding:20px;">';
        echo '<h2>'. $title .'</h2>';

        if ($is_vcard) {
            $vcf = "data:text/x-vcard;charset=utf-8," . rawurlencode($payload);
            echo '<p><a href="'.$vcf.'" download="'.esc_attr(sanitize_title($title)).'.vcf" style="display:inline-block;border:1px solid #333;padding:8px 12px;border-radius:8px;text-decoration:none">Download Contact (.vcf)</a></p>';
        }

        if (!empty($payload)) {
            $safe = esc_html($payload);
            echo '<pre style="white-space:pre-wrap;background:#f7f7f7;padding:12px;border-radius:8px;border:1px solid #e5e7eb;">'.$safe.'</pre>';
        } else {
            echo '<p>No content.</p>';
        }

        if ($short) {
            echo '<p style="opacity:.7;font-size:12px">Short link: <a href="'.esc_url($short).'">'.esc_html($short).'</a></p>';
        }

        echo '</body></html>';
        exit;
    }

    private function record_scan($qr_id, $alias) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SCANS;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_textarea_field($_SERVER['HTTP_USER_AGENT']) : '';
        $ref = isset($_SERVER['HTTP_REFERER']) ? sanitize_textarea_field($_SERVER['HTTP_REFERER']) : '';
        $wpdb->insert($table, [
            'qr_id' => $qr_id,
            'alias' => $alias,
            'scanned_at' => current_time('mysql'),
            'ip' => $ip,
            'ua' => $ua,
            'referrer' => $ref,
        ]);
    }
/* ================= Admin UI (incl. User controls) ================= */

    public function admin_menu() {
        add_menu_page('RightWin QR', 'RightWin QR', 'manage_options', 'rwqr-admin', [$this, 'admin_page'], 'dashicons-qrcode', 56);
        add_submenu_page('rwqr-admin', 'All QR Codes', 'All QR Codes', 'manage_options', 'edit.php?post_type=' . self::CPT);
        add_submenu_page('rwqr-admin', 'Users', 'Users', 'manage_options', 'rwqr-users', [$this, 'admin_users']);
        add_submenu_page('rwqr-admin', 'Settings', 'Settings', 'manage_options', 'rwqr-settings', [$this, 'admin_settings']);
    }

    public function admin_page() {
        echo '<div class="wrap"><h1>RightWin QR — Overview</h1><p>Manage QR codes or switch to the <a href="'.esc_url(admin_url('admin.php?page=rwqr-users')).'">Users</a> tab.</p>';
        $q = new WP_Query(['post_type' => self::CPT, 'posts_per_page' => 50, 'post_status' => 'any']);
        if ($q->have_posts()) {
            echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Owner</th><th>Alias</th><th>Status</th><th>Scans</th><th>Actions</th></tr></thead><tbody>';
            while ($q->have_posts()) { $q->the_post();
                $id = get_the_ID();
                $alias = get_post_meta($id, 'alias', true);
                $status = get_post_meta($id, 'status', true) ?: 'active';
                $scans = intval(get_post_meta($id, 'scan_count', true));
                $owner = get_userdata(get_post_field('post_author', $id));
                $toggle = wp_nonce_url(admin_url('admin-post.php?action=rwqr_toggle&post='.$id), 'rwqr_toggle_'.$id);
                $delete = wp_nonce_url(admin_url('admin-post.php?action=rwqr_delete&post='.$id), 'rwqr_delete_'.$id);
                echo '<tr>';
                echo '<td><a href="'.esc_url(get_edit_post_link($id)).'" target="_blank">'.esc_html(get_the_title()).'</a></td>';
                echo '<td>'.esc_html($owner ? $owner->user_login : '—').'</td>';
                echo '<td>'.esc_html($alias ?: '—').'</td>';
                echo '<td>'.esc_html($status).'</td>';
                echo '<td>'.esc_html($scans).'</td>';
                echo '<td><a class="button" href="'.$toggle.'">Start/Pause</a> ';
                echo '<a class="button button-danger" href="'.$delete.'" onclick="return confirm(\'Delete this QR?\')">Delete</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>'; wp_reset_postdata();
        } else {
            echo '<p>No QR codes yet.</p>';
        }
        echo '</div>';
    }

    public function admin_users() {
        if (!current_user_can('manage_options')) wp_die('No permission');

        // Actions handled by admin_post_* hooks; here we list users with controls.
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 20;

        $user_query = new WP_User_Query([
            'number' => $per_page,
            'paged'  => $paged,
            'orderby'=> 'user_registered',
            'order'  => 'DESC',
            'fields' => 'all',
        ]);
        $users = $user_query->get_results();
        $total = $user_query->get_total();

        echo '<div class="wrap"><h1>RightWin QR — Users</h1>';
        echo '<p>Pause/Resume prevents a user’s QR redirects and creation. Delete will remove the WP account (irreversible).</p>';

        if ($users) {
            echo '<table class="widefat striped"><thead><tr><th>User</th><th>Email</th><th>Role</th><th>QR Count</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            foreach ($users as $u) {
                $paused = $this->is_user_paused($u->ID);
                $qr_cnt = (new WP_Query([
                    'post_type'=>self::CPT,
                    'post_status'=>'any',
                    'author'=>$u->ID,
                    'fields'=>'ids',
                    'posts_per_page'=>1
                ]))->found_posts;

                $toggle = wp_nonce_url(admin_url('admin-post.php?action=rwqr_user_toggle&user='.$u->ID), 'rwqr_user_toggle_'.$u->ID);
                $delete = wp_nonce_url(admin_url('admin-post.php?action=rwqr_user_delete&user='.$u->ID), 'rwqr_user_delete_'.$u->ID);

                echo '<tr>';
                echo '<td>'.esc_html($u->user_login).'</td>';
                echo '<td>'.esc_html($u->user_email).'</td>';
                echo '<td>'.esc_html(implode(', ', $u->roles)).'</td>';
                echo '<td>'.esc_html($qr_cnt).'</td>';
                echo '<td>'.($paused?'<span style="color:#b91c1c">Paused</span>':'<span style="color:#065f46">Active</span>').'</td>';
                echo '<td><a class="button" href="'.$toggle.'">'.($paused?'Resume User':'Pause User').'</a> ';
                echo '<a class="button button-danger" href="'.$delete.'" onclick="return confirm(\'Delete this user account? This cannot be undone.\')">Delete User</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            // Simple pager
            $pages = ceil($total / $per_page);
            if ($pages > 1) {
                echo '<p>';
                for ($i=1; $i<=$pages; $i++) {
                    $url = add_query_arg(['paged'=>$i]);
                    if ($i === $paged) echo '<strong style="margin-right:6px">'.$i.'</strong>';
                    else echo '<a style="margin-right:6px" href="'.esc_url($url).'">'.$i.'</a>';
                }
                echo '</p>';
            }

        } else {
            echo '<p>No users found.</p>';
        }
        echo '</div>';
    }

    public function admin_user_toggle() {
        if (!current_user_can('manage_options')) wp_die('No permission');
        $uid = absint($_GET['user'] ?? 0);
        check_admin_referer('rwqr_user_toggle_' . $uid);
        $paused = $this->is_user_paused($uid);
        update_user_meta($uid, self::USER_PAUSED_META, $paused ? 0 : 1);
        wp_safe_redirect(admin_url('admin.php?page=rwqr-users')); exit;
    }

    public function admin_user_delete() {
        if (!current_user_can('delete_users')) wp_die('No permission');
        $uid = absint($_GET['user'] ?? 0);
        check_admin_referer('rwqr_user_delete_' . $uid);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($uid);
        wp_safe_redirect(admin_url('admin.php?page=rwqr-users')); exit;
    }

    public function admin_settings() {
        if (isset($_POST['rwqr_settings_nonce']) && wp_verify_nonce($_POST['rwqr_settings_nonce'], 'rwqr_save_settings')) {
            $settings = [
                'max_logo_mb' => max(0, floatval($_POST['max_logo_mb'] ?? 2)),
                'contact_html' => wp_kses_post($_POST['contact_html'] ?? '')
            ];
            update_option(self::OPTION_SETTINGS, $settings);
            echo '<div class="updated"><p>Saved.</p></div>';
        }
        $s = get_option(self::OPTION_SETTINGS, ['max_logo_mb'=>2, 'contact_html'=>'Contact: +91-00000 00000 | info@rightwinmedias.com']);
        ?>
        <div class="wrap"><h1>RightWin QR — Settings</h1>
            <form method="post">
                <?php wp_nonce_field('rwqr_save_settings', 'rwqr_settings_nonce'); ?>
                <table class="form-table">
                    <tr><th><label for="max_logo_mb">Max logo upload (MB)</label></th>
                        <td><input type="number" step="0.1" min="0" id="max_logo_mb" name="max_logo_mb" value="<?php echo esc_attr($s['max_logo_mb']); ?>"></td></tr>
                    <tr><th><label for="contact_html">Powered by / Contact (HTML)</label></th>
                        <td><textarea id="contact_html" name="contact_html" rows="3" class="large-text"><?php echo esc_textarea($s['contact_html']); ?></textarea></td></tr>
                </table>
                <p><button class="button button-primary">Save Settings</button></p>
            </form>
        </div><?php
    }

/* ================= Meta Boxes ================= */

    public function add_meta_boxes() {
        add_meta_box('rwqr_meta', 'QR Settings', [$this, 'render_meta'], self::CPT, 'normal', 'default');
    }

    public function render_meta($post) {
        wp_nonce_field('rwqr_save_meta', 'rwqr_meta_nonce');
        $m = $this->get_qr_meta($post->ID);
        $title_font_px = intval(get_post_meta($post->ID,'title_font_px',true));
        if ($title_font_px <= 0) $title_font_px = 28;
        ?>
        <style>.rwqr-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}</style>
        <div class="rwqr-grid">
            <p><label>Type<br>
            <select name="rwqr_type">
                <option value="dynamic" <?php selected($m['type'],'dynamic'); ?>>Dynamic (trackable)</option>
                <option value="static" <?php selected($m['type'],'static'); ?>>Static (non-trackable)</option>
            </select></label></p>
            <p><label>Content Type<br>
            <select name="rwqr_content_type">
                <option value="link" <?php selected($m['content_type'],'link'); ?>>Link</option>
                <option value="text" <?php selected($m['content_type'],'text'); ?>>Text</option>
                <option value="vcard" <?php selected($m['content_type'],'vcard'); ?>>vCard</option>
                <option value="file" <?php selected($m['content_type'],'file'); ?>>File</option>
                <option value="catalogue" <?php selected($m['content_type'],'catalogue'); ?>>Catalogue</option>
                <option value="price" <?php selected($m['content_type'],'price'); ?>>Price</option>
                <option value="social" <?php selected($m['content_type'],'social'); ?>>Social</option>
                <option value="greview" <?php selected($m['content_type'],'greview'); ?>>Google Review</option>
            </select></label></p>
            <p><label>Alias (dynamic)<br><input type="text" name="rwqr_alias" value="<?php echo esc_attr($m['alias']); ?>"></label></p>
            <p><label>Target URL (dynamic)<br><input type="text" name="rwqr_target_url" value="<?php echo esc_attr($m['target_url']); ?>"></label></p>
            <p><label>Static Payload<br><input type="text" name="rwqr_payload" value="<?php echo esc_attr($m['payload']); ?>"></label></p>
            <p><label>Dark Color<br><input type="color" name="rwqr_dark" value="<?php echo esc_attr($m['dark']); ?>"></label></p>
            <p><label>Light Color<br><input type="color" name="rwqr_light" value="<?php echo esc_attr($m['light']); ?>"></label></p>
            <p><label>Top Title<br><input type="text" name="rwqr_title_top" value="<?php echo esc_attr($m['title_top']); ?>"></label></p>
            <p><label>Bottom Title<br><input type="text" name="rwqr_title_bottom" value="<?php echo esc_attr($m['title_bottom']); ?>"></label></p>
            <p><label>Title Font Size (px)<br><input type="number" name="rwqr_title_font_px" min="10" max="120" value="<?php echo esc_attr($title_font_px); ?>"></label></p>
            <p><label>Start At (Y-m-d H:i)<br><input type="text" name="rwqr_start_at" value="<?php echo esc_attr($m['start_at']); ?>"></label></p>
            <p><label>End At (Y-m-d H:i)<br><input type="text" name="rwqr_end_at" value="<?php echo esc_attr($m['end_at']); ?>"></label></p>
            <p><label>Scan Limit (0 = unlimited)<br><input type="number" name="rwqr_scan_limit" min="0" value="<?php echo esc_attr($m['scan_limit']); ?>"></label></p>
            <p><label>Logo (Attachment ID)<br><input type="number" name="rwqr_logo_id" min="0" value="<?php echo esc_attr($m['logo_id']); ?>"></label></p>
            <p><label>Logo Size %<br><input type="number" name="rwqr_logo_pct" min="0" max="60" value="<?php echo esc_attr($m['logo_pct']); ?>"></label></p>
            <p><label>Status<br>
            <select name="rwqr_status">
                <option value="active" <?php selected($m['status'],'active'); ?>>Active</option>
                <option value="paused" <?php selected($m['status'],'paused'); ?>>Paused</option>
            </select></label></p>
        </div>
        <p><em>Save to regenerate the QR PNG.</em></p>
        <?php
    }

    public function save_meta($post_id, $post) {
        if (!isset($_POST['rwqr_meta_nonce']) || !wp_verify_nonce($_POST['rwqr_meta_nonce'],'rwqr_save_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $fields = [
            'rwqr_type','rwqr_content_type','rwqr_alias','rwqr_payload','rwqr_target_url','rwqr_dark','rwqr_light',
            'rwqr_title_top','rwqr_title_bottom','rwqr_title_font_px',
            'rwqr_start_at','rwqr_end_at','rwqr_scan_limit','rwqr_logo_id','rwqr_logo_pct','rwqr_status'
        ];
        foreach ($fields as $name) {
            $v = $_POST[$name] ?? '';
            if ($name==='rwqr_dark' || $name==='rwqr_light') $v = sanitize_hex_color($v);
            elseif ($name==='rwqr_target_url') $v = esc_url_raw( $this->normalize_url($v) );
            elseif ($name==='rwqr_title_font_px') $v = max(10, min(120, intval($v)));
            elseif (in_array($name,['rwqr_scan_limit','rwqr_logo_id','rwqr_logo_pct'])) $v = intval($v);
            else $v = sanitize_text_field($v);
            update_post_meta($post_id, str_replace('rwqr_','',$name), $v);
        }

        $png_id = $this->generate_and_attach_png($post_id);
        if ($png_id) set_post_thumbnail($post_id, $png_id);
    }

/* ================= Assets & Elementor Mode ================= */

    public function enqueue() {
        wp_enqueue_style('rwqr-portal', plugins_url('assets/portal.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('rwqr-portal', plugins_url('assets/portal.js', __FILE__), ['jquery'], self::VERSION, true);
        wp_localize_script('rwqr-portal', 'rwqrPortal', [
            'maxLogoMB' => floatval((get_option(self::OPTION_SETTINGS)['max_logo_mb'] ?? 2)),
        ]);
    }
    public function enqueue_admin() {
        wp_enqueue_style('rwqr-portal', plugins_url('assets/portal.css', __FILE__), [], self::VERSION);
    }

    private function is_elementor_edit() {
        if (!defined('ELEMENTOR_VERSION')) return false;
        try {
            return class_exists('\Elementor\Plugin') &&
                \Elementor\Plugin::$instance->editor &&
                \Elementor\Plugin::$instance->editor->is_edit_mode();
        } catch (\Throwable $e) { return false; }
    }

/* ================= Shortcodes: Portal / Wizard ================= */

    public function sc_portal($atts, $content = '') {
        if ($this->is_elementor_edit()) {
            return '<div class="rwqr-card"><h3>Portal Preview</h3><p>Login / Register / Forgot forms render on the live page.</p></div>';
        }
        if (is_user_logged_in()) {
            return '<div class="rwqr-card"><h3>You are logged in</h3><p><a class="rwqr-btn" href="'.esc_url(get_permalink()).'?logout=1">Logout</a> <a class="rwqr-btn" href="'.esc_url(site_url('/dashboard')).'">Go to Dashboard</a></p></div>'
                . $this->handle_logout();
        }

        // Forms
        $out = '<div class="rwqr-auth">'
            .'<div class="rwqr-card"><h3>Login</h3><form method="post">'
            .wp_nonce_field('rwqr_login','_rwqr_login',true,false)
            .'<p><label>Username or Email<br><input type="text" name="log" required></label></p>'
            .'<p><label>Password<br><input type="password" name="pwd" required></label></p>'
            .'<p><button class="rwqr-btn">Login</button></p></form></div>'

            .'<div class="rwqr-card"><h3>Register</h3><form method="post">'
            .wp_nonce_field('rwqr_register','_rwqr_register',true,false)
            .'<p><label>Username<br><input type="text" name="user_login" required></label></p>'
            .'<p><label>Email<br><input type="email" name="user_email" required></label></p>'
            .'<p><label>Password<br><input type="password" name="user_pass" required></label></p>'
            .'<p><button class="rwqr-btn">Register</button></p></form></div>'

            .'<div class="rwqr-card"><h3>Forgot Password</h3><form method="post" action="'.esc_url(wp_lostpassword_url()).'">'
            .'<p><label>Email<br><input type="email" name="user_login" required></label></p>'
            .'<p><button class="rwqr-btn">Send Reset Link</button></p></form></div>'
            .'</div>';

        // Handlers
        if (isset($_POST['_rwqr_login']) && wp_verify_nonce($_POST['_rwqr_login'],'rwqr_login')) {
            $creds = ['user_login'=>sanitize_text_field($_POST['log']), 'user_password'=>$_POST['pwd'], 'remember'=>true];
            $u = wp_signon($creds, is_ssl());
            if (!is_wp_error($u)) { wp_safe_redirect(site_url('/create')); exit; }
            else $out = '<div class="rwqr-error">'.esc_html($u->get_error_message()).'</div>'.$out;
        }
        if (isset($_POST['_rwqr_register']) && wp_verify_nonce($_POST['_rwqr_register'],'rwqr_register')) {
            $uid = wp_create_user(sanitize_user($_POST['user_login']), (string)$_POST['user_pass'], sanitize_email($_POST['user_email']));
            if (!is_wp_error($uid)) {
                $user_obj = new WP_User($uid);
                $user_obj->set_role('author'); // allow edit/uploads
                $out = '<div class="rwqr-success">Registered. Please login.</div>' . $out;
            } else {
                $out = '<div class="rwqr-error">'.esc_html($uid->get_error_message()).'</div>'.$out;
            }
        }

        return $out;
    }

    private function handle_logout() {
        if (isset($_GET['logout'])) { wp_logout(); wp_safe_redirect(get_permalink()); exit; }
        return '';
    }

    public function sc_wizard($atts, $content = '') {
        if ($this->is_elementor_edit()) {
            return '<div class="rwqr-card"><h3>Create Wizard Preview</h3><p>Editor-safe placeholder.</p></div>';
        }
        if (!is_user_logged_in()) {
            return '<div class="rwqr-card"><p>Please <a href="'.esc_url(site_url('/portal')).'">login</a> first.</p></div>';
        }

        // Block if admin paused this user
        $u = wp_get_current_user();
        if ($this->is_user_paused($u->ID)) {
            return '<div class="rwqr-card rwqr-error"><strong>Your account is paused by admin.</strong><br>QR creation and redirects are disabled for your account. Please contact support.</div>';
        }

        $msg = '';
        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rwqr_wizard_nonce']) && wp_verify_nonce($_POST['rwqr_wizard_nonce'],'rwqr_wizard')) {
            $post_id = $this->handle_wizard_submit();
            if (is_wp_error($post_id)) $msg = '<div class="rwqr-error">'.esc_html($post_id->get_error_message()).'</div>';
            else $msg = '<div class="rwqr-success">QR created successfully. <a class="rwqr-btn" href="'.esc_url(site_url('/dashboard')).'">Go to dashboard</a> <a class="rwqr-btn" href="'.esc_url(site_url('/create')).'">Create another</a></div>';
        }

        ob_start(); ?>
        <div class="rwqr-card">
            <h3>Create QR Code</h3>
            <?php echo $msg; ?>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('rwqr_wizard','rwqr_wizard_nonce'); ?>
                <div class="rwqr-grid">
                    <p><label>QR Name<br><input type="text" name="qr_name" required></label></p>

                    <p><label>QR Mode<br>
                        <select name="qr_type" id="qr_mode">
                            <option value="dynamic">Dynamic (trackable)</option>
                            <option value="static">Static (non-trackable)</option>
                        </select></label>
                    </p>

                    <p><label>Content Type<br>
                        <select name="qr_content_type" id="qr_content_type">
                            <option value="link">Link</option>
                            <option value="text">Text</option>
                            <option value="vcard">vCard</option>
                            <option value="file">File</option>
                            <option value="catalogue">Catalogue</option>
                            <option value="price">Price</option>
                            <option value="social">Social Links</option>
                            <option value="greview">Google Review (Place ID)</option>
                        </select></label>
                    </p>

                    <p><label>Pattern<br>
                        <select name="qr_pattern">
                            <option value="square">Square</option>
                            <option value="dots">Dots</option>
                            <option value="rounded">Rounded</option>
                        </select></label>
                    </p>
                    <p><label>Dark Color<br><input type="color" name="qr_dark" value="#000000"></label></p>
                    <p><label>Light Color<br><input type="color" name="qr_light" value="#ffffff"></label></p>
                    <p><label>Logo (PNG/JPG)<br><input type="file" name="qr_logo" accept=".png,.jpg,.jpeg"></label></p>
                    <p><label>Logo Size % of QR width<br><input type="number" name="qr_logo_pct" min="0" max="60" value="20"></label></p>
                    <p><label>Top Title<br><input type="text" name="qr_title_top"></label></p>
                    <p><label>Bottom Title<br><input type="text" name="qr_title_bottom"></label></p>
                    <p><label>Title Font Size (px)<br><input type="number" name="qr_title_font_px" min="10" max="120" value="28"></label></p>

                    <p class="rwqr-dynamic-only"><label>Alias (for dynamic)<br><input type="text" name="qr_alias" placeholder="optional-custom-alias"></label></p>

                    <!-- Content fields -->
                    <div class="rwqr-fieldset rwqr-ct-link">
                        <p><label>Destination URL<br><input type="text" name="ct_link_url" placeholder="https://example.com or example.com"></label></p>
                    </div>

                    <div class="rwqr-fieldset rwqr-ct-text" style="display:none">
                        <p><label>Message Text<br><textarea name="ct_text" rows="4" placeholder="Your text or instructions"></textarea></label></p>
                    </div>

                    <div class="rwqr-fieldset rwqr-ct-vcard" style="display:none">
                        <p><label>Full Name<br><input type="text" name="ct_v_name" placeholder="Jane Doe"></label></p>
                        <p><label>Title<br><input type="text" name="ct_v_title" placeholder="Marketing Manager"></label></p>
                        <p><label>Organization<br><input type="text" name="ct_v_org" placeholder="Company Pvt Ltd"></label></p>
                        <p><label>Phone<br><input type="text" name="ct_v_tel" placeholder="+91 9xxxxxxxxx"></label></p>
                        <p><label>Email<br><input type="email" name="ct_v_email" placeholder="name@example.com"></label></p>
                        <p><label>Website<br><input type="text" name="ct_v_url" placeholder="https://... or domain.com"></label></p>
                    </div>

                    <div class="rwqr-fieldset rwqr-ct-file" style="display:none">
                        <p><label>File Upload (PDF/Doc/Image)<br><input type="file" name="ct_file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg"></label></p>
                    </div>

                    <div class="rwqr-fieldset rwqr-ct-catalogue" style="display:none">
                        <p><label>Catalogue URL<br><input type="text" name="ct_catalogue_url" placeholder="https://your-catalogue or catalogue.domain.com"></label></p>
                    </div>

                    <div class="rwqr-fieldset rwqr-ct-price" style="display:none">
                        <p><label>Amount<br><input type="number" step="0.01" name="ct_price_amount" placeholder="999.00"></label></p>
                        <p><label>Currency<br><input type="text" name="ct_price_currency" value="INR"></label></p>
                        <p><label>Product/Page URL (optional)<br><input type="text" name="ct_price_url" placeholder="https://buy-link or domain.com/buy"></label></p>
                    </div>

                    <div class="rwqr-fieldset rwqr-ct-social" style="display:none">
                        <p><label>Facebook<br><input type="text" name="ct_social_fb" placeholder="https://facebook.com/..."></label></p>
                        <p><label>Instagram<br><input type="text" name="ct_social_ig" placeholder="https://instagram.com/..."></label></p>
                        <p><label>YouTube<br><input type="text" name="ct_social_yt" placeholder="https://youtube.com/@..."></label></p>
                        <p><label>WhatsApp (share text)<br><input type="text" name="ct_social_wa" placeholder="Hi!"></label></p>
                        <p><label>Telegram<br><input type="text" name="ct_social_tg" placeholder="https://t.me/..."></label></p>
                    </div>

                    <div class="rwqr-fieldset rwqr-ct-greview" style="display:none">
                        <p><label>Google Place ID<br><input type="text" name="ct_g_placeid" placeholder="ChIJ..."></label></p>
                    </div>

                    <p><label>Start At (Y-m-d H:i)<br><input type="text" name="qr_start"></label></p>
                    <p><label>End At (Y-m-d H:i)<br><input type="text" name="qr_end"></label></p>
                    <p><label>Scan Limit (0 = unlimited)<br><input type="number" name="qr_limit" value="0" min="0"></label></p>
                </div>
                <p><button class="rwqr-btn">Create</button></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
/* ================= Wizard Submit ================= */

    private function handle_wizard_submit() {
        $user = wp_get_current_user();
        if (!$user || 0 === $user->ID) return new WP_Error('not_logged_in','Please login.');

        // Block if admin paused this user
        if ($this->is_user_paused($user->ID)) {
            return new WP_Error('paused','Your account is paused by admin. QR creation disabled.');
        }

        $name  = sanitize_text_field($_POST['qr_name'] ?? '');
        $type  = sanitize_text_field($_POST['qr_type'] ?? 'dynamic');
        $ct    = sanitize_text_field($_POST['qr_content_type'] ?? 'link');
        $pattern = sanitize_text_field($_POST['qr_pattern'] ?? 'square');
        $dark  = sanitize_hex_color($_POST['qr_dark'] ?? '#000000');
        $light = sanitize_hex_color($_POST['qr_light'] ?? '#ffffff');
        $logo_pct = min(60, max(0, intval($_POST['qr_logo_pct'] ?? 20)));
        $title_top = sanitize_text_field($_POST['qr_title_top'] ?? '');
        $title_bottom = sanitize_text_field($_POST['qr_title_bottom'] ?? '');
        $title_font_px = max(10, min(120, intval($_POST['qr_title_font_px'] ?? 28)));
        $alias = sanitize_title($_POST['qr_alias'] ?? '');
        $start = sanitize_text_field($_POST['qr_start'] ?? '');
        $end   = sanitize_text_field($_POST['qr_end'] ?? '');
        $limit = intval($_POST['qr_limit'] ?? 0);

        if (!$name) return new WP_Error('missing','Name is required');

        // Compute target/payload based on content type
        $target = ''; $payload = '';
        // (Switch logic omitted for brevity, same as previous version — keep from earlier chunk 4 code)

        // ... [INSERT same content-type switch code here from earlier build] ...

        // Create post
        $post_id = wp_insert_post([
            'post_type'=>self::CPT,'post_status'=>'publish','post_title'=>$name,'post_author'=>$user->ID
        ], true);
        if (is_wp_error($post_id)) return $post_id;

        if ($type==='dynamic') {
            if (empty($alias)) $alias = $this->generate_unique_alias($post_id, $name);
        } else {
            $alias = '';
        }

        // [Handle logo/file upload + save meta same as earlier build]

        // Save meta (alias, pattern, colors, titles, etc.)
        update_post_meta($post_id,'type',$type);
        update_post_meta($post_id,'content_type',$ct);
        update_post_meta($post_id,'alias',$alias);
        update_post_meta($post_id,'pattern',$pattern);
        update_post_meta($post_id,'dark',$dark);
        update_post_meta($post_id,'light',$light);
        update_post_meta($post_id,'logo_pct',$logo_pct);
        update_post_meta($post_id,'title_top',$title_top);
        update_post_meta($post_id,'title_bottom',$title_bottom);
        update_post_meta($post_id,'title_font_px',$title_font_px);
        update_post_meta($post_id,'start_at',$start);
        update_post_meta($post_id,'end_at',$end);
        update_post_meta($post_id,'scan_limit',$limit);
        update_post_meta($post_id,'status','active');
        update_post_meta($post_id,'scan_count',0);
        if ($type==='dynamic') update_post_meta($post_id,'target_url',$target);
        else update_post_meta($post_id,'payload',$payload);

        // Generate QR PNG
        $png_id = $this->generate_and_attach_png($post_id);
        if ($png_id) set_post_thumbnail($post_id, $png_id);

        return $post_id;
    }

/* ================= Dashboard (with Quick Edit) ================= */

    public function sc_dashboard($atts, $content = '') {
        if ($this->is_elementor_edit()) {
            return '<div class="rwqr-card"><h3>Dashboard Preview</h3><p>Editor-safe placeholder.</p></div>';
        }
        if (!is_user_logged_in()) return '<div class="rwqr-card"><p>Please <a href="'.esc_url(site_url('/portal')).'">login</a> first.</p></div>';

        $uid = get_current_user_id();
        if ($this->is_user_paused($uid)) {
            return '<div class="rwqr-card rwqr-error"><strong>Your account is paused by admin.</strong><br>Dashboard access limited. Please contact support.</div>';
        }

        // Quick-edit POST handler
        if ($_SERVER['REQUEST_METHOD']==='POST') { $this->handle_quick_edit(); }

        $q = new WP_Query(['post_type'=>self::CPT,'author'=>$uid,'posts_per_page'=>100,'post_status'=>['publish','draft']]);
        ob_start();
        echo '<div class="rwqr-card"><h3>Your QR Codes</h3>';
        echo '<p><a class="rwqr-btn" href="'.esc_url(site_url('/create')).'">+ Create New QR</a></p>';
        if ($q->have_posts()) {
            echo '<table class="rwqr-table"><thead><tr><th>Name</th><th>Type</th><th>Short Link</th><th>Status</th><th>Scans</th><th>Actions</th></tr></thead><tbody>';
            while ($q->have_posts()) { $q->the_post();
                $id = get_the_ID();
                $type = get_post_meta($id,'type',true);
                $alias= get_post_meta($id,'alias',true);
                $status=get_post_meta($id,'status',true) ?: 'active';
                $scans=intval(get_post_meta($id,'scan_count',true));
                $short = $alias ? $this->build_shortlink($alias) : '—';
                $thumb = get_the_post_thumbnail_url($id,'medium');

                echo '<tr><td>'.esc_html(get_the_title()).'</td>';
                echo '<td>'.esc_html(ucfirst($type)).'</td>';
                echo '<td>'.($alias?'<a href="'.esc_url($short).'" target="_blank">'.esc_html($short).'</a>':'—').'</td>';
                echo '<td>'.esc_html($status).'</td>';
                echo '<td>'.esc_html($scans).'</td><td>';

                if ($thumb) {
                    echo '<a class="rwqr-btn" href="'.esc_url($thumb).'" download>PNG</a> ';
                    echo '<a class="rwqr-btn" href="'.esc_url(add_query_arg('rwqr_pdf',$id,home_url('/'))).'">PDF</a> ';
                }
                echo '<a class="rwqr-btn" href="'.esc_url(get_edit_post_link($id)).'" target="_blank" rel="noopener">Edit</a> ';

                // Quick Edit
                echo '<a class="rwqr-btn" href="#" onclick="var f=this.nextElementSibling; f.style.display=f.style.display?\'\':\'block\'; return false;">Quick Edit</a>';
                echo '<form method="post" style="display:none; margin-top:8px;">';
                wp_nonce_field('rwqr_quickedit','rwqr_quickedit_nonce');
                echo '<input type="hidden" name="rwqr_id" value="'.intval($id).'">';
                echo '<p>Alias: <input type="text" name="rwqr_alias" value="'.esc_attr($alias).'"></p>';
                echo '<p>Status: <select name="rwqr_status"><option value="active"'.selected($status,'active',false).'>Active</option><option value="paused"'.selected($status,'paused',false).'>Paused</option></select></p>';
                echo '<p><button class="rwqr-btn">Save</button></p>';
                echo '</form>';

                echo '</td></tr>';
            }
            echo '</tbody></table>'; wp_reset_postdata();
        } else {
            echo '<p>No QR codes yet. <a class="rwqr-btn" href="'.esc_url(site_url('/create')).'">Create one</a></p>';
        }
        echo '</div>';
        return ob_get_clean();
    }

/* ================= Helpers, PNG generation, Footer ================= */
// (Use the same helper + PNG generator code from the previous Chunk 4 we built, unchanged)

}

/* ================= Bootstrap ================= */

function rwqr_instance(){ static $i; if(!$i) $i=new RightWin_QR_Portal(); return $i; }
rwqr_instance();
