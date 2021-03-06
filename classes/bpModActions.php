<?php

/**
 * Handle frontend actions
 */
class bpModActions extends bpModeration
{

	function  __construct()
	{
		parent::__construct();

		add_action('bp_moderation_init', array(&$this, 'route_action'));

		// notifications
		if ( bp_is_active( 'notifications' ) ) {
			add_action( 'bp_moderation_content_flagged',   array( $this, 'add_notification' ), 10, 2 );
			add_action( 'bp_moderation_content_unflagged', array( $this, 'remove_notification' ), 10, 6 );

			// notifiy sub-site admin when a blog type item is flagged
			if ( true === (bool) apply_filters( 'bp_moderation_notify_admin', true ) ) {
				add_action( 'bp_moderation_content_flagged', array( $this, 'notify_admin' ), 10, 2 );
			}
		}
	}

	function route_action()
	{
		if (empty($_REQUEST['bpmod-action'])) return;

		$method = (defined('DOING_AJAX') && DOING_AJAX ? 'ajax_' : 'action_') . $_REQUEST['bpmod-action'];

		if (is_callable(array($this, $method))) {
			call_user_func(array($this, $method));
		}
	}

	function action_flag()
	{
		if ('flag' != $_REQUEST['bpmod-action']) {
			return false;
		}

		$result = $this->request_flag();

		if ('already flagged' === $result) {
			bp_core_add_message(__('You have already flagged this content', 'bp-moderation'), 'error');
		} elseif (true === $result) {
			bp_core_add_message(__('Thank you for your report', 'bp-moderation'), 'success');
		} else {
			bp_core_add_message(__('There was a problem flagging that content', 'bp-moderation'), 'error');
		}

		bp_core_redirect(wp_get_referer());
	}


	function ajax_flag()
	{
		if ('flag' != $_REQUEST['bpmod-action']) {
			return false;
		}

		$result = $this->request_flag();

		if ($result) {
			$text = $this->options['flagged_text'];
			$text = apply_filters('bp_moderation_link_text', $text, true, $_REQUEST['type'], $_REQUEST['id'], $_REQUEST['id2']);

			$new_nonce = $this->create_nonce('unflag', $_REQUEST['type'], $_REQUEST['id'], $_REQUEST['id2']);

			$message = array('msg' => $text, 'type' => 'success', 'new_nonce' => $new_nonce);

			if ('already flagged' === $result) {
				$message['type'] = 'fade warning';
				$message['fade_msg'] = __('Already flagged', 'bp-moderation');
			}
		} else {
			$message = array('msg' => __('Error', 'bp-moderation'), 'type' => 'error');
		}

		die(json_encode($message));
	}

	function request_flag()
	{
		$type = $_REQUEST['type'];
		$id = $_REQUEST['id'];
		$id2 = $_REQUEST['id2'];

		if (!$this->verify_nonce('flag', $type, $id, $id2)) {
			return false;
		}

		global $bp;

		if (!$reporter = $bp->loggedin_user->id) {
			return false;
		}

		return $this->flag($type, $id, $id2, $reporter);

	}

	function flag($type, $id, $id2, $reporter)
	{
		list($cont_id, $flag_id) = $this->check_flag($type, $id, $id2, $reporter);
		if ($cont_id && $flag_id) {
			return 'already flagged';
		}

		$flag_count = false;

		bpModLoader::load_class('bpModObjContent');
		if ($cont_id) {
			$cont = new bpModObjContent($cont_id);
		} else {
			$callaback_info = $this->content_types[$type]->callbacks['info'];
			$info = call_user_func($callaback_info, $id, $id2);
			if (!$info) {
				return false;
			}

			$cont = new bpModObjContent();
			$cont->item_type = $type;
			$cont->item_id = $id;
			$cont->item_id2 = $id2;
			$cont->item_author = $info['author'];
			$cont->item_url = $info['url'];
			$cont->item_date = $info['date'];
			$cont->status = 'new';
			$cont->save();
			$cont_id = $cont->content_id;
			do_action('bp_moderation_first_flag', array(&$cont));

			$flag_count = 1;
		}

		if (!$cont_id) {
			return false;
		}

		bpModLoader::load_class('bpModObjFlag');
		$flag = new bpModObjFlag();
		$flag->content_id = $cont_id;
		$flag->reporter_id = $reporter;
		$flag->date = gmdate("Y-m-d H:i:s", time());
		$flag->save();

		if ($flag->flag_id) {
			if (!$flag_count) {
				$flag_count = $this->count_flags($cont_id);
			}

			//check and send warning message
			$warning_threshold = $this->options['warning_threshold'];
			if ('new' == $cont->status && $warning_threshold && $flag_count >= $warning_threshold) {
				$this->send_warning($cont, $flag_count);
			}

			do_action_ref_array('bp_moderation_content_flagged', array(&$cont, &$flag));
			return true;
		} else
		{
			return false;
		}

	}

	function send_warning(&$cont, $flag_count)
	{
		$sitename = get_blog_option(BP_ROOT_BLOG, 'blogname');
		$siteurl = get_blog_option(BP_ROOT_BLOG, 'siteurl');
		$author_name = bp_core_get_user_displayname($cont->item_author);
		$author_email = bp_core_get_user_email($cont->item_author);

		$subject = "[$sitename] " . __('one of your contents has been reported as inappropriate', 'bp-moderation');
		$message = $this->options['warning_message'];
		$message = str_replace('%AUTHORNAME%', $author_name, $message);
		$message = str_replace('%CONTENTURL%', $cont->item_url, $message);
		$message = str_replace('%SITENAME%', $sitename, $message);
		$message = str_replace('%SITEURL%', $siteurl, $message);

		wp_mail($author_email, $subject, $message);

		$cont->status = 'warned';
		$cont->save();

		do_action('bp_moderation_content_warned', $cont->content_id, $cont);

		if ($this->options['warning_forward']) {
			$admin_subject = "[$sitename] " . sprintf(__('a warning for inappropriate content has been sent to %s', 'bp-moderation'), $author_name);
			$admin_msg_prefix = sprintf(__(
											'Content url: %1$s
Total flags: %2$s
Author profile: %3$s

----- Message sent to the author -----
', 'bp-moderation'), $cont->item_url, $flag_count, bp_core_get_user_domain($cont->item_author));

			wp_mail($this->options['warning_forward'], $admin_subject, $admin_msg_prefix . $message);
		}
	}

	function action_unflag()
	{
		if ('unflag' != $_REQUEST['bpmod-action']) {
			return false;
		}

		$result = $this->request_unflag();

		if ('non flagged' === $result) {
			bp_core_add_message(__("This content wasn't flagged", 'bp-moderation'), 'error');
		} elseif ($result) {
			bp_core_add_message(__('Thank you for your report', 'bp-moderation'), 'success');
		} else {
			bp_core_add_message(__('There was a problem unflagging that content', 'bp-moderation'), 'error');
		}

		bp_core_redirect(wp_get_referer());
	}

	function ajax_unflag()
	{
		if ('unflag' != $_REQUEST['bpmod-action']) {
			return false;
		}

		$result = $this->request_unflag();

		if ($result) {
			$text = $this->options['unflagged_text'];
			$text = apply_filters('bp_moderation_link_text', $text, false, $_REQUEST['type'], $_REQUEST['id'], $_REQUEST['id2']);

			$new_nonce = $this->create_nonce('flag', $_REQUEST['type'], $_REQUEST['id'], $_REQUEST['id2']);

			$message = array('msg' => $text, 'type' => 'success', 'new_nonce' => $new_nonce);

			if ('non flagged' === $result) {
				$message['type'] = 'fade warning';
				$message['fade_msg'] = __("It wasn't flagged", 'bp-moderation');
			}

		} else {
			$message = array('msg' => __('Error', 'bp-moderation'), 'type' => 'error');
		}

		die(json_encode($message));
	}

	function request_unflag()
	{
		$type = $_REQUEST['type'];
		$id = $_REQUEST['id'];
		$id2 = $_REQUEST['id2'];

		if (!$this->verify_nonce('unflag', $type, $id, $id2)) {
			return false;
		}

		global $bp;

		if (!$reporter = $bp->loggedin_user->id) {
			return false;
		}

		return $this->unflag($type, $id, $id2, $reporter);

	}

	function unflag($type, $id, $id2, $reporter)
	{

		list($cont_id, $flag_id) = $this->check_flag($type, $id, $id2, $reporter);

		if (!$cont_id || !$flag_id) {
			return 'non flagged';
		}

		bpModLoader::load_class('bpModObjFlag');
		$flag = new bpModObjFlag($flag_id);

		if ($flag->delete()) {
			do_action_ref_array('bp_moderation_content_unflagged', array($type, $id, $id2, $reporter, $cont_id, &$flag));
			return true;
		} else {
			return false;
		}
	}

	/**
	 * check if the content identified by $type,$id,$id2 is already in the db
	 * and if is already flagged by $reporter
	 *
	 * @param <string> $type
	 * @param <int> $id
	 * @param <int> $id2
	 * @param <int> $reporter
	 * @return <array> (content_id, flag_id) could return null,null|int,null|int,int
	 */
	function check_flag($type, $id, $id2, $reporter)
	{
		global $wpdb;

		$sql = <<<SQL
SELECT c.content_id, f.flag_id
FROM {$this->contents_table} c LEFT OUTER JOIN {$this->flags_table} f
	ON (c.content_id = f.content_id AND f.reporter_id = %d )
WHERE c.item_type = %s AND c.item_id = %d AND c.item_id2 = %d LIMIT 1
SQL;
		$sql = $wpdb->prepare($sql, $reporter, $type, $id, $id2);

		$cont_id = $wpdb->get_var($sql, 0);
		$flag_id = $wpdb->get_var(null, 1);

		return array($cont_id, $flag_id);

	}

	/**
	 * count how many times a content has been flagged
	 *
	 * @param int $cont_id the content id
	 * @return int flags count
	 */
	function count_flags($cont_id)
	{
		global $wpdb;


		$sql = $wpdb->prepare("SELECT COUNT(*) FROM {$this->flags_table} f WHERE content_id = %d", $cont_id);

		$count = (int)$wpdb->get_var($sql);

		return $count;

	}

	/**
	 * Add a screen notification when a user is getting warned.
	 *
	 * @since 0.2.0
	 *
	 * @param bpModObjContent $cont
	 * @param bpModObjFlag    $flag
	 */
	public function add_notification( $cont, $flag ) {
		$warning_threshold = $this->options['warning_threshold'];
		$flag_count = $this->count_flags( $cont->content_id );

		$add = false;
		if ( $warning_threshold && $flag_count >= $warning_threshold) {
			$add = true;
		}

		if ( false === $add || 0 === (int) $cont->item_author ) {
			return;
		}

		// do not add notifications for the following types
		// @todo make this filterable
		switch ( $cont->item_type ) {
			case 'member' :
			case 'private_message_sender' :
				return;
				break;
		}

		bp_notifications_add_notification( array(
			'user_id'           => $cont->item_author,
			'item_id'           => $flag->flag_id,
			'secondary_item_id' => $flag->content_id,
			'component_name'    => 'moderation',
			'component_action'  => $cont->item_type,
			'date_notified'     => $flag->date,
			'is_new'            => 1,
		) );
	}

	/**
	 * Remove a screen notification when a user is getting warned.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $type
	 * @param int           $id
	 * @param int           $id2
	 * @param int           $reporter
	 * @param int           $cont_id
	 * @param bpModObjFlag  $flag
	 */
	public function remove_notification( $type, $id, $id2, $reporter, $cont_id, $flag ) {
		BP_Notifications_Notification::delete( array(
			'component_name' => 'moderation',
			'item_id' => $flag->flag_id
		) );
	}

	/**
	 * Notify the sub-site admin when a blog type item is flagged.
	 *
	 * @since 0.2.0
	 *
	 * @param bpModObjContent $cont
	 * @param bpModObjFlag    $flag
	 */
	public function notify_admin( $cont, $flag ) {
		$email = get_option( 'admin_email' );
		if ( false === is_email( $email ) ) {
			return;
		}

		$user = get_user_by( 'email', $email );

		// do not notify if logged-in user matches the admin user
		if ( $user instanceof WP_User && (int) get_current_user_id() === (int) $user->ID ) {
			return;
		}

		switch ( $cont->item_type ) {
			// only notify sub-site admin for the following types for now
			case 'blog_post' :
			case 'blog_comment' :
			case 'blog_page' :
				break;

			// bail
			default :
				return;
				break;
		}

		$bpMod =& bpModeration::get_istance();

		$type = ! empty( $bpMod->content_types[$cont->item_type]->label ) ? strtolower( $bpMod->content_types[$cont->item_type]->label ) : str_replace( '_', ' ', $cont->item_type );

		// send the email
		wp_mail(
			$email,
			bp_get_email_subject( array(
				'text' => sprintf(
					__( 'A %s was just flagged on your site', 'bp-moderation' ),
					$type
				)
			) ),
			sprintf(
				__( 'Hi,

A %1$s was just flagged on your site.

You can view the flagged item here:
%2$s

The %1$s was reported by the user, %3$s:
%4$s', 'bp-moderation' ),
				$type,
				$cont->item_url,
				bp_core_get_username( $flag->reporter_id ),
				bp_core_get_user_domain( $flag->reporter_id )
			)
		);
	}
}
