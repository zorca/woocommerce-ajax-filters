<?php
class BeRocket_aapf_variations_tables_addon extends BeRocket_framework_addon_lib {
    public $addon_file = __FILE__;
    public $plugin_name = 'ajax_filters';
    public $php_file_name   = 'add_table';
    function __construct() {
        parent::__construct();
        $active_addons = apply_filters('berocket_addons_active_'.$this->plugin_name, array());
        $created_table = get_option('BeRocket_aapf_variations_tables_addon_ready');
        if( in_array($this->addon_file, $active_addons) ) {
            if( empty($created_table) ) {
                $this->activate();
            }
        } else {
            if( ! empty($created_table) ) {
                $this->deactivate();
            }
        }
    }
    function get_addon_data() {
        $data = parent::get_addon_data();
        return array_merge($data, array(
            'addon_name'    => __('Variations Tables (BETA)', 'BeRocket_AJAX_domain'),
            'tooltip'       => __('Create 2 additional table to speed up functions for variation filtering', 'BeRocket_AJAX_domain'),
        ));
    }
    function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        //* Create dayli table
        $table_name = $wpdb->prefix . 'braapf_product_stock_status_parent';
        $sql = "CREATE TABLE $table_name (
        post_id BIGINT NOT NULL,
        parent_id BIGINT NOT NULL,
        stock_status TINYINT,
        PRIMARY KEY (post_id),
        INDEX stock_status (stock_status)
        ) $charset_collate;";
        dbDelta( $sql );
        $sql = "INSERT INTO {$table_name}
        SELECT {$wpdb->posts}.ID as post_id, {$wpdb->posts}.post_parent as parent_id, IF({$wpdb->prefix}wc_product_meta_lookup.stock_status = 'instock', 1, 0) as stock_status FROM {$wpdb->prefix}wc_product_meta_lookup
        JOIN {$wpdb->posts} ON {$wpdb->prefix}wc_product_meta_lookup.product_id = {$wpdb->posts}.ID";
        $wpdb->query($sql);
        //* Create Table
        $table_name = $wpdb->prefix . 'braapf_product_variation_attributes';
        $sql = "CREATE TABLE $table_name (
        post_id BIGINT NOT NULL,
        parent_id BIGINT NOT NULL,
        meta_key VARCHAR(255) NOT NULL,
        meta_value_id BIGINT NOT NULL,
        INDEX post_id (post_id),
        INDEX meta_key (meta_key),
        INDEX meta_value_id (meta_value_id)
        ) $charset_collate;";
        dbDelta( $sql );
        $sql = "INSERT INTO {$table_name}
        SELECT {$wpdb->postmeta}.post_id as post_id, {$wpdb->posts}.post_parent as parent_id, {$wpdb->term_taxonomy}.taxonomy as meta_key, {$wpdb->terms}.term_id as meta_value_id FROM {$wpdb->postmeta}
        JOIN {$wpdb->term_taxonomy} ON CONCAT('attribute_', {$wpdb->term_taxonomy}.taxonomy) = {$wpdb->postmeta}.meta_key
        JOIN {$wpdb->terms} ON {$wpdb->terms}.term_id = {$wpdb->term_taxonomy}.term_id AND {$wpdb->postmeta}.meta_value = {$wpdb->terms}.slug
        JOIN {$wpdb->posts} ON {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID
        WHERE {$wpdb->postmeta}.meta_key LIKE 'attribute_pa_%'";
        $wpdb->query($sql);
        update_option('BeRocket_aapf_variations_tables_addon_ready', true);
    }
    function deactivate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'braapf_product_stock_status_parent';
        $sql = "DROP TABLE IF EXISTS {$table_name};";
        $wpdb->query($sql);
        $table_name = $wpdb->prefix . 'braapf_product_variation_attributes';
        $sql = "DROP TABLE IF EXISTS {$table_name};";
        $wpdb->query($sql);
        update_option('BeRocket_aapf_variations_tables_addon_ready', false);
    }
}
new BeRocket_aapf_variations_tables_addon();
