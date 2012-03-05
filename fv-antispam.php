<?php
/*
Plugin Name: FV Antispam
Plugin URI: http://foliovision.com/seo-tools/wordpress/plugins/fv-antispam
Description: Powerful and simple antispam plugin. Puts all the spambot comments directly into trash and let's other plugins (Akismet) deal with the rest.
Author: Foliovision
Version: 1.8.4
Author URI: http://www.foliovision.com
*/

if (!function_exists ('is_admin')) {
  header('Status: 403 Forbidden');
  header('HTTP/1.1 403 Forbidden');
  exit();
}

class FV_Antispam {
  var $basename;
  var $protect;
  var $locale;
  function FV_Antispam() {
    $this->basename = plugin_basename(__FILE__);
    ///
    //$this->protect = 'comment-' .substr(md5(get_bloginfo('url')), 0, 5);
    $this->protect = 'a'.substr(md5(get_bloginfo('url')), 0, 10).'';
    ///
    $this->locale = get_locale();
    if (is_admin()) {

      
      add_action( 'init', array( $this, 'load_plugin_lang' ) );
      add_action( 'admin_menu', array( $this, 'init_admin_menu' ) );

      if ($this->is_current_page('home')) {
        add_action( 'admin_head', array( $this, 'show_plugin_head' ) );
      } else if ($this->is_current_page('index')) {
        if ($this->is_min_wp('2.7') && ( $this->get_plugin_option('dashboard_count') || 1>0 ) ) { //  always ON
          add_action( 'right_now_table_end', array( $this, 'show_dashboard_count' ) );
        }
      } else if ($this->is_current_page('plugins')) {
        add_action( 'activate_' .$this->basename, array( $this, 'init_plugin_options' ) );
        add_action( 'deactivate_' .$this->basename, array( $this, 'clear_scheduled_hook' ) );
        if ($this->is_min_wp('2.8')) {
          add_filter( 'plugin_row_meta', array( $this, 'init_row_meta' ), 10, 2 );
        } else {
          add_filter( 'plugin_action_links', array( $this, 'init_action_links' ), 10, 2 );
        }
      }
      
      if( $this->get_plugin_option('comment_status_links') ) {
        $this->in_admin_header();
        add_action( 'comment_status_links', array( $this, 'comment_status_links' ) );
      }      
      
      add_action( 'init', array( $this, 'InitiateCron' ) );
      
    } else {
      ///
      add_action( 'comment_post', array( $this, 'fv_blacklist_to_trash_post' ), 1000 ); //  all you need
      ///
      add_action( 'template_redirect', array( $this, 'replace_comment_field' ) );
      add_action( 'init', array( $this, 'precheck_comment_request' ), 0 );
      add_action( 'preprocess_comment', array( $this, 'verify_comment_request' ), 1 );
      add_action( 'antispam_bee_count', array( $this, 'the_spam_count' ) );
      
      /// 2010/12/21
      if( $_SERVER['REQUEST_METHOD'] == 'POST' && isset ($_POST['filled_in_form']) && $this->get_plugin_option('protect_filledin') ) {
        add_filter( 'plugins_loaded', array( $this, 'filled_in_check' ), 9 );
  		}
  		if( $this->get_plugin_option('protect_filledin') ) {
  		  add_filter( 'the_content', array( $this, 'the_content' ), 999 );
  		}
  		///
      
      if ($GLOBALS['pagenow'] == 'wp-login.php' && $this->get_plugin_option('spam_registrations')) {
        add_action( 'login_head', array( $this, 'protect_spam_registrations_style' ) );            
        add_action( 'init', array( $this, 'replace_email_registration_field_start' ) );
        add_action( 'register_form', array( $this, 'replace_email_registration_field_flush' ) );
        add_action( 'login_form', array( $this, 'replace_message_field_flush' ) );
        add_action( 'init', array( $this, 'protect_spam_registrations_check' ), 0 );
      }
    }
    
    add_action( 'fv_clean_trash_hourly', array( $this, 'clean_comments_trash' ) );
  }
  
  
  /*function init_scheduled_hook() {
    if (function_exists('wp_schedule_event')) {
      if (!wp_next_scheduled('antispam_bee_daily_cronjob')) {
        wp_schedule_event(time(), 'daily', 'antispam_bee_daily_cronjob');
      }
    }
  }*/
  
  
  function clear_scheduled_hook() {
    if (function_exists('wp_schedule_event')) {
      if (wp_next_scheduled('fv_clean_trash_hourly')) {
        wp_clear_scheduled_hook('fv_clean_trash_hourly');
      }
    }
  }
  
  
  function check_user_can() {
    if (current_user_can('manage_options') === false || current_user_can('edit_plugins') === false || !is_user_logged_in()) {
      wp_die('You do not have permission to access!');
    }
  }
  
  
  /**
    * Clear the URI for use in onclick events.
    * 
    * @param string Comment status links HTML
    * 
    * @return string Updated comment status links HTML
    */ 
  function comment_status_links( $content ) {
    if( is_admin() ) {
      $post_id = isset($_REQUEST['p']) ? (int) $_REQUEST['p'] : 0;
      
      //  count total comments per status and type
      global $wpdb;
      if ( $post_id > 0 ) {
  		  $where = $wpdb->prepare( "WHERE comment_post_ID = %d", $post_id );
      }
      $count = $wpdb->get_results( "SELECT comment_approved, comment_type, COUNT( * ) AS num_comments FROM {$wpdb->comments} {$where} GROUP BY comment_approved, comment_type" );
  
      $count_types = array();
      foreach( $count AS $count_item ) {
        if( $count_item->comment_type == '' ) $count_types[$count_item->comment_approved]['comments'] = $count_item->num_comments;
        else $count_types[$count_item->comment_approved]['pings'] += $count_item->num_comments;
      }

      if( $this->is_min_wp( '3.1' ) ) {
        foreach( $content AS $content_key => $content_item ) {
        	if( $content_key == 'moderated' ) {
        		$content_key_select = '0';
        	} else {
        		$content_key_select = $content_key;
        	}
          $new_count = number_format( intval( $count_types[$content_key_select]['comments'] ) ).'</span>/'.number_format( intval( $count_types[$content_key_select]['pings'] ) );
          $content[$content_key] = preg_replace( '@(<span class="count">\(<span class="\S+-count">)[\d,]+</span>@', '$1&shy;'.$new_count, $content[$content_key] );
        }
      } else {
        foreach( array( 'moderated' => 0, 'spam' => 'spam', 'trash' => 'trash' ) AS $key => $value ) {
          $new_count = number_format( intval( $count_types[$value]['comments'] ) ).'/'.number_format( intval( $count_types[$value]['pings'] ) );
          $content = preg_replace( '@(<li class=\''.$key.'\'>.*?<span class="count">\(<span class="\S+-count">)[\d,]+@', '$1&shy;'.$new_count, $content );  
        }
      }
    }
    $content['help'] = '<abbr title="The numbers show counts of comments/pings for each group.">(?)</abbr>';
    return $content; 
  }
  
  
  function cut_ip_address($ip) {
    if (!empty($ip)) {
      return str_replace( strrchr($ip, '.'), '', $ip );
    }
  }
  
  
  function delete_spam_comments() {
    $days = intval($this->get_plugin_option('cronjob_interval'));
    if (empty($days)) {
      return false;
    }
    $GLOBALS['wpdb']->query( sprintf( "DELETE FROM %s WHERE comment_approved = 'spam' AND SUBDATE(NOW(), %s) > comment_date_gmt", $GLOBALS['wpdb']->comments, $days ) );
    $GLOBALS['wpdb']->query("OPTIMIZE TABLE `" .$GLOBALS['wpdb']->comments. "`");
  }
  
  
  function exe_daily_cronjob() {
    $this->delete_spam_comments();
    $this->set_plugin_option( 'cronjob_timestamp', time() );
  }
  
  
  /**
    * Check if the fake field has been filled in POST and take action
    * 
    * @global array $_POST              
    * 
    */    
  function filled_in_check() {
    if( isset( $_POST[$this->get_filled_in_fake_field_name()] ) && strlen( $_POST[$this->get_filled_in_fake_field_name()] ) ) {
      unset( $_POST['filled_in_form'] );
    }
  }
  
  
  /**
    * Shows a warning of any of the Filled in form fields matches the fake field. First looks up the forms and then parses posts and pages.
    * 
    * @global object WPDB.               
    * 
    */    
  function filled_in_collision_check( $force = false ) {
    if( stripos( $_SERVER['REQUEST_URI'], 'fv-antispam.php' ) && !$force ) return;
    
    $problems = get_option( 'fv_antispam_filledin_conflict' );
    if( $problems === false ) {
      global $wpdb;
      
      $forms = $wpdb->get_col( "SELECT name FROM {$wpdb->prefix}filled_in_forms" );
      
      if( $forms ) {
        $where = array();
        foreach( $forms AS $forms_item ) {
          $where[] = '( post_content LIKE \'%id="'.$forms_item.'"%\' AND post_status = \'publish\' )';
        }
        $where = implode( ' OR ', $where );
        $posts = $wpdb->get_results( "SELECT ID,post_title,post_content FROM $wpdb->posts WHERE {$where} ORDER BY post_date DESC" );
        if( $posts ) {
          $problems = array();
          foreach( $posts AS $posts_item ) {
            foreach( $forms AS $forms_item ) {
              $res = preg_match_all( '@<form.*?id=[\'"]'.$forms_item.'[\'"].*?name=[\'"]'.$this->get_filled_in_fake_field_name().'[\'"].*?</form>@si', $posts_item->post_content, $matches );
              if( $res ) {
                $problems[] = array( 'post_id' => $posts_item->ID, 'post_title' => $posts_item->post_title, 'form_name' => $forms_item );
              } 
            }
          }
          if( $problems ) {
            update_option( 'fv_antispam_filledin_conflict', $problems );
          } else {
            update_option( 'fv_antispam_filledin_conflict', array( ) );
          }
           
        }
      }
    }
    if( $problems ) {
      $problematic_message = '';
      foreach( $problems AS $key=>$problems_item ) {
        $problematic_message .= ' <a title="Post \''.$problems_item['post_title'].'\' containing form \''.$problems_item['form_name'].'\'" href="'.get_bloginfo( 'url' ).'?p='.$problems_item['post_id'].'">'.$problems_item['post_id'].'</a>';
      }
      echo '<div class="error fade"><p>FV Antispam detected that following posts contain Filled in forms that conflict with FV Antispam fake field name:'.$problematic_message.'. Please set a different fake field name <a href="'.get_bloginfo( 'wpurl' ).'/wp-admin/options-general.php?page=fv-antispam/fv-antispam.php">here</a>. <a href="http://foliovision.com/seo-tools/wordpress/plugins/fv-antispam/filled-in-protection">Read more</a> about this issue.</p></div>'; 

    }
  }
  
  
  function flag_comment_request($comment, $is_ping = false) { ///  action part - moves to spam or deletes
    $this->update_spam_count();
    add_filter( 'pre_comment_approved', array( $this, 'i_am_spam' ) );
    return $comment;
  }
  

  /**
    * Sends comment to spam based on the antispam check and blacklist check
    * 
    * @param int Comment ID.  
    * 
    */ 
  function fv_blacklist_to_trash_post( $id ) {
    $spam_remove = true;//!$this->get_plugin_option('flag_spam');
    $commentdata = get_comment( $id );
    
    if( $this->get_plugin_option('trash_banned') ) {
      $res = wp_blacklist_check($commentdata->comment_author, $commentdata->comment_author_email, $commentdata->comment_author_url, $commentdata->comment_content, $commentdata->comment_author_IP, $commentdata->comment_agent);
    }
    if( $spam_remove ) {
      $res2 = $_POST['bee_spam'];
    }
      
    if ( $res || $res2 == 1 ) {
      wp_set_comment_status( $id, 'trash' );
      //$fp = fopen( 'trashedforreal', 'a' );
      //fwrite( $fp, "\n: ".$id.' - '.var_export( $commentdata, true )."\n" );
    }
     
  }
  
  
  function get_admin_page($page) {
    if (empty($page)) {
      return;
    }
    if (function_exists('admin_url')) {
      return admin_url($page);
    }
    return (get_option('siteurl'). '/wp-admin/' .$page);
  }
  
  
  /**
    * Returns name of the fake field.          
    * 
    * @return string Fake field name.
    */    
  function get_filled_in_fake_field_name() {
    $name = $this->get_plugin_option('protect_filledin_field');
    if( !$name ) {
      $name = 'comment';
    }
    return $name;
  }
    
  
  function get_plugin_option($field) {
    if (!$options = wp_cache_get('fv_antispam')) {
      $options = get_option('fv_antispam');
      wp_cache_set( 'fv_antispam', $options );
    }
    return @$options[$field];
  }
  
  
  function get_spam_count() {
    return number_format_i18n(
      $this->get_plugin_option('spam_count')
    );
  }
  
    
  function i_am_spam($approved) { ///  moves to spam
    return 'spam';
  }
  
  
  /**
    * Change the request to show only comments, it no type specified
    * 
    * @global array $_GET, $_SERVER
    *                              
    */   
  function in_admin_header() {
    if( stripos( $_SERVER['REQUEST_URI'], 'edit-comments.php' ) !== FALSE ) {
      if( !isset($_GET['comment_type'] ) ) {
        $_GET['comment_type'] = 'comment';
      }
    }
  }
  
  
  function init_admin_menu() {
    add_options_page( 'FV Antispam', 'FV Antispam', ($this->is_min_wp('2.8') ? 'manage_options' : 9), __FILE__, array( $this, 'show_admin_menu' ) );
  }
  
  
  function init_action_links($links, $file) {
    if ($this->basename == $file) {
      return array_merge( array( sprintf( '<a href="options-general.php?page=%s">%s</a>', $this->basename, __('Settings') ) ), $links );
    }
    return $links;
  }
  
  
  function init_plugin_options() {
    add_option( 'fv_antispam', array( 'trash_banned' => true, 'protect_filledin' => true, 'spam_registrations' => true ) );
  }
  
  
  function init_row_meta($links, $file) {
    if ($this->basename == $file) {
      return array_merge( $links, array( sprintf( '<a href="options-general.php?page=%s">%s</a>', $this->basename,  __('Settings') ) ) );
    }
    return $links;
  }
  
  
  function is_current_page($page) {
    switch($page) {
      case 'home':
        return (isset($_REQUEST['page']) && $_REQUEST['page'] == $this->basename);
      case 'index':
      case 'plugins':
        return ($GLOBALS['pagenow'] == sprintf('%s.php', $page));
    }
    return false;
  }
    
    
  function is_min_wp($version) {
    return version_compare( $GLOBALS['wp_version'], $version. 'alpha', '>=' );
  }

  
  function is_wp_touch() {
    return strpos(TEMPLATEPATH, 'wptouch');
  }
  
  
  function load_plugin_lang() {
    if ($this->is_min_wp('2.7')) {
      load_plugin_textdomain( 'antispam_bee', false, 'fv-antispam/lang' );
    } else {
      if (!defined('PLUGINDIR')) {
        define('PLUGINDIR', 'wp-content/plugins');
      }
      load_plugin_textdomain( 'antispam_bee', sprintf( '%s/fv-antispam/lang', PLUGINDIR ) );
    }
  }
  
  
  function precheck_comment_request() { ///  detect spam here
    if (is_feed() || is_trackback() || $this->is_wp_touch()) {
      return;
    }
    $request_url = @$_SERVER['REQUEST_URI'];
    $hidden_field = @$_POST['comment'];
    $plugin_field = @$_POST[$this->protect($_POST['comment_post_ID'])];
    if (empty($_POST) || empty($request_url) || strpos($request_url, 'wp-comments-post.php') === false) {
      return;
    }
    if (empty($hidden_field) && !empty($plugin_field)) {
      $_POST['comment'] = $plugin_field;
      unset($_POST[$this->protect($_POST['comment_post_ID'])]);
    } else {
      $_POST['bee_spam'] = 1;
    }
  }
  
  
  /**
    * Generate the unique hash key.
    * 
    * @param int $postID Current post ID.             
    * 
    * @return string Hash key.
    */    
  static function protect($postID) {
    $postID = 0;  //  some templates are not able to give us the post ID when submitting comment, so we turn this of for now
    return 'a'.substr(md5(get_bloginfo('url').$postID), 0, 8);
  }
  
  
  /**
    * Adds fake field.
    * 
    * @global object Current post object.                
    * 
    */ 
  function replace_comment_field() {
    if (is_feed() || is_trackback() || $this->is_wp_touch()) {
      return;
    }
    if (!is_singular() /*&& !$this->get_plugin_option('always_allowed')*/ ) {
      return;
    }
    global $post;
    ob_start(
      create_function(
      '$input',
      /*
      'return preg_replace("#<textarea(.*?)name=([\"\'])comment([\"\'])(.+?)</textarea>#s", "<textarea$1name=$2' .$this->protect. '$3$4</textarea><textarea name=\"comment\" rows=\"1\" cols=\"1\" style=\"display:none\"></textarea>", $input, 1);'*/
      /*'return preg_replace("#<textarea(.*?)name=([\"\'])comment([\"\'])(.+?)</textarea>#s", "<textarea$1name=$2' .$this->protect($post->ID). '$3$4</textarea><textarea name=\"comment\" rows=\"1\" cols=\"1\" class=\"comment-field\"></textarea><style>.comment-field { display: none; }</style>", $input, 1);'*/
      'return preg_replace_callback("#wp-comments-post.php\".*?(<textarea.*?(class=\".*?\")?.*?</textarea>)#s", "FV_Antispam::replace_textarea" , $input, 1);'
      )
    );
  }          
  
  /**
    * Callback which adds fake field.
    * 
    * @param string Page content from OB.
    * @global object Current post object.                
    * 
    * @return string New page content.
    */    
  static function replace_textarea( $match ) {
    global $post;

    preg_match( '/class=[\"\'](.*?)[\"\']/', $match[1], $class );
    preg_match( '/id=[\"\'](.*?)[\"\']/', $match[1], $id );
    preg_match( '/name=[\"\'](.*?)[\"\']/', $match[1], $name );

    $class = $class[1];
    $id = $id[1];
    $name = $name[1];
    
    if( !FV_Antispam::get_plugin_option('my_own_styling') ) {
      $css = '';
      if( $class != '' ) {
        //  this is no good for some templates
        //$css .= '.'.$class.' { display: none; } ';
      }
      if( $id != '' ) {
        $css .= '#'.$id.' { display: none !important; } ';
      }
      
      $css = '<style>'.$css.'</style>';
    }
    
    $new = preg_replace( '/id=[\'"]'.$id.'[\'"]/i', 'id="'.FV_Antispam::protect($post->ID).'"', $match[1] );
    $new = preg_replace( '/name=[\'"]'.$name.'[\'"]/i', 'name="'.FV_Antispam::protect($post->ID).'"', $new );
    $new = preg_replace( '/name=[\'"]'.$name.'[\'"]/i', 'name="'.FV_Antispam::protect($post->ID).'"', $new );
    
    $output = $match[0].'<!-- </form> -->'.$new.$css;
   
    return $output;
  }
      
  
  function set_plugin_option($field, $value) {
    if (empty($field)) {
      return;
    }
    $this->set_plugin_options( array( $field => $value ) );
  }
  
  
  function set_plugin_options($data) {
    if (empty($data)) {
      return;
    }
    $options = array_merge( (array)get_option('fv_antispam'), $data );
    delete_option( 'fv_antispam_filledin_conflict' );
    update_option( 'fv_antispam', $options );
    wp_cache_set( 'fv_antispam', $options );
  }
  

  function show_admin_menu() {
	
    $this->check_user_can();
    if (!empty($_POST)) {
    
    check_admin_referer('fvantispam');
    $options = array(
      //'flag_spam'=> (isset($_POST['antispam_bee_flag_spam']) ? (int)$_POST['antispam_bee_flag_spam'] : 0),
      'spam_registrations'=> (isset($_POST['spam_registrations']) ? (int)$_POST['spam_registrations'] : 0),
      'ignore_pings'=> (isset($_POST['antispam_bee_ignore_pings']) ? (int)$_POST['antispam_bee_ignore_pings'] : 0),
      //'ignore_filter'=> (isset($_POST['antispam_bee_ignore_filter']) ? (int)$_POST['antispam_bee_ignore_filter'] : 0),
      //'ignore_type'=> (isset($_POST['antispam_bee_ignore_type']) ? (int)$_POST['antispam_bee_ignore_type'] : 0),
      //'no_notice'=> (isset($_POST['antispam_bee_no_notice']) ? (int)$_POST['antispam_bee_no_notice'] : 0),
      //'email_notify'=> (isset($_POST['antispam_bee_email_notify']) ? (int)$_POST['antispam_bee_email_notify'] : 0),
      'cronjob_enable'=> (isset($_POST['cronjob_enable']) ? (int)$_POST['cronjob_enable'] : 0),
      //'cronjob_interval'=> (isset($_POST['antispam_bee_cronjob_interval']) ? (int)$_POST['antispam_bee_cronjob_interval'] : 0),
      //'dashboard_count'=> (isset($_POST['antispam_bee_dashboard_count']) ? (int)$_POST['antispam_bee_dashboard_count'] : 0),
      //'advanced_check'=> (isset($_POST['antispam_bee_advanced_check']) ? (int)$_POST['antispam_bee_advanced_check'] : 0),
      //'already_commented'=> (isset($_POST['antispam_bee_already_commented']) ? (int)$_POST['antispam_bee_already_commented'] : 0),
      //'always_allowed'=> (isset($_POST['antispam_bee_always_allowed']) ? (int)$_POST['antispam_bee_always_allowed'] : 0),
      ///
      'my_own_styling'=> (isset($_POST['my_own_styling']) ? (int)$_POST['my_own_styling'] : 0),
      'trash_banned'=> (isset($_POST['trash_banned']) ? (int)$_POST['trash_banned'] : 0),
      'protect_filledin'=> (isset($_POST['protect_filledin']) ? (int)$_POST['protect_filledin'] : 0),
      'protect_filledin_disable_notice'=> (isset($_POST['protect_filledin_disable_notice']) ? (int)$_POST['protect_filledin_disable_notice'] : 0),
      'protect_filledin_field'=> $_POST['protect_filledin_field'],
      'disable_pingback_notify'=> (isset($_POST['disable_pingback_notify']) ? (int)$_POST['disable_pingback_notify'] : 0),
      'pingback_notify_email'=> $_POST['pingback_notify_email'],
      'comment_status_links'=> (isset($_POST['comment_status_links']) ? (int)$_POST['comment_status_links'] : 0)
      ///
    );
    
    $this->set_plugin_options($options); ?>
    <div id="message" class="updated fade">
    <p>
    <strong>
    <?php _e('Settings saved.') ?>
    </strong>
    </p>
    </div>
    <?php } ?>
    <div class="wrap">
    <?php if ($this->is_min_wp('2.7')) { ?>
    <div id="icon-options-general" class="icon32"><br /></div>
    <?php }
 ?>
    <h2>FV Antispam</h2>
    
    <form method="post" action="">
      <?php wp_nonce_field('fvantispam') ?>
      <div id="poststuff" class="ui-sortable">
      <div class="postbox">
      <h3>
      <?php _e('Settings') ?>
      </h3>
      <div class="inside">
        <!--<table class="form-table">
          <tr>
            <td>
              <label for="antispam_bee_flag_spam">
              <input type="checkbox" name="antispam_bee_flag_spam" id="antispam_bee_flag_spam" value="1" <?php checked($this->get_plugin_option('flag_spam'), 1) ?> />
              <?php _e('Mark as Spam, do not delete', 'antispam_bee') ?> <?php $this->show_help_link('flag_spam') ?>
              </label>
            </td>
          </tr>
          <tr>
            <td class="shift">
              <input type="checkbox" name="antispam_bee_ignore_filter" id="antispam_bee_ignore_filter" value="1" <?php checked($this->get_plugin_option('ignore_filter'), 1) ?> />
              <?php _e('Limit on', 'antispam_bee') ?> <select name="antispam_bee_ignore_type"><?php foreach(array(1 => __('Comments'), 2 => __('Pings')) as $key => $value) {
              echo '<option value="' .$key. '" ';
              selected($this->get_plugin_option('ignore_type'), $key);
              echo '>' .$value. '</option>';
              } ?>
              </select> <?php $this->show_help_link('ignore_filter') ?>
            </td>
          </tr>

          <tr>
            <td class="shift">
              <label for="antispam_bee_no_notice">
              <input type="checkbox" name="antispam_bee_no_notice" id="antispam_bee_no_notice" value="1" <?php checked($this->get_plugin_option('no_notice'), 1) ?> />
              <?php _e('Hide the &quot;MARKED AS SPAM&quot; note', 'antispam_bee') ?>
              </label>
            </td>
          </tr>
          <tr>
            <td class="shift">
              <label for="antispam_bee_email_notify">
              <input type="checkbox" name="antispam_bee_email_notify" id="antispam_bee_email_notify" value="1" <?php checked($this->get_plugin_option('email_notify'), 1) ?> />
              <?php _e('Send an admin email when new spam item incoming', 'antispam_bee') ?>
              </label>
            </td>
          </tr>
        </table>-->
        <table class="form-table">
          <tr>
            <td>
              <label for="antispam_bee_ignore_pings">
              <input type="checkbox" name="antispam_bee_ignore_pings" id="antispam_bee_ignore_pings" value="1" <?php checked($this->get_plugin_option('ignore_pings'), 1) ?> />
              <?php _e('Do not check trackbacks / pingbacks', 'antispam_bee') ?>
              </label>
            </td>
          </tr>
          <?php if ($this->is_min_wp('2.7')) { ?>
            <!--<tr>
              <td>
                <label for="antispam_bee_dashboard_count">
                <input type="checkbox" name="antispam_bee_dashboard_count" id="antispam_bee_dashboard_count" value="1" <?php checked($this->get_plugin_option('dashboard_count'), 1) ?> />
                <?php _e('Display blocked comments count on the dashboard', 'antispam_bee') ?> <?php $this->show_help_link('dashboard_count') ?>
                </label>
              </td>
            </tr>-->
          <?php } ?>
          <!--<tr>
            <td>
              <label for="antispam_bee_advanced_check">
              <input type="checkbox" name="antispam_bee_advanced_check" id="antispam_bee_advanced_check" value="1" <?php checked($this->get_plugin_option('advanced_check'), 1) ?> />
              <?php _e('Enable stricter inspection for incomming comments', 'antispam_bee') ?> <?php $this->show_help_link('advanced_check') ?>
              </label>
            </td>
          </tr>-->
          <!--<tr>
            <td>
              <label for="antispam_bee_already_commented">
              <input type="checkbox" name="antispam_bee_already_commented" id="antispam_bee_already_commented" value="1" <?php checked($this->get_plugin_option('already_commented'), 1) ?> />
              <?php _e('Do not check for spam if the author has already commented and approved', 'antispam_bee') ?> <?php $this->show_help_link('already_commented') ?>
              </label>
            </td>
          </tr>-->
          <!--<tr>
            <td>
              <label for="antispam_bee_always_allowed">
              <input type="checkbox" name="antispam_bee_always_allowed" id="antispam_bee_always_allowed" value="1" <?php checked($this->get_plugin_option('always_allowed'), 1) ?> />
              <?php _e('Comments are also used outside of posts and pages', 'antispam_bee') ?> <?php $this->show_help_link('always_allowed') ?>
              </label>
            </td>
          </tr>-->
          <tr>
            <td>
              <label for="my_own_styling">
              <input type="checkbox" name="my_own_styling" id="my_own_styling" value="1" <?php checked($this->get_plugin_option('my_own_styling'), 1) ?> />
              <?php _e('I\'ll put in my own styling', 'antispam_bee') ?><span class="description">(Make sure that #comment is hidden in your CSS!)</span>
              </label>
            </td>
          </tr>
          <tr>
            <td>
              <label for="trash_banned">
              <input type="checkbox" name="trash_banned" id="trash_banned" value="1" <?php checked($this->get_plugin_option('trash_banned'), 1) ?> />
              <?php _e('Trash banned (blacklisted) comments, don\'t just mark them as spam', 'antispam_bee') ?>
              </label>
            </td>
          </tr>
          <tr>
            <td>
              <label for="spam_registrations">
              <input type="checkbox" name="spam_registrations" id="spam_registrations" value="1" <?php checked($this->get_plugin_option('spam_registrations'), 1) ?> />
              <?php _e('Protect the registration form', 'antispam_bee') ?>
              </label>
            </td>
          </tr>
          <tr>
            <td>
              <label for="comment_status_links">
              <input type="checkbox" name="comment_status_links" id="comment_status_links" value="1" <?php checked($this->get_plugin_option('comment_status_links'), 1) ?> />
              <?php _e('Enhance Wordpress Admin Comments section', 'antispam_bee') ?> <span class="description">Hides trackbacks and shows separate counts for comments and trackbacks</span>
              </label>
            </td>
          </tr>            
          <tr>
            <td>
              <label for="cronjob_enable">
              <input type="checkbox" name="cronjob_enable" id="cronjob_enable" value="1" <?php checked($this->get_plugin_option('cronjob_enable'), 1) ?> />
              Remove trash comments older than 30 days
              </label>
            </td>
          </tr>                  
          <tr>
            <td>
              <div class="postbox">
                <h3>Pingback/trackback notification tweaks</h3>
                <div class="inside">
                  <table class="form-table">
                    <tr>
                      <td>
                      Enter alternative email address for pingback and trackback notifications<br />
                        <label for="pingback_notify_email">
                        <input type="text" class="regular-text" name="pingback_notify_email" id="pingback_notify_email" value="<?php if( function_exists( 'esc_attr' ) ) echo esc_attr( $this->get_plugin_option('pingback_notify_email') ); else echo ( $this->get_plugin_option('pingback_notify_email') ); ?>" />
                        <span class="description"><?php _e('Leave empty if you want to use the default address from General Settings', 'antispam_bee') ?> <?php $this->show_help_link('disable_pingback_notify') ?></span>
                        </label>
                      </td>
                    </tr>
                    <tr>
                      <td>
                      Or<br />
                        <label for="disable_pingback_notify">
                        <input type="checkbox" name="disable_pingback_notify" id="disable_pingback_notify" value="1" <?php checked($this->get_plugin_option('disable_pingback_notify'), 1) ?> />
                        <?php _e('Disable notifications for pingbacks and trackbacks', 'antispam_bee') ?> 
                        </label>
                      </td>
                    </tr>           
                  </table>
                </div>
              </div>
            </td>
          </tr>
          <tr>
            <td>
              <div class="postbox">
                <h3>Filled In forms spam protection</h3>
                <div class="inside">
                  <table class="form-table">
                    <tr>
                      <td>
                        <label for="protect_filledin">
                        <input type="checkbox" name="protect_filledin" id="protect_filledin" value="1" <?php checked($this->get_plugin_option('protect_filledin'), 1) ?> />
                        <?php _e('Protect Filled in forms', 'antispam_bee') ?>
                        </label>
                      </td>
                    </tr>
                    <tr>
                      <td>
                      Enter fake field name<br />
                        <label for="protect_filledin_field">
                        <input type="text" class="regular-text" name="protect_filledin_field" id="protect_filledin_field" value="<?php if( function_exists( 'esc_attr' ) ) echo esc_attr( $this->get_plugin_option('protect_filledin_field') ); else echo ( $this->get_plugin_option('protect_filledin_field') ); ?>" />
                        <span class="description"><?php _e('Leave empty if you want to use the default', 'antispam_bee') ?> <?php $this->show_help_link('protect_filledin_field') ?></span>
                        </label>
                      </td>
                    </tr>
                    <tr>
                      <td>
                        <label for="protect_filledin_disable_notice">
                        <input type="checkbox" name="protect_filledin_disable_notice" id="protect_filledin_disable_notice" value="1" <?php checked($this->get_plugin_option('protect_filledin_disable_notice'), 1) ?> />
                        <?php _e('Disable protection notice', 'antispam_bee') ?> <span class="description"><?php _e('(Logged in administrators normall see a notice that FV Antispam is protecting a Filled in form)', 'antispam_bee') ?></span>
                        </label>
                      </td>
                    </tr>
                    <tr>
                      <td>
                        <?php
                        $problems = get_option( 'fv_antispam_filledin_conflict' );
                        if( $problems ) {
                          $this->filled_in_collision_check( true );
                        }
                        else if( $problems === false ) {
                          $this->filled_in_collision_check( true );
                        } else {
                          ?>No conflicts with Filled In detected<?php
                        }
                        ?>
                      </td>
                    </tr>
                             
                  </table>
                </div>
              </div>
            </td>
          </tr>
        </table>
        <p>
        <input type="submit" name="fv_antispam_submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
        </p>
      </div>
      </div>
      </div>
    </form>
    </div>
  <?php }
  
  
  function show_dashboard_count() {
    if( $this->is_min_wp( '3.0' ) ) {
      echo sprintf(
      '<tr>
      <td class="b b-spam" style="font-size:18px">%s</td>
      <td class="last t">%s</td>
      </tr>',
      $this->get_spam_count(),
      __('Blocked (<abbr title="Number of spam comments blocked by FV Antispam">?</a>)', 'antispam_bee')
      );
    } else {
      echo sprintf(
      '<tr>
      <td class="first b b-tags"></td>
      <td class="t tags"></td>
      <td class="b b-spam" style="font-size:18px">%s</td>
      <td class="last t">%s</td>
      </tr>',
      $this->get_spam_count(),
      __('Blocked (<abbr title="Number of spam comments blocked by FV Antispam">?</a>)', 'antispam_bee')
      );
    }
  }
  
  
  function show_help_link($anchor) {
  }
    
  
  function show_plugin_head() {
    wp_enqueue_script('jquery'); ?>
    <style type="text/css">
    <?php if ($this->is_min_wp('2.7')) { ?>
    div.less {
      background: none;
    }
    <?php } ?>
    select {
      margin: 0 0 -3px;
    }
    input.small-text {
      margin: -5px 0;
    }
    td.shift {
      padding-left: 30px;
    }
    </style>
    <script type="text/javascript">
    jQuery(document).ready(
      function($) {
        function manage_options() {
          var id = 'antispam_bee_flag_spam';
          $('#' + id).parents('.form-table').find('input[id!="' + id + '"]').attr('disabled', !$('#' + id).attr('checked'));
        }
        $('#antispam_bee_flag_spam').click(manage_options);
        manage_options();
      }
    );
    </script>
  <?php }
  
  
  /**
    * Shows unapproved comments bellow posts if user can moderate_comments. Hooked to comments_array. In WP, all the unapproved comments are shown both to contributors and authors in wp-admin, but we don't do that in frontend.
    * 
    * @param string $content Post content.             
    * 
    * @return string Content with fake field added.
    */    
  function the_content( $content ) {
    if( stripos( $content, '<input type="hidden" name="filled_in_form" ' ) === FALSE ) {  //  first check if there's any filled in form
      return $content;
    }
    preg_match_all( '~<form[\s\S]*?</form>~', $content, $forms );
    
    foreach( $forms[0] AS $form ) {
      
      if( current_user_can('manage_options') && !$this->get_plugin_option('protect_filledin_disable_notice') ) {
        $protection_notice = '<p><small>(Note for WP Admins: Form Protected by <a href="http://foliovision.com/seo-tools/wordpress/plugins/fv-antispam/filled-in-protection">FV Antispam</a>)</small></p>';
      }
      
      if( !FV_Antispam::get_plugin_option('my_own_styling') ) {

        $css = '#'.$this->get_filled_in_fake_field_name().' { display: none; } ';

        $css = '<style>'.$css.'</style>';
      }
      
      $form_protected = preg_replace( '~(<form[\s\S]*?)(<input type="hidden" name="filled_in_form" value="\d+"/>)([\s\S]*?)(<[^<]*?submit)([\s\S]*?</form>)~', $protection_notice.'$1$2$3<textarea id="'.$this->get_filled_in_fake_field_name().'" name="'.$this->get_filled_in_fake_field_name().'" rows="12" cols="40"></textarea>'.$css."\n".'$4$5', $form );
      $content = str_replace( $form, $form_protected, $content );
    }

    return $content;
  }
  
  
  function update_spam_count() {
    $this->set_plugin_option( 'spam_count', intval($this->get_plugin_option('spam_count') + 1) );
  }
  
  
  function verify_comment_request($comment) { ///  detect spam here

    $request_url = @$_SERVER['REQUEST_URI'];
    $request_ip = @$_SERVER['REMOTE_ADDR'];
    if (empty($request_url) || empty($request_ip)) {
      return $this->flag_comment_request($comment);
    }
    $comment_type = @$comment['comment_type'];
    
    $comment_url = @$comment['comment_author_url'];
    $comment_body = @$comment['comment_content'];
    $comment_email = @$comment['comment_author_email'];
    $ping_types = array('pingback', 'trackback', 'pings');
    /// Global WP setting for closing ping overrides individual post setting in old WP
    if ($this->is_min_wp('2.7')) {
    }
    else if( in_array($comment_type, $ping_types) && get_option( 'default_ping_status' ) == 'closed' ) {
      die( '<response><error>1</error><message>Sorry, trackbacks are closed for this item.</message></response>' );
    }
    ///
    $ping_allowed = !$this->get_plugin_option('ignore_pings');
    if (!empty($comment_url)) {
      $comment_parse = @parse_url($comment_url);
      $comment_host = @$comment_parse['host'];
    }
    if (strpos($request_url, 'wp-comments-post.php') !== false && !empty($_POST)) {
      if ($this->get_plugin_option('already_commented') && 1<0 ) {  ///  if comment author has an approved comment, turned OFF
        if ($GLOBALS['wpdb']->get_var("SELECT COUNT(comment_ID) FROM `" .$GLOBALS['wpdb']->comments. "` WHERE `comment_author_email` = '" .$comment_email. "' AND `comment_approved` = '1' LIMIT 1")) {
          return $comment;
        }
      }
      if (!empty($_POST['bee_spam'])) { //  check the fake field
        return $this->flag_comment_request($comment);
      }
      if ($this->get_plugin_option('advanced_check') && 1<0) { //  advanced check based on IP addres, turned OFF
        if (strpos($request_ip, $this->cut_ip_address(gethostbyname(gethostbyaddr($request_ip)))) === false) {
          return $this->flag_comment_request($comment);
        }
      }
    } else if (!empty($comment_type) && in_array($comment_type, $ping_types) && $ping_allowed) {
      if (empty($comment_url) || empty($comment_body)) {
        return $this->flag_comment_request($comment, true);
      } else if (!empty($comment_host) && gethostbyname($comment_host) != $request_ip) {  //  check pingback sender site vs. IP
        return $this->flag_comment_request($comment, true);
      }
    }
    return $comment;
  }
 
  
  function send_email_notify($comment) {
    $email = get_bloginfo('admin_email');
    $blog = get_bloginfo('name');
    $body = @$comment['comment_content'];
    if (empty($email) || empty($blog) || empty($body)) {
      return;
    }
    $body = stripslashes(strip_tags($body));
    $this->load_plugin_lang();
    wp_mail( $email, sprintf( '[%s] %s', $blog,  __('Comment marked as spam', 'antispam_bee') ), 
      sprintf( "%s\n\n%s: %s", $body, __('Spam list', 'antispam_bee'), $this->get_admin_page('edit-comments.php?comment_status=spam')
    )
    );
  }
  
  
  function the_spam_count() {
    echo $this->get_spam_count();
  }
  
  function protect_spam_registrations_style() {
    ?>
    <style type="text/css">
      #user_email {display: none;}
    </style>      
    <?php
  }
  
  function replace_email_registration_field_start() {            
    ob_start();
  }
  
  function replace_email_registration_field_flush() {
    $html = ob_get_clean();
    if (current_user_can('manage_options')) {
      $protection_notice = '<p class="message"><small>(Note for WP Admins: Form Protected by <a href="' . site_url() . '/wp-admin/options-general.php?page=fv-antispam/fv-antispam.php">FV Antispam</a>)</small></p>';
      $html = preg_replace("~(<\/h1>)\s*?(.*?)\s*?(<form)~", '$1$2' . $protection_notice . '$3', $html);
    }       
    echo preg_replace("~(<input.*?name\=\"user_email\".*?\/>)~", '$1<input type="email" name="' . $this->protect(-1) . '" id="user_email_" class="input" value="' . (isset($_POST[$this->protect(-1)]) ? $_POST[$this->protect(-1)] : "") . '" size="25" tabindex="20" />' , $html);    
  }
  
  function protect_spam_registrations_check() {
    if (isset($_POST['user_email'])) {          
      if ($_POST['user_email'] == "") {
        $_POST['user_email'] = $_POST[$this->protect(-1)];
      }
      else {              
        //write to log
        $file = "spam.log";
        $fh = fopen($file, 'a'); 
        fwrite($fh, implode(", ", $_POST) . ", " . date("Ymd") . "\n");        
        fclose($fh);
        $_POST['user_email'] = "";
      }
    }    
  }
  
  function replace_message_field_flush() {
    $html = ob_get_clean();
    if (current_user_can('manage_options')) {      
      $protection_notice = '<p class="message"><small>(Note for WP Admins: Form Protected by <a href="' . site_url() . '/wp-admin/options-general.php?page=fv-antispam/fv-antispam.php">FV Antispam</a>)</small></p>';
      $html = preg_replace("~(<\/h1>)\s*?(.*?)\s*?(<form)~", '$1$2' . $protection_notice . '$3', $html);
    }
    echo $html;
  }
  
  public function InitiateCron() {
    if( !wp_next_scheduled( 'fv_clean_trash_hourly' ) ){
      wp_schedule_event( time(), 'hourly', 'fv_clean_trash_hourly' );
    }
  }
  
  public function clean_comments_trash() {
    global $wpdb;
    
    if( !$this->get_plugin_option('cronjob_enable') ) {
      return;
    }
    
    $date = date('Y-m-d H:i:s' ,mktime(0, 0, 0, date("m")-1, date("d"), date("Y")));
    $comments = $wpdb->get_results("SELECT comment_ID FROM $wpdb->comments WHERE comment_date_gmt < ' $date ' AND comment_approved = 'trash' LIMIT 1000", ARRAY_N);
    
    $comments_imploded = '';
    foreach($comments as $comment) {
      $comments_imploded .= $comment[0] . ',';      
    }
    $comments_imploded = substr($comments_imploded, 0, -1);
    
    $wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id IN ($comments_imploded)");
    $wpdb->query("DELETE FROM $wpdb->comments WHERE comment_ID IN ($comments_imploded)");                
  }    
  
}

$GLOBALS['FV_Antispam'] = new FV_Antispam();

/*
Extra
*/
if ( !function_exists('wp_notify_moderator') && ( $GLOBALS['FV_Antispam']->get_plugin_option('disable_pingback_notify') || $GLOBALS['FV_Antispam']->get_plugin_option('pingback_notify_email') ) ) :
/**
 * wp_notify_moderator function modified to skip notifications for trackback and pingback type comments
 *
 * @param int $comment_id Comment ID
 * @return bool Always returns true
 */
 
  if( $GLOBALS['FV_Antispam']->is_min_wp('2.5') ) : 
   
  function wp_notify_moderator($comment_id) {
  	global $wpdb;
  
  	if( get_option( "moderation_notify" ) == 0 )
  		return true;
  
  	$comment = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->comments WHERE comment_ID=%d LIMIT 1", $comment_id));
  	$post = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE ID=%d LIMIT 1", $comment->comment_post_ID));
  
  	$comment_author_domain = @gethostbyaddr($comment->comment_author_IP);
  	$comments_waiting = $wpdb->get_var("SELECT count(comment_ID) FROM $wpdb->comments WHERE comment_approved = '0'");
  
  	// The blogname option is escaped with esc_html on the way into the database in sanitize_option
  	// we want to reverse this for the plain text arena of emails.
  	$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
  
  	switch ($comment->comment_type)
  	{
  		case 'trackback':
  		  if( $GLOBALS['FV_Antispam']->get_plugin_option('disable_pingback_notify') ) {
  		    return true;
  		  }
  			$notify_message  = sprintf( __('A new trackback on the post "%s" is waiting for your approval'), $post->post_title ) . "\r\n";
  			$notify_message .= get_permalink($comment->comment_post_ID) . "\r\n\r\n";
  			$notify_message .= sprintf( __('Website : %1$s (IP: %2$s , %3$s)'), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
  			$notify_message .= sprintf( __('URL    : %s'), $comment->comment_author_url ) . "\r\n";
  			$notify_message .= __('Trackback excerpt: ') . "\r\n" . $comment->comment_content . "\r\n\r\n";
  			break;
  		case 'pingback':
  		  if( $GLOBALS['FV_Antispam']->get_plugin_option('disable_pingback_notify') ) {
  		    return true;
  		  }
  			$notify_message  = sprintf( __('A new pingback on the post "%s" is waiting for your approval'), $post->post_title ) . "\r\n";
  			$notify_message .= get_permalink($comment->comment_post_ID) . "\r\n\r\n";
  			$notify_message .= sprintf( __('Website : %1$s (IP: %2$s , %3$s)'), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
  			$notify_message .= sprintf( __('URL    : %s'), $comment->comment_author_url ) . "\r\n";
  			$notify_message .= __('Pingback excerpt: ') . "\r\n" . $comment->comment_content . "\r\n\r\n";
  			break;
  		default: //Comments
  			$notify_message  = sprintf( __('A new comment on the post "%s" is waiting for your approval'), $post->post_title ) . "\r\n";
  			$notify_message .= get_permalink($comment->comment_post_ID) . "\r\n\r\n";
  			$notify_message .= sprintf( __('Author : %1$s (IP: %2$s , %3$s)'), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
  			$notify_message .= sprintf( __('E-mail : %s'), $comment->comment_author_email ) . "\r\n";
  			$notify_message .= sprintf( __('URL    : %s'), $comment->comment_author_url ) . "\r\n";
  			$notify_message .= sprintf( __('Whois  : http://ws.arin.net/cgi-bin/whois.pl?queryinput=%s'), $comment->comment_author_IP ) . "\r\n";
  			$notify_message .= __('Comment: ') . "\r\n" . $comment->comment_content . "\r\n\r\n";
  			break;
  	}
  
  	$notify_message .= sprintf( __('Approve it: %s'),  admin_url("comment.php?action=approve&c=$comment_id") ) . "\r\n";
  	if ( EMPTY_TRASH_DAYS )
  		$notify_message .= sprintf( __('Trash it: %s'), admin_url("comment.php?action=trash&c=$comment_id") ) . "\r\n";
  	else
  		$notify_message .= sprintf( __('Delete it: %s'), admin_url("comment.php?action=delete&c=$comment_id") ) . "\r\n";
  	$notify_message .= sprintf( __('Spam it: %s'), admin_url("comment.php?action=spam&c=$comment_id") ) . "\r\n";
  
  	$notify_message .= sprintf( _n('Currently %s comment is waiting for approval. Please visit the moderation panel:',
   		'Currently %s comments are waiting for approval. Please visit the moderation panel:', $comments_waiting), number_format_i18n($comments_waiting) ) . "\r\n";
  	$notify_message .= admin_url("edit-comments.php?comment_status=moderated") . "\r\n";
  
  	$subject = sprintf( __('[%1$s] Please moderate: "%2$s"'), $blogname, $post->post_title );
  	$admin_email = get_option('admin_email');
  	
  	if( $GLOBALS['FV_Antispam']->get_plugin_option('pingback_notify_email') && ( $comment->comment_type == 'trackback' || $comment->comment_type == 'pingback' ) ) {
  	   $admin_email = $GLOBALS['FV_Antispam']->get_plugin_option('pingback_notify_email');
    }
  	
  	$message_headers = '';
  
  	$notify_message = apply_filters('comment_moderation_text', $notify_message, $comment_id);
  	$subject = apply_filters('comment_moderation_subject', $subject, $comment_id);
  	$message_headers = apply_filters('comment_moderation_headers', $message_headers);
  
  	@wp_mail($admin_email, $subject, $notify_message, $message_headers);
  
  	return true;
  }
  
  else :
  /// This function is used for Wordpress < 2.5
  function wp_notify_moderator($comment_id) {
  	global $wpdb;
  
  	if( get_option( "moderation_notify" ) == 0 )
  		return true; 
  		
  	if( $comment->comment_type == 'pingback' || $comment->comment_type == 'trackback' ) {
  	  if( $GLOBALS['FV_Antispam']->get_plugin_option('disable_pingback_notify') ) {
		    return true;
		  }
  	}
      
  	$comment = $wpdb->get_row("SELECT * FROM $wpdb->comments WHERE comment_ID='$comment_id' LIMIT 1");
  	$post = $wpdb->get_row("SELECT * FROM $wpdb->posts WHERE ID='$comment->comment_post_ID' LIMIT 1");
  
  	$comment_author_domain = @gethostbyaddr($comment->comment_author_IP);
  	$comments_waiting = $wpdb->get_var("SELECT count(comment_ID) FROM $wpdb->comments WHERE comment_approved = '0'");
  
  	$notify_message  = sprintf( __('A new comment on the post #%1$s "%2$s" is waiting for your approval'), $post->ID, $post->post_title ) . "\r\n";
  	$notify_message .= get_permalink($comment->comment_post_ID) . "\r\n\r\n";
  	$notify_message .= sprintf( __('Author : %1$s (IP: %2$s , %3$s)'), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
  	$notify_message .= sprintf( __('E-mail : %s'), $comment->comment_author_email ) . "\r\n";
  	$notify_message .= sprintf( __('URL    : %s'), $comment->comment_author_url ) . "\r\n";
  	$notify_message .= sprintf( __('Whois  : http://ws.arin.net/cgi-bin/whois.pl?queryinput=%s'), $comment->comment_author_IP ) . "\r\n";
  	$notify_message .= __('Comment: ') . "\r\n" . $comment->comment_content . "\r\n\r\n";
  	$notify_message .= sprintf( __('Approve it: %s'),  get_option('siteurl')."/wp-admin/comment.php?action=mac&c=$comment_id" ) . "\r\n";
  	$notify_message .= sprintf( __('Delete it: %s'), get_option('siteurl')."/wp-admin/comment.php?action=cdc&c=$comment_id" ) . "\r\n";
  	$notify_message .= sprintf( __('Spam it: %s'), get_option('siteurl')."/wp-admin/comment.php?action=cdc&dt=spam&c=$comment_id" ) . "\r\n";
  	$notify_message .= sprintf( __('Currently %s comments are waiting for approval. Please visit the moderation panel:'), $comments_waiting ) . "\r\n";
  	$notify_message .= get_option('siteurl') . "/wp-admin/moderation.php\r\n";
  
  	$subject = sprintf( __('[%1$s] Please moderate: "%2$s"'), get_option('blogname'), $post->post_title );
  	$admin_email = get_option('admin_email');
  	
  	if( $GLOBALS['FV_Antispam']->get_plugin_option('pingback_notify_email') && ( $comment->comment_type == 'trackback' || $comment->comment_type == 'pingback' ) ) {
  	   $admin_email = $GLOBALS['FV_Antispam']->get_plugin_option('pingback_notify_email');
    }
  
  	$notify_message = apply_filters('comment_moderation_text', $notify_message, $comment_id);
  	$subject = apply_filters('comment_moderation_subject', $subject, $comment_id);
  
  	@wp_mail($admin_email, $subject, $notify_message);
  
  	return true;
  }
  
  endif;

endif;

?>
