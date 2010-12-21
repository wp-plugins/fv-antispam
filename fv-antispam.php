<?php
/*
Plugin Name: FV Antispam
Plugin URI: http://foliovision.com/seo-tools/wordpress/plugins/fv-antispam
Description: Powerful and simple antispam plugin. Puts all the spambot comments directly into trash and let's other plugins (Akismet) deal with the rest.
Author: Foliovision
Version: 1.8.2
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
        /*deleteif (!$this->is_min_wp('2.3')) {
          add_action( 'admin_notices', array( $this, 'show_plugin_notices' ) );
        }*/
        add_action( 'activate_' .$this->basename, array( $this, 'init_plugin_options' ) );
        //add_action( 'deactivate_' .$this->basename, array( $this, 'clear_scheduled_hook' ) );
        if ($this->is_min_wp('2.8')) {
          add_filter( 'plugin_row_meta', array( $this, 'init_row_meta' ), 10, 2 );
        } else {
          add_filter( 'plugin_action_links', array( $this, 'init_action_links' ), 10, 2 );
        }
      }
    } else {
      ///
      add_action( 'comment_post', array( $this, 'fv_blacklist_to_trash_post' ), 1000 ); //  all you need
      ///
      add_action( 'template_redirect', array( $this, 'replace_comment_field' ) );
      add_action( 'init', array( $this, 'precheck_comment_request' ), 0 );
      add_action( 'preprocess_comment', array( $this, 'verify_comment_request' ), 1 );
      add_action( 'antispam_bee_count', array( $this, 'the_spam_count' ) );
      /*if ($this->get_plugin_option('cronjob_enable')) {
        add_action( 'antispam_bee_daily_cronjob', array( $this, 'exe_daily_cronjob' ) );
      }*/
    }
  }
  
  ///
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
  ///
  
  
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
  
  function init_action_links($links, $file) {
    if ($this->basename == $file) {
      return array_merge( array( sprintf( '<a href="options-general.php?page=%s">%s</a>', $this->basename, __('Settings') ) ), $links );
    }
    return $links;
  }
  
  function init_row_meta($links, $file) {
    if ($this->basename == $file) {
      return array_merge( $links, array( sprintf( '<a href="options-general.php?page=%s">%s</a>', $this->basename,  __('Settings') ) ) );
    }
    return $links;
  }
  
  function init_plugin_options() {
    add_option( 'fv_antispam', array( 'trash_banned' => true ) );
    /*$this->migrate_old_options();
    if ($this->get_plugin_option('cronjob_enable')) {
      $this->init_scheduled_hook();
    }*/
  }
  
  /*function init_scheduled_hook() {
    if (function_exists('wp_schedule_event')) {
      if (!wp_next_scheduled('antispam_bee_daily_cronjob')) {
        wp_schedule_event(time(), 'daily', 'antispam_bee_daily_cronjob');
      }
    }
  }*/
  
  ///
  static function protect($postID) {
    $postID = 0;  //  some templates are not able to give us the post ID when submitting comment, so we turn this of for now
    return 'a'.substr(md5(get_bloginfo('url').$postID), 0, 8);
  }
  ///
  
  /*function clear_scheduled_hook() {
    if (function_exists('wp_schedule_event')) {
      if (wp_next_scheduled('antispam_bee_daily_cronjob')) {
        wp_clear_scheduled_hook('antispam_bee_daily_cronjob');
      }
    }
  }*/
  
  function get_plugin_option($field) {
    if (!$options = wp_cache_get('fv_antispam')) {
      $options = get_option('fv_antispam');
      wp_cache_set( 'fv_antispam', $options );
    }
    return @$options[$field];
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
    update_option( 'fv_antispam', $options );
    wp_cache_set( 'fv_antispam', $options );
  }
  
  /*function migrate_old_options() {
    if (get_option('antispam_bee_cronjob_timestamp') === false) {
      return;
    }
    $fields = array(
      'flag_spam',
      'ignore_pings',
      'ignore_filter',
      'ignore_type',
      'no_notice',
      'cronjob_enable',
      'cronjob_interval',
      'cronjob_timestamp',
      'spam_count',
      'dashboard_count'
    );
    foreach($fields as $field) {
      $this->set_plugin_option( $field, get_option('antispam_bee_' .$field) );
    }
    $GLOBALS['wpdb']->query("DELETE FROM `" .$GLOBALS['wpdb']->options. "` WHERE option_name LIKE 'antispam_bee_%'");
    $GLOBALS['wpdb']->query("OPTIMIZE TABLE `" .$GLOBALS['wpdb']->options. "`");
  }*/
  
  function init_admin_menu() {
    add_options_page( 'FV Antispam', 'FV Antispam', ($this->is_min_wp('2.8') ? 'manage_options' : 9), __FILE__, array( $this, 'show_admin_menu' ) );
  }
  
  function exe_daily_cronjob() {
    $this->delete_spam_comments();
    $this->set_plugin_option( 'cronjob_timestamp', time() );
  }
  
  function delete_spam_comments() {
    $days = intval($this->get_plugin_option('cronjob_interval'));
    if (empty($days)) {
      return false;
    }
    $GLOBALS['wpdb']->query( sprintf( "DELETE FROM %s WHERE comment_approved = 'spam' AND SUBDATE(NOW(), %s) > comment_date_gmt", $GLOBALS['wpdb']->comments, $days ) );
    $GLOBALS['wpdb']->query("OPTIMIZE TABLE `" .$GLOBALS['wpdb']->comments. "`");
  }
  
  function is_min_wp($version) {
    return version_compare( $GLOBALS['wp_version'], $version. 'alpha', '>=' );
  }
  
  function is_wp_touch() {
    return strpos(TEMPLATEPATH, 'wptouch');
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
  
  function check_user_can() {
    if (current_user_can('manage_options') === false || current_user_can('edit_plugins') === false || !is_user_logged_in()) {
      wp_die('You do not have permission to access!');
    }
  }
  
  function show_dashboard_count() {
    echo sprintf(
    '<tr>
    <td class="first b b-tags"></td>
    <td class="t tags"></td>
    <td class="b b-spam" style="font-size:18px">%s</td>
    <td class="last t">%s</td>
    </tr>',
    $this->get_spam_count(),
    __('Blocked', 'antispam_bee')
    );
  }
  
  function show_plugin_notices() {
  echo sprintf(
  '<div class="error"><p><strong>Antispam Bee</strong> %s</p></div>',
  __('requires at least WordPress 2.3', 'antispam_bee')
  );
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
  
  function cut_ip_address($ip) {
    if (!empty($ip)) {
      return str_replace( strrchr($ip, '.'), '', $ip );
    }
  }
  
  //  new textarea which is nearly the same + css
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
        $css .= '#'.$id.' { display: none; } ';
      }
      
      $css = '<style>'.$css.'</style>';
    }
    
    $new = preg_replace( '/id=[\'"]'.$id.'[\'"]/i', 'id="'.FV_Antispam::protect($post->ID).'"', $match[1] );
    $new = preg_replace( '/name=[\'"]'.$name.'[\'"]/i', 'name="'.FV_Antispam::protect($post->ID).'"', $new );
    $new = preg_replace( '/name=[\'"]'.$name.'[\'"]/i', 'name="'.FV_Antispam::protect($post->ID).'"', $new );
    
    return $match[0].'<!-- </form> -->'.$new.$css;
  }
  
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
  
  function flag_comment_request($comment, $is_ping = false) { ///  action part - moves to spam or deletes
    $this->update_spam_count();
    add_filter( 'pre_comment_approved', array( $this, 'i_am_spam' ) );
    return $comment;
  }
  
  function i_am_spam($approved) { ///  moves to spam
    return 'spam';
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
  
  function get_spam_count() {
    return number_format_i18n(
      $this->get_plugin_option('spam_count')
    );
  }
  
  function the_spam_count() {
    echo $this->get_spam_count();
  }
  
  function update_spam_count() {
    $this->set_plugin_option( 'spam_count', intval($this->get_plugin_option('spam_count') + 1) );
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
  
  function show_admin_menu() {
  $this->check_user_can();
  if (!empty($_POST)) {
  check_admin_referer('antispam_bee');
  $options = array(
    //'flag_spam'=> (isset($_POST['antispam_bee_flag_spam']) ? (int)$_POST['antispam_bee_flag_spam'] : 0),
    'ignore_pings'=> (isset($_POST['antispam_bee_ignore_pings']) ? (int)$_POST['antispam_bee_ignore_pings'] : 0),
    //'ignore_filter'=> (isset($_POST['antispam_bee_ignore_filter']) ? (int)$_POST['antispam_bee_ignore_filter'] : 0),
    //'ignore_type'=> (isset($_POST['antispam_bee_ignore_type']) ? (int)$_POST['antispam_bee_ignore_type'] : 0),
    //'no_notice'=> (isset($_POST['antispam_bee_no_notice']) ? (int)$_POST['antispam_bee_no_notice'] : 0),
    //'email_notify'=> (isset($_POST['antispam_bee_email_notify']) ? (int)$_POST['antispam_bee_email_notify'] : 0),
    //'cronjob_enable'=> (isset($_POST['antispam_bee_cronjob_enable']) ? (int)$_POST['antispam_bee_cronjob_enable'] : 0),
    //'cronjob_interval'=> (isset($_POST['antispam_bee_cronjob_interval']) ? (int)$_POST['antispam_bee_cronjob_interval'] : 0),
    //'dashboard_count'=> (isset($_POST['antispam_bee_dashboard_count']) ? (int)$_POST['antispam_bee_dashboard_count'] : 0),
    //'advanced_check'=> (isset($_POST['antispam_bee_advanced_check']) ? (int)$_POST['antispam_bee_advanced_check'] : 0),
    //'already_commented'=> (isset($_POST['antispam_bee_already_commented']) ? (int)$_POST['antispam_bee_already_commented'] : 0),
    //'always_allowed'=> (isset($_POST['antispam_bee_always_allowed']) ? (int)$_POST['antispam_bee_always_allowed'] : 0),
    ///
    'my_own_styling'=> (isset($_POST['my_own_styling']) ? (int)$_POST['my_own_styling'] : 0),
    'trash_banned'=> (isset($_POST['trash_banned']) ? (int)$_POST['trash_banned'] : 0),
    'disable_pingback_notify'=> (isset($_POST['disable_pingback_notify']) ? (int)$_POST['disable_pingback_notify'] : 0),
    'pingback_notify_email'=> $_POST['pingback_notify_email']
    ///
  );
  if (empty($options['cronjob_interval'])) {
    $options['cronjob_enable'] = 0;
  }
  /*if ($options['cronjob_enable'] && !$this->get_plugin_option('cronjob_enable')) {
    $this->init_scheduled_hook();
  } else if (!$options['cronjob_enable'] && $this->get_plugin_option('cronjob_enable')) {
    $this->clear_scheduled_hook();
  }*/
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
  <?php } ?>
  <h2>FV Antispam</h2>
  <form method="post" action="">
    <?php wp_nonce_field('antispam_bee') ?>
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
            <input type="checkbox" name="antispam_bee_cronjob_enable" id="antispam_bee_cronjob_enable" value="1" <?php checked($this->get_plugin_option('cronjob_enable'), 1) ?> />
            <?php echo sprintf(__('Spam will be automatically deleted after %s days', 'antispam_bee'), '<input type="text" name="antispam_bee_cronjob_interval" value="' .$this->get_plugin_option('cronjob_interval'). '" class="small-text" />') ?>&nbsp;<?php $this->show_help_link('cronjob_enable') ?>
            <?php echo ($this->get_plugin_option('cronjob_timestamp') ? ('&nbsp;<span class="setting-description">(' .__('Last', 'antispam_bee'). ': '. date_i18n('d.m.Y H:i:s', $this->get_plugin_option('cronjob_timestamp')). ')</span>') : '') ?>
          </td>
        </tr>
        <tr>
          <td class="shift">
            <label for="antispam_bee_no_notice">
            <input type="checkbox" name="antispam_bee_no_notice" id="antispam_bee_no_notice" value="1" <?php checked($this->get_plugin_option('no_notice'), 1) ?> />
            <?php _e('Hide the &quot;MARKED AS SPAM&quot; note', 'antispam_bee') ?> <?php $this->show_help_link('no_notice') ?>
            </label>
          </td>
        </tr>
        <tr>
          <td class="shift">
            <label for="antispam_bee_email_notify">
            <input type="checkbox" name="antispam_bee_email_notify" id="antispam_bee_email_notify" value="1" <?php checked($this->get_plugin_option('email_notify'), 1) ?> />
            <?php _e('Send an admin email when new spam item incoming', 'antispam_bee') ?> <?php $this->show_help_link('email_notify') ?>
            </label>
          </td>
        </tr>
      </table>-->
      <table class="form-table">
        <tr>
          <td>
            <label for="antispam_bee_ignore_pings">
            <input type="checkbox" name="antispam_bee_ignore_pings" id="antispam_bee_ignore_pings" value="1" <?php checked($this->get_plugin_option('ignore_pings'), 1) ?> />
            <?php _e('Do not check trackbacks / pingbacks', 'antispam_bee') ?> <?php $this->show_help_link('ignore_pings') ?>
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
            <?php _e('I\'ll put in my own styling', 'antispam_bee') ?> <?php $this->show_help_link('my_own_styling') ?>
            </label>
          </td>
        </tr>
        <tr>
          <td>
            <label for="trash_banned">
            <input type="checkbox" name="trash_banned" id="trash_banned" value="1" <?php checked($this->get_plugin_option('trash_banned'), 1) ?> />
            <?php _e('Trash banned (blacklisted) comments, don\'t just mark them as spam', 'antispam_bee') ?> <?php $this->show_help_link('trash_banned') ?>
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
                      <?php _e('Disable notifications for pingbacks and trackbacks', 'antispam_bee') ?> <?php $this->show_help_link('disable_pingback_notify') ?>
                      </label>
                    </td>
                  </tr>           
                </table>
              </div>
            </div>
          </td>
        </tr>
      </table>
      <p>
      <input type="submit" name="antispam_bee_submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
      </p>
    </div>
    </div>
    </div>
  </form>
  </div>
  <?php }
}
$GLOBALS['FV_Antispam'] = new FV_Antispam();


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