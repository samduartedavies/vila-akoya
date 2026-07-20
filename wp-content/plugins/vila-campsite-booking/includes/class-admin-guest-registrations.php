<?php
/**
 * Admin UI for guest legal registrations.
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce admin screen for SIBA registration data.
 */
class Admin_Guest_Registrations {

	const PAGE = 'vila-cb-guest-registrations';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 61 );
		add_action( 'admin_post_vila_cb_guest_registration_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_vila_cb_guest_registration_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_vila_cb_guest_registration_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Register submenu.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'Campsite guest registrations', 'vila-campsite-booking' ),
			__( 'Campsite · Guest registrations', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render current screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$id = isset( $_GET['registration_id'] ) ? absint( wp_unslash( Request_String::scalar( $_GET['registration_id'] ) ) ) : 0;
		if ( $id ) {
			self::render_detail( $id );
			return;
		}
		self::render_list();
	}

	/**
	 * Render list.
	 */
	private static function render_list() {
		$repo    = new Guest_Registration_Repository();
		$filters = self::filters_from_request();
		$rows    = $repo->list_registrations( $filters );
		?>
		<div class="wrap vila-cb-admin vila-cb-guest-reg">
			<?php self::notice(); ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Campsite · Guest registrations', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead"><?php esc_html_e( 'Review guest identity details collected for SIBA and export non-Portuguese guests for portal upload.', 'vila-campsite-booking' ); ?></p>
				</div>
				<div class="vila-cb-ui-nav vila-cb-daynav">
					<a class="button vila-cb-ui-nav-btn" href="<?php echo esc_url( self::export_url( 'csv', $filters ) ); ?>"><?php esc_html_e( 'Export CSV', 'vila-campsite-booking' ); ?></a>
					<a class="button button-primary vila-cb-ui-nav-btn vila-cb-ui-nav-btn--primary" href="<?php echo esc_url( self::export_url( 'dat', $filters ) ); ?>"><?php esc_html_e( 'Export SIBA DAT', 'vila-campsite-booking' ); ?></a>
				</div>
			</div>

			<div class="vila-cb-ui-panel">
				<form method="get" class="vila-cb-ui-filters">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
					<label>
						<?php esc_html_e( 'From', 'vila-campsite-booking' ); ?>
						<input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
					</label>
					<label>
						<?php esc_html_e( 'To', 'vila-campsite-booking' ); ?>
						<input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
					</label>
					<label>
						<?php esc_html_e( 'Status', 'vila-campsite-booking' ); ?>
						<select name="status">
							<?php foreach ( self::statuses() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'Search', 'vila-campsite-booking' ); ?>
						<input type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" />
					</label>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'vila-campsite-booking' ); ?></button>
				</form>
			</div>

			<div class="vila-cb-ui-panel">
				<div class="vila-cb-ui-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Stay', 'vila-campsite-booking' ); ?></th>
								<th><?php esc_html_e( 'Booker', 'vila-campsite-booking' ); ?></th>
								<th><?php esc_html_e( 'Guests', 'vila-campsite-booking' ); ?></th>
								<th><?php esc_html_e( 'SIBA rows', 'vila-campsite-booking' ); ?></th>
								<th><?php esc_html_e( 'Status', 'vila-campsite-booking' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'vila-campsite-booking' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr><td colspan="6"><?php esc_html_e( 'No guest registrations found.', 'vila-campsite-booking' ); ?></td></tr>
							<?php endif; ?>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$persons    = $repo->get_persons( (int) $row['id'] );
								$siba_count = 0;
								foreach ( $persons as $person ) {
									if ( empty( $person['skip_siba'] ) ) {
										++$siba_count;
									}
								}
								$detail_url = admin_url( 'admin.php?page=' . self::PAGE . '&registration_id=' . (int) $row['id'] );
								?>
								<tr>
									<td><?php echo esc_html( (string) $row['check_in'] . ' → ' . (string) $row['check_out'] ); ?></td>
									<td>
										<strong><?php echo esc_html( (string) $row['booker_name'] ); ?></strong><br />
										<small><?php echo esc_html( (string) $row['contact_email'] ); ?></small>
									</td>
									<td><?php echo esc_html( (string) count( $persons ) ); ?></td>
									<td><?php echo esc_html( (string) $siba_count ); ?></td>
									<td><?php echo esc_html( self::status_label( (string) $row['siba_status'] ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'View / edit', 'vila-campsite-booking' ); ?></a>
										<?php if ( 'exported' !== (string) $row['siba_status'] && $siba_count > 0 ) : ?>
											<br /><a href="<?php echo esc_url( self::status_url( (int) $row['id'], 'exported' ) ); ?>"><?php esc_html_e( 'Mark exported', 'vila-campsite-booking' ); ?></a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param int $id Registration ID.
	 */
	private static function render_detail( $id ) {
		$repo = new Guest_Registration_Repository();
		$data = $repo->get_with_persons( $id );
		if ( ! $data ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Guest registration not found.', 'vila-campsite-booking' ) . '</h1></div>';
			return;
		}
		$reg     = $data['registration'];
		$persons = $data['persons'];
		?>
		<div class="wrap vila-cb-admin vila-cb-guest-reg">
			<?php self::notice(); ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Edit guest registration', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead"><?php echo esc_html( (string) $reg['check_in'] . ' → ' . (string) $reg['check_out'] ); ?></p>
				</div>
				<a class="button vila-cb-ui-nav-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Back to registrations', 'vila-campsite-booking' ); ?></a>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vila_cb_guest_registration_save" />
				<input type="hidden" name="registration_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'vila_cb_guest_registration_save_' . $id, 'vila_cb_guest_registration_nonce' ); ?>

				<div class="vila-cb-ui-panel">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Stay', 'vila-campsite-booking' ); ?></h2>
					<table class="form-table vila-cb-ui-form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Dates', 'vila-campsite-booking' ); ?></th>
							<td>
								<input type="date" name="check_in" value="<?php echo esc_attr( (string) $reg['check_in'] ); ?>" required />
								<input type="date" name="check_out" value="<?php echo esc_attr( (string) $reg['check_out'] ); ?>" required />
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Booker', 'vila-campsite-booking' ); ?></th>
							<td>
								<input type="text" name="booker_name" class="regular-text" value="<?php echo esc_attr( (string) $reg['booker_name'] ); ?>" />
								<input type="email" name="contact_email" class="regular-text" value="<?php echo esc_attr( (string) $reg['contact_email'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'SIBA status', 'vila-campsite-booking' ); ?></th>
							<td>
								<select name="siba_status">
									<?php foreach ( self::statuses( false ) as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $reg['siba_status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<div class="vila-cb-ui-panel">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Guests', 'vila-campsite-booking' ); ?></h2>
					<div class="vila-cb-ui-table-wrap">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Skip SIBA', 'vila-campsite-booking' ); ?></th>
									<th><?php esc_html_e( 'First names', 'vila-campsite-booking' ); ?></th>
									<th><?php esc_html_e( 'Surname', 'vila-campsite-booking' ); ?></th>
									<th><?php esc_html_e( 'Nationality', 'vila-campsite-booking' ); ?></th>
									<th><?php esc_html_e( 'DOB', 'vila-campsite-booking' ); ?></th>
									<th><?php esc_html_e( 'Doc type', 'vila-campsite-booking' ); ?></th>
									<th><?php esc_html_e( 'Doc no.', 'vila-campsite-booking' ); ?></th>
									<th><?php esc_html_e( 'Doc country', 'vila-campsite-booking' ); ?></th>
									<th><?php esc_html_e( 'Residence', 'vila-campsite-booking' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$rows = array_merge( $persons, array( array() ) );
								foreach ( $rows as $i => $person ) :
									self::person_row( (int) $i, $person );
								endforeach;
								?>
							</tbody>
						</table>
					</div>
					<p class="description"><?php esc_html_e( 'Use the blank final row to add one extra guest. Save again to add another.', 'vila-campsite-booking' ); ?></p>
				</div>

				<?php submit_button( __( 'Save registration', 'vila-campsite-booking' ), 'primary vila-cb-ui-submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param int                  $i Row index.
	 * @param array<string, mixed> $person Person row.
	 */
	private static function person_row( $i, array $person ) {
		$prefix = 'persons[' . $i . ']';
		?>
		<tr>
			<td><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[skip_siba]" value="1" <?php checked( ! empty( $person['skip_siba'] ) ); ?> /></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[given_name]" value="<?php echo esc_attr( isset( $person['given_name'] ) ? (string) $person['given_name'] : '' ); ?>" /></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[surname]" value="<?php echo esc_attr( isset( $person['surname'] ) ? (string) $person['surname'] : '' ); ?>" /></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[nationality]" maxlength="3" value="<?php echo esc_attr( isset( $person['nationality'] ) ? (string) $person['nationality'] : '' ); ?>" /></td>
			<td><input type="date" name="<?php echo esc_attr( $prefix ); ?>[date_of_birth]" value="<?php echo esc_attr( isset( $person['date_of_birth'] ) ? (string) $person['date_of_birth'] : '' ); ?>" /></td>
			<td>
				<select name="<?php echo esc_attr( $prefix ); ?>[document_type]">
					<option value=""></option>
					<option value="P" <?php selected( isset( $person['document_type'] ) ? (string) $person['document_type'] : '', 'P' ); ?>>P</option>
					<option value="B" <?php selected( isset( $person['document_type'] ) ? (string) $person['document_type'] : '', 'B' ); ?>>B</option>
					<option value="O" <?php selected( isset( $person['document_type'] ) ? (string) $person['document_type'] : '', 'O' ); ?>>O</option>
				</select>
			</td>
			<td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[document_number]" value="<?php echo esc_attr( isset( $person['document_number'] ) ? (string) $person['document_number'] : '' ); ?>" /></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[document_country]" maxlength="3" value="<?php echo esc_attr( isset( $person['document_country'] ) ? (string) $person['document_country'] : '' ); ?>" /></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[country_of_residence]" maxlength="3" value="<?php echo esc_attr( isset( $person['country_of_residence'] ) ? (string) $person['country_of_residence'] : '' ); ?>" /></td>
		</tr>
		<?php
	}

	/**
	 * Save admin edits.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vila-campsite-booking' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_POST['registration_id'] ) ? absint( wp_unslash( Request_String::scalar( $_POST['registration_id'] ) ) ) : 0;
		if ( ! $id || ! isset( $_POST['vila_cb_guest_registration_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_guest_registration_nonce'] ) ) ), 'vila_cb_guest_registration_save_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'vila-campsite-booking' ), '', array( 'response' => 403 ) );
		}
		$repo = new Guest_Registration_Repository();
		$old  = $repo->get( $id );
		if ( ! $old ) {
			wp_die( esc_html__( 'Guest registration not found.', 'vila-campsite-booking' ), '', array( 'response' => 404 ) );
		}
		$result = $repo->update_normalized(
			$id,
			array(
				'booking_id'    => isset( $old['booking_id'] ) ? (int) $old['booking_id'] : 0,
				'order_id'      => isset( $old['order_id'] ) ? (int) $old['order_id'] : 0,
				'check_in'      => isset( $_POST['check_in'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['check_in'] ) ) ) : '',
				'check_out'     => isset( $_POST['check_out'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['check_out'] ) ) ) : '',
				'booker_name'   => isset( $_POST['booker_name'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['booker_name'] ) ) ) : '',
				'contact_email' => isset( $_POST['contact_email'] ) ? sanitize_email( wp_unslash( Request_String::scalar( $_POST['contact_email'] ) ) ) : '',
				'source'        => isset( $old['source'] ) ? (string) $old['source'] : 'manual',
				'siba_status'   => isset( $_POST['siba_status'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $_POST['siba_status'] ) ) ) : 'pending',
			),
			self::persons_from_post()
		);
		$msg = is_wp_error( $result ) || false === $result ? 'error' : 'saved';
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&registration_id=' . $id . '&vila_cb_msg=' . $msg ) );
		exit;
	}

	/**
	 * Mark registration status.
	 */
	public static function handle_status() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vila-campsite-booking' ), '', array( 'response' => 403 ) );
		}
		$id     = isset( $_GET['registration_id'] ) ? absint( wp_unslash( Request_String::scalar( $_GET['registration_id'] ) ) ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $_GET['status'] ) ) ) : 'pending';
		if ( ! $id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_GET['_wpnonce'] ) ) ), 'vila_cb_guest_registration_status_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'vila-campsite-booking' ), '', array( 'response' => 403 ) );
		}
		$repo = new Guest_Registration_Repository();
		$repo->update_status( $id, $status );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&vila_cb_msg=status' ) );
		exit;
	}

	/**
	 * Download export.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vila-campsite-booking' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_GET['_wpnonce'] ) ) ), 'vila_cb_guest_registration_export' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'vila-campsite-booking' ), '', array( 'response' => 403 ) );
		}
		$format  = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $_GET['format'] ) ) ) : 'csv';
		$filters = self::filters_from_request();
		$repo    = new Guest_Registration_Repository();
		$rows    = $repo->export_person_rows( $filters );
		if ( 'dat' === $format ) {
			$body = Guest_Registration_Exporter::dat( $rows );
			if ( is_wp_error( $body ) ) {
				wp_die( esc_html( $body->get_error_message() ), '', array( 'response' => 400 ) );
			}
			self::download( 'vila-siba-' . gmdate( 'Ymd-His' ) . '.dat', 'text/plain; charset=ISO-8859-1', (string) $body );
		}
		self::download( 'vila-guest-registrations-' . gmdate( 'Ymd-His' ) . '.csv', 'text/csv; charset=utf-8', Guest_Registration_Exporter::csv( $rows ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function persons_from_post() {
		$raw = isset( $_POST['persons'] ) && is_array( $_POST['persons'] ) ? wp_unslash( $_POST['persons'] ) : array();
		$out = array();
		foreach ( $raw as $person ) {
			if ( ! is_array( $person ) ) {
				continue;
			}
			$given = isset( $person['given_name'] ) ? sanitize_text_field( (string) $person['given_name'] ) : '';
			$sur   = isset( $person['surname'] ) ? sanitize_text_field( (string) $person['surname'] ) : '';
			if ( '' === $given && '' === $sur ) {
				continue;
			}
			$out[] = array(
				'given_name'           => $given,
				'surname'              => $sur,
				'nationality'          => isset( $person['nationality'] ) ? sanitize_text_field( (string) $person['nationality'] ) : '',
				'date_of_birth'        => isset( $person['date_of_birth'] ) ? sanitize_text_field( (string) $person['date_of_birth'] ) : '',
				'document_type'        => isset( $person['document_type'] ) ? sanitize_text_field( (string) $person['document_type'] ) : '',
				'document_number'      => isset( $person['document_number'] ) ? sanitize_text_field( (string) $person['document_number'] ) : '',
				'document_country'     => isset( $person['document_country'] ) ? sanitize_text_field( (string) $person['document_country'] ) : '',
				'country_of_residence' => isset( $person['country_of_residence'] ) ? sanitize_text_field( (string) $person['country_of_residence'] ) : '',
				'skip_siba'            => ! empty( $person['skip_siba'] ) ? 1 : 0,
			);
		}
		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private static function filters_from_request() {
		return array(
			'from'   => isset( $_GET['from'] ) ? Guest_Registration_Repository::ymd_or_empty( sanitize_text_field( wp_unslash( Request_String::scalar( $_GET['from'] ) ) ) ) : '',
			'to'     => isset( $_GET['to'] ) ? Guest_Registration_Repository::ymd_or_empty( sanitize_text_field( wp_unslash( Request_String::scalar( $_GET['to'] ) ) ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $_GET['status'] ) ) ) : 'pending',
			's'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_GET['s'] ) ) ) : '',
		);
	}

	/**
	 * @param bool $include_all Include all option.
	 * @return array<string, string>
	 */
	private static function statuses( $include_all = true ) {
		$out = $include_all ? array( 'all' => __( 'All', 'vila-campsite-booking' ) ) : array();
		return array_merge(
			$out,
			array(
				'pending'      => __( 'Pending export', 'vila-campsite-booking' ),
				'exported'     => __( 'Exported', 'vila-campsite-booking' ),
				'not_required' => __( 'Not required', 'vila-campsite-booking' ),
			)
		);
	}

	/**
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$statuses = self::statuses( false );
		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
	}

	/**
	 * @param int    $id Registration ID.
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_url( $id, $status ) {
		return wp_nonce_url(
			admin_url(
				add_query_arg(
					array(
						'action'          => 'vila_cb_guest_registration_status',
						'registration_id' => (int) $id,
						'status'          => $status,
					),
					'admin-post.php'
				)
			),
			'vila_cb_guest_registration_status_' . (int) $id
		);
	}

	/**
	 * @param string               $format Format.
	 * @param array<string, mixed> $filters Filters.
	 * @return string
	 */
	private static function export_url( $format, array $filters ) {
		return wp_nonce_url(
			admin_url(
				add_query_arg(
					array_merge(
						array(
							'action' => 'vila_cb_guest_registration_export',
							'format' => $format,
						),
						$filters
					),
					'admin-post.php'
				)
			),
			'vila_cb_guest_registration_export'
		);
	}

	/**
	 * @param string $filename Filename.
	 * @param string $type MIME type.
	 * @param string $body Body.
	 */
	private static function download( $filename, $type, $body ) {
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download content.
		exit;
	}

	/**
	 * Render notices.
	 */
	private static function notice() {
		if ( empty( $_GET['vila_cb_msg'] ) ) {
			return;
		}
		$msg  = sanitize_key( wp_unslash( Request_String::scalar( $_GET['vila_cb_msg'] ) ) );
		$text = __( 'Saved.', 'vila-campsite-booking' );
		$type = 'success';
		if ( 'error' === $msg ) {
			$text = __( 'Could not save the registration. Check required SIBA fields.', 'vila-campsite-booking' );
			$type = 'error';
		} elseif ( 'status' === $msg ) {
			$text = __( 'Status updated.', 'vila-campsite-booking' );
		}
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
