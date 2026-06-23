<?php
// Settings page for the "credits" plugin. Admin actions are password-gated and
// go through action.php (server-side); the live balance comes from status.php.
global $pluginSettings, $settings;
if (!isset($pluginSettings) || !is_array($pluginSettings)) $pluginSettings = array();
if (session_status() === PHP_SESSION_NONE) @session_start();

function cr_get($k, $d = '') { global $pluginSettings; return isset($pluginSettings[$k]) ? $pluginSettings[$k] : $d; }
$cr_hasPw    = !empty(cr_get('admin_password_hash'));
$cr_unlocked = (isset($_SESSION['credits_admin']) && $_SESSION['credits_admin'] === true) || !$cr_hasPw;
$cr_spc      = (int)cr_get('seconds_per_credit', 3600);
$cr_mode     = cr_get('count_mode', 'running');
$cr_blank    = (int)cr_get('blank_channels', 524288);
?>
<style>
#cr{max-width:780px;margin:0 auto;color:#1f2733;font-size:14px}
#cr .intro{color:#6b7280;font-size:13px;margin:0 0 16px}
#cr .card{border:1px solid #e4e7ec;border-radius:12px;background:#fff;margin:0 0 14px;overflow:hidden}
#cr .head{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#f6f8fa;border-bottom:1px solid #eceef2}
#cr .head .t{font-size:15px;font-weight:600;flex:1}
#cr .body{padding:16px}
#cr .grid{display:grid;grid-template-columns:170px 1fr;gap:12px 16px;align-items:center}
#cr .lab{font-weight:500;color:#374151}
#cr .help{color:#6b7280;font-size:12.5px;margin-top:3px}
#cr input[type=number],#cr input[type=password],#cr select{padding:8px 10px;border:1px solid #cdd3dc;border-radius:7px;background:#fff;font-size:14px;max-width:220px}
#cr button{padding:8px 14px;border:0;border-radius:7px;background:#2f6fed;color:#fff;font-size:14px;font-weight:600;cursor:pointer}
#cr button.sec{background:#eceef2;color:#374151}
#cr button:disabled{opacity:.5;cursor:default}
#cr .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
#cr .sw{position:relative;display:inline-block;width:46px;height:25px}
#cr .sw input{opacity:0;width:0;height:0}
#cr .sw .sl2{position:absolute;cursor:pointer;inset:0;background:#cbd1da;border-radius:25px;transition:.18s}
#cr .sw .sl2:before{content:"";position:absolute;height:19px;width:19px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.18s}
#cr .sw input:checked + .sl2{background:#2f9e6f}#cr .sw input:checked + .sl2:before{transform:translateX(21px)}
#cr .big{font-size:34px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1}
#cr .sub{color:#6b7280;font-size:13px;margin-top:4px}
#cr .pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;font-size:12.5px;font-weight:600}
#cr .pill.ok{background:#e6f4ed;color:#1d8a5b}#cr .pill.warn{background:#fdecec;color:#c0392b}#cr .pill.off{background:#eceef2;color:#6b7280}
#cr .dot{width:9px;height:9px;border-radius:50%;background:currentColor}
#cr .msg{font-size:13px;margin-top:8px;min-height:18px}
#cr .msg.err{color:#c0392b}#cr .msg.good{color:#1d8a5b}
#cr .bar{height:12px;background:#eceef2;border-radius:7px;overflow:hidden;margin-top:10px}
#cr .bar>div{height:100%;background:#2f9e6f;width:0%;transition:width .3s}
#cr .note{background:#fff8e6;border:1px solid #f5e3a6;color:#7a5d00;padding:10px 12px;border-radius:8px;font-size:13px;margin:0 0 14px}
</style>

<div id="cr" data-unlocked="<?php echo $cr_unlocked ? '1':'0'; ?>" data-haspw="<?php echo $cr_hasPw ? '1':'0'; ?>">
  <p class="intro">Prepaid run-time gating. The device burns one credit per <span id="cr-spc-h"></span> while it runs; when the balance reaches zero all lights are forced off until an admin tops it up. Time is tracked with the monotonic clock, so no RTC is required.</p>

  <?php if (!$cr_hasPw): ?>
  <div class="note">No admin password is set yet. Anyone can change these settings until you set one in <b>Admin access</b> below.</div>
  <?php endif; ?>

  <!-- Live balance -->
  <div class="card">
    <div class="head"><span class="t">Balance</span><span id="cr-state" class="pill off"><span class="dot"></span><span id="cr-state-t">…</span></span></div>
    <div class="body">
      <div class="big"><span id="cr-rem">–</span> <span style="font-size:16px;color:#6b7280;font-weight:600">credits</span></div>
      <div class="sub" id="cr-time">about — left at the current rate</div>
      <div class="bar"><div id="cr-bar"></div></div>
      <div class="sub" id="cr-meta" style="margin-top:10px"></div>
    </div>
  </div>

  <!-- Admin access -->
  <div class="card">
    <div class="head"><span class="t">Admin access</span></div>
    <div class="body">
      <div id="cr-login" style="<?php echo $cr_unlocked ? 'display:none':''; ?>">
        <div class="row">
          <input type="password" id="cr-pw" placeholder="Admin password" autocomplete="current-password">
          <button onclick="crLogin()">Unlock</button>
        </div>
        <div class="help">Enter the admin password to change credits and settings.</div>
      </div>
      <div id="cr-admin-on" style="<?php echo $cr_unlocked ? '':'display:none'; ?>">
        <div class="row">
          <span class="pill ok"><span class="dot"></span>Unlocked</span>
          <?php if ($cr_hasPw): ?><button class="sec" onclick="crLogout()">Lock</button><?php endif; ?>
        </div>
        <div class="row" style="margin-top:14px">
          <input type="password" id="cr-newpw" placeholder="<?php echo $cr_hasPw ? 'New password':'Set a password'; ?>" autocomplete="new-password">
          <button class="sec" onclick="crSetPw()"><?php echo $cr_hasPw ? 'Change password':'Set password'; ?></button>
        </div>
      </div>
      <div class="msg" id="cr-msg-auth"></div>
    </div>
  </div>

  <!-- Admin controls -->
  <div class="card" id="cr-admin" style="<?php echo $cr_unlocked ? '':'display:none'; ?>">
    <div class="head"><span class="t">Admin controls</span></div>
    <div class="body">
      <div class="grid">
        <div class="lab">Plugin enabled</div>
        <div>
          <label class="sw"><input type="checkbox" id="cr-enabled" <?php echo cr_get('enabled')=='1'?'checked':''; ?> onchange="crSetEnabled(this.checked)"><span class="sl2"></span></label>
          <div class="help">When off, lights are never gated.</div>
        </div>

        <div class="lab">Set credits</div>
        <div>
          <div class="row">
            <input type="number" id="cr-setval" min="0" step="1" placeholder="e.g. 100" value="<?php echo (int)cr_get('recharge_value',0); ?>">
            <button onclick="crRecharge()">Set balance</button>
          </div>
          <div class="help">Sets the device's balance to this many credits (a top-up / recharge).</div>
        </div>

        <div class="lab">Seconds per credit</div>
        <div>
          <input type="number" id="cr-spc" min="1" step="1" value="<?php echo $cr_spc; ?>" onchange="crSet('seconds_per_credit', this.value)">
          <div class="help">3600 = one credit per hour. Lower it to test quickly.</div>
        </div>

        <div class="lab">Count time</div>
        <div>
          <select id="cr-mode" onchange="crSet('count_mode', this.value)">
            <option value="running" <?php echo $cr_mode=='running'?'selected':''; ?>>While the device is running</option>
            <option value="playing" <?php echo $cr_mode=='playing'?'selected':''; ?>>Only while a sequence is playing</option>
          </select>
        </div>

        <div class="lab">Channels to blank</div>
        <div>
          <input type="number" id="cr-blank" min="0" step="1" value="<?php echo $cr_blank; ?>" onchange="crSet('blank_channels', this.value)">
          <div class="help">How many channels to force off when out of credits. Default covers any home display; raise to your exact channel count if larger.</div>
        </div>
      </div>
      <div class="msg" id="cr-msg"></div>
    </div>
  </div>
</div>

<script>
(function(){
  var base = 'plugin.php?plugin=credits&page=';
  var spc = <?php echo $cr_spc; ?>;
  function $(id){return document.getElementById(id);}
  function fmtDur(sec){
    if(!isFinite(sec)||sec<0) return '—';
    var h=Math.floor(sec/3600), m=Math.floor((sec%3600)/60);
    if(h>=24){var d=Math.floor(h/24);return d+'d '+(h%24)+'h';}
    if(h>0) return h+'h '+m+'m';
    if(m>0) return m+'m';
    return Math.floor(sec)+'s';
  }
  function post(action, data, cb){
    var fd=new FormData(); fd.append('action',action);
    for(var k in data) fd.append(k,data[k]);
    fetch(base+'action.php&nopage=1',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();}).then(cb).catch(function(){cb({ok:false,error:'Request failed'});});
  }
  function msg(id,text,ok){var e=$(id);e.textContent=text||'';e.className='msg '+(text?(ok?'good':'err'):'');}

  // The full-page render can't read the PHP session (FPP sends HTML before our
  // session_start), so we never trust server-rendered lock state — we ask
  // action.php (a nopage=1 request, where the session works) and toggle here.
  function applyUnlock(u){
    $('cr-login').style.display = u ? 'none' : '';
    $('cr-admin-on').style.display = u ? '' : 'none';
    $('cr-admin').style.display = u ? '' : 'none';
  }
  window.crLogin=function(){
    post('login',{pw:$('cr-pw').value},function(r){
      if(r.ok){$('cr-pw').value='';msg('cr-msg-auth','Unlocked.',true);applyUnlock(true);}
      else msg('cr-msg-auth',r.error||'Login failed',false);
    });
  };
  window.crLogout=function(){post('logout',{},function(){applyUnlock(false);msg('cr-msg-auth','Locked.',true);});};
  window.crSetPw=function(){
    var pw=$('cr-newpw').value;
    post('setpw',{pw:pw},function(r){
      if(r.ok){msg('cr-msg-auth','Password saved.',true);$('cr-newpw').value='';applyUnlock(true);}
      else msg('cr-msg-auth',r.error||'Could not set password',false);
    });
  };
  // Decide the real lock state on load.
  post('check',{},function(r){ if(r.ok) applyUnlock(r.unlocked); });
  window.crSetEnabled=function(on){post('set',{enabled:on?'1':'0'},function(r){msg('cr-msg',r.ok?'Saved.':(r.error||'Failed'),r.ok);});};
  window.crSet=function(k,v){var d={};d[k]=v;if(k==='seconds_per_credit')spc=parseInt(v)||3600;post('set',d,function(r){msg('cr-msg',r.ok?'Saved.':(r.error||'Failed'),r.ok);});};
  window.crRecharge=function(){
    var v=parseInt($('cr-setval').value);
    if(isNaN(v)||v<0){msg('cr-msg','Enter a valid number of credits',false);return;}
    post('recharge',{value:v},function(r){msg('cr-msg',r.ok?('Balance set to '+r.value+' credits.'):(r.error||'Failed'),r.ok);});
  };

  function poll(){
    fetch(base+'status.php&nopage=1',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(s){
      spc = s.secondsPerCredit||spc;
      $('cr-spc-h').textContent = spc===3600 ? 'hour' : (spc+'s');
      $('cr-rem').textContent = (s.remaining!=null)? Number(s.remaining).toFixed(2) : '–';
      $('cr-time').textContent = 'about '+fmtDur((s.remaining||0)*spc)+' left at the current rate';
      var st=$('cr-state'), t=$('cr-state-t');
      if(!s.enabled){st.className='pill off';t.textContent='Plugin off';}
      else if(s.blocking){st.className='pill warn';t.textContent='OUT OF CREDITS — lights off';}
      else {st.className='pill ok';t.textContent='Active';}
      var pct = Math.max(0,Math.min(100, (s.remaining||0) /  Math.max(1,(s.remaining||0)+ (s.consumed||0)) *100));
      $('cr-bar').style.width = pct+'%';
      $('cr-bar').style.background = s.blocking ? '#c0392b' : (s.remaining<2 ? '#e0922f' : '#2f9e6f');
      $('cr-meta').innerHTML = 'Used '+Number(s.consumed||0).toFixed(2)+' credits this top-up · counting <b>'+(s.countMode==='playing'?'only while playing':'while running')
        +'</b>'+(s.countMode==='playing'?(' · '+(s.playing?'sequence playing':'idle')):'')
        + (s.live?'':' · <span style="color:#c0392b">no live data (is fppd running with the plugin loaded?)</span>');
    }).catch(function(){});
  }
  poll(); setInterval(poll, 3000);
})();
</script>
