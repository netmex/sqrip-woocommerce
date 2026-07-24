<?php

/**
 * sqrip Version Sync
 *
 * @since 1.9
 */

class Sqrip_Version_Sync
{

    public $cron_hook;
    
    public $expired_date;

    public function __construct()
    {

        // Store our cron hook name
        $this->cron_hook = 'sqrip_version_sync';
        // Install cron!
        $this->setup_cron();

        // Add action that points to class method
        add_action($this->cron_hook, array($this, 'sync_request'));
    }

    public function setup_cron()
    {
        // Return if existing hooks
        if (wp_next_scheduled($this->cron_hook)) return;
        // wp_clear_scheduled_hook($this->cron_hook);

        // Add schedule event
        wp_schedule_event(time(), 'weekly', $this->cron_hook);
    }

    public function sync_request()
    {
        $endpoint = 'details';    
        $plugin_version = '';
        $plugins = get_plugins();
        $sqrip_info = array_filter($plugins, fn($item) => $item["Name"] == "sqrip.ch");

        if ($sqrip_info) {
            $plugin_version = array_values($sqrip_info)[0]['Version'];
        }
        $plugin_token = sqrip_get_plugin_option('token');

        $args = sqrip_prepare_remote_args('', 'GET', $plugin_token);
        $params = $plugin_version ? "?version=".$plugin_version : "";
        $response = wp_remote_request(SQRIP_ENDPOINT . $endpoint . $params, $args);
        $response_code = wp_remote_retrieve_response_code($response);
    }
}


new Sqrip_Version_Sync;
