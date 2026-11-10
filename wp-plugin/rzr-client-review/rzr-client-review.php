<?php
/**
 * Plugin Name: Client Review
 * Description: Lets clients comment on pages with annotate.js. Feedback is stored on your review server. Per page mode: add ?review to a page URL. Full site mode: the review toolbar is on every page (for staging sites).
 * Version: 1.0.6
 */

defined( 'ABSPATH' ) || exit;

const RZR_CR_OPT        = 'rzr_client_review';
const RZR_CR_ANNOTATE   = 'https://cdn.jsdelivr.net/npm/@reviewjs/annotate@1.6.0/annotate.js';
const RZR_CR_TOKEN_RE   = '/^[A-Za-z0-9_-]{1,64}$/'; // a server invite (inv_...) or a self-serve code

function rzr_cr_settings() {
	return wp_parse_args( get_option( RZR_CR_OPT, array() ), array(
		'server'  => '',
		'token'   => '',
		'mode'    => 'off', // off | per_page | full_site
		'open'    => 1,
		'shared'  => 1, // everyone sees all comments (shared board)
		'selfserve' => 1, // anyone may join with ?review=CODE using a code they pick
		'note'    => '',
		'review'  => 'rvw_' . preg_replace( '/[^a-z0-9]+/', '-', strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ) ),
	) );
}

function rzr_cr_origin() {
	$p = wp_parse_url( home_url() );
	return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
}

/** Calls the review server's admin API. Returns array data or WP_Error. */
function rzr_cr_token() {
	// Prefer a server-side secret (keeps the admin token out of the WordPress database).
	if ( defined( 'RZR_CR_TOKEN' ) && RZR_CR_TOKEN ) {
		return RZR_CR_TOKEN;
	}
	$env = getenv( 'RZR_CR_TOKEN' );
	if ( $env ) {
		return $env;
	}
	$s = rzr_cr_settings();
	return $s['token'];
}

function rzr_cr_api( $method, $path, $body = null ) {
	$s     = rzr_cr_settings();
	$token = rzr_cr_token();
	if ( ! $s['server'] || ! $token ) {
		return new WP_Error( 'rzr_cr_config', 'Set the server URL and admin token first.' );
	}
	$args = array(
		'method'  => $method,
		'timeout' => 15,
		'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$res = wp_remote_request( untrailingslashit( $s['server'] ) . $path, $args );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code >= 300 ) {
		return new WP_Error( 'rzr_cr_http', 'Review server answered ' . $code . ': ' . ( $data['error'] ?? 'error' ) );
	}
	return is_array( $data ) ? $data : array();
}

function rzr_cr_sync_review() {
	$s = rzr_cr_settings();
	return rzr_cr_api( 'PUT', '/admin/reviews/' . rawurlencode( $s['review'] ), array(
		'name'    => get_bloginfo( 'name' ),
		'note'    => $s['note'],
		'origins' => array( rzr_cr_origin() ),
		'open'    => (bool) $s['open'],
		'shared'  => (bool) $s['shared'],
		'selfServe' => (bool) $s['selfserve'],
		'closed'  => 'off' === $s['mode'],
	) );
}

/* ---------- Front end ---------- */

function rzr_cr_active_request() {
	if ( is_admin() || wp_doing_ajax() || isset( $_GET['elementor-preview'] ) || is_feed() ) {
		return false;
	}
	$s = rzr_cr_settings();
	if ( ! $s['server'] || 'off' === $s['mode'] ) {
		return false;
	}
	return 'full_site' === $s['mode'] || isset( $_GET['review'] );
}

function rzr_cr_snippet() {
	$s     = rzr_cr_settings();
	$token = isset( $_GET['review'] ) ? sanitize_text_field( wp_unslash( $_GET['review'] ) ) : '';
	$token = preg_match( RZR_CR_TOKEN_RE, $token ) ? $token : '';
	$page  = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ) ?: '/';

	$cfg = array(
		'api'      => untrailingslashit( $s['server'] ),
		'review'   => $s['review'],
		'page'     => $page,
		'note'     => $s['note'],
		'token'    => $token,
		'annotate' => RZR_CR_ANNOTATE,
		'picker'   => true,
	);
	$json = wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	return '<script>window.RZR_CR=' . $json . ';' . rzr_cr_bootstrap_js() . '</script>' . "\n";
}

/** Front-end bootstrap: identity so viewers see the shared board, plus a custom colour picker. */
function rzr_cr_bootstrap_js() {
	return <<<'JS'
(function(){
 var C=window.RZR_CR||{}; if(!C.api||!C.review) return;
 var g=function(k){try{return localStorage.getItem(k)}catch(e){return null}};
 var s=function(k,v){try{localStorage.setItem(k,v)}catch(e){}};
 var ik='annotate:invite:'+C.review, ck='an-color', dft='#f59e0b';
 // A personal/self-serve code from ?review=CODE: remember it, then clean the address to ?review.
 if(C.token){ s(ik,C.token);
  try{var u=new URL(location.href);u.searchParams.set('review','');history.replaceState(history.state,'',u.pathname+u.search.replace('review=','review')+u.hash);}catch(e){} }
 // Give every visitor a stable per-browser identity so they always see the shared board (no codes to make).
 if(!g(ik) && !g('an-author')){ s(ik,'g-'+Math.random().toString(36).slice(2,10)+Math.random().toString(36).slice(2,6)); }
 // Colour is left to annotate: it defaults to the first preset and keeps whatever each person picks.
 function load(){
  var sc=document.createElement('script'); sc.src=C.annotate; sc.defer=true;
  sc.setAttribute('data-api',C.api); sc.setAttribute('data-review-id',C.review);
  sc.setAttribute('data-project',C.review); sc.setAttribute('data-page',C.page);
  if(C.note) sc.setAttribute('data-note',C.note); sc.setAttribute('data-start-open','true');
  (document.body||document.documentElement).appendChild(sc);
  if(C.picker) picker();
 }
 load();
 draftOutline();
 // Keep the drawn shape visible while composing (annotate removes it on mouse-up), Figma-style.
 // We mirror the drag and draw a dashed outline into annotate's own overlay layer (#__an_overlay,
 // in document coordinates), then remove it when the composer closes (posted or cancelled).
 function draftOutline(){
  var NS='http://www.w3.org/2000/svg', start=null, tool=null, watch=null;
  function activeTool(){ var b=document.querySelector('#__an_bar .an-btn.an-on'); return b?b.getAttribute('data-tool'):null; }
  function colr(){ try{return localStorage.getItem('an-color')||'#10b981';}catch(e){return '#10b981';} }
  function wipe(){ var o=document.getElementById('rzr-cr-draft'); if(o&&o.parentNode)o.parentNode.removeChild(o); if(watch){clearInterval(watch);watch=null;} }
  document.addEventListener('pointerdown',function(e){
   if(e.button!==0 && e.pointerType==='mouse'){ start=null; return; }
   var t=activeTool();
   if((t==='rect'||t==='circle') && !(e.target.closest && e.target.closest('#__an_bar,#__an_panel,#__an_compose,#__an_namewrap,#__an_toasts'))){
    start={x:e.pageX,y:e.pageY}; tool=t; wipe();
   } else { start=null; }
  },true);
  document.addEventListener('pointerup',function(e){
   if(!start) return;
   var s=start, t=tool; start=null;
   var x=Math.min(s.x,e.pageX), y=Math.min(s.y,e.pageY), w=Math.abs(e.pageX-s.x), h=Math.abs(e.pageY-s.y);
   if(w<6 && h<6) return; // annotate treats this as a click: no composer, nothing to outline
   var ov=document.getElementById('__an_overlay'); if(!ov) return;
   var col=colr(), gEl=document.createElementNS(NS,'g'); gEl.setAttribute('id','rzr-cr-draft'); gEl.setAttribute('pointer-events','none');
   var shape;
   if(t==='circle'){ shape=document.createElementNS(NS,'ellipse'); shape.setAttribute('cx',x+w/2); shape.setAttribute('cy',y+h/2); shape.setAttribute('rx',w/2); shape.setAttribute('ry',h/2); }
   else { shape=document.createElementNS(NS,'rect'); shape.setAttribute('x',x); shape.setAttribute('y',y); shape.setAttribute('width',w); shape.setAttribute('height',h); shape.setAttribute('rx',6); }
   shape.setAttribute('fill','none'); shape.setAttribute('stroke',col); shape.setAttribute('stroke-width','2'); shape.setAttribute('stroke-dasharray','6 4');
   var dot=document.createElementNS(NS,'circle'); dot.setAttribute('cx',s.x); dot.setAttribute('cy',s.y); dot.setAttribute('r',6); dot.setAttribute('fill',col);
   gEl.appendChild(shape); gEl.appendChild(dot); ov.appendChild(gEl);
   if(watch) clearInterval(watch);
   watch=setInterval(function(){ var c=document.getElementById('__an_compose'); if(!c || !c.classList.contains('an-show')) wipe(); },200);
  },true);
 }
 // Custom colour: add a "+" swatch inside annotate's own colour menu (#__an_colorpop),
 // after its five presets. Annotate builds the menu asynchronously, so poll, then inject once.
 function picker(){
  var tries=0;
  (function attach(){
   var pop=document.getElementById('__an_colorpop');
   if(!pop){ if(tries++<80){ setTimeout(attach,250); } return; }
   if(document.getElementById('rzr-cr-pick')) return;
   var plus=document.createElement('label'); plus.className='an-sw'; plus.id='rzr-cr-pick'; plus.title='Custom colour';
   // Rainbow fill with a white ring and a white + (matching the presets) so it reads as "pick any colour".
   plus.style.cssText='width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:conic-gradient(from 90deg,#f43f5e,#f59e0b,#eab308,#10b981,#0ea5e9,#8b5cf6,#ec4899,#f43f5e);border:2px solid #fff;color:#fff;font:800 16px/1 system-ui,-apple-system,sans-serif;cursor:pointer;position:relative;box-sizing:border-box;text-shadow:0 0 2px rgba(0,0,0,.55),0 0 2px rgba(0,0,0,.55)';
   plus.appendChild(document.createTextNode('+'));
   var i=document.createElement('input'); i.type='color'; i.value=g(ck)||dft;
   i.style.cssText='position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer';
   plus.appendChild(i);
   i.addEventListener('change',function(){ s(ck,i.value); location.reload(); });
   pop.appendChild(plus);
  })();
 }
})();
JS;
}

add_action( 'send_headers', function () {
	if ( rzr_cr_active_request() && isset( $_GET['review'] ) ) {
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
} );

add_action( 'wp_footer', function () {
	if ( rzr_cr_active_request() ) {
		echo rzr_cr_snippet(); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* above.
	}
}, 99 );

// Pages served by the Raw HTML Pages plugin skip wp_footer, so add the toolbar there too.
add_filter( 'rzr_raw_html_output', function ( $html ) {
	if ( ! rzr_cr_active_request() ) {
		return $html;
	}
	$snippet = rzr_cr_snippet();
	$pos     = strripos( $html, '</body>' );
	return false === $pos ? $html . $snippet : substr_replace( $html, $snippet, $pos, 0 );
} );

/* ---------- Admin ---------- */

add_action( 'admin_menu', function () {
	add_options_page( 'Client Review', 'Client Review', 'manage_options', 'rzr-client-review', 'rzr_cr_page' );
} );

add_action( 'admin_post_rzr_cr_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'rzr_cr_save' );
	$s           = rzr_cr_settings();
	$s['server'] = esc_url_raw( wp_unslash( $_POST['server'] ?? '' ) );
	if ( ! empty( $_POST['token'] ) ) {
		$s['token'] = sanitize_text_field( wp_unslash( $_POST['token'] ) );
	}
	$s['mode'] = in_array( $_POST['mode'] ?? '', array( 'off', 'per_page', 'full_site' ), true ) ? $_POST['mode'] : 'off';
	$s['open']     = empty( $_POST['open'] ) ? 0 : 1;
	$s['shared']   = empty( $_POST['shared'] ) ? 0 : 1;
	$s['selfserve'] = empty( $_POST['selfserve'] ) ? 0 : 1;
	$s['note'] = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
	update_option( RZR_CR_OPT, $s, false );
	$r = rzr_cr_sync_review();
	rzr_cr_redirect( is_wp_error( $r ) ? $r->get_error_message() : 'Saved and synced to the review server.' );
} );

add_action( 'admin_post_rzr_cr_check', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'rzr_cr_check' );
	$s = rzr_cr_settings();
	$r = rzr_cr_api( 'GET', '/admin/reviews/' . rawurlencode( $s['review'] ) );
	if ( is_wp_error( $r ) ) {
		rzr_cr_redirect( 'Connection failed. ' . $r->get_error_message() );
	}
	rzr_cr_redirect( 'Connection OK. The server knows this review (' . esc_html( $s['review'] ) . ').' );
} );

function rzr_cr_redirect( $msg ) {
	wp_safe_redirect( add_query_arg( array( 'page' => 'rzr-client-review', 'rzr_msg' => rawurlencode( $msg ) ), admin_url( 'options-general.php' ) ) );
	exit;
}

function rzr_cr_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s      = rzr_cr_settings();
	$ready  = $s['server'] && rzr_cr_token();
	$found  = $ready ? rzr_cr_api( 'GET', '/admin/reviews/' . rawurlencode( $s['review'] ) . '/comments' ) : null;
	?>
	<div class="wrap">
		<h1>Client Review</h1>
		<?php if ( isset( $_GET['rzr_msg'] ) ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( wp_unslash( $_GET['rzr_msg'] ) ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'rzr_cr_save' ); ?>
			<input type="hidden" name="action" value="rzr_cr_save">
			<table class="form-table">
				<tr><th>Review server URL</th><td><input class="regular-text" name="server" value="<?php echo esc_attr( $s['server'] ); ?>" placeholder="https://review.example.com"></td></tr>
				<tr><th>Admin token</th><td><input class="regular-text" type="password" name="token" value="" placeholder="<?php echo $s['token'] ? 'Saved. Leave empty to keep it.' : ''; ?>"></td></tr>
				<tr><th>Mode</th><td>
					<label><input type="radio" name="mode" value="off" <?php checked( $s['mode'], 'off' ); ?>> Off (review closed)</label><br>
					<label><input type="radio" name="mode" value="per_page" <?php checked( $s['mode'], 'per_page' ); ?>> Per page: the toolbar shows only when the URL ends with <code>?review</code></label><br>
					<label><input type="radio" name="mode" value="full_site" <?php checked( $s['mode'], 'full_site' ); ?>> Full site: the toolbar shows on every page (staging sites only)</label>
				</td></tr>
				<tr><th>Name only access</th><td><label><input type="checkbox" name="open" value="1" <?php checked( $s['open'] ); ?>> Anyone with the link can comment after typing their name (no codes to create)</label></td></tr>
				<tr><th>Shared board</th><td><label><input type="checkbox" name="shared" value="1" <?php checked( $s['shared'] ); ?>> Everyone sees everyone's comments. Leave off to let each person see only their own.</label></td></tr>
				<tr><th>Self-serve codes</th><td><label><input type="checkbox" name="selfserve" value="1" <?php checked( $s['selfserve'] ); ?>> Allow <code>?review=CODE</code> with any code a person picks, as their saved identity across devices. Optional; the plain <code>?review</code> link already works without codes.</label></td></tr>
				<tr><th>Note to reviewers</th><td><textarea class="large-text" rows="2" name="note"><?php echo esc_textarea( $s['note'] ); ?></textarea></td></tr>
				<tr><th>Review ID</th><td><code><?php echo esc_html( $s['review'] ); ?></code></td></tr>
			</table>
			<?php submit_button( 'Save and sync' ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:-10px 0 20px">
			<?php wp_nonce_field( 'rzr_cr_check' ); ?>
			<input type="hidden" name="action" value="rzr_cr_check">
			<?php submit_button( 'Check connection', 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $ready ) : ?>
			<h2>Feedback</h2>
			<?php if ( is_wp_error( $found ) ) : ?>
				<p><?php echo esc_html( $found->get_error_message() ); ?></p>
			<?php elseif ( empty( $found['comments'] ) ) : ?>
				<p>No comments yet.</p>
			<?php else : ?>
				<table class="widefat striped"><thead><tr><th>When</th><th>Page</th><th>From</th><th>Type</th><th>Comment</th><th>Status</th><th>Replies</th></tr></thead><tbody>
				<?php foreach ( $found['comments'] as $c ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $c['createdAt'] ) ) ) ) ); ?></td>
						<td><a href="<?php echo esc_url( home_url( $c['page'] ) . '?review' ); ?>" target="_blank"><?php echo esc_html( $c['page'] ); ?></a></td>
						<td><?php echo esc_html( $c['author'] ?? '' ); ?></td>
						<td><?php echo esc_html( $c['type'] ?? '' ); ?></td>
						<td><?php echo esc_html( $c['text'] ?? '' ); ?></td>
						<td><?php echo esc_html( str_replace( '_', ' ', $c['status'] ?? 'open' ) ); ?></td>
						<td><?php echo count( $c['replies'] ?? array() ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}
