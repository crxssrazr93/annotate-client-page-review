<?php
/**
 * Plugin Name: Annotate Client Review
 * Description: Lets clients comment on pages with annotate.js. Feedback is stored on your review server. Per page mode: add ?review to a page URL. Full site mode: the review toolbar is on every page (for staging sites).
 * Version: 1.1.1
 */

defined( 'ABSPATH' ) || exit;

const ACR_OPT        = 'acr_client_review';
const ACR_ANNOTATE   = 'https://cdn.jsdelivr.net/npm/@reviewjs/annotate@1.6.0/annotate.js';
const ACR_TOKEN_RE   = '/^[A-Za-z0-9_-]{1,64}$/'; // a server invite (inv_...) or a self-serve code

function acr_settings() {
	return wp_parse_args( get_option( ACR_OPT, array() ), array(
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

function acr_origin() {
	$p = wp_parse_url( home_url() );
	return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
}

/** Calls the review server's admin API. Returns array data or WP_Error. */
function acr_token() {
	// Prefer a server-side secret (keeps the admin token out of the WordPress database).
	if ( defined( 'ACR_TOKEN' ) && ACR_TOKEN ) {
		return ACR_TOKEN;
	}
	$env = getenv( 'ACR_TOKEN' );
	if ( $env ) {
		return $env;
	}
	$s = acr_settings();
	return $s['token'];
}

function acr_api( $method, $path, $body = null ) {
	$s     = acr_settings();
	$token = acr_token();
	if ( ! $s['server'] || ! $token ) {
		return new WP_Error( 'acr_config', 'Set the server URL and admin token first.' );
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
		return new WP_Error( 'acr_http', 'Review server answered ' . $code . ': ' . ( $data['error'] ?? 'error' ) );
	}
	return is_array( $data ) ? $data : array();
}

function acr_sync_review() {
	$s = acr_settings();
	return acr_api( 'PUT', '/admin/reviews/' . rawurlencode( $s['review'] ), array(
		'name'    => get_bloginfo( 'name' ),
		'note'    => $s['note'],
		'origins' => array( acr_origin() ),
		'open'    => (bool) $s['open'],
		'shared'  => (bool) $s['shared'],
		'selfServe' => (bool) $s['selfserve'],
		'closed'  => 'off' === $s['mode'],
	) );
}

/* ---------- Front end ---------- */

function acr_active_request() {
	if ( is_admin() || wp_doing_ajax() || isset( $_GET['elementor-preview'] ) || is_feed() ) {
		return false;
	}
	$s = acr_settings();
	if ( ! $s['server'] || 'off' === $s['mode'] ) {
		return false;
	}
	return 'full_site' === $s['mode'] || isset( $_GET['review'] );
}

function acr_snippet() {
	$s     = acr_settings();
	$token = isset( $_GET['review'] ) ? sanitize_text_field( wp_unslash( $_GET['review'] ) ) : '';
	$token = preg_match( ACR_TOKEN_RE, $token ) ? $token : '';
	$page  = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ) ?: '/';

	$cfg = array(
		'api'      => untrailingslashit( $s['server'] ),
		'review'   => $s['review'],
		'page'     => $page,
		'note'     => $s['note'],
		'token'    => $token,
		'annotate' => ACR_ANNOTATE,
		'picker'   => true,
	);
	$json = wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	return '<script>window.ACR=' . $json . ';' . acr_bootstrap_js() . '</script>' . "\n";
}

/** Front-end bootstrap: identity so viewers see the shared board, plus a custom colour picker. */
function acr_bootstrap_js() {
	return <<<'JS'
(function(){
 var C=window.ACR||{}; if(!C.api||!C.review) return;
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
  function wipe(){ var o=document.getElementById('acr-draft'); if(o&&o.parentNode)o.parentNode.removeChild(o); if(watch){clearInterval(watch);watch=null;} }
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
   var col=colr(), gEl=document.createElementNS(NS,'g'); gEl.setAttribute('id','acr-draft'); gEl.setAttribute('pointer-events','none');
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
 // Custom colour: a "+" swatch inside annotate's own colour menu (#__an_colorpop) that opens a
 // small in-page picker anchored to the toolbar. We avoid the native <input type=color> because
 // on some platforms (e.g. Linux/GTK) it opens in a screen corner, far from the toolbar.
 function picker(){
  var tries=0;
  (function attach(){
   var pop=document.getElementById('__an_colorpop');
   if(!pop){ if(tries++<80){ setTimeout(attach,250); } return; }
   if(document.getElementById('acr-pick')) return;
   var plus=document.createElement('button'); plus.type='button'; plus.className='an-sw'; plus.id='acr-pick'; plus.title='Custom colour';
   // Rainbow fill with a white ring and a white + (matching the presets) so it reads as "pick any colour".
   plus.style.cssText='width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:conic-gradient(from 90deg,#f43f5e,#f59e0b,#eab308,#10b981,#0ea5e9,#8b5cf6,#ec4899,#f43f5e);border:2px solid #fff;color:#fff;font:800 16px/1 system-ui,-apple-system,sans-serif;cursor:pointer;position:relative;box-sizing:border-box;padding:0;text-shadow:0 0 2px rgba(0,0,0,.55),0 0 2px rgba(0,0,0,.55)';
   plus.appendChild(document.createTextNode('+'));
   plus.addEventListener('click',function(e){ e.preventDefault(); e.stopPropagation(); openPicker(); });
   pop.appendChild(plus);
  })();
 }
 function clampf(x){ return Math.max(0,Math.min(1,x)); }
 function hsv2rgb(h,sa,va){ var c=va*sa, x=c*(1-Math.abs((h/60)%2-1)), m=va-c, r=0,gg=0,b=0;
  if(h<60){r=c;gg=x;} else if(h<120){r=x;gg=c;} else if(h<180){gg=c;b=x;} else if(h<240){gg=x;b=c;} else if(h<300){r=x;b=c;} else {r=c;b=x;}
  return [Math.round((r+m)*255),Math.round((gg+m)*255),Math.round((b+m)*255)]; }
 function hsv2hex(h,sa,va){ return '#'+hsv2rgb(h,sa,va).map(function(n){ return ('0'+n.toString(16)).slice(-2); }).join(''); }
 function hex2hsv(hex){ var m=/^#?([0-9a-f]{6})$/i.exec(String(hex||'').trim()); if(!m) return null;
  var n=parseInt(m[1],16), r=(n>>16&255)/255, gg=(n>>8&255)/255, b=(n&255)/255;
  var mx=Math.max(r,gg,b), mn=Math.min(r,gg,b), d=mx-mn, h=0;
  if(d){ if(mx===r)h=((gg-b)/d)%6; else if(mx===gg)h=(b-r)/d+2; else h=(r-gg)/d+4; h*=60; if(h<0)h+=360; }
  return [h, mx?d/mx:0, mx]; }
 function bindDrag(el,fn){ el.addEventListener('pointerdown',function(e){ e.preventDefault(); if(el.setPointerCapture){try{el.setPointerCapture(e.pointerId);}catch(x){}} fn(e);
  function mv(ev){ fn(ev); } function up(){ el.removeEventListener('pointermove',mv); el.removeEventListener('pointerup',up); }
  el.addEventListener('pointermove',mv); el.addEventListener('pointerup',up); }); }
 function closePicker(){ var p=document.getElementById('acr-cpick'); if(p){ if(p._out) document.removeEventListener('pointerdown',p._out,true); p.remove(); } }
 function openPicker(){
  closePicker();
  var hsv=hex2hsv(g(ck)||dft)||[160,.6,.7], ch=hsv[0], cs=hsv[1], cv=hsv[2], SVW=172, SVH=120;
  var P=document.createElement('div'); P.id='acr-cpick';
  P.style.cssText='position:fixed;z-index:2147483647;width:196px;background:#fff;border:1px solid rgba(0,0,0,.12);border-radius:12px;padding:12px;box-shadow:0 10px 30px rgba(0,0,0,.18);font:500 12px system-ui,-apple-system,sans-serif;color:#333;touch-action:none';
  var svArea=document.createElement('div'); svArea.style.cssText='position:relative;width:'+SVW+'px;height:'+SVH+'px;border-radius:8px;overflow:hidden;cursor:crosshair';
  var svDot=document.createElement('div'); svDot.style.cssText='position:absolute;width:12px;height:12px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.45);transform:translate(-50%,-50%);pointer-events:none'; svArea.appendChild(svDot);
  var hueBar=document.createElement('div'); hueBar.style.cssText='position:relative;height:12px;margin-top:10px;border-radius:6px;cursor:pointer;background:linear-gradient(to right,#f00,#ff0,#0f0,#0ff,#00f,#f0f,#f00)';
  var hueDot=document.createElement('div'); hueDot.style.cssText='position:absolute;top:50%;width:14px;height:14px;border-radius:50%;background:#fff;border:1px solid rgba(0,0,0,.35);transform:translate(-50%,-50%);pointer-events:none'; hueBar.appendChild(hueDot);
  var foot=document.createElement('div'); foot.style.cssText='display:flex;align-items:center;gap:8px;margin-top:10px';
  var prev=document.createElement('div'); prev.style.cssText='width:26px;height:26px;border-radius:6px;border:1px solid rgba(0,0,0,.18);flex:none';
  var hx=document.createElement('input'); hx.type='text'; hx.spellcheck=false; hx.maxLength=7; hx.style.cssText='flex:1;min-width:0;background:#f6f6f7;border:1px solid rgba(0,0,0,.14);border-radius:6px;color:#222;padding:5px 7px;font:inherit';
  var ok=document.createElement('button'); ok.type='button'; ok.textContent='Apply'; ok.style.cssText='background:#17171f;color:#fff;border:0;border-radius:6px;padding:6px 11px;font:700 12px system-ui,sans-serif;cursor:pointer';
  foot.appendChild(prev); foot.appendChild(hx); foot.appendChild(ok);
  P.appendChild(svArea); P.appendChild(hueBar); P.appendChild(foot);
  document.body.appendChild(P);
  var bar=document.getElementById('__an_bar'), br=bar?bar.getBoundingClientRect():null, pw=P.offsetWidth||220, ph=P.offsetHeight||200;
  P.style.left=(br?Math.max(8, br.left-pw-10):Math.max(8, window.innerWidth-pw-70))+'px';
  P.style.top=(br?Math.min(window.innerHeight-ph-8, Math.max(8, br.top+br.height/2-ph/2)):80)+'px';
  function render(kbd){ svArea.style.background='linear-gradient(to top,#000,rgba(0,0,0,0)),linear-gradient(to right,#fff,hsl('+ch+',100%,50%))';
   svDot.style.left=(cs*SVW)+'px'; svDot.style.top=((1-cv)*SVH)+'px'; hueDot.style.left=((ch/360)*SVW)+'px';
   var hex=hsv2hex(ch,cs,cv); prev.style.background=hex; if(!kbd) hx.value=hex; }
  render();
  bindDrag(svArea,function(e){ var r=svArea.getBoundingClientRect(); cs=clampf((e.clientX-r.left)/r.width); cv=clampf(1-(e.clientY-r.top)/r.height); render(); });
  bindDrag(hueBar,function(e){ var r=hueBar.getBoundingClientRect(); ch=clampf((e.clientX-r.left)/r.width)*360; render(); });
  hx.addEventListener('input',function(){ var hv=hex2hsv(hx.value); if(hv){ ch=hv[0]; cs=hv[1]; cv=hv[2]; render(true); } });
  function apply(){ s(ck, hsv2hex(ch,cs,cv)); location.reload(); }
  ok.addEventListener('click',apply);
  hx.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); apply(); } });
  setTimeout(function(){ P._out=function(e){ if(!P.contains(e.target)){ closePicker(); } }; document.addEventListener('pointerdown',P._out,true); },0);
 }
})();
JS;
}

add_action( 'send_headers', function () {
	if ( acr_active_request() && isset( $_GET['review'] ) ) {
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
} );

add_action( 'wp_footer', function () {
	if ( acr_active_request() ) {
		echo acr_snippet(); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* above.
	}
}, 99 );

// Pages served by the Raw HTML Pages plugin skip wp_footer, so add the toolbar there too.
add_filter( 'acr_raw_html_output', function ( $html ) {
	if ( ! acr_active_request() ) {
		return $html;
	}
	$snippet = acr_snippet();
	$pos     = strripos( $html, '</body>' );
	return false === $pos ? $html . $snippet : substr_replace( $html, $snippet, $pos, 0 );
} );

/* ---------- Admin ---------- */

add_action( 'admin_menu', function () {
	add_options_page( 'Annotate Client Review', 'Annotate Client Review', 'manage_options', 'annotate-client-review', 'acr_page' );
} );

add_action( 'admin_post_acr_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'acr_save' );
	$s           = acr_settings();
	$s['server'] = esc_url_raw( wp_unslash( $_POST['server'] ?? '' ) );
	if ( ! empty( $_POST['token'] ) ) {
		$s['token'] = sanitize_text_field( wp_unslash( $_POST['token'] ) );
	}
	$s['mode'] = in_array( $_POST['mode'] ?? '', array( 'off', 'per_page', 'full_site' ), true ) ? $_POST['mode'] : 'off';
	$s['open']     = empty( $_POST['open'] ) ? 0 : 1;
	$s['shared']   = empty( $_POST['shared'] ) ? 0 : 1;
	$s['selfserve'] = empty( $_POST['selfserve'] ) ? 0 : 1;
	$s['note'] = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
	update_option( ACR_OPT, $s, false );
	$r = acr_sync_review();
	acr_redirect( is_wp_error( $r ) ? $r->get_error_message() : 'Saved and synced to the review server.' );
} );

add_action( 'admin_post_acr_check', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'acr_check' );
	$s = acr_settings();
	$r = acr_api( 'GET', '/admin/reviews/' . rawurlencode( $s['review'] ) );
	if ( is_wp_error( $r ) ) {
		acr_redirect( 'Connection failed. ' . $r->get_error_message() );
	}
	acr_redirect( 'Connection OK. The server knows this review (' . esc_html( $s['review'] ) . ').' );
} );

function acr_redirect( $msg ) {
	wp_safe_redirect( add_query_arg( array( 'page' => 'annotate-client-review', 'acr_msg' => rawurlencode( $msg ) ), admin_url( 'options-general.php' ) ) );
	exit;
}

function acr_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s      = acr_settings();
	$ready  = $s['server'] && acr_token();
	$found  = $ready ? acr_api( 'GET', '/admin/reviews/' . rawurlencode( $s['review'] ) . '/comments' ) : null;
	?>
	<div class="wrap">
		<h1>Annotate Client Review</h1>
		<?php if ( isset( $_GET['acr_msg'] ) ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( wp_unslash( $_GET['acr_msg'] ) ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'acr_save' ); ?>
			<input type="hidden" name="action" value="acr_save">
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
			<?php wp_nonce_field( 'acr_check' ); ?>
			<input type="hidden" name="action" value="acr_check">
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
