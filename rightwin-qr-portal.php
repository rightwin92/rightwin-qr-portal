<?php
/*
Plugin Name: RightWin QR Portal
Description: QR code portal with dynamic redirects, analytics, Elementor-safe shortcodes, quick-edit dashboard, admin user controls (pause/resume/delete), and settings.
Version: 1.4.0
Author: RIGHT WIN MEDIAS
Text Domain: rightwin-qr-portal
*/

if (!defined('ABSPATH')) exit;

if (!class_exists('RightWin_QR_Portal')) :

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
        add_action('template_redirect', [$this, 'handle_view']); // landing for text-like dynamic

        /* ===== Admin ===== */
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_rwqr_toggle', [$this, 'admin_toggle_qr']);
        add_action('admin_post_rwqr_delete', [$this, 'admin_delete_qr']);

        // Admin user actions
        add_action('admin_post_rwqr_user_toggle', [$this, 'admin_user_toggle']);
        add_action('admin_post_rwqr_user_delete', [$this, 'admin_user_delete']);

        /* ===== Editing / Meta ===== */
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::CPT, [$this, 'save_meta'], 10, 2);

        /* ===== Shortcodes ===== */
        add_shortcode('rwqr_portal',   [$this, 'sc_portal']);    // login/register/forgot
        add_shortcode('rwqr_wizard',   [$this, 'sc_wizard']);    // create wizard
        add_shortcode('rwqr_dashboard',[$this, 'sc_dashboard']); // user dashboard (with quick edit)

        /* ===== Assets & Footer ===== */
        add_action('wp_enqueue_scripts',   [$this, 'enqueue']);
        add_action('admin_enqueue_scripts',[$this, 'enqueue_admin']);
        add_action('wp_footer',            [$this, 'footer_disclaimer']);
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

        // Ensure Authors can edit/upload
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
            'labels' => [ 'name' => 'QR Codes', 'singular_name' => 'QR Code' ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_admin_bar' => true,
            'menu_icon' => 'dashicons-qrcode',
            'supports' => ['title', 'thumbnail', 'author'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function add_rewrite() {
        add_rewrite_tag('%rwqr_alias%', '([^&]+)');
        add_rewrite_rule('^r/([^/]+)/?', 'index.php?rwqr_alias=$matches[1]', 'top');
    }

    public function query_vars($vars) {
        $vars[] = 'rwqr_alias';
        $vars[] = 'rwqr_pdf';
        $vars[] = 'rwqr_view';
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
        if (preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url)) return $url;
        if (strpos($url, '//') === 0) return 'https:' . $url;
        return 'https://' . $url;
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

    /* ================= Meta Boxes =================
