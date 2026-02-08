<?php
/**
 * Plugin Name: WP Packager
 * Plugin URI: https://pacosalcedo.com/
 * Description: Install your favorite plugins in a chain from the official repository. Export and import configuration lists.
 * Version: 1.2.0
 * Author: Paco Salcedo
 * Author URI: https://pacosalcedo.com
 * Text Domain: wp-packager
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.7
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Packager
{
    public function __construct()
    {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wpp_install_plugin', [$this, 'ajax_install_plugin']);

        // Migrar datos antiguos si es necesario
        $this->migrate_legacy_list();
    }

    // ==================== PRESET MANAGEMENT ====================

    private function migrate_legacy_list()
    {
        $legacy = get_option('wpp_plugin_list', null);
        $presets = get_option('wpp_presets', null);

        if ($legacy !== null && $presets === null && !empty($legacy)) {
            $preset_id = uniqid('preset_');
            $new_presets = [
                $preset_id => [
                    'name' => __('My Original List', 'wp-packager'),
                    'plugins' => $legacy
                ]
            ];
            update_option('wpp_presets', $new_presets);
            update_option('wpp_active_preset', $preset_id);
            delete_option('wpp_plugin_list');
        }

        // Asegurar que siempre haya al menos un preset
        if (empty(get_option('wpp_presets', []))) {
            $this->create_preset();
        }
    }

    private function get_presets()
    {
        return get_option('wpp_presets', []);
    }

    private function get_active_preset_id()
    {
        $active = get_option('wpp_active_preset', '');
        $presets = $this->get_presets();
        if (!isset($presets[$active]) && !empty($presets)) {
            $active = array_key_first($presets);
            update_option('wpp_active_preset', $active);
        }
        return $active;
    }

    private function get_active_preset()
    {
        $presets = $this->get_presets();
        $active_id = $this->get_active_preset_id();
        return $presets[$active_id] ?? ['name' => '', 'plugins' => []];
    }

    private function save_preset($id, $name, $plugins)
    {
        $presets = $this->get_presets();
        $presets[$id] = [
            'name' => sanitize_text_field($name),
            'plugins' => array_filter(array_map('trim', $plugins))
        ];
        update_option('wpp_presets', $presets);
    }

    private function delete_preset($id)
    {
        $presets = $this->get_presets();
        if (count($presets) <= 1) {
            return false; // No eliminar el único preset
        }
        if (isset($presets[$id])) {
            unset($presets[$id]);
            update_option('wpp_presets', $presets);
            // Si era el activo, cambiar al primero disponible
            if ($this->get_active_preset_id() === $id) {
                update_option('wpp_active_preset', array_key_first($presets));
            }
            return true;
        }
        return false;
    }

    private function create_preset()
    {
        $presets = $this->get_presets();
        $new_id = uniqid('preset_');
        $count = count($presets) + 1;
        $presets[$new_id] = [
            'name' => sprintf(__('Package %d', 'wp-packager'), $count),
            'plugins' => []
        ];
        update_option('wpp_presets', $presets);
        update_option('wpp_active_preset', $new_id);
        return $new_id;
    }

    private function set_active_preset($id)
    {
        $presets = $this->get_presets();
        if (isset($presets[$id])) {
            update_option('wpp_active_preset', $id);
            return true;
        }
        return false;
    }

    private function handle_preset_actions()
    {
        // Cambiar preset activo
        if (isset($_POST['wpp_preset_select']) && wp_verify_nonce($_POST['wpp_switch_nonce'] ?? '', 'wpp_switch_preset')) {
            $this->set_active_preset(sanitize_text_field($_POST['wpp_preset_select']));
        }

        // Guardar preset actual
        if (isset($_POST['save_preset']) && wp_verify_nonce($_POST['wpp_save_nonce'] ?? '', 'wpp_save_preset')) {
            $id = sanitize_text_field($_POST['preset_id'] ?? '');
            $name = sanitize_text_field($_POST['wpp_preset_name'] ?? '');
            $slugs = array_map('trim', explode(',', sanitize_textarea_field($_POST['wpp_slugs'] ?? '')));
            $this->save_preset($id, $name, $slugs);
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Package saved successfully.', 'wp-packager') . '</p></div>';
        }

        // Nuevo preset
        if (isset($_POST['new_preset']) && wp_verify_nonce($_POST['wpp_save_nonce'] ?? '', 'wpp_save_preset')) {
            $this->create_preset();
            echo '<div class="notice notice-success is-dismissible"><p>' . __('New package created.', 'wp-packager') . '</p></div>';
        }

        // Eliminar preset
        if (isset($_POST['delete_preset']) && wp_verify_nonce($_POST['wpp_save_nonce'] ?? '', 'wpp_save_preset')) {
            $id = sanitize_text_field($_POST['preset_id'] ?? '');
            if ($this->delete_preset($id)) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . __('Package deleted.', 'wp-packager') . '</p></div>';
            }
        }
    }

    public function load_textdomain()
    {
        load_plugin_textdomain('wp-packager', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function add_menu()
    {
        add_menu_page(
            'WP Packager',
            'WP Packager',
            'manage_options',
            'wp-packager',
            [$this, 'render_page'],
            'dashicons-archive',
            100
        );
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_wp-packager')
            return;

        wp_enqueue_script('wp-packager-js', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], '1.1.0', true);
        wp_localize_script('wp-packager-js', 'wpp_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpp_nonce'),
            'confirm_copy' => __('Do you want to add the current plugins to the end of your list?', 'wp-packager'),
            'select_at_least_one' => __('Please select at least one plugin to install.', 'wp-packager'),
            'installing' => __('⏳ Installing...', 'wp-packager'),
            'starting' => __('🏁 Starting installation of ', 'wp-packager'),
            'selected_plugins' => __(' selected plugins...', 'wp-packager'),
            'finished' => __('✅ Finished!', 'wp-packager'),
            'processed' => __('🎉 The selected plugins have been processed.', 'wp-packager'),
            'status_installing' => __('Installing...', 'wp-packager'),
            'processing' => __('🔄 Processing: ', 'wp-packager'),
            'completed' => __('Completed', 'wp-packager'),
            'error' => __('Error', 'wp-packager')
        ]);

        wp_add_inline_style('wp-admin', '
            .wpp-flex { display: flex; gap: 20px; align-items: flex-start; margin-top: 20px; flex-wrap: nowrap; }
            .wpp-main-col { flex: 1; min-width: 0; }
            .wpp-side-col { flex: 0 0 300px; }
            .wpp-container { background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; }
            .wpp-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .wpp-table th { text-align: left; background: #f6f7f7; padding: 10px; border-bottom: 2px solid #ccd0d4; }
            .wpp-table td { padding: 10px; border-bottom: 1px solid #eee; vertical-align: middle; }
            .wpp-status { font-weight: bold; }
            .status-waiting { color: #666; }
            .status-working { color: #2271b1; }
            .status-success { color: #00a32a; }
            .status-error { color: #d63638; }
            #wpp-log { background: #f0f0f1; padding: 10px; border: 1px solid #ccd0d4; height: 150px; overflow-y: scroll; font-family: monospace; margin-top: 20px; font-size: 12px; }
            .wpp-help-card { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #72aee6; padding: 15px; border-radius: 2px; }
            .wpp-help-card h3 { margin-top: 0; color: #1d2327; }
            .wpp-help-card ul { margin-bottom: 0; padding-left: 20px; }
            .wpp-help-card li { margin-bottom: 10px; list-style: decimal; }
            .wpp-help-card code { background: #f0f0f1; padding: 2px 4px; border-radius: 3px; }
            @media (max-width: 900px) { .wpp-flex { flex-wrap: wrap; } .wpp-main-col, .wpp-side-col { flex: 1 1 100%; max-width: 100%; width: 100%; } }
        ');
    }

    public function render_page()
    {
        // Procesar acciones POST antes de renderizar
        $this->handle_preset_actions();

        // Obtener datos de presets
        $presets = $this->get_presets();
        $active_id = $this->get_active_preset_id();
        $active_preset = $this->get_active_preset();
        $plugins = $active_preset['plugins'] ?? [];
        ?>
        <div class="wrap">
            <h1><?php _e('WP Packager', 'wp-packager'); ?> <small>v1.2.0</small></h1>
            <p><?php _e('Define your essential plugins and install them in a chain.', 'wp-packager'); ?></p>

            <div class="wpp-flex">
                <div class="wpp-main-col">
                    <div class="wpp-container">
                        <h2><?php _e('1. Package/Preset Management', 'wp-packager'); ?></h2>

                        <!-- Selector de Preset -->
                        <form method="post" action="" style="margin-bottom: 15px;">
                            <?php wp_nonce_field('wpp_switch_preset', 'wpp_switch_nonce'); ?>
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <label
                                    for="wpp_preset_select"><strong><?php _e('Active Package:', 'wp-packager'); ?></strong></label>
                                <select name="wpp_preset_select" id="wpp_preset_select" onchange="this.form.submit()"
                                    style="min-width: 200px;">
                                    <?php foreach ($presets as $id => $preset): ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $active_id); ?>>
                                            <?php echo esc_html($preset['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="action" value="switch_preset">
                            </div>
                        </form>

                        <!-- Formulario de Edición del Preset Activo -->
                        <form method="post" action="">
                            <?php wp_nonce_field('wpp_save_preset', 'wpp_save_nonce'); ?>
                            <input type="hidden" name="preset_id" value="<?php echo esc_attr($active_id); ?>">

                            <p>
                                <label
                                    for="wpp_preset_name"><strong><?php _e('Package Name:', 'wp-packager'); ?></strong></label><br>
                                <input type="text" name="wpp_preset_name" id="wpp_preset_name"
                                    value="<?php echo esc_attr($active_preset['name']); ?>"
                                    style="width: 100%; max-width: 400px;">
                            </p>

                            <p>
                                <label for="wpp_slugs"><strong><?php _e('Plugins:', 'wp-packager'); ?></strong></label><br>
                                <textarea name="wpp_slugs" id="wpp_slugs" rows="5" style="width: 100%;"
                                    placeholder="Example: updraftplus, filebird, rank-math-seo"><?php echo esc_textarea(implode(', ', $plugins)); ?></textarea>
                            </p>
                            <p class="description"><?php _e('Enter the slugs separated by commas.', 'wp-packager'); ?></p>

                            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
                                <?php submit_button(__('💾 Save Package', 'wp-packager'), 'primary', 'save_preset', false); ?>
                                <button type="submit" name="new_preset" class="button button-secondary">➕
                                    <?php _e('New Package', 'wp-packager'); ?></button>
                                <?php if (count($presets) > 1): ?>
                                    <button type="submit" name="delete_preset" class="button" style="color: #d63638;"
                                        onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this package?', 'wp-packager'); ?>');">
                                        🗑️ <?php _e('Delete', 'wp-packager'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>

                        <hr>

                        <h2><?php _e('2. Bulk Installation', 'wp-packager'); ?></h2>
                        <div class="wpp-list">
                            <?php if (empty($plugins)): ?>
                                <p><?php _e('There are no plugins in the list.', 'wp-packager'); ?></p>
                            <?php else: ?>
                                <table class="wpp-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 30px;">#</th>
                                            <th style="width: 30px;"><input type="checkbox" id="wpp-select-all" checked></th>
                                            <th><?php _e('Plugin (Slug)', 'wp-packager'); ?></th>
                                            <th style="text-align: right;"><?php _e('Status', 'wp-packager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1;
                                        foreach ($plugins as $slug): ?>
                                            <tr class="wpp-item" data-slug="<?php echo esc_attr($slug); ?>">
                                                <td><?php echo $i++; ?></td>
                                                <td><input type="checkbox" class="wpp-plugin-select" checked></td>
                                                <td><strong><?php echo esc_html($slug); ?></strong></td>
                                                <td style="text-align: right;">
                                                    <span
                                                        class="wpp-status status-waiting"><?php _e('Waiting...', 'wp-packager'); ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <br>
                                <div style="margin-bottom: 15px;">
                                    <label><input type="checkbox" id="wpp-auto-activate" checked>
                                        <strong><?php _e('Auto-activate plugins after installation', 'wp-packager'); ?></strong></label>
                                </div>
                                <button id="wpp-start"
                                    class="button button-secondary"><?php _e('🚀 Start Selected Installation', 'wp-packager'); ?></button>
                            <?php endif; ?>
                        </div>

                        <div id="wpp-log">
                            <?php _e('Welcome to WP Packager. Click the button above to start.', 'wp-packager'); ?>
                        </div>

                        <hr>

                        <h2><?php _e('3. Currently Installed Plugins', 'wp-packager'); ?></h2>
                        <p class="description">
                            <?php _e('These are the plugins you currently have. You can copy them to your favorites list.', 'wp-packager'); ?>
                        </p>
                        <div
                            style="background: #f9f9f9; padding: 15px; border: 1px dashed #ccc; max-height: 200px; overflow-y: auto; margin-bottom: 20px;">
                            <ul id="wpp-current-list" style="margin: 0; padding: 0; list-style: none;">
                                <?php
                                $all_plugins = get_plugins();
                                $current_slugs = [];
                                foreach ($all_plugins as $file => $data) {
                                    $slug = dirname($file);
                                    if ($slug === '.' || $slug === 'wp-packager')
                                        continue;
                                    $current_slugs[] = $slug;
                                    echo '<li><code>' . esc_html($slug) . '</code> - ' . esc_html($data['Name']) . '</li>';
                                }
                                ?>
                            </ul>
                        </div>
                        <button type="button" class="button"
                            id="wpp-copy-current"><?php _e('📋 Load current Slugs into the list', 'wp-packager'); ?></button>
                        <script>
                            document.getElementById('wpp-copy-current').addEventListener('click', function () {
                                const slugs = <?php echo json_encode(implode(', ', $current_slugs)); ?>;
                                const textarea = document.querySelector('textarea[name="wpp_slugs"]');
                                if (textarea.value.trim() !== "") {
                                    if (confirm(wpp_vars.confirm_copy)) {
                                        textarea.value += (textarea.value.trim().endsWith(',') ? ' ' : ', ') + slugs;
                                    }
                                } else {
                                    textarea.value = slugs;
                                }
                            });
                        </script>

                        <hr>

                        <h2><?php _e('4. Export / Import', 'wp-packager'); ?></h2>
                        <div style="display:flex; gap:10px;">
                            <a href="<?php echo admin_url('admin-ajax.php?action=wpp_export&nonce=' . wp_create_nonce('wpp_export')); ?>"
                                class="button"><?php _e('⬇️ Export JSON', 'wp-packager'); ?></a>
                            <button class="button"
                                onclick="document.getElementById('wpp_import_file').click()"><?php _e('⬆️ Import JSON', 'wp-packager'); ?></button>
                            <form id="wpp_import_form" method="post" enctype="multipart/form-data" style="display:none;">
                                <input type="file" id="wpp_import_file" name="wpp_import_file"
                                    onchange="document.getElementById('wpp_import_form').submit()">
                                <input type="hidden" name="action" value="wpp_import_action">
                                <?php wp_nonce_field('wpp_import', 'wpp_import_nonce'); ?>
                            </form>
                        </div>
                        <?php
                        if (isset($_FILES['wpp_import_file']) && check_admin_referer('wpp_import', 'wpp_import_nonce')) {
                            $file = $_FILES['wpp_import_file']['tmp_name'];
                            if (file_exists($file)) {
                                $content = file_get_contents($file);
                                $data = json_decode($content, true);
                                if (isset($data['plugins'])) {
                                    update_option('wpp_plugin_list', $data['plugins']);
                                    echo '<script>window.location.reload();</script>';
                                }
                            }
                        }
                        ?>
                    </div> <!-- .wpp-container -->
                </div> <!-- .wpp-main-col -->

                <div class="wpp-side-col">
                    <div class="wpp-help-card">
                        <h3><?php _e('📖 Quick Start Guide', 'wp-packager'); ?></h3>
                        <p><?php _e('Follow these steps to set up your perfect installation in seconds:', 'wp-packager'); ?>
                        </p>
                        <ul>
                            <li><strong><?php _e('Official Slugs', 'wp-packager'); ?>:</strong>
                                <?php _e('Search for the plugin on WordPress.org. The "slug" is the last part of the URL.', 'wp-packager'); ?>
                            </li>
                            <li><strong><?php _e('Chain Installation', 'wp-packager'); ?>:</strong>
                                <?php _e('Check the plugins and click "Start".', 'wp-packager'); ?></li>
                            <li><strong><?php _e('Portability', 'wp-packager'); ?>:</strong>
                                <?php _e('Export your list to JSON and use it on other sites.', 'wp-packager'); ?></li>
                        </ul>
                        <p style="font-size: 11px; color: #666; margin-top: 15px;">
                            <?php _e('Note: Save your JSON file as <code>wp-packager-export.json</code> in the plugin folder for automatic loading.', 'wp-packager'); ?>
                        </p>
                    </div>
                </div> <!-- .wpp-side-col -->
            </div> <!-- .wpp-flex -->
        </div>
        <?php
    }

    public function ajax_install_plugin()
    {
        check_ajax_referer('wpp_nonce', 'nonce');
        if (!current_user_can('install_plugins'))
            wp_send_json_error(__('You do not have permissions.', 'wp-packager'));

        $slug = sanitize_text_field($_POST['slug']);

        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);

        if (is_wp_error($api)) {
            wp_send_json_error(__('Plugin not found in the repository.', 'wp-packager'));
        }

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->install($api->download_link);

        // Si hay error pero es porque ya existe, lo ignoramos para intentar activar
        if (is_wp_error($result) && $result->get_error_code() !== 'folder_exists') {
            wp_send_json_error($result->get_error_message());
        }

        // Activarlo si se solicita
        if (isset($_POST['activate']) && $_POST['activate'] == '1') {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            // Forzar actualización de la lista de plugins internos de WP
            wp_cache_delete('plugins', 'plugins');

            $installed_plugins = get_plugins();
            $main_file = '';

            foreach ($installed_plugins as $file => $data) {
                if (strpos($file, $slug . '/') === 0) {
                    $main_file = $file;
                    break;
                }
            }

            if ($main_file) {
                $activate_result = activate_plugin($main_file, '', false, true); // modo silencioso

                if (is_wp_error($activate_result)) {
                    wp_send_json_error(sprintf(__('Installed but failed to activate: %s', 'wp-packager'), $activate_result->get_error_message()));
                }
                wp_send_json_success(__('Completed: Installed and Activated.', 'wp-packager'));
            } else {
                // Fallback manual si get_plugins no lo ve aún
                $fallback = $slug . '/' . $slug . '.php';
                activate_plugin($fallback, '', false, true);
                wp_send_json_success(__('Completed: Installed (activation attempted via fallback).', 'wp-packager'));
            }
        }

        wp_send_json_success(__('Completed: Installed (manual activation required).', 'wp-packager'));
    }
}

/**
 * Clase para gestionar actualizaciones automáticas desde GitHub (o cualquier URL)
 */
class WPP_Updater
{
    private $slug;
    private $current_version;
    private $update_url;

    public function __construct($slug, $current_version, $update_url)
    {
        $this->slug = $slug;
        $this->current_version = $current_version;
        $this->update_url = $update_url;

        add_filter('site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);

        // Habilitar el enlace de "Activar actualizaciones automáticas" en la lista de plugins
        add_filter('auto_update_plugin', [$this, 'should_auto_update'], 10, 2);
        add_filter('plugin_auto_update_setting_html', [$this, 'auto_update_setting_html'], 10, 3);

        // Normalizar la fuente del ZIP de GitHub antes de la instalación
        add_filter('upgrader_source_selection', [$this, 'normalize_source_directory'], 10, 4);

        // Desactivar el plugin temporalmente antes de la instalación para evitar bloqueos de archivos en Windows
        add_filter('upgrader_pre_install', [$this, 'deactivate_before_install'], 10, 2);
    }

    public function deactivate_before_install($response, $hook_extra)
    {
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->slug . '/' . $this->slug . '.php') {
            deactivate_plugins($hook_extra['plugin']);
        }
        return $response;
    }

    public function normalize_source_directory($source, $remote_source, $upgrader, $hook_extra)
    {
        global $wp_filesystem;

        // Solo actuar si es nuestro plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->slug . '/' . $this->slug . '.php') {
            return $source;
        }

        $source_path = untrailingslashit($source);
        $remote_source_path = untrailingslashit($remote_source);
        $corrected_source = trailingslashit($remote_source_path) . $this->slug;

        if ($source_path !== $corrected_source) {
            if ($wp_filesystem->exists($corrected_source)) {
                $wp_filesystem->delete($corrected_source, true);
            }
            $wp_filesystem->move($source, $corrected_source);
        }

        return trailingslashit($corrected_source);
    }

    public function should_auto_update($update, $item)
    {
        if (isset($item->slug) && $item->slug === $this->slug) {
            return true;
        }
        return $update;
    }

    public function auto_update_setting_html($html, $plugin_file, $plugin_data)
    {
        if ($this->slug . '/' . $this->slug . '.php' === $plugin_file) {
            // Permitir que WordPress muestre el enlace estándar
            return $html;
        }
        return $html;
    }

    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Permitir forzar la comprobación vía URL para pruebas
        if (isset($_GET['wpp_check'])) {
            delete_site_transient('update_plugins'); // Borramos para forzar recarga limpia
            $remote = $this->get_remote_data();
        } else {
            $remote = $this->get_remote_data();
        }

        $plugin_file = $this->slug . '/' . $this->slug . '.php';

        if ($remote && version_compare($this->current_version, $remote->version, '<')) {
            $res = new stdClass();
            $res->slug = $this->slug;
            $res->plugin = $plugin_file;
            $res->new_version = $remote->version;
            $res->tested = $remote->tested;
            $res->package = $remote->download_url;
            $res->url = 'https://github.com/spockyto/WP-packager'; // Requerido para ver detalles

            $transient->response[$plugin_file] = $res;
            unset($transient->no_update[$plugin_file]);
        } else {
            $item = new stdClass();
            $item->slug = $this->slug;
            $item->plugin = $plugin_file;
            $item->new_version = $this->current_version;
            $item->package = '';
            $transient->no_update[$plugin_file] = $item;
            unset($transient->response[$plugin_file]);
        }

        return $transient;
    }

    public function plugin_info($res, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $res;
        }

        if ($this->slug !== $args->slug) {
            return $res;
        }

        $remote = $this->get_remote_data();

        if (!$remote) {
            return $res;
        }

        $res = new stdClass();
        $res->name = 'WP Packager';
        $res->slug = $this->slug;
        $res->version = $remote->version;
        $res->tested = $remote->tested;
        $res->last_updated = $remote->last_updated;
        $res->sections = [
            'description' => $remote->sections->description,
            'changelog' => $remote->sections->changelog
        ];
        $res->download_link = $remote->download_url;

        return $res;
    }

    private function get_remote_data()
    {
        // En local (WAMP), a veces GitHub falla por SSL, intentamos con verificación off si falla
        $args = [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json']
        ];

        $url = add_query_arg('t', time(), $this->update_url);
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            // Reintento sin SSL para entornos locales problemáticos
            $args['sslverify'] = false;
            $response = wp_remote_get(add_query_arg('t', time(), $this->update_url), $args);
        }

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
            return json_decode(wp_remote_retrieve_body($response));
        }

        return false;
    }
}

// Handler para la exportación
add_action('wp_ajax_wpp_export', function () {
    if (!check_admin_referer('wpp_export', 'nonce'))
        exit;

    // Obtener el preset activo
    $presets = get_option('wpp_presets', []);
    $active_id = get_option('wpp_active_preset', '');
    $active_preset = $presets[$active_id] ?? ['name' => 'Default', 'plugins' => []];

    $data = [
        'name' => $active_preset['name'],
        'plugins' => $active_preset['plugins']
    ];

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="wp-packager-' . sanitize_file_name($active_preset['name']) . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
});

// Inicializar el plugin y su sistema de actualizaciones
new WP_Packager();
new WPP_Updater(
    'wp-packager',
    '1.2.0',
    'https://raw.githubusercontent.com/spockyto/WP-packager/main/update.json'
);
