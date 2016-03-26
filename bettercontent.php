<?php

/*
Plugin Name: Better Content
Plugin URI: http://www.iamklaus.org/better-content
Description: Logged in user have the ability to track changes of single (custom) posts by receiving an email.
Author: iamklaus
Version: 1.0.1
Author URI: http://www.iamklaus.org
*/

register_activation_hook( __FILE__, 'bettercontent_create_db' );
function bettercontent_create_db() {

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'bettercontent_notifyme';

    $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            UNIQUE KEY id (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

add_action( 'wp_enqueue_scripts', 'ajax_enqueue_scripts' );
function ajax_enqueue_scripts() {
	if( is_single() ) wp_enqueue_style( 'bettercontent', plugins_url( '/css/bettercontent.css', __FILE__ ) );
	wp_enqueue_script( 'bettercontent', plugins_url( '/js/bettercontent.js', __FILE__ ), array('jquery'), '1.0', true );
	wp_localize_script( 'bettercontent', 'postbettercontent', array( 'ajax_url' => admin_url( 'admin-ajax.php' )	));
}

add_filter( 'the_content', 'post_notifyme_display' );
function post_notifyme_display ( $content ) {    
    
    if(!is_single() || !is_user_logged_in ()) 
        return $content;

    global $post;
    global $wpdb;
    $current_user = wp_get_current_user();
    
    $table_name = $wpdb->prefix . 'bettercontent_notifyme';
    $sql = "SELECT count(user_id) FROM ".$table_name." WHERE post_id=".$post->ID." and user_id=".$current_user->ID;
    $result_count = $wpdb->get_var($sql);

    if($result_count > 0) {
	    $content .= '<a class="bettercontent-notifyme-button" id="bettercontent-notifyme" href="' 
	        . admin_url( 'admin-ajax.php?action=post_bettercontent_add_notifyme&post_id='
	        . get_the_ID() ) 
	        . '" data-action="del" data-id="'
	        . get_the_ID() 
	        . '">Stop sending me emails about changes.</a><br>'; 
    } else {
	    $content .= '<a class="bettercontent-notifyme-button" id="bettercontent-notifyme" href="' 
	        . admin_url( 'admin-ajax.php?action=post_bettercontent_add_notifyme&post_id='
	        . get_the_ID() ) 
	        . '" data-action="add" data-id="'
	        . get_the_ID() 
	        . '">Send me an email about changes.</a><br>'; 
	}
	
	return $content;
}

add_action( 'wp_ajax_nopriv_post_bettercontent_add_notifyme', 'post_bettercontent_add_notifyme' );
add_action( 'wp_ajax_post_bettercontent_add_notifyme', 'post_bettercontent_add_notifyme' );
function post_bettercontent_add_notifyme() {

    global $wpdb;
    $current_user = wp_get_current_user();
    $table_name = $wpdb->prefix . 'bettercontent_notifyme';

	if ($_REQUEST['post_action'] == 'add') {
	    $wpdb->insert( $table_name, array( 'user_id' => $current_user->ID, 'post_id' => $_REQUEST['post_id']) );
	    $message = 'Stop sending me emails about changes.';
	} elseif ($_REQUEST['post_action'] == 'del') {
	    $wpdb->delete( $table_name, array( 'user_id' => $current_user->ID, 'post_id' => $_REQUEST['post_id']) );
	    $message = 'Send me an email about changes.';
	}

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
	    echo $message;
		die();
	}
	else {
		wp_redirect( get_permalink( $_REQUEST['post_id'] ) );
		exit();
	}
}

add_action( 'post_updated', 'bettercontent_notifyme', 10, 3 ); 
function bettercontent_notifyme($post_id, $post_after, $post_before){
        
    // If this is just a revision, don't send the email.
    if ( wp_is_post_revision( $post_id ) )
        return;
            
    $post_title = get_the_title( $post_id );
    $post_url = get_permalink( $post_id );
    
    
    if ($post_after->post_status == "trash") {
        $subject = 'A post has been trashed';
        $message = "A post has been trashed on your website:<br><br>";
        $message .= $post_title . ": " . $post_url;
    } else {
        $left_string = $post_before->post_title."\n".$post_before->post_content;
        $right_string = $post_after->post_title."\n".$post_after->post_content;
        $diff_table = wp_text_diff($left_string, $right_string);

        $css = "<style type=\"text/css\">";
        $css .= "table.diff { width: 100%; }";
        $css .= "table.diff .diff-sub-title th { text-align: left; background-color: #f00;}";
        $css .= "table.diff th { text-align: left; }";
        $css .= "table.diff .diff-deletedline { background-color:#fdd; width: 50%; }";
        $css .= "table.diff .diff-deletedline del { background-color:#f99; text-decoration: none; }";
        $css .= "table.diff .diff-addedline { background-color:#dfd; width: 50%; }";
        $css .= "table.diff .diff-addedline ins { background-color:#9f9; text-decoration: none; }";
        $css .= "</style>";
        
        $subject = 'A post has been updated';
        $message = $css;
        $message .= "A post has been updated on your website:<br><br>";
        $message .= $post_url."<br><br>";
        $message .= $diff_table;
    }

    if ( $current_user = wp_get_current_user() ) {
        if (function_exists( 'bp_loggedin_user_domain' )) {
            $message .= '<br><br>';
            $message .= 'Modified by: <a href="'. bp_loggedin_user_domain() . '">'. $current_user->display_name . '</a>';
        
        } else {
            $message .= '<br><br>';
            $message .= 'Modified by: <a href="'. get_author_posts_url($current_user->ID). '">'. $current_user->display_name . '</a>';
        }
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'bettercontent_notifyme';
    $sql = "SELECT user_id, post_id FROM ".$table_name." WHERE post_id=".$post_id;
    $result = $wpdb->get_results($sql);
    $headers = array('Content-Type: text/html; charset=UTF-8');

    foreach( $result as $results ) {
        $user_info = get_userdata($results->user_id);
        wp_mail( $user_info->user_email, $subject, $message, $headers);
    }
    
}