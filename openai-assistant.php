<?php
/*
Plugin Name: OpenAI Assistant
Description: Embed OpenAI Assistants via shortcode.
Version: 2.9.25
Author: Tangible Data
Text Domain: oa-assistant
*/

if (!defined('ABSPATH')) exit;

class OA_Assistant_Plugin {
    const VERSION = "2.9.25";
    public function __construct() {
        $this->maybe_migrate_key();
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('wp_ajax_oa_assistant_chat', [$this, 'ajax_chat']);
        add_action('wp_ajax_nopriv_oa_assistant_chat', [$this, 'ajax_chat']);
    }

    public function add_admin_menu() {
        add_menu_page('OpenAI Assistant', 'OpenAI Assistant', 'manage_options', 'oa-assistant', [$this, 'settings_page'], 'dashicons-format-chat');
    }

    public function register_settings() {
        register_setting('oa-assistant-general', 'oa_assistant_api_key_enc', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_and_encrypt_key'],
            'default' => '',
        ]);
        add_settings_section('oa-assistant-api-section', 'Ajustes generales', function(){
            echo '<p>Tu clave secreta de OpenAI.</p>';
        }, 'oa-assistant-general');
        add_settings_field('oa_assistant_api_key', 'OpenAI API Key', function(){
            $val = '';
            if (method_exists($this, 'get_api_key')) {
                $val = $this->get_api_key();
            }
            printf(
                '<input type="password" id="oa_assistant_api_key" name="oa_assistant_api_key_enc" value="%s" class="regular-text" placeholder="sk-..." />'
                . '<p class="description">%s</p>',
                esc_attr($val),
                esc_html__('Introduce tu clave secreta empezando por sk-', 'oa-assistant')
            );
        }, 'oa-assistant-general', 'oa-assistant-api-section');

        register_setting('oa-assistant-configs', 'oa_assistant_configs', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_configs'],
            'default' => [],
        ]);
    }

    public function sanitize_configs($configs) {
        if (!is_array($configs)) return [];
        $sanitized = [];
        foreach ($configs as $cfg) {
            if (empty($cfg['slug'])) continue;
            $sanitized[] = [
                'nombre' => sanitize_text_field($cfg['nombre'] ?? ''),
                'slug' => sanitize_title($cfg['slug'] ?? ''),
                'assistant_id' => sanitize_text_field($cfg['assistant_id'] ?? ''),
                'developer_instructions' => sanitize_textarea_field($cfg['developer_instructions'] ?? ''),
                'vector_store_id' => sanitize_text_field($cfg['vector_store_id'] ?? ''),
            ];
        }
        return $sanitized;
    }

    public function sanitize_and_encrypt_key($key) {
        $key = sanitize_text_field($key);
        if (empty($key)) return '';
        return $this->encrypt_key($key);
    }

    private function encrypt_key($key) {
        if (!function_exists('openssl_encrypt')) {
            return base64_encode($key);
        }
        $method = 'AES-256-CBC';
        $iv = substr(hash('sha256', AUTH_KEY), 0, 16);
        return base64_encode(openssl_encrypt($key, $method, AUTH_KEY, OPENSSL_RAW_DATA, $iv));
    }

    private function decrypt_key($cipher) {
        if (!$cipher) return '';
        if (!function_exists('openssl_decrypt')) {
            return base64_decode($cipher);
        }
        $method = 'AES-256-CBC';
        $iv = substr(hash('sha256', AUTH_KEY), 0, 16);
        $plain = openssl_decrypt(base64_decode($cipher), $method, AUTH_KEY, OPENSSL_RAW_DATA, $iv);
        return $plain ?: '';
    }

    private function log_error($msg) {
        update_option('oa_assistant_last_error', date('Y-m-d H:i:s') . ' - ' . $msg);
    }

    private function get_api_key() {
        $enc = get_option('oa_assistant_api_key_enc', '');
        return $this->decrypt_key($enc);
    }

    private function maybe_migrate_key() {
        $new = get_option('oa_assistant_api_key_enc', null);
        if ($new !== null && $new !== '') {
            return;
        }
        $plain = get_option('oa_assistant_api_key', '');
        if ($plain) {
            update_option('oa_assistant_api_key_enc', $this->encrypt_key($plain));
        }
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_oa-assistant') return;
        wp_enqueue_style('oa-admin-css', plugin_dir_url(__FILE__).'css/assistant.css', [], self::VERSION);
        wp_enqueue_script('oa-admin-js', plugin_dir_url(__FILE__).'js/assistant.js', ['jquery'], self::VERSION, true);
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_style('oa-frontend-css', plugin_dir_url(__FILE__).'css/assistant.css', [], self::VERSION);
        wp_enqueue_script('oa-frontend-js', plugin_dir_url(__FILE__).'js/assistant-frontend.js', ['jquery'], self::VERSION, true);
    }

    public function register_shortcodes() {
        add_shortcode('openai_assistant', [$this, 'render_assistant_shortcode']);
    }

    public function settings_page() {
        $last_error = get_option('oa_assistant_last_error', '');
        echo '<div class="wrap"><h1>OpenAI Assistant</h1>';
        echo '<p><em>Version '.self::VERSION.'</em></p>';
        if ($last_error) {
            echo '<div style="border:1px solid #c00;padding:10px;background:#fff0f0;margin-bottom:15px;">'
                .'<strong>Último error:</strong> '.esc_html($last_error).'</div>';
        }
        echo '<form method="post" action="options.php">';
        settings_fields('oa-assistant-general');
        do_settings_sections('oa-assistant-general');
        submit_button();
        echo '</form>';

        $configs = get_option('oa_assistant_configs', []);
        echo '<h2>'.__('Assistants','oa-assistant').'</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('oa-assistant-configs');
        echo '<table class="form-table" id="oa-configs"><thead><tr>';
        echo '<th>'.__('Nombre','oa-assistant').'</th><th>'.__('Slug').'</th><th>Assistant ID</th><th>'.__('Instrucciones').'</th><th>Vector store ID</th></tr></thead><tbody>';
        $i=0;
        foreach($configs as $cfg){
            printf(
                '<tr><td><input class="regular-text" name="oa_assistant_configs[%1$d][nombre]" value="%2$s" placeholder="Ej: Soporte" /></td>'
                .'<td><input class="regular-text" name="oa_assistant_configs[%1$d][slug]" value="%3$s" placeholder="soporte" /></td>'
                .'<td><input class="regular-text" name="oa_assistant_configs[%1$d][assistant_id]" value="%4$s" placeholder="asst_..." /></td>'
                .'<td><textarea name="oa_assistant_configs[%1$d][developer_instructions]" rows="3" style="width:100%;" placeholder="Instrucciones para el assistant">%5$s</textarea></td>'
                .'<td><input class="regular-text" name="oa_assistant_configs[%1$d][vector_store_id]" value="%6$s" placeholder="categoria" /></td></tr>',
                $i,
                esc_attr($cfg['nombre']),
                esc_attr($cfg['slug']),
                esc_attr($cfg['assistant_id']),
                esc_textarea($cfg['developer_instructions']),
                esc_attr($cfg['vector_store_id'])
            );
            $i++;
        }
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="oa-add-config">'.__('Añadir','oa-assistant').'</button></p>';
        submit_button();
        echo '</form></div>';
    }

    public function render_assistant_shortcode($atts) {
        $atts = shortcode_atts(['slug' => ''], $atts, 'openai_assistant');
        if (empty($atts['slug'])) {
            return '<p style="color:red;">Error: falta atributo slug.</p>';
        }
        $configs = get_option('oa_assistant_configs', []);
        $cfgs = array_filter($configs, function($c) use ($atts) {
            return $c['slug'] === $atts['slug'];
        });
        if (!$cfgs) {
            return '<p style="color:red;">Assistant “'.esc_html($atts['slug']).'” no encontrado.</p>';
        }
        $c = array_pop($cfgs);
        $ajax_url = esc_attr(admin_url('admin-ajax.php'));
        $nonce    = esc_attr(wp_create_nonce('oa_assistant_chat'));

        ob_start(); ?>
        <div class="oa-assistant-chat"
             data-slug="<?php echo esc_attr($c['slug']); ?>"
             data-ajax="<?php echo $ajax_url; ?>"
             data-nonce="<?php echo $nonce; ?>">
          <div class="oa-messages"></div>
          <form class="oa-form">
            <input type="text" name="user_message" placeholder="Escribe tu mensaje…" required />
            <button type="submit">Enviar</button>
          </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_chat() {
        check_ajax_referer('oa_assistant_chat','nonce');
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $msg  = sanitize_text_field($_POST['message'] ?? '');
        if (!$slug || !$msg) {
            $this->log_error('Faltan parámetros');
            wp_send_json_error('Faltan parámetros');
        }
        $configs = get_option('oa_assistant_configs', []);
        $cfgs = array_filter($configs, function($c) use ($slug) {
            return $c['slug'] === $slug;
        });
        if (!$cfgs) {
            $this->log_error('Assistant no encontrado: ' . $slug);
            wp_send_json_error('Assistant no encontrado');
        }
        $c = array_pop($cfgs);

        // Retrieve context from vector store (implement your function)
        $context_chunks = $this->get_vector_context($c['vector_store_id'], $msg);

        $messages = [];
        if (!empty($c['developer_instructions'])) {
            $messages[] = ['role' => 'system', 'content' => $c['developer_instructions']];
        }
        if (!empty($context_chunks)) {
            $messages[] = [
                'role' => 'system',
                'content' => "Contexto relevante:\n" . implode("\n\n", $context_chunks),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $msg];

        $payload = [
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages,
            'temperature' => 0.7,
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_api_key(),
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $msg_err = $response->get_error_message();
            $this->log_error('OpenAI request failed: '.$msg_err);
            error_log('OpenAI request failed: '.$msg_err);
            wp_send_json_error('Error al conectar con OpenAI', 500);
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $body_err = wp_remote_retrieve_body($response);
            $this->log_error('OpenAI API status '.$status.': '.$body_err);
            error_log('OpenAI API status '.$status.': '.$body_err);
            wp_send_json_error('Error del servicio OpenAI', 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error('OpenAI JSON decode error: '.json_last_error_msg());
            error_log('OpenAI JSON decode error: '.json_last_error_msg());
            wp_send_json_error('Respuesta inválida del servicio', 500);
        }
        $reply = $body['choices'][0]['message']['content'] ?? '';
        if (!$reply) {
            $this->log_error('OpenAI empty reply');
            wp_send_json_error('No llegó respuesta del assistant', 500);
        }

        // clear previous error on success
        $this->log_error('');

        wp_send_json_success(['reply' => $reply]);
    }

    // Simple vector context retrieval using WP posts as storage
    private function get_vector_context($vector_store_id, $query) {
        $vector_store_id = sanitize_title($vector_store_id);
        $query = sanitize_text_field($query);
        if (empty($vector_store_id) || empty($query)) {
            return [];
        }

        $posts = get_posts([
            's'              => $query,
            'posts_per_page' => 3,
            'category_name'  => $vector_store_id,
        ]);

        $chunks = [];
        foreach ($posts as $p) {
            $chunks[] = wp_trim_words(strip_tags($p->post_content), 40);
        }
        return $chunks;
    }
}

add_action('plugins_loaded', function(){
    new OA_Assistant_Plugin();
});
