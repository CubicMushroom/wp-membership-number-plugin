<?php
/*
Plugin Name: Cubic Mushroom Membership Numbers plugin
Plugin URI: http://cubicmushroom.co.uk
Description: A plugin to allow users to link user accounts with membership numbers
Version: 1.0
Author: Toby Griffiths - Cubic Mushroom Ltd.
Author URI: http://cubicmushroom.co.uk
License: GPL2
*/

/*  Copyright 2012  Cubic Mushroom Ltd.  (email : support@cubicmushroom.co.uk)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * Class to namespace the plugin functionality
 * 
 * @package       CM Membership Plugin
 * 
 * @since         v0.1
 * @author        Toby Griffiths <toby@cubicmushroom.co.uk>
 * @copyright     © 2012 Cubic Mushroom Ltd.
 */
class CMMembershipPlugin
{

  const PLUGIN_VERSION = '1.0';
  const OPTIONS_DB_KEY = 'cmmp_db_version';
  const DB_VERSION = '1.0';
  const AVAILABLE_NUMBERS_TABLE_NAME = 'cmmp_available_numbers';
  const MEMBERSHIP_NO_PREFIX = '3251';
  const USER_MEMBERSHIP_NO_META_KEY = 'cmmp_membership_no';
  const USER_MEMBERSHIP_STARTED_ON_META_KEY = 'cmmp_membership_started';

  /**
   * Property used to store whether the membership no entered was OK when saving user's profile
   * @var     bool
   */
  private $membership_no_not_available;

  /**
   * Property to store the actual table name
   * @var     string
   */
  private $available_number_table_name;

  function __construct()
  {
    global $wpdb;
    $this->available_number_table_name = $wpdb->prefix . self::AVAILABLE_NUMBERS_TABLE_NAME;

    // Plugin activation, deactivation & uninstall
    register_activation_hook(__FILE__, array($this, 'activate'));
    register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    register_uninstall_hook(__FILE__, array($this, 'uninstall'));

    // Initialise the plugin
    add_action('init', array($this, 'init'));
  }


  /**
   * Plugin activation method
   */
  function activate()
  {
    global $wpdb, $CMMP;
    $sql = "CREATE TABLE $CMMP->available_number_table_name (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      number varchar(32) NOT NULL,
      number_used boolean NOT NULL DEFAULT '0' COMMENT  'Whether this number has been used for a membership card',
      PRIMARY KEY  id (id)
    );";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Add the plugin version number
    add_option(self::OPTIONS_DB_KEY, self::DB_VERSION);
  }

  /**
   * Plugin uninstall method
   */
  function uninstall()
  {
    global $wpdb, $CMMP;
    $wpdb->query("DROP TABLE $CMMP->available_number_table_name;");

    // Add the plugin version number
    delete_option(self::OPTIONS_DB_KEY, self::DB_VERSION);
  }




  function init()
  {
    // Register styles
    wp_register_style('cmmp-admin', plugin_dir_url(__FILE__) . 'css/cmmp-style.css', array(), '0.1', 'all');
    wp_register_style('jquery-ui', plugin_dir_url(__FILE__) . 'css/jquery.ui.css', array(), '0.1', 'all');
    wp_register_style('jquery-ui-tabs', plugin_dir_url(__FILE__) . 'css/jquery.ui.tabs.css', array(), '0.1', 'all');


    // Register scripts
    wp_register_script('cmmp-admin', plugin_dir_url(__FILE__) . 'js/cmmp-admin.js', array('jquery', 'jquery-ui-tabs'), '1.0', true);

    // Add actions
    add_action('admin_init', array($this, 'admin_init'));
    add_action('admin_menu', array($this, 'admin_menu'));

    add_action('register_form', array($this, 'add_member_no_to_registration_form'));
    add_action('registration_errors', array($this, 'check_registration_fields'), 10, 3);
    add_action('user_register', array($this, 'save_membership_no_after_registration'));
    // Add membership fields to profile page
    add_action('show_user_profile', array($this, 'add_member_no_to_profile_form'), 1);
    add_action('edit_user_profile', array($this, 'add_member_no_to_profile_form'), 1);
    add_action('personal_options_update', array($this, 'save_member_no_profile_field'));
    add_action('edit_user_profile_update', array($this, 'save_member_no_profile_field'));
    add_action('user_profile_update_errors', array($this, 'check_profile_update_fields'), 10, 3);

  }

  function admin_init()
  {
    // Admin page action hooks
    add_action('admin_print_scripts', array($this, 'admin_styles'));
    add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    add_action('wp_ajax_use_membership_no', array($this, 'ajax_use_member_no_handler'));
  }

  function admin_styles()
  {
    wp_enqueue_style('cmmp-admin');
  }

  function admin_menu()
  {
    $member_nos_page = add_users_page( __('Member numbers', 'CMMP'), __('Member numbers', 'CMMP'), 'edit_users',
        'member-nos', array($this, 'admin_menu_member_numbers_page'));

    // Add the member number handler
    add_action('load-' . $member_nos_page, array($this, 'handle_generating_member_nos'));

    // Load the member number page scripts
    add_action( 'admin_print_scripts-' . $member_nos_page, array($this, 'admin_head'));

    return;
  }

  function admin_menu_member_numbers_page()
  {
    // Check user's permissions
    if (!current_user_can('edit_users'))
      wp_die(__('Permission denied'));

    // Get numbers from d/b
    $this->get_available_nos();
    ?>
<div id="wpbody-content">
  <div class="wrap">

    <?php
    if (isset($_GET['nos_requested']) && isset($_GET['nos_generated']))
      if ($_GET['nos_requested'] == $_GET['nos_generated'])
      {
        printf('<div class="update-nag">%d %s added</div>',
          $_GET['nos_requested'],
          __ngettext('number', 'numbers', $_GET['nos_requested'], 'CMMP')
        );
      }
      else
      {
        printf('<div class="update-nag">%d %s requested but could only generate %d unique %s</div>',
          $_GET['nos_requested'],
          __ngettext('number', 'numbers', $_GET['nos_requested'], 'CMMP'),
          $_GET['nos_generated'],
          __ngettext('number', 'numbers', $_GET['nos_generated'], 'CMMP')
        );
      }
    ?>

    <div id="icon-users" class="icon32">
      <br></div>
    <h2>
      <?php _e('Member Numbers', 'CMMP'); ?>
    </h2>
    <div id="member-nos-tabs" style="margin-top: 40px;">
      <ul>
        <li><a href="#available-nos"><?php _e('Available numbers', 'CMMP'); ?></a></li>
        <li><a href="#used-nos"><?php _e('Used numbers', 'CMMP'); ?></a></li>
        <li><a href="#assigned-nos"><?php _e('Assigned numbers', 'CMMP'); ?></a></li>
      </ul>
      <div id="available-nos" class="clearfix">
        <form id="add-membership-nos-form" method="post">
          Generate more nos:
          <select name="no_to_generate">
            <option>5</option>
            <option>10</option>
            <option>50</option>
            <option>100</option>
            <option>200</option>
            <option>500</option>
            <option>1000</option>
            <option>2000</option>
            <option>5000</option>
          </select>
          <?php wp_nonce_field('add_member_nos'); ?>
          <input type="submit" value="Generate" />
        </form>
        <?php
        $available_nos = $this->get_available_nos();
        if (empty($available_nos)) {
          echo '<p>No numbers available</p>';
        } else {
          echo '<ul class="membership-no-list clearfix">';
          foreach ($available_nos as $no) {
            printf(
              '<li id="available-no-%d"><span class="member_number">%s</span> <a class="use-member-no-link" href="#">Use no.</a></li>',
              $no->number,
              $this->format_no($no->number)
            );
          }
          echo '</ul>';
        }
        ?>
        <div class="clear"></div>
      </div>
      <div id="used-nos" class="clearfix">
        <?php
        $used_nos = $this->get_used_nos();
        if (empty($used_nos)) {
          echo '<p>No numbers available</p>';
        } else {
          echo '<ul class="membership-no-list clearfix">';
          foreach ($used_nos as $no) {
            printf(
              '<li id="used-no-%d"><span class="member_number">%s</span></li>',
              $no->number,
              $this->format_no($no->number)
            );
          }
          echo '</ul>';
        }
        ?>
        <div class="clear"></div>
      </div>
      <div id="assigned-nos" class="clearfix">
        <?php
        $assigned_nos = $this->get_assigned_nos();
        if (empty($assigned_nos)) {
          echo '<p>No numbers assigned</p>';
        } else {
          echo '<ul class="membership-no-list clearfix">';
          foreach ($assigned_nos as $no => $data) {
            $user = get_userdata($data['user_id']);
            printf ("<li>$no<br><a href=\"%s\">$user->user_login</a></li>",
                admin_url("user-edit.php?user_id=" . $data['user_id']));
          }
          echo '</ul>';
        }
        ?>
      </div>
    </div>
  <div class="clear"></div>
</div>
    <?php
  }

  // Method used to check if we're dealing with a request to generate new membership nos
  function handle_generating_member_nos() {

    // Check user's permissions
    if (!current_user_can('edit_users'))
      wp_die(__('Permission denied'));

    // Have we been asked to generate more numbers?
    if (!empty($_POST['no_to_generate']) && (int)$_POST['no_to_generate'] > 0
        && wp_verify_nonce($_POST['_wpnonce'],'add_member_nos'))
    {
      // Generate the new nos.
      $created_count = $this->generate_numbers($_POST['no_to_generate']);

      $protocol = strpos(strtolower($_SERVER['SERVER_PROTOCOL']),'https') 
                      === FALSE ? 'http' : 'https';
      $host     = $_SERVER['HTTP_HOST'];
      $script   = $_SERVER['SCRIPT_NAME'];
      wp_parse_str($_SERVER['QUERY_STRING'], $params);
      $params   = http_build_query(wp_parse_args(array(
        'nos_generated' => $created_count,
        'nos_requested' => $_POST['no_to_generate'],
      ), $params));
       
      $currentUrl = $protocol . '://' . $host . $script . '?' . $params;
      
      wp_redirect($currentUrl, 302);

    }
  }

  function admin_head() {
    wp_enqueue_style('jquery-ui-tabs');
  }

  function admin_scripts($hook) {
    if ($hook == 'users_page_member-nos')
    {
      wp_enqueue_script( 'jquery');
      wp_enqueue_script( 'jquery-ui-tabs');
      wp_enqueue_script( 'cmmp-admin');
    }
  }



  function check_number_is_available($no)
  {
    global $wpdb;
    // Remove spaces
    $no = str_replace(' ', '', $no);
    $query = sprintf("SELECT * FROM $this->available_number_table_name WHERE number = '%s'", $no);
    $result = $wpdb->query($query);
    return $result > 0;
  }
  function mark_number_as_used($no) {
    global $wpdb;
    $update_query = sprintf(
      "UPDATE $this->available_number_table_name SET number_used = true WHERE number_used = false AND number = '%s';",
      $this->deformat_no($no)
    );
    return ($wpdb->query($update_query) > 0);
  }
  function get_available_nos()
  {
    global $wpdb;
    $select_query = "SELECT * FROM $this->available_number_table_name";
    $select_query .= " WHERE number_used IS NOT NULL AND number_used = '' ORDER BY number;";
    $nos = $wpdb->get_results($select_query);
    return $nos;
  }
  function get_used_nos()
  {
    global $wpdb;
    $select_query = "SELECT * FROM $this->available_number_table_name WHERE number_used = true ORDER BY number;";
    $nos = $wpdb->get_results($select_query);
    return $nos;
  }
  function get_assigned_nos()
  {
    global $wpdb;
    $select_query = sprintf("SELECT * FROM $wpdb->usermeta WHERE meta_key = '%s' AND meta_value <> '' ORDER BY number;",
          self::USER_MEMBERSHIP_NO_META_KEY);
    $user_meta = $wpdb->get_results($select_query);
    $nos = array();
    foreach ($user_meta as $index => $meta) {
      $nos[$meta->meta_value] = array('user_id' => $meta->user_id);
    }
    return $nos;
  }
  function generate_numbers($count)
  {
    global $wpdb;
    $nos = array();
    $existing_available_nos_query = "SELECT number FROM $this->available_number_table_name WHERE 1";
    $existing_assigned_nos_query = sprintf(
      "SELECT meta_value FROM $wpdb->usermeta WHERE meta_key='%s'",
      self::USER_MEMBERSHIP_NO_META_KEY
    );
    $existing_nos = array_merge(
      $wpdb->get_col($existing_available_nos_query, 0),
      $wpdb->get_col($existing_assigned_nos_query, 0)
    );
    $limit = $count * 5;
    while (count($nos) < $count && $limit-- >0)
    {
      $new_no = self::MEMBERSHIP_NO_PREFIX . $this->random_number(12);
      if (!in_array($new_no, $existing_nos))
      {
        $nos[] = sprintf("('%s')", $new_no);
        $existing_nos[] = $new_no;
      }
    }
    $insert_query = sprintf("INSERT INTO $this->available_number_table_name (number) VALUES %s;", implode(', ', $nos));

    return $wpdb->query($insert_query);
  }
  function random_number($length)
  {
    $chars = '0123456789';
    $size = strlen( $chars );
    for( $i = 0; $i < $length; $i++ ) {
      $str .= $chars[ rand( 0, $size - 1 ) ];
    }
    return $str;
  }
  function remove_number($no)
  {
    global $wpdb;
    $delete_query = sprintf("DELETE FROM $this->available_number_table_name WHERE number = '%s';", $no);
    return $wpdb->query($delete_query) > 0;
  }



  function add_member_no_to_registration_form()
  {
    echo $this->number_field();
  }
  function check_registration_fields($errors, $login, $email)
  {
    $membership_no = trim($_POST['cmmp']['membership_no']);
    if (!empty($membership_no))
    {
      $no_available = $this->check_number_is_available($_POST['cmmp']['membership_no']);
      if (!$no_available)
        $errors->add('membership_no_not_availabled',
            '<strong>ERROR 1</strong>: Sorry, but this is not a valid membership number.  Please try again.'); 
    }
    return $errors;
  }
  function save_membership_no_after_registration($user_id, $password='', $meta=array())
  {

    if (!empty($_POST['cmmp']['membership_no']))
    {
      $this->assign_membership_no_to_user($user_id, $_POST['cmmp']['membership_no']);
    }
  }
  function add_member_no_to_profile_form($user)
  {
    ?>
    <h3><?php _e('Membership Details', 'CMMP'); ?></h3>
    <table class="form-table">
      <tr>
        <th><label for="description"><?php echo $this->number_field_label(); ?></label></th>
        <td><?php echo $this->number_field_input($user->ID); ?></td>
      </tr>
    </table>
    <?php
  }
  function save_member_no_profile_field($user_id)
  {
    // Check current user is allowed to edit this user
    if (!current_user_can('edit_user', $user_id))
      return false;

    // Check we have a valid membership no
    if (!empty($_POST['cmmp']['membership_no']))
    {
      $no_available = $this->check_number_is_available($_POST['cmmp']['membership_no']);
      if (!$no_available)
      {
        // Check it isn't the same number that the user already had
        $existing_membership_no = get_user_meta($user_id, self::USER_MEMBERSHIP_NO_META_KEY, true);
        if ($this->format_no($_POST['cmmp']['membership_no']) != $this->format_no($existing_membership_no))
          $this->membership_no_not_available = true;
      }
      else
      {
        $this->assign_membership_no_to_user($user_id, $_POST['cmmp']['membership_no']);
      }
    }
    else if (isset($_POST['cmmp']['member_no_included']))
    {
      // If an admin has cleared the field, 
      delete_user_meta($user_id, self::USER_MEMBERSHIP_NO_META_KEY);
      delete_user_meta($user_id, self::USER_MEMBERSHIP_STARTED_ON_META_KEY);
    }
  }
  function check_profile_update_fields($errors, $update, $user)
  {
    if (!empty($this->membership_no_not_available))
      $errors->add('membership_no_not_availabled',
          '<strong>ERROR 2</strong>: Sorry, but this is not a valid membership number.  Please try again.'); 
  }
  function assign_membership_no_to_user($user_id, $membership_no)
  {
    $membership_no = str_replace(' ', '', $membership_no);
    // Add the membership no to the user
    update_user_meta($user_id, self::USER_MEMBERSHIP_NO_META_KEY, $membership_no);
    update_user_meta($user_id, self::USER_MEMBERSHIP_STARTED_ON_META_KEY, date('Y-m-d'));
    // And clear it out of the available numbers
    $this->remove_number($membership_no);
  }



  function ajax_use_member_no_handler()
  {

    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: ' . date('r'));
    header('Content-type: text/javascript');

    if (current_user_can('create_users'))
    {
      global $wpdb;

      if (empty($_POST['membership_no']))
      {
        $this->ajax_response(array(
          'success' => false,
          'error' => array(
            'message' => 'No membership number provided',
          ),
        ));
      }

      $no = $_POST['membership_no'];
      if ($this->mark_number_as_used($no))
      {
        $this->ajax_response(array(
          'success' => true,
          'membership_no' => $no,
        ));
      }
      else
      {
        $this->ajax_response(array(
          'success' => false,
          'error' => array(
            'message' => 'Membership number not available to use',
          ),
        ));
      }
    }
  }
  function ajax_response($data)
  {
    die(json_encode($data));
  }



  function format_no($no)
  {
    return chunk_split(str_replace(' ', '', $no), 4, ' ');
  }
  function deformat_no($no)
  {
    return str_replace(' ', '', $no);
  }

  function number_field($user_id = false)
  {
    $format = '<p>%s%s</p>';
    return sprintf($format, $this->number_field_label(), $this->number_field_input($user_id));
  }
  function number_field_label()
  {
    return sprintf('<label for="cmmp_membership_no">%s</label>', __('Membership no. (if you have one)', 'cmmp'));
  }
  function number_field_input($user_id = false)
  {
    if (!empty($user_id))
      $membership_no = get_user_meta($user_id, self::USER_MEMBERSHIP_NO_META_KEY, true);
    if (current_user_can('create_users') || empty($membership_no))
    {
      $membership_no = !empty($_REQUEST['cmmp']['membership_no']) ? stripslashes($_REQUEST['cmmp']['membership_no']) : $this->format_no($membership_no);
      $html = sprintf('<input id="cmmp_membership_no" class="input" name="cmmp[membership_no]" type="text" size="40" value="%s" />',
        $membership_no
      );
      $html .= '<input type="hidden" name="cmmp[member_no_included]" value="true" />';
    }
    else
    {
      $html = sprintf('<span id="cmmp_membership_no">%s</span>', $this->format_no($membership_no));
    }
    return $html;
  }

}

global $CMMP;
$CMMP = new CMMembershipPlugin();

register_activation_hook( __FILE__, array( 'CMMP_Setup', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'CMMP_Setup', 'on_deactivate' ) );
register_uninstall_hook( __FILE__, array( 'CMMP_Setup', 'on_uninstall' ) );
?>