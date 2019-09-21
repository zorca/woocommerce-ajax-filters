<?php
class BeRocket_aapf_variations_tables {
    function __construct() {
        add_filter('berocket_aapf_wcvariation_filtering_total_query', array($this, 'wcvariation_filtering_total_query'), 10, 4);
        add_filter('berocket_aapf_wcvariation_filtering_main_query', array($this, 'wcvariation_filtering_main_query'), 10, 4);
        add_action( 'woocommerce_variation_set_stock_status', array($this, 'set_stock_status'), 10, 3 );
        add_action( 'woocommerce_product_set_stock_status', array($this, 'set_stock_status'), 10, 3 );
        add_action( 'delete_post', array($this, 'delete_post'), 10, 1 );
        add_action( 'woocommerce_after_product_object_save', array($this, 'variation_object_save'), 10, 1 );
    }
    function wcvariation_filtering_main_query($query, $input, $terms, $limits) {
        $current_terms = array(0);
        if( is_array($terms) && count($terms) ) {
            foreach($terms as $term) {
                if( substr( $term[0], 0, 3 ) == 'pa_' ) {
                    $current_terms[] = $term[1];
                }
            }
        }
        if( is_array($limits) && count($limits) ) {
            foreach($limits as $attr => $term_ids) {
                if( substr( $attr, 0, 3 ) == 'pa_' ) {
                    $current_attributes[] = sanitize_title('attribute_' . $attr);
                    foreach($term_ids as $term_id) {
                        $term = get_term($term_id);
                        if( ! empty($term) && ! is_wp_error($term) ) {
                            $current_terms[] = $term->term_id;
                        }
                    }
                }
            }
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'braapf_product_variation_attributes';
        $query = array(
            'select'    => 'SELECT '.$table_name.'.post_id as var_id, '.$table_name.'.parent_id as ID, COUNT('.$table_name.'.post_id) as meta_count',
            'from'      => 'FROM '.$table_name,
            'where'     => 'WHERE '.$table_name.'.meta_value_id IN ('.implode(',', $current_terms).')',
            'group'     => 'GROUP BY '.$table_name.'.post_id'
        );
        return $query;
    }
    function wcvariation_filtering_total_query($query, $input, $terms, $limits) {
        global $wpdb;
        $query_custom = array(
            'select'    => "SELECT {$wpdb->prefix}braapf_product_stock_status_parent.post_id as id, IF({$wpdb->prefix}braapf_product_stock_status_parent.stock_status = 1, 0, 1) as out_of_stock_init",
            'from'      => "FROM {$wpdb->prefix}braapf_product_stock_status_parent",
        );
        if ( ! empty($_POST['price_ranges']) || ! empty($_POST['price']) ) {
            $query_custom['join'] = "JOIN {$wpdb->prefix}wc_product_meta_lookup as wc_product_meta_lookup ON wc_product_meta_lookup.product_id = {$wpdb->prefix}braapf_product_stock_status_parent.post_id";
            $query_custom['where_open'] = 'WHERE';
            if ( ! empty($_POST['price']) ) {
                $min = isset( $_POST['price'][0] ) ? floatval( $_POST['price'][0] ) : 0;
                $max = isset( $_POST['price'][1] ) ? floatval( $_POST['price'][1] ) : 9999999999;
                $query_custom['where_1'] = $wpdb->prepare(
                    'wc_product_meta_lookup.min_price < %f AND wc_product_meta_lookup.max_price > %f ',
                    $min,
                    $max
                );
            } else {
                $price_ranges = array();
                foreach ( $_POST['price_ranges'] as $range ) {
                    $range = explode( '*', $range );
                    $min = isset( $range[0] ) ? floatval( ($range[0] - 1) ) : 0;
                    $max = isset( $range[1] ) ? floatval( $range[1] ) : 0;
                    $price_ranges[] = $wpdb->prepare(
                        'wc_product_meta_lookup.min_price < %f AND wc_product_meta_lookup.max_price > %f ',
                        $min,
                        $max
                    );
                }
                $query_custom['where_1'] = implode(' AND ', $price_ranges);
            }
        }
        $query_custom['group'] = 'GROUP BY id';
        $query['subquery']['subquery_3'] = $query_custom;
        return $query;
    }
    function delete_post($product_id) {
        global $wpdb;
        $sql = "DELETE FROM {$wpdb->prefix}braapf_product_stock_status_parent WHERE post_id={$product_id};";
        $wpdb->query($sql);
        $sql = "DELETE FROM {$wpdb->prefix}braapf_product_stock_status_parent WHERE parent_id={$product_id};";
        $wpdb->query($sql);
        $sql = "DELETE FROM {$wpdb->prefix}braapf_product_variation_attributes WHERE post_id={$product_id};";
        $wpdb->query($sql);
        $sql = "DELETE FROM {$wpdb->prefix}braapf_product_variation_attributes WHERE parent_id={$product_id};";
        $wpdb->query($sql);
    }
    function set_stock_status($product_id, $stock_status, $product) {
        global $wpdb;
        $parent = wp_get_post_parent_id($product_id);
        $stock_status_int = ($stock_status == 'instock' ? 1 : 0);
        $sql = "INSERT INTO {$wpdb->prefix}braapf_product_stock_status_parent (post_id, parent_id, stock_status) VALUES({$product_id}, {$parent}, {$stock_status_int}) ON DUPLICATE KEY UPDATE stock_status={$stock_status_int}";
        $wpdb->query($sql);
        
        if ( $product->get_manage_stock() ) {
            $children = $product->get_children();
            if ( $children ) {
                $status           = $product->get_stock_status();
                $format           = array_fill( 0, count( $children ), '%d' );
                $query_in         = '(' . implode( ',', $format ) . ')';
                $managed_children = array_unique( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_manage_stock' AND meta_value != 'yes' AND post_id IN {$query_in}", $children ) ) );
                foreach ( $managed_children as $managed_child ) {
                    $sql = "INSERT INTO {$wpdb->prefix}braapf_product_stock_status_parent (post_id, parent_id, stock_status) VALUES({$managed_child}, {$product_id}, {$stock_status_int}) ON DUPLICATE KEY UPDATE stock_status={$stock_status_int}";
                    $wpdb->query($sql);
                }
            }
        }
    }
    function variation_object_save($product) {
        if( $product->get_type() == 'variation' ) {
            global $wpdb;
            $product_id = $product->get_id();
            $parent_id = $product->get_parent_id();
            $product_attributes = $product->get_variation_attributes();
            $parent_product = wc_get_product($parent_id);
            $sql = "DELETE FROM {$wpdb->prefix}braapf_product_variation_attributes WHERE post_id={$product_id};";
            $wpdb->query($sql);
            foreach($product_attributes as $taxonomy => $attributes) {
                $taxonomy = str_replace('attribute_', '', $taxonomy);
                if( empty($attributes) ) {
                    $attributes = $parent_product->get_variation_attributes();
                    if( isset($attributes[$taxonomy]) ) {
                        $attributes = $attributes[$taxonomy];
                    } else {
                        $attributes = array();
                    }
                } elseif( ! is_array($attributes) ) {
                    $attributes = array($attributes);
                }
                foreach($attributes as $attribute) {
                    $term = get_term_by('slug', $attribute, $taxonomy);
                    $sql = "INSERT INTO {$wpdb->prefix}braapf_product_variation_attributes (post_id, parent_id, meta_key, meta_value_id) VALUES({$product_id}, {$parent_id}, '{$taxonomy}', {$term->term_id})";
                    $wpdb->query($sql);
                }
            }
        }
    }
}
new BeRocket_aapf_variations_tables();
