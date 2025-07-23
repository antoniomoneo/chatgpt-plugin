<?php
/*
Plugin Name: OpenAI Assistant
Description: Embed OpenAI Assistants via shortcode.
Version: 2.9.19
Author: Tangible Data
Text Domain: oa-assistant
*/

if (!defined('ABSPATH')) exit;

class OA_Assistant_Plugin {
    public function __construct() {
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
        register_setting('oa-assistant-general', 'oa_assistant_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        add_settings_section('oa-assistant-api-section', 'Ajustes generales', function(){
            echo '<p>Tu clave secreta de OpenAI.</p>';
        }, 'oa-assistant-general');
        add_settings_field('oa_assistant_api_key', 'OpenAI API Key', function(){
            printf('<input type="password" id="oa_assistant_api_key" name="oa_assistant_api_key" value="%s" class="regular-text" />', esc_attr(get_option('oa_assistant_api_key','')));
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

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_oa-assistant') return;
        wp_enqueue_style('oa-admin-css', plugin_dir_url(__FILE__).'css/assistant.css', [], '2.9.19');
        wp_enqueue_script('oa-admin-js', plugin_dir_url(__FILE__).'js/assistant.js', ['jquery'], '2.9.19', true);
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_style('oa-frontend-css', plugin_dir_url(__FILE__).'css/assistant.css', [], '2.9.19');
        wp_enqueue_script('oa-frontend-js', plugin_dir_url(__FILE__).'js/assistant-frontend.js', ['jquery'], '2.9.19', true);
    }

    public function register_shortcodes() {
        add_shortcode('openai_assistant', [$this, 'render_assistant_shortcode']);
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
            wp_send_json_error('Faltan parámetros');
        }
        $configs = get_option('oa_assistant_configs', []);
        $cfgs = array_filter($configs, function($c) use ($slug) {
            return $c['slug'] === $slug;
        });
        if (!$cfgs) {
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
                'content' => "Contexto relevante:
" . implode("

", $context_chunks),
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
                'Authorization' => 'Bearer ' . get_option('oa_assistant_api_key'),
            ],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message(), 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $reply = $body['choices'][0]['message']['content'] ?? '';
        if (!$reply) {
            wp_send_json_error('No llegó respuesta del assistant');
        }

        wp_send_json_success(['reply' => $reply]);
    }

    // Placeholder: implement your vector DB retrieval logic
    private function get_vector_context($vector_store_id, $query) {
        // TODO: conectarse a tu vector store usando $vector_store_id y retornar array de fragmentos relevantes
        return [];
    }
}

new OA_Assistant_Plugin();
