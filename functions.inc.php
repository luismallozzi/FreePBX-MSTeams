<?php
namespace FreePBX\modules;
use BMO;
use FreePBX_Helpers;

class Msteams extends FreePBX_Helpers implements BMO {
	const CUSTOM_FILE = '/etc/asterisk/pjsip.transports_custom.conf';
	const MARK_BEGIN  = '; BEGIN:msteams';
	const MARK_END    = '; END:msteams';

	// Satisfy FileHooks: provide genConfig, and accept optional param in writeConfig
	public function genConfig() { return []; }

	public function writeConfig($engine = null) {
		$db = \FreePBX::Database();
		$rows = $db->query("SELECT trunkid, msaddr, base_transport FROM msteams_trunks")->fetchAll(\PDO::FETCH_ASSOC);
		$blocks = [self::MARK_BEGIN];
		foreach ($rows as $r) {
			$core = \FreePBX::Core();
			$t = $core->getTrunkByID($r['trunkid']);
			if (!$t || $t['tech'] !== 'pjsip') { continue; }
			$name = preg_replace('/[^A-Za-z0-9_\-]/','', $t['name']);
			$clone = "{$r['base_transport']}-teams-{$name}-{$r['trunkid']}";
			$blocks[] = "[{$clone}]({$r['base_transport']})";
			$blocks[] = "type=transport";
			$blocks[] = "ms_signaling_address={$r['msaddr']}";
			$blocks[] = "";
		}
		$blocks[] = self::MARK_END;

		$old = is_file(self::CUSTOM_FILE) ? file_get_contents(self::CUSTOM_FILE) : '';
		$new = $this->injectMarked($old, implode(PHP_EOL, $blocks));
		file_put_contents(self::CUSTOM_FILE, $new);
		@chown(self::CUSTOM_FILE, 'asterisk'); @chgrp(self::CUSTOM_FILE, 'asterisk');
	}

	// BMO required
	public function install() {}
	public function uninstall() {}

	// Hooks
	public static function myGuiHooks() { return ['core']; }
	public static function myConfigPageInits() { return ['trunks']; }

	private function injectMarked($orig, $block) {
		if ($orig === '') return $block . PHP_EOL;
		$re = '/' . preg_quote(self::MARK_BEGIN,'/') . '.*?' . preg_quote(self::MARK_END,'/') . '/s';
		if (preg_match($re, $orig)) return preg_replace($re, $block, $orig);
		return rtrim($orig) . PHP_EOL . $block . PHP_EOL;
	}

	public function doConfigPageInit($page) {
		if ($page !== 'trunks') { return; }

		// Manipular salvamento
		$request = $this->getSanitizedRequest();
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$action = $request['action'] ?? '';

		// Extrair trunkId de diferentes formatos (ex: OUT_123)
		$extdisplay = $request['extdisplay'] ?? '';
		if (is_string($extdisplay) && strncmp($extdisplay, 'OUT_', 4) === 0) {
			$trunkId = (int)substr($extdisplay, 4);
		} else {
			$trunkId = (int)($request['trunkid'] ?? $request['id'] ?? 0);
		}

		if ($method === 'POST') {
			$msAddr = trim($_POST['msteams_msaddr'] ?? ''); // usar bruto para permitir FQDN com '-'
			// Se for adição, ainda não temos trunkId. Guardar em sessão para aplicar após redirect.
			if ($trunkId <= 0 && $action === 'addtrunk') {
				if ($msAddr !== '') {
					$pending = [
						'msaddr' => $msAddr,
						'base'   => $_POST['transport'] ?? '0.0.0.0-tls',
					];
					$_SESSION['MSTEAMS_PENDING'] = $pending;
				} else {
					unset($_SESSION['MSTEAMS_PENDING']);
				}
				return;
			}

			// Edição de tronco existente
			if ($trunkId > 0) {
				$db = \FreePBX::Database();
				$core = \FreePBX::Core();
				$tr = $core->getTrunkByID($trunkId);
				if ($tr && ($tr['tech'] ?? '') === 'pjsip') {
					$details = $core->getTrunkDetails($trunkId);
					$base = $details['transport'] ?? ($_POST['transport'] ?? '0.0.0.0-tls');
					if ($msAddr === '') {
						$db->prepare('DELETE FROM msteams_trunks WHERE trunkid=?')->execute([$trunkId]);
						$db->prepare("UPDATE pjsip SET data=? WHERE id=? AND keyword='transport'")->execute([$base,$trunkId]);
					} else {
						$db->prepare('REPLACE INTO msteams_trunks (trunkid, msaddr, base_transport) VALUES (?,?,?)')
						   ->execute([$trunkId, $msAddr, $base]);
						$name = preg_replace('/[^A-Za-z0-9_\-]/','', $tr['name']);
						$clone = "$base-teams-$name-$trunkId";
						$db->prepare("UPDATE pjsip SET data=? WHERE id=? AND keyword='transport'")->execute([$clone,$trunkId]);
					}
				}
			}
		} else {
			// GET após addtrunk: aplicar pendente se houver e agora temos OUT_<id>
			if (!empty($_SESSION['MSTEAMS_PENDING']) && $trunkId > 0) {
				$db = \FreePBX::Database();
				$core = \FreePBX::Core();
				$tr = $core->getTrunkByID($trunkId);
				if ($tr && ($tr['tech'] ?? '') === 'pjsip') {
					$details = $core->getTrunkDetails($trunkId);
					$baseFromDb = $details['transport'] ?? '0.0.0.0-tls';
					$pending = $_SESSION['MSTEAMS_PENDING'];
					$msAddr = trim($pending['msaddr'] ?? '');
					$base = $pending['base'] ?? $baseFromDb;
					if ($msAddr !== '') {
						$db->prepare('REPLACE INTO msteams_trunks (trunkid, msaddr, base_transport) VALUES (?,?,?)')
						   ->execute([$trunkId, $msAddr, $base]);
						$name = preg_replace('/[^A-Za-z0-9_\-]/','', $tr['name']);
						$clone = "$base-teams-$name-$trunkId";
						$db->prepare("UPDATE pjsip SET data=? WHERE id=? AND keyword='transport'")->execute([$clone,$trunkId]);
					}
				}
				unset($_SESSION['MSTEAMS_PENDING']);
			}
		}
	}

	// Inject UI via GUI hook (runs after core/trunks page renders)
	public function doGuiHook(&$cc) {
		if (($_REQUEST['display'] ?? '') !== 'trunks') { return; }

		$db = \FreePBX::Database();
		$existing = [];
		try {
			$existing = $db->query('SELECT trunkid, msaddr FROM msteams_trunks')->fetchAll(\PDO::FETCH_KEY_PAIR);
		} catch (\Throwable $e) {}

		$currId = 0;
		$extdisplay = $_GET['extdisplay'] ?? '';
		if (is_string($extdisplay) && strncmp($extdisplay,'OUT_',4) === 0) {
			$currId = (int)substr($extdisplay,4);
		} else {
			$currId = (int)($_GET['id'] ?? 0);
		}
		$currVal = ($currId && isset($existing[$currId])) ? htmlspecialchars($existing[$currId], ENT_QUOTES, 'UTF-8') : '';

		$label = function_exists('dgettext') ? dgettext('msteams', 'MS Teams Signaling Address (FQDN)') : _("MS Teams Signaling Address (FQDN)");
		$help  = function_exists('dgettext') ? dgettext('msteams', 'Leave blank to remove and restore original transport.') : _("Leave blank to remove and restore original transport.");
		echo '<script>'
		  . '(function(){'
		  . 'var tries=0, obs;'
		  . 'function findTransportContainer(){'
		  . '  var a=document.querySelector("#pjsgeneral .element-container select#transport");'
		  . '  return a ? a.closest(".element-container") : null;'
		  . '}'
		  . 'function buildGroup(){'
		  . '  var wrap=document.createElement("div");'
		  . '  wrap.className="element-container";'
		  . '  wrap.id="msteams-msaddr-wrap";'
		  . '  wrap.innerHTML='.
			json_encode(
			  '<div class="row">'
			.   '<div class="col-md-12">'
			.     '<div class="row">'
			.       '<div class="form-group">'
			.         '<div class="col-md-3">'
			.           '<label class="control-label" for="msteams_msaddr">'.htmlentities($label, ENT_QUOTES, 'UTF-8').'</label>'
			.           '<i class="fa fa-question-circle fpbx-help-icon" data-for="msteams_msaddr"></i>'
			.         '</div>'
			.         '<div class="col-md-9">'
			.           '<input name="msteams_msaddr" id="msteams_msaddr" class="form-control" placeholder="sip.pstnhub.microsoft.com" value="'.$currVal.'">'
			.         '</div>'
			.       '</div>'
			.     '</div>'
			.   '</div>'
			. '</div>'
			. '<div class="row">'
			.   '<div class="col-md-12">'
			.     '<span id="msteams_msaddr-help" class="help-block fpbx-help-block">'.htmlentities($help, ENT_QUOTES, 'UTF-8').'</span>'
			.   '</div>'
			. '</div>'
			)
			. ';'
		  . '  return wrap;'
		  . '}'
		  . 'function inject(){'
		  . '  if(document.getElementById("msteams-msaddr-wrap")) return true;'
		  . '  var cont=findTransportContainer();'
		  . '  if(!cont||!cont.parentElement) return false;'
		  . '  var grp=buildGroup();'
		  . '  cont.parentElement.insertBefore(grp, cont.nextSibling);'
		  . '  return true;'
		  . '}'
		  . 'function tryInject(){ if(inject()) return; if(tries++<40){ setTimeout(tryInject,200);} }'
		  . 'function startObserver(){ if(obs) return; obs=new MutationObserver(function(){ inject(); }); obs.observe(document.getElementById("pjsgeneral")||document.body,{childList:true,subtree:true}); }'
		  . 'if(document.readyState!=="loading"){ startObserver(); tryInject(); } else { document.addEventListener("DOMContentLoaded", function(){ startObserver(); tryInject(); }); }'
		  . '})();'
		  . '</script>';
	}
}
