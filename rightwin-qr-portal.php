<?php
/*
Plugin Name: RightWin QR Portal
Description: QR code portal with dynamic redirects, analytics, Elementor-safe shortcodes, quick-edit dashboard, admin user controls (pause/resume/delete), Q/A form, and settings.
Version: 1.4.4
Author: RIGHT WIN MEDIAS
Text Domain: rightwin-qr-portal
*/

if (!defined('ABSPATH')) exit;

/* ============================================================
   Safe-boot guard to avoid duplicate class fatals
   ============================================================ */
if (!class_exists('RightWin_QR_Portal')) :

class RightWin_QR_Portal {

    const VERSION = '1.4.4';
    const CPT = 'rwqr';
    const TABLE_SCANS = 'rwqr_scans';
    const OPTION_SETTINGS = 'rwqr_settings';
    const USER_PAUSED_META = 'rwqr_paused'; // 1/0
    const META_ADMIN_LOCKED = 'admin_locked'; // 1=paused by admin (owner cannot override)
    const CPT_QA = 'rwqr_qa';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        /* ===== Init / Routing ===== */
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'register_cpt_qa']);
        add_action('init', [$this, 'add_rewrite']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('init', [$this, 'ensure_author_caps']); // ensure caps on every load

        add_action('template_redirect', [$this, 'handle_redirect']);
        add_action('template_redirect', [$this, 'handle_pdf']);
        add_action('template_redirect', [$this, 'handle_view']); // landing for text-like dynamic

        /* ===== Admin ===== */
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_rwqr_toggle', [$this, 'admin_toggle_qr']);
        add_action('admin_post_rwqr_delete', [$this, 'admin_delete_qr']);

        // Admin user controls
        add_action('admin_post_rwqr_user_toggle', [$this, 'admin_user_toggle']);
        add_action('admin_post_rwqr_user_delete', [$this, 'admin_user_delete']);

        /* ===== Owner front-end actions ===== */
        add_action('admin_post_rwqr_owner_toggle', [$this, 'owner_toggle_qr']);
        add_action('admin_post_nopriv_rwqr_owner_toggle', [$this, 'owner_toggle_qr_nopriv']);
        add_action('admin_post_rwqr_owner_delete', [$this, 'owner_delete_qr']);
        add_action('admin_post_nopriv_rwqr_owner_delete', [$this, 'owner_owner_delete_nopriv']);

        /* ===== Editing / Meta ===== */
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::CPT, [$this, 'save_meta'], 10, 2);

        /* ===== Shortcodes ===== */
        add_shortcode('rwqr_portal', [$this, 'sc_portal']);       // login/register/forgot
        add_shortcode('rwqr_wizard', [$this, 'sc_wizard']);       // create wizard
        add_shortcode('rwqr_dashboard', [$this, 'sc_dashboard']); // user dashboard
        add_shortcode('rwqr_qa', [$this, 'sc_qa']);               // Q/A form

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

        $this->ensure_author_caps();
    }

    public function ensure_author_caps() {
        // guarantee authors can edit their published CPT items even on strict hosts
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

    public function register_cpt_qa() {
        register_post_type(self::CPT_QA, [
            'labels' => [ 'name' => 'QR Q/A', 'singular_name' => 'QR Question' ],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-editor-help',
            'supports' => ['title', 'editor', 'author'],
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

    private function build_shortlink($alias) {
        $alias = ltrim((string)$alias, '/');
        $pretty = get_option('permalink_structure');
        if (!empty($pretty)) return home_url('r/' . $alias);
        return add_query_arg('rwqr_alias', $alias, home_url());
    }

    private function normalize_url($url) {
        $url = trim((string)$url);
        if ($url === '') return '';
        if (preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url)) return $url;
        if (strpos($url, '//') === 0) return 'https:' . $url;
        return 'https://' . $url;
    }

    private function is_user_paused($user_id) {
        return intval(get_user_meta($user_id, self::USER_PAUSED_META, true)) === 1;
    }

    /* ================= Redirect, PDF, View ================= */

    public function handle_redirect() {
        $alias = get_query_var('rwqr_alias');
        if (!$alias && isset($_GET['rwqr_alias'])) $alias = sanitize_title($_GET['rwqr_alias']);
        if (!$alias) return;

        $qr = $this->get_qr_by_alias($alias);
        if (!$qr) { status_header(404); echo '<h1>QR Not Found</h1>'; exit; }

        $m = $this->get_qr_meta($qr->ID);

        // Block if user is paused
        if ($this->is_user_paused($qr->post_author)) { status_header(403); echo '<h1>User Paused by Admin</h1>'; exit; }

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

        $target = $this->normalize_url($m['target_url'] ?? '');
        if (strpos($target, 'rwqr_view=') !== false) $target = add_query_arg('rwqr_src', 'alias', $target);

        if (!$target) { status_header(200); echo '<h1>Dynamic QR</h1><p>No target configured.</p>'; exit; }
        wp_redirect(esc_url_raw($target), 302); exit;
    }

    public function handle_pdf() {
        $id = absint(get_query_var('rwqr_pdf'));
        if (!$id) return;

        $qr = get_post($id);
        if (!$qr || $qr->post_type !== self::CPT) { status_header(404); echo 'Not found'; exit; }

        if ($this->is_user_paused($qr->post_author)) { status_header(403); echo 'User Paused by Admin'; exit; }

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

    public function handle_view() {
        $view_id = absint(get_query_var('rwqr_view'));
        if (!$view_id) return;

        $qr = get_post($view_id);
        if (!$qr || $qr->post_type !== self::CPT) { status_header(404); echo 'Not found'; exit; }

        if ($this->is_user_paused($qr->post_author)) { status_header(403); echo 'User Paused by Admin'; exit; }

        $m = $this->get_qr_meta($qr->ID);
        $ct = get_post_meta($qr->ID,'content_type',true);
        $title = esc_html(get_the_title($qr));
        $payload = (string)($m['payload'] ?? '');
        $short = ($m['alias'] ? $this->build_shortlink($m['alias']) : '');
        $is_vcard = (stripos($payload, 'BEGIN:VCARD') !== false);

        // Count scan if direct access (not via alias)
        $src = isset($_GET['rwqr_src']) ? sanitize_text_field($_GET['rwqr_src']) : '';
        if ($src !== 'alias') {
            $this->record_scan($qr->ID, get_post_meta($qr->ID, 'alias', true));
            $count = intval(get_post_meta($qr->ID, 'scan_count', true));
            update_post_meta($qr->ID, 'scan_count', $count + 1);
        }

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>'.$title.'</title>';
        echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:0;padding:24px;background:#fafafa;color:#111} .card{background:#fff;border:1px solid #eee;border-radius:12px;max-width:760px;margin:0 auto;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,.04)} .btn{display:inline-block;padding:10px 14px;border-radius:8px;border:1px solid #111;text-decoration:none} .list a{display:block;margin:6px 0} .muted{opacity:.7;font-size:12px}</style>';
        echo '</head><body>';
        echo '<div class="card"><h2 style="margin-top:0">'.$title.'</h2>';

        if ($ct === 'text') {
            $safe = esc_html($payload);
            echo '<pre style="white-space:pre-wrap;background:#f7f7f7;padding:12px;border-radius:8px;border:1px solid #e5e7eb;">'.$safe.'</pre>';
        } elseif ($ct === 'vcard' && $is_vcard) {
            $vcf = "data:text/x-vcard;charset=utf-8," . rawurlencode($payload);
            echo '<p><a class="btn" href="'.$vcf.'" download="'.esc_attr(sanitize_title($title)).'.vcf">Download Contact (.vcf)</a></p>';
            echo '<pre style="white-space:pre-wrap;background:#f7f7f7;padding:12px;border-radius:8px;border:1px solid #e5e7eb;">'.esc_html($payload).'</pre>';
        } elseif ($ct === 'social') {
            echo '<div class="list">';
            $lines = preg_split('/\r\n|\r|\n/', $payload);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                // Try to split "LABEL: value"
                if (strpos($line, ':') !== false) {
                    list($label, $val) = array_map('trim', explode(':', $line, 2));
                    $url = $this->normalize_url($val);
                    echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($label).'</a>';
                } else {
                    $url = $this->normalize_url($line);
                    echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($url).'</a>';
                }
            }
            echo '</div>';
        } elseif ($ct === 'price') {
            // Expect "PRICE: 999 INR | BUY: https://..."
            $safe = esc_html($payload);
            $buy = '';
            if (preg_match('~BUY:\s*(https?://\S+)~i', $payload, $m2)) $buy = $m2[1];
            echo '<p style="font-size:18px;margin:6px 0"><strong>'.$safe.'</strong></p>';
            if ($buy) echo '<p><a class="btn" href="'.esc_url($buy).'" target="_blank" rel="noopener">Buy</a></p>';
        } else {
            // Fallback: raw payload or message
            if ($payload !== '') {
                echo '<pre style="white-space:pre-wrap;background:#f7f7f7;padding:12px;border-radius:8px;border:1px solid #e5e7eb;">'.esc_html($payload).'</pre>';
            } else {
                echo '<p>No content.</p>';
            }
        }

        if ($short) echo '<p class="muted">Short link: <a href="'.esc_url($short).'">'.esc_html($short).'</a></p>';

        echo '</div></body></html>'; exit;
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
        add_submenu_page('rwqr-admin', 'Q/A', 'Q/A', 'manage_options', 'edit.php?post_type=' . self::CPT_QA);
        add_submenu_page('rwqr-admin', 'Settings', 'Settings', 'manage_options', 'rwqr-settings', [$this, 'admin_settings']);
    }

    public function admin_page() {
        echo '<div class="wrap"><h1>RightWin QR — Overview</h1><p>Manage QR codes or switch to the <a href="'.esc_url(admin_url('admin.php?page=rwqr-users')).'">Users</a> / <a href="'.esc_url(admin_url('edit.php?post_type='.self::CPT_QA)).'">Q/A</a> tabs.</p>';
        $q = new WP_Query(['post_type' => self::CPT, 'posts_per_page' => 50, 'post_status' => 'any']);
        if ($q->have_posts()) {
            echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Owner</th><th>Alias</th><th>Status</th><th>Scans</th><th>Actions</th></tr></thead><tbody>';
            while ($q->have_posts()) { $q->the_post();
                $id = get_the_ID();
                $alias = get_post_meta($id, 'alias', true);
                $status = get_post_meta($id, 'status', true) ?: 'active';
                $admin_locked = intval(get_post_meta($id, self::META_ADMIN_LOCKED, true)) === 1;
                $scans = intval(get_post_meta($id, 'scan_count', true));
                $owner = get_userdata(get_post_field('post_author', $id));
                $toggle = wp_nonce_url(admin_url('admin-post.php?action=rwqr_toggle&post='.$id), 'rwqr_toggle_'.$id);
                $delete = wp_nonce_url(admin_url('admin-post.php?action=rwqr_delete&post='.$id), 'rwqr_delete_'.$id);
                echo '<tr>';
                echo '<td><a href="'.esc_url(get_edit_post_link($id)).'" target="_blank">'.esc_html(get_the_title()).'</a></td>';
                echo '<td>'.esc_html($owner ? $owner->user_login : '—').'</td>';
                echo '<td>'.esc_html($alias ?: '—').'</td>';
                $label = $admin_locked ? 'Paused by Admin' : ucfirst($status);
                $badge = ($status==='paused')
                    ? '<span style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:2px 8px;border-radius:12px;font-weight:600;font-size:12px;">'.$label.'</span>'
                    : '<span style="background:#dcfce7;border:1px solid #bbf7d0;color:#065f46;padding:2px 8px;border-radius:12px;font-weight:600;font-size:12px;">'.$label.'</span>';
                echo '<td>'.$badge.'</td>';
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
        $total = $user_query->get_total();
        $users = $user_query->get_results();

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
                echo '<td>'.($paused?'<span style="color:#b91c1c;font-weight:600;">Paused</span>':'<span style="color:#065f46;font-weight:600;">Active</span>').'</td>';
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

    /* ================= Owner Actions ================= */

    public function owner_toggle_qr_nopriv() {
        wp_safe_redirect(site_url('/portal')); exit;
    }

    public function owner_owner_delete_nopriv() {
        wp_safe_redirect(site_url('/portal')); exit;
    }

    public function owner_toggle_qr() {
        if (!is_user_logged_in()) { wp_safe_redirect(site_url('/portal')); exit; }
        $post_id = absint($_POST['rwqr_id'] ?? 0);
        check_admin_referer('rwqr_owner_toggle_' . $post_id);
        if (!$post_id) { wp_safe_redirect(site_url('/dashboard')); exit; }

        $owner_id = intval(get_post_field('post_author', $post_id));

        // Block toggling if admin paused the whole user account
        if ($this->is_user_paused($owner_id)) { wp_safe_redirect(add_query_arg(['notice'=>'account_paused'], site_url('/dashboard'))); exit; }

        // Block toggling if admin locked this QR
        $admin_locked = intval(get_post_meta($post_id, self::META_ADMIN_LOCKED, true)) === 1;
        if ($admin_locked) { wp_safe_redirect(add_query_arg(['notice'=>'admin_locked'], site_url('/dashboard'))); exit; }

        // Permission: allow owner OR any user with edit_post OR admins
        if (get_current_user_id() !== $owner_id && !current_user_can('edit_post', $post_id) && !current_user_can('manage_options')) {
            wp_safe_redirect(add_query_arg(['notice'=>'no_permission'], site_url('/dashboard'))); exit;
        }

        $st = get_post_meta($post_id, 'status', true) ?: 'active';
        $new = ($st === 'active') ? 'paused' : 'active';
        update_post_meta($post_id, 'status', $new);

        wp_safe_redirect(add_query_arg(['_'=>time(),'notice'=>'toggled'], site_url('/dashboard'))); exit;
    }

    public function owner_delete_qr() {
        if (!is_user_logged_in()) { wp_safe_redirect(site_url('/portal')); exit; }
        $post_id = absint($_POST['rwqr_id'] ?? 0);
        check_admin_referer('rwqr_owner_delete_' . $post_id);
        if (!$post_id) { wp_safe_redirect(site_url('/dashboard')); exit; }
        $owner_id = intval(get_post_field('post_author', $post_id));

        // Block if account paused
        if ($this->is_user_paused($owner_id)) { wp_safe_redirect(add_query_arg(['notice'=>'account_paused'], site_url('/dashboard'))); exit; }

        // Admin-locked QR cannot be deleted by owner
        $admin_locked = intval(get_post_meta($post_id, self::META_ADMIN_LOCKED, true)) === 1;
        if ($admin_locked) { wp_safe_redirect(add_query_arg(['notice'=>'admin_locked'], site_url('/dashboard'))); exit; }

        // Permission
        if (get_current_user_id() !== $owner_id && !current_user_can('delete_post', $post_id)) {
            wp_safe_redirect(add_query_arg(['notice'=>'no_permission'], site_url('/dashboard'))); exit;
        }

        wp_delete_post($post_id, true);
        wp_safe_redirect(add_query_arg(['notice'=>'deleted','_'=>time()], site_url('/dashboard'))); exit;
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

        $admin_locked = intval(get_post_meta($post->ID, self::META_ADMIN_LOCKED, true)) === 1;

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
            <p><label>Static/Dynamic Payload<br><input type="text" name="rwqr_payload" value="<?php echo esc_attr($m['payload']); ?>"></label></p>
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
            <?php if ($admin_locked): ?>
                <p><em style="color:#991b1b">This QR is locked by Admin (owner cannot change status from dashboard).</em></p>
            <?php endif; ?>
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
            else $v = is_array($v) ? '' : sanitize_text_field($v);
            update_post_meta($post_id, str_replace('rwqr_','',$name), $v);
        }

        $png_id = $this->generate_and_attach_png($post_id);
        if ($png_id) set_post_thumbnail($post_id, $png_id);
    }

    /* ================= Assets & Elementor Mode ================= */

    public function enqueue() {
        wp_enqueue_style('rwqr-portal', plugins_url('assets/portal.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('rwqr-portal', plugins_url('assets/portal.js', __FILE__), ['jquery'], self::VERSION, true);
        $settings = get_option(self::OPTION_SETTINGS, ['max_logo_mb'=>2]);
        wp_localize_script('rwqr-portal', 'rwqrPortal', [
            'maxLogoMB' => floatval($settings['max_logo_mb'] ?? 2),
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

        if (isset($_POST['_rwqr_login']) && wp_verify_nonce($_POST['_rwqr_login'],'rwqr_login')) {
            $creds = ['user_login'=>sanitize_text_field($_POST['log']), 'user_password'=>$_POST['pwd'], 'remember'=>true];
            $u = wp_signon($creds, is_ssl());
            if (!is_wp_error($u)) { wp_safe_redirect(site_url('/create')); exit; }
            else $out = '<div class="rwqr-error">'.esc_html($u->get_error_message()).'</div>'.$out;
        }
        if (isset($_POST['_rwqr_register']) && wp_verify_nonce($_POST['_rwqr_register'],'rwqr_register')) {
            $uid = wp_create_user(sanitize_user($_POST['user_login']), (string)$_POST['user_pass'], sanitize_email($_POST['user_email']));
            if (!is_wp_error($uid)) { (new WP_User($uid))->set_role('author'); $out = '<div class="rwqr-success">Registered. Please login.</div>' . $out; }
            else { $out = '<div class="rwqr-error">'.esc_html($uid->get_error_message()).'</div>'.$out; }
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
        if (!is_user_logged_in()) return '<div class="rwqr-card"><p>Please <a href="'.esc_url(site_url('/portal')).'">login</a> first.</p></div>';

        $u = wp_get_current_user();
        if ($this->is_user_paused($u->ID)) {
            return '<div class="rwqr-card rwqr-error"><strong>Your account is paused by admin.</strong><br>QR creation and redirects are disabled for your account. Please contact support.</div>';
        }

        $msg = '';
        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rwqr_wizard_nonce']) && wp_verify_nonce($_POST['rwqr_wizard_nonce'],'rwqr_wizard')) {
            $post_id = $this->handle_wizard_submit();
            $msg = is_wp_error($post_id)
                ? '<div class="rwqr-error">'.esc_html($post_id->get_error_message()).'</div>'
                : '<div class="rwqr-success">QR created successfully. <a class="rwqr-btn" href="'.esc_url(site_url('/dashboard')).'">Go to dashboard</a> <a class="rwqr-btn" href="'.esc_url(site_url('/create')).'">Create another</a></div>';
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
                        <p><label>WhatsApp (share text or wa.me link)<br><input type="text" name="ct_social_wa" placeholder="Hi! or https://wa.me/91XXXXXXXXXX"></label></p>
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
        if ($this->is_user_paused($user->ID)) return new WP_Error('paused','Your account is paused by admin.');

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

        $target = ''; $payload = '';

        switch ($ct) {
            case 'link':
                $link_in = (string)($_POST['ct_link_url'] ?? '');
                $link = esc_url_raw( $this->normalize_url($link_in) );
                if ($type==='dynamic') $target = $link; else $payload = $link;
                break;
            case 'text':
                $payload = sanitize_textarea_field($_POST['ct_text'] ?? '');
                break;
            case 'vcard':
                $v = [
                    'name'=>sanitize_text_field($_POST['ct_v_name'] ?? ''),
                    'title'=>sanitize_text_field($_POST['ct_v_title'] ?? ''),
                    'org'=>sanitize_text_field($_POST['ct_v_org'] ?? ''),
                    'tel'=>sanitize_text_field($_POST['ct_v_tel'] ?? ''),
                    'email'=>sanitize_email($_POST['ct_v_email'] ?? ''),
                    'url'=>$this->normalize_url((string)($_POST['ct_v_url'] ?? ''))
                ];
                $payload = "BEGIN:VCARD\nVERSION:3.0\nFN:{$v['name']}\nTITLE:{$v['title']}\nORG:{$v['org']}\nTEL:{$v['tel']}\nEMAIL:{$v['email']}\nURL:{$v['url']}\nEND:VCARD";
                break;
            case 'catalogue':
                $c = esc_url_raw( $this->normalize_url((string)($_POST['ct_catalogue_url'] ?? '')) );
                if ($type==='dynamic') $target = $c; else $payload = $c;
                break;
            case 'price':
                $amt = sanitize_text_field($_POST['ct_price_amount'] ?? '');
                $cur = sanitize_text_field($_POST['ct_price_currency'] ?? 'INR');
                $url = esc_url_raw( $this->normalize_url((string)($_POST['ct_price_url'] ?? '')) );
                $payload = "PRICE: {$amt} {$cur}" . ($url ? " | BUY: {$url}" : "");
                break;
            case 'social':
                $fb = trim((string)($_POST['ct_social_fb'] ?? ''));
                $ig = trim((string)($_POST['ct_social_ig'] ?? ''));
                $yt = trim((string)($_POST['ct_social_yt'] ?? ''));
                $wa = trim((string)($_POST['ct_social_wa'] ?? ''));
                $tg = trim((string)($_POST['ct_social_tg'] ?? ''));
                $lines = [];
                if ($fb !== '') $lines[] = "Facebook: ".$this->normalize_url($fb);
                if ($ig !== '') $lines[] = "Instagram: ".$this->normalize_url($ig);
                if ($yt !== '') $lines[] = "YouTube: ".$this->normalize_url($yt);
                if ($wa !== '') {
                    // allow raw text or wa.me link
                    $wa_val = (stripos($wa,'http')===0 || strpos($wa,'//')===0) ? $this->normalize_url($wa) : 'https://api.whatsapp.com/send?text='.rawurlencode($wa);
                    $lines[] = "WhatsApp: ".$wa_val;
                }
                if ($tg !== '') $lines[] = "Telegram: ".$this->normalize_url($tg);
                $payload = implode("\n", $lines);
                break;
            case 'greview':
                $pid = sanitize_text_field($_POST['ct_g_placeid'] ?? '');
                $reviewUrl = 'https://search.google.com/local/writereview?placeid=' . rawurlencode($pid);
                if ($type==='dynamic') $target = $reviewUrl; else $payload = $reviewUrl;
                break;
            case 'file':
                // handled after post creation
                break;
        }

        if ($type==='dynamic' && empty($target) && $ct!=='file' && !in_array($ct, ['text','vcard','social','price'], true))
            return new WP_Error('missing','Please provide destination for dynamic QR.');
        if ($type==='static' && empty($payload)) return new WP_Error('missing','Please provide content for static QR.');

        $post_id = wp_insert_post([
            'post_type'=>self::CPT,'post_status'=>'publish','post_title'=>$name,'post_author'=>$user->ID
        ], true);
        if (is_wp_error($post_id)) return $post_id;

        if ($type==='dynamic' && empty($alias)) $alias = $this->generate_unique_alias($post_id, $name);
        if ($type!=='dynamic') $alias = '';

        // Logo
        $logo_id = 0;
        if (!empty($_FILES['qr_logo']['name'])) {
            $settings = get_option(self::OPTION_SETTINGS, ['max_logo_mb'=>2]);
            $max_bytes = floatval($settings['max_logo_mb'] ?? 2) * 1024 * 1024;
            if ($_FILES['qr_logo']['size'] > $max_bytes) return new WP_Error('too_large','Logo exceeds max size.');
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $up = wp_handle_upload($_FILES['qr_logo'], ['test_form'=>false]);
            if (!isset($up['error'])) $logo_id = $this->create_attachment_from_upload($up, $post_id);
        }

        // File content
        if ($ct==='file') {
            if (!empty($_FILES['ct_file']['name'])) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                $up = wp_handle_upload($_FILES['ct_file'], ['test_form'=>false]);
                if (!isset($up['error'])) {
                    $att_id = $this->create_attachment_from_upload($up, $post_id);
                    $file_url = wp_get_attachment_url($att_id);
                    if ($type==='dynamic') $target = $file_url; else $payload = $file_url;
                } else return new WP_Error('missing','Please upload a file.');
            } else return new WP_Error('missing','Please upload a file.');
        }

        // Dynamic text-like -> landing AND keep payload for rendering
        if ($type === 'dynamic' && in_array($ct, ['text','vcard','social','price'], true)) {
            $target = add_query_arg('rwqr_view', $post_id, home_url('/'));
        }

        // Save meta
        update_post_meta($post_id,'type',$type);
        update_post_meta($post_id,'content_type',$ct);
        update_post_meta($post_id,'alias',$alias);
        update_post_meta($post_id,'pattern',$pattern);
        update_post_meta($post_id,'dark',$dark);
        update_post_meta($post_id,'light',$light);
        update_post_meta($post_id,'logo_id',$logo_id);
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
        // ALWAYS store payload when we have it (needed for dynamic landing rendering)
        if (!empty($payload)) update_post_meta($post_id,'payload',$payload);

        // Generate PNG
        $png_id = $this->generate_and_attach_png($post_id);
        if ($png_id) set_post_thumbnail($post_id, $png_id);

        return $post_id;
    }

    /* ================= Dashboard (Quick Edit + Start/Pause/Delete + no-cache) ================= */

    private function handle_quick_edit() {
        if (!isset($_POST['rwqr_quickedit_nonce']) || !wp_verify_nonce($_POST['rwqr_quickedit_nonce'],'rwqr_quickedit')) return;
        $id = absint($_POST['rwqr_id'] ?? 0);
        if (!$id) return;

        $owner_id = intval(get_post_field('post_author', $id));

        // Allow owner or editors/admins
        if (get_current_user_id() !== $owner_id && !current_user_can('edit_post', $id) && !current_user_can('manage_options')) return;

        // Block if account paused
        if ($this->is_user_paused($owner_id)) return;

        // Block if QR locked by admin
        $admin_locked = intval(get_post_meta($id, self::META_ADMIN_LOCKED, true)) === 1;
        if ($admin_locked) return;

        $alias  = sanitize_title($_POST['rwqr_alias'] ?? '');
        $status = ($_POST['rwqr_status'] ?? 'active') === 'paused' ? 'paused' : 'active';

        $type = get_post_meta($id, 'type', true);
        $ct   = get_post_meta($id, 'content_type', true);

        update_post_meta($id, 'alias', $alias);
        update_post_meta($id, 'status', $status);

        if ($type === 'dynamic') {
            $target_in = (string)($_POST['rwqr_target_url'] ?? '');
            $target = esc_url_raw( $this->normalize_url($target_in) );
            if (in_array($ct, ['text','vcard','social','price'], true)) {
                // dynamic text-like: target must be landing + we also accept payload edits
                $target = add_query_arg('rwqr_view', $id, home_url('/'));
                $payload = isset($_POST['rwqr_payload']) ? sanitize_textarea_field($_POST['rwqr_payload']) : '';
                if ($payload !== '') update_post_meta($id, 'payload', $payload);
            }
            update_post_meta($id, 'target_url', $target);
        } else {
            $payload = sanitize_text_field($_POST['rwqr_payload'] ?? '');
            update_post_meta($id, 'payload', $payload);
        }

        $this->generate_and_attach_png($id);
        wp_safe_redirect(add_query_arg(['_'=>time(),'notice'=>'saved'], site_url('/dashboard'))); exit;
    }

    public function sc_dashboard($atts, $content = '') {
        if ($this->is_elementor_edit()) {
            return '<div class="rwqr-card"><h3>Dashboard Preview</h3><p>Editor-safe placeholder.</p></div>';
        }
        if (!is_user_logged_in()) return '<div class="rwqr-card"><p>Please <a href="'.esc_url(site_url('/portal')).'">login</a> first.</p></div>';

        // Strong no-cache to reflect fresh scan counts and status
        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        if ($_SERVER['REQUEST_METHOD']==='POST') { $this->handle_quick_edit(); }

        $uid = get_current_user_id();
        $account_paused = $this->is_user_paused($uid);

        $q = new WP_Query(['post_type'=>self::CPT,'author'=>$uid,'posts_per_page'=>100,'post_status'=>['publish','draft']]);
        ob_start();
        echo '<div class="rwqr-card"><h3>Your QR Codes</h3>';

        $notice = isset($_GET['notice']) ? sanitize_text_field($_GET['notice']) : '';
        if ($notice === 'admin_locked') echo '<div class="rwqr-error" style="margin-bottom:10px">This QR is locked by admin and cannot be changed.</div>';
        if ($notice === 'account_paused') echo '<div class="rwqr-error" style="margin-bottom:10px">Your account is paused by admin.</div>';
        if ($notice === 'no_permission') echo '<div class="rwqr-error" style="margin-bottom:10px">No permission to modify this QR.</div>';
        if ($notice === 'toggled') echo '<div class="rwqr-success" style="margin-bottom:10px">Status updated.</div>';
        if ($notice === 'saved') echo '<div class="rwqr-success" style="margin-bottom:10px">Changes saved.</div>';
        if ($notice === 'deleted') echo '<div class="rwqr-success" style="margin-bottom:10px">QR deleted.</div>';

        if ($account_paused) {
            echo '<div class="rwqr-error" style="margin-bottom:10px"><strong>Your account is paused by admin.</strong> Redirects and actions are disabled.</div>';
        }

        echo '<p><a class="rwqr-btn'.($account_paused?' rwqr-btn-disabled':'').'" href="'.esc_url(site_url('/create')).'" '.($account_paused?'onclick="return false"':'').'>+ Create New QR</a></p>';
        if ($q->have_posts()) {
            echo '<table class="rwqr-table"><thead><tr><th>Name</th><th>Type</th><th>Short Link</th><th>Status</th><th>Scans</th><th>Actions</th></tr></thead><tbody>';
            while ($q->have_posts()) { $q->the_post();
                $id = get_the_ID();
                $type = get_post_meta($id,'type',true);
                $ct = get_post_meta($id,'content_type',true);
                $alias= get_post_meta($id,'alias',true);
                $status=get_post_meta($id,'status',true) ?: 'active';
                $admin_locked = intval(get_post_meta($id, self::META_ADMIN_LOCKED, true)) === 1;
                $scans=intval(get_post_meta($id,'scan_count',true));
                $short = $alias ? $this->build_shortlink($alias) : '—';
                $thumb = get_the_post_thumbnail_url($id,'medium');

                echo '<tr><td>'.esc_html(get_the_title()).'</td>';
                echo '<td>'.esc_html(ucfirst($type)).'</td>';
                echo '<td>'.($alias?'<a href="'.esc_url($short).'" target="_blank">'.esc_html($short).'</a>':'—').'</td>';

                $label = $admin_locked ? 'Paused by Admin' : ucfirst($status);
                $badge_class = ($status === 'paused' || $admin_locked) ? 'rwqr-status-badge rwqr-status-paused' : 'rwqr-status-badge rwqr-status-active';
                echo '<td><span class="'.$badge_class.'">'.esc_html($label).'</span></td>';

                echo '<td>'.esc_html($scans).'</td><td>';

                if ($thumb) {
                    echo '<a class="rwqr-btn" href="'.esc_url($thumb).'" download>PNG</a> ';
                    echo '<a class="rwqr-btn" href="'.esc_url(add_query_arg('rwqr_pdf',$id,home_url('/'))).'">PDF</a> ';
                    if ($alias) {
                        $sharetext = rawurlencode('Scan this QR: '.$short);
                        echo '<a class="rwqr-btn" target="_blank" href="https://api.whatsapp.com/send?text='.$sharetext.'">WhatsApp</a> ';
                        echo '<a class="rwqr-btn" href="mailto:?subject=Your QR&body='.$sharetext.'">Email</a> ';
                    }
                }
                echo '<a class="rwqr-btn'.($account_paused?' rwqr-btn-disabled':'').'" href="'.esc_url(get_edit_post_link($id)).'" target="_blank" rel="noopener" '.($account_paused?'onclick="return false"':'').'>Edit</a> ';

                // Owner Start/Pause (disabled if account paused OR admin locked)
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';
                wp_nonce_field('rwqr_owner_toggle_'.$id);
                echo '<input type="hidden" name="action" value="rwqr_owner_toggle">';
                echo '<input type="hidden" name="rwqr_id" value="'.intval($id).'">';
                $disabled = ($account_paused || $admin_locked) ? ' disabled' : '';
                $btn_class = 'rwqr-btn'.(($account_paused||$admin_locked)?' rwqr-btn-disabled':'');
                echo '<button class="'.$btn_class.'"'.$disabled.'>'.($status==='active' && !$admin_locked?'Pause':'Start').'</button>';
                echo '</form> ';

                // Owner Delete (disabled if account paused OR admin locked)
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline" onsubmit="return confirm(\'Delete this QR permanently?\')">';
                wp_nonce_field('rwqr_owner_delete_'.$id);
                echo '<input type="hidden" name="action" value="rwqr_owner_delete">';
                echo '<input type="hidden" name="rwqr_id" value="'.intval($id).'">';
                $btn_del_class = 'rwqr-btn'.(($account_paused||$admin_locked)?' rwqr-btn-disabled':'');
                echo '<button class="'.$btn_del_class.'"'.$disabled.'>Delete</button>';
                echo '</form> ';

                // Inline Quick Edit (disabled if account paused OR admin locked)
                $dis = ($account_paused || $admin_locked) ? ' disabled' : '';
                echo '<a class="rwqr-btn'.(($account_paused||$admin_locked)?' rwqr-btn-disabled':'').'" href="#" onclick="if(this.classList.contains(\'rwqr-btn-disabled\'))return false; var f=this.nextElementSibling; f.style.display=f.style.display?\'\':\'block\'; return false;">Quick Edit</a>';
                echo '<form method="post" style="display:none; margin-top:8px;">';
                wp_nonce_field('rwqr_quickedit','rwqr_quickedit_nonce');
                echo '<input type="hidden" name="rwqr_id" value="'.intval($id).'">';
                echo '<p>Alias: <input type="text" name="rwqr_alias" value="'.esc_attr($alias).'"'.$dis.'></p>';

                if ($type==='dynamic') {
                    echo '<p>Target URL: <input type="text" name="rwqr_target_url" value="'.esc_attr(get_post_meta($id,'target_url',true)).'"'.$dis.'></p>';
                    if (in_array($ct, ['text','vcard','social','price'], true)) {
                        echo '<p>Details/Payload:<br><textarea name="rwqr_payload" rows="3"'.$dis.'>'.esc_textarea(get_post_meta($id,'payload',true)).'</textarea></p>';
                    }
                } else {
                    echo '<p>Static Payload:<br><textarea name="rwqr_payload" rows="3"'.$dis.'>'.esc_textarea(get_post_meta($id,'payload',true)).'</textarea></p>';
                }

                echo '<p>Status: <select name="rwqr_status"'.$dis.'><option value="active"'.selected($status,'active',false).'>Active</option><option value="paused"'.selected($status,'paused',false).'>Paused</option></select></p>';
                echo '<p><button class="rwqr-btn"'.$dis.'>Save</button></p>';
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

    /* ================= Q/A Shortcode ================= */

    public function sc_qa($atts, $content='') {
        if ($this->is_elementor_edit()) {
            return '<div class="rwqr-card"><h3>Q/A Form Preview</h3><p>Users can send questions on the live page.</p></div>';
        }
        if (!is_user_logged_in()) return '<div class="rwqr-card"><p>Please <a href="'.esc_url(site_url('/portal')).'">login</a> to submit a question.</p></div>';

        $msg = '';
        if (isset($_POST['rwqr_qa_nonce']) && wp_verify_nonce($_POST['rwqr_qa_nonce'],'rwqr_qa')) {
            $title = sanitize_text_field($_POST['qa_title'] ?? '');
            $body  = sanitize_textarea_field($_POST['qa_body'] ?? '');
            if ($title && $body) {
                $pid = wp_insert_post([
                    'post_type'=>self::CPT_QA,
                    'post_status'=>'publish',
                    'post_title'=>$title,
                    'post_content'=>$body,
                    'post_author'=>get_current_user_id(),
                ], true);
                if (!is_wp_error($pid)) $msg = '<div class="rwqr-success">Thanks! Your question was submitted.</div>';
                else $msg = '<div class="rwqr-error">'.esc_html($pid->get_error_message()).'</div>';
            } else {
                $msg = '<div class="rwqr-error">Please fill in both the subject and message.</div>';
            }
        }

        ob_start();
        echo '<div class="rwqr-card"><h3>Ask a Question</h3>'.$msg;
        echo '<form method="post">';
        wp_nonce_field('rwqr_qa','rwqr_qa_nonce');
        echo '<p><label>Subject<br><input type="text" name="qa_title" required></label></p>';
        echo '<p><label>Message<br><textarea name="qa_body" rows="5" required></textarea></label></p>';
        echo '<p><button class="rwqr-btn">Send</button></p>';
        echo '</form></div>';
        return ob_get_clean();
    }

    /* ================= Helpers ================= */

    private function create_attachment_from_upload($upload, $post_id) {
        $filetype = wp_check_filetype($upload['file'], null);
        $attachment = [
            'post_mime_type'=>$filetype['type'],
            'post_title'=>sanitize_file_name(basename($upload['file'])),
            'post_content'=>'','post_status'=>'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment, $upload['file'], $post_id);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        return $attach_id;
    }

    private function get_qr_by_alias($alias) {
        $q = new WP_Query(['post_type'=>self::CPT,'meta_key'=>'alias','meta_value'=>$alias,'posts_per_page'=>1,'post_status'=>'publish']);
        if ($q->have_posts()) { $q->the_post(); $p = get_post(); wp_reset_postdata(); return $p; }
        return null;
    }

    private function get_qr_meta($post_id) {
        return [
            'type'=>get_post_meta($post_id,'type',true) ?: 'dynamic',
            'content_type'=>get_post_meta($post_id,'content_type',true) ?: 'link',
            'alias'=>get_post_meta($post_id,'alias',true),
            'payload'=>get_post_meta($post_id,'payload',true),
            'target_url'=>get_post_meta($post_id,'target_url',true),
            'dark'=>get_post_meta($post_id,'dark',true) ?: '#000000',
            'light'=>get_post_meta($post_id,'light',true) ?: '#ffffff',
            'title_top'=>get_post_meta($post_id,'title_top',true),
            'title_bottom'=>get_post_meta($post_id,'title_bottom',true),
            'start_at'=>get_post_meta($post_id,'start_at',true),
            'end_at'=>get_post_meta($post_id,'end_at',true),
            'scan_limit'=>get_post_meta($post_id,'scan_limit',true),
            'logo_id'=>get_post_meta($post_id,'logo_id',true),
            'logo_pct'=>get_post_meta($post_id,'logo_pct',true) ?: 20,
            'status'=>get_post_meta($post_id,'status',true) ?: 'active'
        ];
    }

    private function generate_unique_alias($post_id, $seed='qr') {
        $base = sanitize_title($seed) ?: 'qr';
        $alias = $base; $i = 2;
        while ($this->get_qr_by_alias($alias)) { $alias = $base.'-'.$i; $i++; }
        return $alias;
    }

    private function hex_to_rgb($hex) {
        $hex = ltrim($hex,'#');
        if (strlen($hex)===3) { $r=hexdec(str_repeat($hex[0],2)); $g=hexdec(str_repeat($hex[1],2)); $b=hexdec(str_repeat($hex[2],2)); }
        else { $r=hexdec(substr($hex,0,2)); $g=hexdec(substr($hex,2,2)); $b=hexdec(substr($hex,4,2)); }
        return [$r,$g,$b];
    }

    /* ================= PNG Generation (with larger, TTF titles) ================= */

    private function generate_and_attach_png($post_id) {
        $m = $this->get_qr_meta($post_id);
        $size = 800;
        $data = ($m['type']==='dynamic')
            ? ( $m['alias'] ? $this->build_shortlink($m['alias']) : '' )
            : ( $m['payload'] ?? '' );
        if (!$data) return 0;

        list($dr,$dg,$db) = $this->hex_to_rgb($m['dark']);
        list($lr,$lg,$lb) = $this->hex_to_rgb($m['light']);

        $font_px = intval(get_post_meta($post_id,'title_font_px',true));
        if ($font_px <= 0) $font_px = 28;

        $qr_url = add_query_arg([
            'size'=>$size.'x'.$size,
            'data'=>rawurlencode($data),
            'color'=>$dr.','.$dg.','.$db,
            'bgcolor'=>$lr.','.$lg.','.$lb,
            'ecc'=>'H','margin'=>'2'
        ], 'https://api.qrserver.com/v1/create-qr-code/');

        $res = wp_remote_get($qr_url, ['timeout'=>20]);
        if (is_wp_error($res)) return 0;
        $body = wp_remote_retrieve_body($res);
        if (!$body) return 0;

        $src = imagecreatefromstring($body);
        if (!$src) return 0;

        $top = trim((string)$m['title_top']); 
        $bottom = trim((string)$m['title_bottom']);

        $ttf = __DIR__ . '/assets/DejaVuSans.ttf';
        $use_ttf = (function_exists('imagettftext') && file_exists($ttf));

        $extra_h = 0; 
        if ($top)    $extra_h += max(60, $font_px + 30);
        if ($bottom) $extra_h += max(60, $font_px + 30);
        $final_h = $size + $extra_h;

        $canvas = imagecreatetruecolor($size, $final_h);
        $bg = imagecolorallocate($canvas, $lr,$lg,$lb);
        imagefilledrectangle($canvas,0,0,$size,$final_h,$bg);

        imagecopy($canvas,$src,0,($extra_h?intval($extra_h/2):0),0,0,$size,$size);

        $textcol = imagecolorallocate($canvas,$dr,$dg,$db);

        if ($top) {
            if ($use_ttf) {
                $bbox = imagettfbbox($font_px, 0, $ttf, $top);
                $tw = $bbox[2] - $bbox[0];
                $th = $bbox[1] - $bbox[7];
                $x = intval(($size - $tw) / 2);
                $y = 10 + $th;
                imagettftext($canvas, $font_px, 0, $x, $y, $textcol, $ttf, $top);
            } else {
                $fw = imagefontwidth(5);
                $tw = $fw * strlen($top);
                $x = intval(($size - $tw) / 2);
                imagestring($canvas, 5, max(10,$x), 10, $top, $textcol);
            }
        }

        if ($bottom) {
            if ($use_ttf) {
                $bbox = imagettfbbox($font_px, 0, $ttf, $bottom);
                $tw = $bbox[2] - $bbox[0];
                $x = intval(($size - $tw) / 2);
                $y = $final_h - 15;
                imagettftext($canvas, $font_px, 0, $x, $y, $textcol, $ttf, $bottom);
            } else {
                $fw = imagefontwidth(5); $fh = imagefontheight(5);
                $tw = $fw * strlen($bottom);
                $x = intval(($size - $tw) / 2);
                imagestring($canvas, 5, max(10,$x), $final_h - $fh - 10, $bottom, $textcol);
            }
        }

        $logo_id = intval($m['logo_id']); $pct = intval($m['logo_pct']);
        if ($logo_id) {
            $path = get_attached_file($logo_id);
            if ($path && file_exists($path)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $logo = ($ext==='png') ? imagecreatefrompng($path) : (in_array($ext,['jpg','jpeg']) ? imagecreatefromjpeg($path) : null);
                if ($logo) {
                    $lw = imagesx($logo); $lh = imagesy($logo);
                    $tw = intval($size * $pct / 100); $th = intval($lh * ($tw/$lw));
                    $resized = imagecreatetruecolor($tw,$th);
                    imagealphablending($resized,false); imagesavealpha($resized,true);
                    imagecopyresampled($resized,$logo,0,0,0,0,$tw,$th,$lw,$lh);
                    $dx = intval(($size-$tw)/2);
                    $dy = intval(($final_h-$size)/2) + intval(($size-$th)/2);
                    imagecopy($canvas,$resized,$dx,$dy,0,0,$tw,$th);
                    imagedestroy($resized); imagedestroy($logo);
                }
            }
        }

        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']).'rightwin-qr';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        $file = trailingslashit($dir).'qr-'.$post_id.'.png';
        imagepng($canvas,$file,9);
        imagedestroy($canvas); imagedestroy($src);

        $filetype = wp_check_filetype($file,null);
        $attachment = ['post_mime_type'=>$filetype['type'],'post_title'=>'QR '.$post_id,'post_content'=>'','post_status'=>'inherit'];
        $attach_id = wp_insert_attachment($attachment,$file,$post_id);
        require_once ABSPATH.'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id,$file);
        wp_update_attachment_metadata($attach_id,$attach_data);
        return $attach_id;
    }

    /* ================= Footer Disclaimer ================= */

    public function footer_disclaimer() {
        $s = get_option(self::OPTION_SETTINGS, ['contact_html'=>'']);
        echo '<div class="rwqr-disclaimer" style="text-align:center;margin:24px 0;font-size:12px;opacity:.8">';
        echo 'Content in QR codes is provided by their creators. Admin is a service provider only. ';
        echo 'Powered by <strong>RIGHT WIN MEDIAS</strong>. '.wp_kses_post($s['contact_html'] ?? '');
        echo '</div>';
    }

    /* ================= Admin QR actions (with admin lock) ================= */

    public function admin_toggle_qr() {
        if (!current_user_can('manage_options')) wp_die('No permission');
        $post_id = absint($_GET['post'] ?? 0);
        check_admin_referer('rwqr_toggle_' . $post_id);
        $st = get_post_meta($post_id,'status',true) ?: 'active';
        $new = ($st==='active') ? 'paused' : 'active';
        update_post_meta($post_id,'status',$new);

        // When admin toggles to paused => lock; when admin toggles to active => unlock
        if ($new === 'paused') update_post_meta($post_id, self::META_ADMIN_LOCKED, 1);
        else delete_post_meta($post_id, self::META_ADMIN_LOCKED);

        wp_safe_redirect(admin_url('admin.php?page=rwqr-admin')); exit;
    }

    public function admin_delete_qr() {
        if (!current_user_can('delete_posts')) wp_die('No permission');
        $post_id = absint($_GET['post'] ?? 0);
        check_admin_referer('rwqr_delete_' . $post_id);
        wp_delete_post($post_id, true);
        wp_safe_redirect(admin_url('admin.php?page=rwqr-admin')); exit;
    }
}

endif;

/* ================= Bootstrap ================= */
if (!function_exists('rwqr_instance')) {
    function rwqr_instance(){ static $i; if(!$i) $i=new RightWin_QR_Portal(); return $i; }
}
add_action('plugins_loaded', 'rwqr_instance');

/* No closing PHP tag */
