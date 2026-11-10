<?php
/**
 * A step-by-step wizard for building or improving the Organization schema.
 *
 * It starts from whatever the Organization already holds (the form fields or
 * the pasted JSON-LD), prefills every question from it, shows a preview with the
 * score before and after, and saves into the JSON-LD source, with an undo.
 *
 * @package lcp-yeast-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'LCP_YEAST_SEO_WIZARD_FORM', 'lcp-yeast-seo-org-wizard' );

/**
 * Answers from a submitted wizard form, unslashed and cleaned.
 *
 * @param array $raw The wizard's part of the request ($_POST['wiz']).
 * @return array
 */
function lcp_yeast_seo_org_wizard_clean( $raw ) {
	$raw  = is_array( $raw ) ? wp_unslash( $raw ) : array();
	$out  = array();
	$line = array( 'name', 'type', 'legal_name', 'founded', 'telephone', 'email', 'contact_type', 'street', 'locality', 'region', 'postcode', 'country', 'latitude', 'longitude', 'price_range', 'hours_mode' );
	$area = array( 'description', 'same_as', 'areas' );

	foreach ( $line as $key ) {
		if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
			$out[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
		}
	}

	foreach ( $area as $key ) {
		if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
			$out[ $key ] = sanitize_textarea_field( (string) $raw[ $key ] );
		}
	}

	foreach ( array( 'logo', 'image' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
			$out[ $key ] = esc_url_raw( trim( (string) $raw[ $key ] ) );
		}
	}

	if ( isset( $out['hours_mode'] ) && ! in_array( $out['hours_mode'], array( 'keep', 'set', 'remove' ), true ) ) {
		$out['hours_mode'] = 'keep';
	}

	$out['hours'] = array();

	foreach ( array_keys( lcp_yeast_seo_org_days() ) as $day ) {
		$row = isset( $raw['hours'][ $day ] ) && is_array( $raw['hours'][ $day ] ) ? $raw['hours'][ $day ] : array();

		$out['hours'][ $day ] = array(
			'open'   => empty( $row['open'] ) ? '' : '1',
			'opens'  => isset( $row['opens'] ) ? sanitize_text_field( (string) $row['opens'] ) : '',
			'closes' => isset( $row['closes'] ) ? sanitize_text_field( (string) $row['closes'] ) : '',
		);
	}

	return $out;
}

/**
 * The nodes the Organization is made of now, and which one is the organization.
 *
 * @return array{nodes:array[],index:?int,mode:string}
 */
function lcp_yeast_seo_org_wizard_source() {
	$raw   = lcp_yeast_seo_org_raw_mode();
	$nodes = $raw ? lcp_yeast_seo_org_raw_nodes() : ( '' !== lcp_yeast_seo_get_setting( 'org_name', '' ) ? array( lcp_yeast_seo_build_organization() ) : array() );
	$index = null;

	foreach ( $nodes as $i => $node ) {
		if ( array_intersect( lcp_yeast_seo_org_types( $node ), array_merge( array( 'Organization', 'Corporation' ), lcp_yeast_seo_local_business_types() ) ) ) {
			$index = $i;
			break;
		}
	}

	return array(
		'nodes' => $nodes,
		'index' => $index,
		'mode'  => $raw ? 'raw' : 'fields',
	);
}

/**
 * The Organization node, or the start of a new one.
 *
 * @return array
 */
function lcp_yeast_seo_org_wizard_current_node() {
	$source = lcp_yeast_seo_org_wizard_source();

	return null !== $source['index'] ? $source['nodes'][ $source['index'] ] : array(
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
	);
}

/**
 * Works out what the wizard's answers would make of the schema, without saving.
 *
 * @param array $answers Cleaned answers.
 * @return array json, added, removed, before, after, problems, switches (whether it moves the source to JSON-LD).
 */
function lcp_yeast_seo_org_wizard_build( array $answers ) {
	$source = lcp_yeast_seo_org_wizard_source();
	$node   = lcp_yeast_seo_org_wizard_current_node();
	$nodes  = $source['nodes'];
	$before = lcp_yeast_seo_org_score_for_site();
	$size   = array();
	$logo   = isset( $answers['logo'] ) ? (string) $answers['logo'] : '';

	if ( '' !== $logo ) {
		$attachment = attachment_url_to_postid( $logo );
		$image      = $attachment ? wp_get_attachment_image_src( $attachment, 'full' ) : false;
		$size       = $image ? array( (int) $image[1], (int) $image[2] ) : array();
	}

	$merged = lcp_yeast_seo_org_apply_answers(
		$node,
		$answers,
		array(
			'home_url'  => home_url( '/' ),
			'id'        => lcp_yeast_seo_organization_id(),
			'replace'   => true,
			'logo_size' => $size,
		)
	);

	if ( null !== $source['index'] ) {
		$nodes[ $source['index'] ] = $merged['node'];
	} else {
		$nodes[] = $merged['node'];
	}

	$payload = lcp_yeast_seo_nodes_to_payload( array_values( $nodes ) );
	$json    = (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return array(
		'json'     => $json,
		'added'    => $merged['added'],
		'removed'  => $merged['removed'],
		'before'   => 'ok' === $before['state'] ? $before['result']['score'] : null,
		'after'    => lcp_yeast_seo_score_organization( $merged['node'], array_values( $nodes ), 'raw' )['score'],
		'problems' => lcp_yeast_seo_validate_schema_value( $json ),
		'switches' => 'fields' === $source['mode'],
	);
}

/**
 * AJAX: the preview shown on the wizard's last step.
 *
 * @return void
 */
function lcp_yeast_seo_org_wizard_ajax_preview() {
	check_ajax_referer( 'lcp_yeast_seo_org_wizard' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
	wp_send_json_success( lcp_yeast_seo_org_wizard_build( lcp_yeast_seo_org_wizard_clean( $_POST['wiz'] ?? array() ) ) );
}
add_action( 'wp_ajax_lcp_yeast_seo_org_wizard_preview', 'lcp_yeast_seo_org_wizard_ajax_preview' );

/**
 * Saves the wizard's result into the Organization JSON-LD, keeping a copy to undo to.
 *
 * @return void
 */
function lcp_yeast_seo_org_wizard_handle_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( 'lcp_yeast_seo_org_wizard' );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
	$built    = lcp_yeast_seo_org_wizard_build( lcp_yeast_seo_org_wizard_clean( $_POST['wiz'] ?? array() ) );
	$settings = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );
	$args     = array(
		'page' => 'lcp-yeast-seo',
		'tab'  => 'schema',
	);

	if ( $built['problems'] ) {
		$args['lcp_org_wiz'] = 'invalid';
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	set_transient(
		'lcp_yeast_seo_org_wizard_undo_' . get_current_user_id(),
		array(
			'mode' => lcp_yeast_seo_org_raw_mode() ? 'raw' : 'fields',
			'raw'  => (string) lcp_yeast_seo_get_setting( 'org_raw', '' ),
		),
		DAY_IN_SECONDS
	);

	$settings['org_mode'] = 'raw';
	// The settings sanitiser is only attached on the settings pages, not on admin-post, so the text is stored as it is.
	$settings['org_raw']  = $built['json'];
	update_option( LCP_YEAST_SEO_SETTINGS_OPTION, $settings );

	$args += array(
		'lcp_org_wiz' => 'saved',
		'from'        => (int) $built['before'],
		'to'          => (int) $built['after'],
		'added'       => count( $built['added'] ),
		'removed'     => count( $built['removed'] ),
	);

	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_lcp_yeast_seo_org_wizard_save', 'lcp_yeast_seo_org_wizard_handle_save' );

/**
 * Puts the Organization back as it was before the last wizard save.
 *
 * @return void
 */
function lcp_yeast_seo_org_wizard_handle_undo() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'lcp-yeast-seo' ) );
	}

	check_admin_referer( 'lcp_yeast_seo_org_wizard_undo' );

	$key    = 'lcp_yeast_seo_org_wizard_undo_' . get_current_user_id();
	$backup = get_transient( $key );
	$args   = array(
		'page' => 'lcp-yeast-seo',
		'tab'  => 'schema',
	);

	if ( is_array( $backup ) ) {
		$settings             = get_option( LCP_YEAST_SEO_SETTINGS_OPTION, array() );
		$settings['org_mode'] = 'raw' === $backup['mode'] ? 'raw' : 'fields';
		$settings['org_raw']  = (string) $backup['raw'];
		update_option( LCP_YEAST_SEO_SETTINGS_OPTION, $settings );
		delete_transient( $key );
		$args['lcp_org_wiz'] = 'undone';
	} else {
		$args['lcp_org_wiz'] = 'nothing';
	}

	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_lcp_yeast_seo_org_wizard_undo', 'lcp_yeast_seo_org_wizard_handle_undo' );

/**
 * Loads the wizard's script.
 *
 * @return void
 */
function lcp_yeast_seo_enqueue_org_wizard() {
	$path = LCP_YEAST_SEO_PATH . 'assets/org-wizard.js';

	wp_enqueue_script( 'lcp-yeast-seo-org-wizard', LCP_YEAST_SEO_URL . 'assets/org-wizard.js', array(), file_exists( $path ) ? filemtime( $path ) : LCP_YEAST_SEO_VERSION, true );
	wp_localize_script( 'lcp-yeast-seo-org-wizard', 'lcpYeastSeoWizard', array( 'ajax' => admin_url( 'admin-ajax.php' ) ) );
}

/**
 * The empty form the wizard's fields belong to (they sit inside the settings
 * form, so they point at this one with the form attribute).
 *
 * @return void
 */
function lcp_yeast_seo_render_org_wizard_form() {
	?>
	<form id="<?php echo esc_attr( LCP_YEAST_SEO_WIZARD_FORM ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lcp_yeast_seo_org_wizard_save">
		<?php wp_nonce_field( 'lcp_yeast_seo_org_wizard' ); ?>
	</form>
	<?php
}

/**
 * Intro text for the wizard card.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_wizard_intro() {
	echo '<p>Answer a few questions to build your Organization schema, or to improve what you have. Everything is filled in from your current schema, so you only change what you want to. You see a preview, and the score before and after, before anything is saved.</p>';
}

/**
 * Prints an input for the wizard form.
 *
 * @param string $key    Answer name.
 * @param string $label  Label.
 * @param array  $values Current answers.
 * @param array  $args   type, placeholder, help, rows (for a textarea).
 * @return void
 */
function lcp_yeast_seo_wizard_field( $key, $label, array $values, array $args = array() ) {
	$type  = $args['type'] ?? 'text';
	$name  = 'wiz[' . $key . ']';
	$id    = 'lcp-wiz-' . $key;
	$value = (string) ( $values[ $key ] ?? '' );
	?>
	<div class="lcp-ys-wiz__field">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<?php if ( 'textarea' === $type ) : ?>
			<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" form="<?php echo esc_attr( LCP_YEAST_SEO_WIZARD_FORM ); ?>" rows="<?php echo (int) ( $args['rows'] ?? 3 ); ?>" class="large-text" placeholder="<?php echo esc_attr( $args['placeholder'] ?? '' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<?php elseif ( 'select' === $type ) : ?>
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" form="<?php echo esc_attr( LCP_YEAST_SEO_WIZARD_FORM ); ?>">
				<?php foreach ( $args['options'] as $option => $text ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, (string) $option ); ?>><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" form="<?php echo esc_attr( LCP_YEAST_SEO_WIZARD_FORM ); ?>" value="<?php echo esc_attr( $value ); ?>" class="<?php echo esc_attr( $args['class'] ?? 'regular-text' ); ?>" placeholder="<?php echo esc_attr( $args['placeholder'] ?? '' ); ?>">
		<?php endif; ?>
		<?php if ( ! empty( $args['help'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['help'] ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The result banner after a save, undo or failed save.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_wizard_notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$state = isset( $_GET['lcp_org_wiz'] ) ? sanitize_key( wp_unslash( $_GET['lcp_org_wiz'] ) ) : '';
	$int   = static function ( $key ) {
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
	};
	// phpcs:enable

	if ( '' === $state ) {
		return;
	}

	$undo = get_transient( 'lcp_yeast_seo_org_wizard_undo_' . get_current_user_id() );
	$link = $undo ? ' <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lcp_yeast_seo_org_wizard_undo' ), 'lcp_yeast_seo_org_wizard_undo' ) ) . '">Undo</a>' : '';

	if ( 'saved' === $state ) {
		$text = sprintf( 'Saved. %s and %s. Your score went from %d to %d.', lcp_yeast_seo_count_noun( $int( 'added' ), 'detail was added or changed', 'details were added or changed' ), lcp_yeast_seo_count_noun( $int( 'removed' ), 'was removed', 'were removed' ), $int( 'from' ), $int( 'to' ) );
		$kind = 'success';
	} elseif ( 'undone' === $state ) {
		$text = 'Your Organization schema is back as it was before the wizard.';
		$kind = 'success';
		$link = '';
	} elseif ( 'invalid' === $state ) {
		$text = 'Nothing was saved, because the result was not valid schema. Check the preview and try again.';
		$kind = 'error';
		$link = '';
	} else {
		$text = 'There was nothing to undo.';
		$kind = 'warning';
		$link = '';
	}

	printf( '<div class="notice notice-%1$s inline"><p>%2$s%3$s</p></div>', esc_attr( $kind ), esc_html( $text ), $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the link is built above with escaped parts.
}

/**
 * The wizard.
 *
 * @return void
 */
function lcp_yeast_seo_render_org_wizard_field() {
	lcp_yeast_seo_render_org_wizard_notice();

	$source = lcp_yeast_seo_org_wizard_source();
	$node   = lcp_yeast_seo_org_wizard_current_node();
	$values = lcp_yeast_seo_org_prefill( $node );
	$hours  = $values['hours'];
	$form   = LCP_YEAST_SEO_WIZARD_FORM;
	$types  = array( '' => 'Keep as it is' ) + lcp_yeast_seo_org_type_options();
	$steps  = array( 'Business', 'Brand', 'Contact', 'Where', 'Hours', 'Review' );

	lcp_yeast_seo_enqueue_org_wizard();
	?>
	<div class="lcp-ys-wiz" data-wizard>
		<ol class="lcp-ys-wiz__steps" aria-label="Wizard steps">
			<?php foreach ( $steps as $i => $title ) : ?>
				<li data-wizard-tab="<?php echo (int) $i; ?>"><?php echo esc_html( $title ); ?></li>
			<?php endforeach; ?>
		</ol>
		<?php if ( 'fields' === $source['mode'] && $source['nodes'] ) : ?>
			<p class="description">Your details are currently entered in the form fields. Saving carries them across and switches the source to JSON-LD, which can also hold opening hours and areas served.</p>
		<?php endif; ?>

		<section data-wizard-step="0">
			<h3>Your business</h3>
			<?php
			lcp_yeast_seo_wizard_field( 'name', 'Business name', $values, array( 'help' => 'The name people know you by.' ) );
			lcp_yeast_seo_wizard_field( 'type', 'Kind of business', $values, array( 'type' => 'select', 'options' => $types, 'help' => 'A more specific type helps search engines treat you as a local business.' ) );
			lcp_yeast_seo_wizard_field( 'description', 'Description', $values, array( 'type' => 'textarea', 'rows' => 3, 'help' => 'Two or three sentences on what you do and where.' ) );
			lcp_yeast_seo_wizard_field( 'legal_name', 'Registered company name', $values, array( 'help' => 'Only if it differs from the name above. Leave empty if it is the same.' ) );
			lcp_yeast_seo_wizard_field( 'founded', 'Year started', $values, array( 'class' => 'small-text', 'placeholder' => '2015' ) );
			?>
		</section>

		<section data-wizard-step="1" hidden>
			<h3>Your brand</h3>
			<div class="lcp-ys-wiz__field">
				<label>Logo</label>
				<?php lcp_yeast_seo_render_image_picker( 'wiz[logo]', $values['logo'], 'Logo', 'Use as logo', $form ); ?>
				<p class="description">At least 112 x 112 pixels; 512 x 512 is a safe size. A wide logo should be no more than about 3 times wider than tall.</p>
			</div>
			<div class="lcp-ys-wiz__field">
				<label>Photo of the business</label>
				<?php lcp_yeast_seo_render_image_picker( 'wiz[image]', $values['image'], 'Photo', 'Use as photo', $form ); ?>
			</div>
			<?php lcp_yeast_seo_wizard_field( 'same_as', 'Profiles and listings', $values, array( 'type' => 'textarea', 'rows' => 4, 'help' => 'One web address per line: Facebook, LinkedIn, Instagram, your Google Business Profile, directory listings.' ) ); ?>
		</section>

		<section data-wizard-step="2" hidden>
			<h3>How people reach you</h3>
			<?php
			lcp_yeast_seo_wizard_field( 'telephone', 'Telephone', $values, array( 'placeholder' => '+44 1624 000000' ) );
			lcp_yeast_seo_wizard_field( 'email', 'Email', $values, array( 'type' => 'email' ) );
			lcp_yeast_seo_wizard_field(
				'contact_type',
				'What are these for?',
				$values,
				array(
					'type'    => 'select',
					'options' => array(
						''                   => 'Do not add a contact point',
						'customer service'   => 'Customer service',
						'sales'              => 'Sales',
						'technical support'  => 'Technical support',
						'billing support'    => 'Billing support',
					),
				)
			);
			?>
		</section>

		<section data-wizard-step="3" hidden>
			<h3>Where you work</h3>
			<div class="lcp-ys-wiz__field">
				<label><input type="checkbox" data-wizard-home form="<?php echo esc_attr( $form ); ?>" <?php checked( '' === $values['street'] && '' !== $values['areas'] ); ?>> I work from home or travel to customers, so I do not show a street address</label>
			</div>
			<div data-wizard-address>
				<?php
				lcp_yeast_seo_wizard_field( 'street', 'Street address', $values );
				lcp_yeast_seo_wizard_field( 'postcode', 'Postcode', $values, array( 'class' => 'small-text' ) );
				?>
			</div>
			<?php
			lcp_yeast_seo_wizard_field( 'locality', 'Town', $values );
			lcp_yeast_seo_wizard_field( 'region', 'County or region', $values );
			lcp_yeast_seo_wizard_field( 'country', 'Country', $values, array( 'class' => 'small-text', 'placeholder' => 'GB', 'help' => 'The two-letter code, such as GB or IM.' ) );
			lcp_yeast_seo_wizard_field( 'areas', 'Areas you cover', $values, array( 'type' => 'textarea', 'rows' => 3, 'help' => 'Towns, counties or regions, one per line.' ) );
			?>
			<div data-wizard-geo>
				<?php
				lcp_yeast_seo_wizard_field( 'latitude', 'Map latitude', $values, array( 'class' => 'small-text', 'help' => 'In Google Maps, right-click your location and click the two numbers to copy them.' ) );
				lcp_yeast_seo_wizard_field( 'longitude', 'Map longitude', $values, array( 'class' => 'small-text' ) );
				?>
			</div>
		</section>

		<section data-wizard-step="4" hidden>
			<h3>Hours and price</h3>
			<div class="lcp-ys-wiz__field">
				<label>Opening hours</label>
				<?php foreach ( array( 'keep' => 'Leave as they are', 'set' => 'Use the hours below', 'remove' => 'Remove the hours' ) as $mode => $text ) : ?>
					<label class="lcp-ys-wiz__radio"><input type="radio" name="wiz[hours_mode]" value="<?php echo esc_attr( $mode ); ?>" form="<?php echo esc_attr( $form ); ?>" <?php checked( 'keep', $mode ); ?>> <?php echo esc_html( $text ); ?></label>
				<?php endforeach; ?>
				<table class="lcp-ys-wiz__hours">
					<?php foreach ( lcp_yeast_seo_org_days() as $key => $day ) : ?>
						<?php $row = $hours[ $key ] ?? array(); ?>
						<tr>
							<th scope="row"><label><input type="checkbox" name="wiz[hours][<?php echo esc_attr( $key ); ?>][open]" value="1" form="<?php echo esc_attr( $form ); ?>" <?php checked( ! empty( $row ) || ( ! $hours && in_array( $key, array( 'mon', 'tue', 'wed', 'thu', 'fri' ), true ) ) ); ?>> <?php echo esc_html( $day ); ?></label></th>
							<td><input type="time" name="wiz[hours][<?php echo esc_attr( $key ); ?>][opens]" value="<?php echo esc_attr( $row['opens'] ?? '09:00' ); ?>" form="<?php echo esc_attr( $form ); ?>"> to <input type="time" name="wiz[hours][<?php echo esc_attr( $key ); ?>][closes]" value="<?php echo esc_attr( $row['closes'] ?? '17:00' ); ?>" form="<?php echo esc_attr( $form ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</table>
				<p class="description">Tick the days you are open. The hours are only used if you choose "Use the hours below".</p>
			</div>
			<?php
			lcp_yeast_seo_wizard_field(
				'price_range',
				'Price range',
				$values,
				array(
					'type'    => 'select',
					'options' => array(
						''     => 'Not shown',
						'£'    => '£  (budget)',
						'££'   => '££  (mid-range)',
						'£££'  => '£££  (premium)',
						'££££' => '££££  (luxury)',
					),
				)
			);
			?>
		</section>

		<section data-wizard-step="5" hidden>
			<h3>Review</h3>
			<p class="description">This is what your schema will become. Nothing is saved until you press Save.</p>
			<div data-wizard-review aria-live="polite"><p>Press Preview to see it.</p></div>
		</section>

		<p class="lcp-ys-wiz__nav">
			<button type="button" class="button" data-wizard-back>Back</button>
			<button type="button" class="button button-primary" data-wizard-next>Next</button>
			<button type="button" class="button button-primary" data-wizard-preview hidden>Preview</button>
			<button type="submit" class="button button-primary" data-wizard-save form="<?php echo esc_attr( $form ); ?>" hidden>Save to my schema</button>
			<span class="description" data-wizard-position></span>
		</p>
	</div>
	<?php
}
