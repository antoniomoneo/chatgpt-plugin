<?php
/*
Plugin Name: OpenAI Assistant
Description: Provides a shortcode to ask questions to an assistant powered by Platform.OpenAI.com.
Version: 1.0.1
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class OA_Assistant {
    const OPTION_KEY = 'oa_assistant_api_key';
    const VERSION    = '1.0.1';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_shortcode('openai_assistant', array($this, 'render_shortcode'));
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function add_settings_page() {
        add_options_page(
            'OpenAI Assistant',
            'OpenAI Assistant',
            'manage_options',
            'oa-assistant',
            array($this, 'settings_page_html')
        );
    }

    public function register_settings() {
        register_setting(
            'oa_assistant',
            self::OPTION_KEY,
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_api_key' ),
                'show_in_rest'      => false,
                'default'           => '',
            )
        );
    }

    public function sanitize_api_key($key) {
        return sanitize_text_field( $key );
    }

    public function settings_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>OpenAI Assistant Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('oa_assistant');
                do_settings_sections('oa_assistant');
                ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr( self::OPTION_KEY ); ?>">API Key</label></th>
                        <td>
                            <input
                                type="password"
                                id="<?php echo esc_attr( self::OPTION_KEY ); ?>"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
                                value="<?php echo esc_attr( get_option( self::OPTION_KEY ) ); ?>"
                                class="regular-text"
                                autocomplete="off"
                            />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_shortcode() {
        wp_enqueue_script(
            'oa-assistant-js',
            plugin_dir_url( __FILE__ ) . 'js/assistant.js',
            array( 'jquery' ),
            self::VERSION,
            true
        );

        wp_localize_script(
            'oa-assistant-js',
            'OA_Assistant',
            array(
                'rest_url' => esc_url_raw( rest_url( 'oa-assistant/v1/ask' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
            )
        );

        return '<div id="oa-assistant"><form id="oa-assistant-form"><input type="text" id="oa-question" required placeholder="Ask something" /><button type="submit">Send</button></form><div id="oa-response"></div></div>';
    }

    public function register_routes() {
        register_rest_route('oa-assistant/v1', '/ask', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_question'),
            'permission_callback' => '__return_true',
        ));
    }

    public function handle_question($request) {
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new WP_REST_Response(array('error' => 'Invalid nonce'), 403);
        }

        $question = sanitize_text_field($request->get_param('question'));
        if (empty($question)) {
            return new WP_REST_Response(array('error' => 'Empty question'), 400);
        }

        $api_key = get_option(self::OPTION_KEY);
        if (empty($api_key)) {
            return new WP_REST_Response(array('error' => 'API key not configured'), 500);
        }

        $body = json_encode(array(
            'model' => 'gpt-3.5-turbo',
            'messages' => array(array('role' => 'user', 'content' => $question)),
        ));

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => $body,
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return new WP_REST_Response(array('error' => $response->get_error_message()), 500);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['choices'][0]['message']['content'])) {
            return new WP_REST_Response(array('answer' => $data['choices'][0]['message']['content']));
        }

        return new WP_REST_Response(array('error' => 'No response'), 500);
    }
}

new OA_Assistant();
