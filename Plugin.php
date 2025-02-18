<?php
/**
 * Plugin Name: Membership Sync to API
 * Description: Syncs user membership status to an external API based on their subscription status.
 * Version: 1.1
 * Author: Muhammad Kashif
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class MembershipSyncAPI {
    private $api_url;
    private $access_token;
    private $log_file;

    public function __construct() {
        $this->api_url = get_option('membership_sync_api_url', 'https://amt-stage.accessdevelopment.com/api/v1/imports.json');
        $this->access_token = get_option('membership_sync_access_token', '');
        $this->log_file = WP_CONTENT_DIR . '/uploads/membership-sync/logs/debug.log';

        // Ensure log directory exists
        if (!file_exists(dirname($this->log_file))) {
            wp_mkdir_p(dirname($this->log_file));
        }

        // Hook for payment completed
        add_action('ihc_payment_completed', [$this, 'handle_payment_completed'], 10, 1);
        // Hook for payment gateway page
        add_action('ihc_payment_gateway_page', [$this, 'handle_payment_completed'], 10, 1);
        // Hook for first-time subscription activation
        add_action('ihc_action_after_subscription_first_time_activated', [$this, 'sync_user_data_on_first_time_activation'], 10, 1);

        // Admin settings
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Handle payment completion by syncing user data after successful payment.
     */
    public function handle_payment_completed($payment_data) {
        // Ensure the payment is successful before syncing
        if ($payment_data['status'] == 'completed') {
            // Payment successful, proceed with syncing after the user is activated
            add_action('ihc_action_after_subscription_first_time_activated', [$this, 'sync_user_data_on_first_time_activation'], 10, 1);
        }
    }

    /**
     * Sync user data to the external API when subscription is activated for the first time.
     */
    public function sync_user_data_on_first_time_activation($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            $this->log_message('ERROR', "User not found for ID: $user_id");
            return;
        }

        $membership_levels = apply_filters('ihc_public_get_user_levels', [], $user_id);
        $subscription_type = $this->determine_subscription_type($membership_levels);

        $status = empty($membership_levels) ? 'SUSPEND' : 'OPEN';
        $user_data = $this->prepare_user_data($user, $subscription_type, $status);

        $this->log_message('INFO', "Syncing first-time activated user $user_id: " . json_encode($user_data));
        $this->send_data_to_api($user_data);
    }

    /**
     * Determine the subscription type based on the membership levels.
     */
    private function determine_subscription_type($membership_levels) {
        foreach ($membership_levels as $level_id) {
            $level_data = ihc_get_level_by_id($level_id);
            if (!empty($level_data['label'])) {
                if (stripos($level_data['label'], 'yearly') !== false) return 'YEARLY';
                if (stripos($level_data['label'], 'monthly') !== false) return 'MONTHLY';
            }
        }
        return 'UNKNOWN';
    }

    /**
     * Prepare the user data for API synchronization.
     */
    private function prepare_user_data($user, $subscription_type, $status) {
        return [
            'record_identifier' => 'USER_' . sanitize_text_field($user->user_login),
            'record_type' => 'MEM_SYN',
            'program_customer_identifier' => '204200',
            'member_customer_identifier' => strtoupper('TDC_' . $user->ID),
            'organization_customer_identifier' => '204200',
            'previous_member_customer_identifier' => null,
            'member_status' => $status,
            'subscription_type' => $subscription_type,
            'full_name' => sanitize_text_field(trim($user->first_name . ' ' . $user->last_name)),
            'first_name' => sanitize_text_field($user->first_name),
            'last_name' => sanitize_text_field($user->last_name),
            'email_address' => sanitize_email($user->user_email)
        ];
    }

    /**
     * Send user data to the external API.
     */
    private function send_data_to_api($user_data, $retry = 0) {
        if ($retry > 2) {
            $this->log_message('ERROR', "API Sync failed after multiple retries for user: " . json_encode($user_data));
            return;
        }

        try {
            $data = ['import' => ['members' => [$user_data]]];
            $request_args = [
                'method' => 'POST',
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Access-Token' => $this->access_token
                ],
                'body' => json_encode($data),
                'timeout' => 45
            ];

            $response = wp_remote_post($this->api_url, $request_args);

            if (is_wp_error($response)) {
                throw new Exception("API Request Failed: " . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($response_code !== 200) {
                $this->log_message('WARNING', "API Response Code: $response_code | Retrying...");
                sleep(3);
                $this->send_data_to_api($user_data, $retry + 1);
            } else {
                $this->log_message('SUCCESS', "API Sync Success: $body");
            }
        } catch (Exception $e) {
            $this->log_message('ERROR', "Exception: " . $e->getMessage());
        }
    }

    /**
     * Log messages to the debug log.
     */
    private function log_message($level, $message) {
        $formatted_message = "[" . date("Y-m-d H:i:s") . "] [$level] $message" . PHP_EOL;
        error_log($formatted_message, 3, $this->log_file);
    }

    /**
     * Add settings page to the admin menu.
     */
    public function add_settings_page() {
        add_options_page(
            'Membership Sync API Settings', // Page title
            'Membership Sync API',          // Menu title
            'manage_options',               // Capability
            'membership-sync-api',          // Menu slug
            [$this, 'render_settings_page'] // Callback to render the settings page
        );
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Membership Sync API Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('membership_sync_api_settings_group');
                do_settings_sections('membership-sync-api');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register settings and fields.
     */
    public function register_settings() {
        register_setting('membership_sync_api_settings_group', 'membership_sync_api_url');
        register_setting('membership_sync_api_settings_group', 'membership_sync_access_token');

        add_settings_section('membership_sync_api_section', 'API Settings', null, 'membership-sync-api');
        
        add_settings_field(
            'membership_sync_api_url',
            'API URL',
            [$this, 'render_api_url_field'],
            'membership-sync-api',
            'membership_sync_api_section'
        );

        add_settings_field(
            'membership_sync_access_token',
            'Access Token',
            [$this, 'render_access_token_field'],
            'membership-sync-api',
            'membership_sync_api_section'
        );
    }

    /**
     * Render API URL field in settings page.
     */
    public function render_api_url_field() {
        $value = get_option('membership_sync_api_url', '');
        echo "<input type='text' name='membership_sync_api_url' value='" . esc_attr($value) . "' />";
    }

    /**
     * Render Access Token field in settings page.
     */
    public function render_access_token_field() {
        $value = get_option('membership_sync_access_token', '');
        echo "<input type='text' name='membership_sync_access_token' value='" . esc_attr($value) . "' />";
    }
}

new MembershipSyncAPI();
