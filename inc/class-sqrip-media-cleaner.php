<?php 
class Sqrip_Media_Clearner {

    public $cron_hook;

    public function __construct() {
        $this->expired_date = sqrip_get_plugin_option('expired_date');
       
        if (!$this->expired_date) {
            return;
        }

        $this->media_prefix = 'sqrip';
        $this->query_param = 'sqrip_media';

        add_filter( 'posts_clauses', array($this, 'filter_posts_by_dates_days'), 10 , 2);
        add_filter( 'posts_where', array($this, 'filter_media_title'), 10, 2 );
        // Store our cron hook name
        $this->cron_hook = 'sqrip_media_cleaner';
        // Install cron!
        $this->setup_cron();

        // Add action that points to class method
        add_action($this->cron_hook, array($this, 'clean'));
    }

    public function setup_cron() {
        // Return if existing hooks
        if (wp_next_scheduled($this->cron_hook)) return;
        // wp_clear_scheduled_hook($this->cron_hook);

        // Add schedule event
        wp_schedule_event(time(), 'daily', $this->cron_hook);
    }

    public function clean() {
        // How many days old.
        $days = $this->expired_date;

        // TRUE = bypasses the trash and force deletion.
        $force_delete = true;
        $param = $this->query_param;
        $prefix = $this->media_prefix;
        // Get all attachments that are images. 
        $atts = get_posts( array(
            $param             => $prefix,
            'post_type'        => 'attachment',
            'post_mime_type'   => 'application/pdf',
            'posts_per_page'   => -1,
            'post_days_old'    => $days,
            'suppress_filters' => false,
        ) );

        $current_date = current_time( 'mysql' );
        $logs = '';

        if ($atts) {
            foreach ( $atts as $att ) {
                // Get the number of days since the attachment was created.
                // $created_datetime = new DateTime( $att->post_date );
                // $current_datetime = new DateTime( $current_date );
                // $interval = $created_datetime->diff( $current_datetime );

                // // If the attachment is $days days old since its CREATION DATE, delete
                // // the attachment (post data and image) and all thumbnails.
                // if ( $interval->days >= $days ) {
                    wp_delete_attachment( $att->ID, $force_delete );
                // }
            }

            $logs = 'Sqrip_Media_Cleaner ran and deleted '.count($atts).' invoices!';
            $logs .= 'List Invoices:';
            $logs .= print_r($atts, true);

        } else {
            $logs = 'Sqrip_Media_Cleaner ran and deleted 0 invoices!';
        }

        error_log($logs);
        
    }

    function filter_media_title( $where, $wp_query ) {
        global $wpdb;
        $param = $this->query_param;

        if ( $sqrip_media = $wp_query->get( $param ) ) {
            $where .= ' AND ' . $wpdb->posts . '.post_title LIKE \'%' . esc_sql( $wpdb->esc_like( $sqrip_media ) ) . '%\'';
        }
        return $where;
    }

    function filter_posts_by_dates_days( array $clauses, WP_Query $wp_query ) {
        $days = $wp_query->get( 'post_days_old' );
        if ( is_numeric( $days ) && $days >= 0 ) {
            global $wpdb;
            $clauses['where'] .= $wpdb->prepare( "
                AND ( DATEDIFF( NOW(), {$wpdb->posts}.post_date ) > %d )
            ", $days );
        }

        return $clauses;
    }

} 


new Sqrip_Media_Clearner;
