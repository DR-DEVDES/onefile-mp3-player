<?php
/**
 * OneFileMP3Player — Drop it in. Press play. — a responsive mp3 web player
 * Put this file in the directory you want to browse.
 */
	// --------------------- KONFIGURATION ----------------------

	define('CHARSET', 'UTF-8');
	define('PLAYER_TITLE', 'AI // Player');
	define('TRACK_AS_TITLE', true);
	define('MIN_SEARCH_STR', 1);
	define('MAX_SEARCH_RESULT', 150);
	define('AUDIO_EXTENSIONS', ['mp3', 'ogg', 'wav', 'flac', 'm4a', 'opus']);
	define('DIR_EXCLUDE_ARR', []); // nur Kleinschreibung
	define('DIR_EXCLUDE_REGEX', '');
	define('SKIP_BUTTONS', [-60, -30, -10, -5, 5, 10, 30, 60]);
	define('SHOW_COVER_DEFAULT', true);

	define('TXT_ROOT', 'Home');

	// ------------------- ENDE KONFIGURATION --------------------


	mb_internal_encoding(CHARSET);


	// ============================================================
	// Dateisystem-Helfer
	// ============================================================

	function is_ok_dir($dir, $item)
	{
		return (is_dir('./' . $dir . $item)
			and $item !== '.'
			and $item !== '..'
			and !in_array(mb_strtolower($item), DIR_EXCLUDE_ARR)
			and (DIR_EXCLUDE_REGEX === '' or !@preg_match(DIR_EXCLUDE_REGEX, $item)));
	}

	function is_audio_file($item)
	{
		$dot = mb_strrpos($item, '.');
		if ($dot === false) return false;
		$ext = mb_strtolower(mb_substr($item, $dot + 1));
		return in_array($ext, AUDIO_EXTENSIONS, true);
	}

	function safe_path($p)
	{
		return (mb_strpos('./' . $p, '/../') === false)
			and (mb_strpos($p, '\\') === false)
			and (mb_strpos($p, "\0") === false);
	}

	function last_dir_in_string($dir)
	{
		$parts = explode('/', trim($dir, '/'));
		return $parts[count($parts) - 1];
	}

	function parent_dir($dir)
	{
		$parts = explode('/', trim($dir, '/'));
		$out = '';
		for ($i = 0; $i < count($parts) - 1; $i++) {
			$out .= $parts[$i] . '/';
		}
		return $out;
	}

	function get_dirs_and_titles($dir)
	{
		$dirs = [];
		$titles = [];
		$items = @scandir('./' . $dir);
		if ($items !== false) {
			foreach ($items as $item) {
				if (is_ok_dir($dir, $item)) {
					$dirs[] = $item;
				} elseif (is_audio_file($item)) {
					$titles[] = $item;
				}
			}
		}
		sort($dirs, SORT_FLAG_CASE | SORT_NATURAL);
		sort($titles, SORT_FLAG_CASE | SORT_NATURAL);
		return [$dirs, $titles];
	}

	function search_title($str, $dir = '')
	{
		$found = [];
		$items = @scandir('./' . $dir);
		if ($items !== false) {
			foreach ($items as $item) {
				if (is_ok_dir($dir, $item)) {
					$found = array_merge($found, search_title($str, $dir . $item . '/'));
				} elseif (is_audio_file($item) and mb_stripos($item, $str) !== false) {
					$found[] = $dir . $item;
				}
				if (count($found) > MAX_SEARCH_RESULT) {
					return array_slice($found, 0, MAX_SEARCH_RESULT + 1);
				}
			}
		}
		return $found;
	}

	function search_dir($str, $dir = '')
	{
		$found = [];
		$items = @scandir('./' . $dir);
		if ($items !== false) {
			foreach ($items as $item) {
				if (is_ok_dir($dir, $item)) {
					$found = array_merge($found, search_dir($str, $dir . $item . '/'));
					if (mb_stripos($item, $str) !== false) {
						$found[] = $dir . $item;
					}
				}
				if (count($found) > MAX_SEARCH_RESULT) {
					return array_slice($found, 0, MAX_SEARCH_RESULT + 1);
				}
			}
		}
		return $found;
	}

	function find_first_title_from($startDir, $getTracksFromStartDir, $comingFrom = '', $loop = true)
	{
		list($dirs, $titles) = get_dirs_and_titles($startDir);

		if ($comingFrom !== '') {
			$comingFromName = last_dir_in_string($comingFrom);
			$idx = array_search($comingFromName, $dirs);
			if ($idx !== false and isset($dirs[$idx + 1])) {
				$result = find_first_title_from($startDir . $dirs[$idx + 1] . '/', true, '', $loop);
				if ($result !== false) return $result;
			} else {
				if ($startDir === '' and !$loop) {
					return [$startDir, 'stop'];
				}
				$parentIsRoot = (parent_dir($startDir) === '');
				$result = find_first_title_from(parent_dir($startDir), $parentIsRoot, $startDir, $loop);
				if ($result !== false) return $result;
			}
		}

		if ($getTracksFromStartDir and count($titles) > 0) {
			return [$startDir, $titles];
		}

		if (count($dirs) > 0) {
			$result = find_first_title_from($startDir . $dirs[0] . '/', true, '', $loop);
			if ($result !== false) return $result;
		}

		return find_first_title_from(parent_dir($startDir), false, $startDir, $loop);
	}


	// ============================================================
	// ID3v2 Parser (Titel / Interpret / Album / Cover-Art)
	// ============================================================

	function read_syncsafe_int($bytes)
	{
		return (ord($bytes[0]) << 21) | (ord($bytes[1]) << 14) | (ord($bytes[2]) << 7) | ord($bytes[3]);
	}

	function read_be_int($bytes)
	{
		return (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
	}

	function decode_id3_text($data)
	{
		if (strlen($data) < 1) return '';
		$enc = ord($data[0]);
		$text = substr($data, 1);
		switch ($enc) {
			case 0: $text = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $text); break;
			case 1: $text = @iconv('UTF-16', 'UTF-8//IGNORE', $text); break;
			case 2: $text = @iconv('UTF-16BE', 'UTF-8//IGNORE', $text); break;
			case 3: break; // schon UTF-8
		}
		return trim(rtrim((string)$text, "\x00"));
	}

	function decode_id3_picture($data, $isV22 = false)
	{
		if (strlen($data) < 4) return null;
		$enc = ord($data[0]);
		$offset = 1;

		if ($isV22) {
			$map = ['JPG' => 'image/jpeg', 'PNG' => 'image/png'];
			$fmt = substr($data, $offset, 3);
			$mime = $map[$fmt] ?? 'image/jpeg';
			$offset += 3;
		} else {
			$nul = strpos($data, "\x00", $offset);
			if ($nul === false) return null;
			$mime = substr($data, $offset, $nul - $offset);
			$offset = $nul + 1;
		}

		$offset += 1; // Bild-Typ-Byte (Cover front/back etc.)

		if ($enc === 1 or $enc === 2) {
			$descEnd = strpos($data, "\x00\x00", $offset);
			$offset = ($descEnd !== false) ? $descEnd + 2 : $offset;
		} else {
			$descEnd = strpos($data, "\x00", $offset);
			$offset = ($descEnd !== false) ? $descEnd + 1 : $offset;
		}

		$imageData = substr($data, $offset);
		if (strlen($imageData) < 10) return null;
		if (empty($mime)) $mime = 'image/jpeg';

		return 'data:' . $mime . ';base64,' . base64_encode($imageData);
	}

	function get_id3_tags($path)
	{
		$result = ['title' => null, 'artist' => null, 'album' => null, 'cover' => null];
		$fp = @fopen($path, 'rb');
		if (!$fp) return $result;

		$header = fread($fp, 10);
		if (strlen($header) < 10 or substr($header, 0, 3) !== 'ID3') {
			fclose($fp);
			return $result;
		}

		$version = ord($header[3]);
		$flags = ord($header[5]);
		$tagSize = read_syncsafe_int(substr($header, 6, 4));
		$tagData = fread($fp, $tagSize);
		fclose($fp);

		$pos = 0;
		if ($flags & 0x40) { // Extended Header vorhanden
			$extSizeBytes = substr($tagData, 0, 4);
			$extSize = ($version >= 4) ? read_syncsafe_int($extSizeBytes) : read_be_int($extSizeBytes);
			$pos += $extSize;
		}

		$len = strlen($tagData);
		while ($pos < $len - 10) {
			if ($version >= 3) {
				$frameId = substr($tagData, $pos, 4);
				if (!preg_match('/^[A-Z0-9]{4}$/', $frameId)) break;
				$frameSize = ($version >= 4)
					? read_syncsafe_int(substr($tagData, $pos + 4, 4))
					: read_be_int(substr($tagData, $pos + 4, 4));
				$headLen = 10;
			} else {
				$frameId = substr($tagData, $pos, 3);
				if (!preg_match('/^[A-Z0-9]{3}$/', $frameId)) break;
				$sizeBytes = substr($tagData, $pos + 3, 3);
				$frameSize = (ord($sizeBytes[0]) << 16) | (ord($sizeBytes[1]) << 8) | ord($sizeBytes[2]);
				$headLen = 6;
			}

			if ($frameSize <= 0 or $pos + $headLen + $frameSize > $len) break;
			$frameData = substr($tagData, $pos + $headLen, $frameSize);

			if (in_array($frameId, ['TIT2', 'TT2'])) {
				$result['title'] = decode_id3_text($frameData);
			} elseif (in_array($frameId, ['TPE1', 'TP1'])) {
				$result['artist'] = decode_id3_text($frameData);
			} elseif (in_array($frameId, ['TALB', 'TAL'])) {
				$result['album'] = decode_id3_text($frameData);
			} elseif (in_array($frameId, ['APIC', 'PIC']) and $result['cover'] === null) {
				$result['cover'] = decode_id3_picture($frameData, $frameId === 'PIC');
			}

			$pos += $headLen + $frameSize;
		}

		return $result;
	}


	// ============================================================
	// AJAX-API
	// ============================================================

	if (isset($_GET['action'])) {
		header('Content-Type: application/json; charset=' . CHARSET);
		$action = $_GET['action'];

		if ($action === 'browse') {
			$dir = isset($_GET['dir']) ? $_GET['dir'] : '';
			if ($dir !== '' and (substr($dir, -1) !== '/' or !safe_path($dir))) {
				echo json_encode(['status' => 'error', 'message' => 'Ungültiges Verzeichnis']);
				exit;
			}
			list($dirs, $titles) = get_dirs_and_titles($dir);
			echo json_encode(['status' => 'ok', 'dir' => $dir, 'dirs' => $dirs, 'titles' => $titles]);
			exit;
		}

		if ($action === 'search') {
			$type = ($_GET['type'] ?? 'title') === 'dir' ? 'dir' : 'title';
			$q = trim($_GET['q'] ?? '');
			if (mb_strlen($q) < MIN_SEARCH_STR) {
				echo json_encode(['status' => 'ok', 'results' => [], 'limited' => false]);
				exit;
			}
			$results = ($type === 'dir') ? search_dir($q) : search_title($q);
			$limited = count($results) > MAX_SEARCH_RESULT;
			if ($limited) $results = array_slice($results, 0, MAX_SEARCH_RESULT);
			sort($results, SORT_FLAG_CASE | SORT_NATURAL);
			echo json_encode(['status' => 'ok', 'type' => $type, 'results' => $results, 'limited' => $limited]);
			exit;
		}

		if ($action === 'tags') {
			$file = $_GET['file'] ?? '';
			if (!safe_path($file) or !is_audio_file($file) or !is_file('./' . $file)) {
				echo json_encode(['status' => 'error']);
				exit;
			}
			$tags = get_id3_tags('./' . $file);
			echo json_encode(['status' => 'ok', 'tags' => $tags]);
			exit;
		}

		if ($action === 'tree_next') {
			$dir = $_GET['dir'] ?? '';
			$loop = ($_GET['loop'] ?? '') === '1';
			if (!safe_path($dir)) {
				echo json_encode(['status' => 'error']);
				exit;
			}
			$result = find_first_title_from($dir, false, '', $loop);
			if ($result === false or $result[1] === 'stop') {
				echo json_encode(['status' => 'stop']);
				exit;
			}
			echo json_encode(['status' => 'ok', 'dir' => $result[0], 'titles' => $result[1]]);
			exit;
		}

		echo json_encode(['status' => 'error', 'message' => 'unbekannte Aktion']);
		exit;
	}

	header('Content-Type: text/html; charset=' . CHARSET);

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="<?php echo CHARSET; ?>">
<title><?php echo PLAYER_TITLE; ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#282a36">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
	:root{
		--bg:#282a36;
		--bg2:#21222c;
		--panel:rgb(52, 55, 70, 0.5);
		--panel-2:#3d4052;
		--border:#44475a;
		--fg:#f8f8f2;
		--comment:#6272a4;
		--purple:#bd93f9;
		--pink:#ff79c6;
		--cyan:#8be9fd;
		--green:#50fa7b;
		--orange:#ffb86c;
		--red:#ff5555;
		--yellow:#f1fa8c;
	}

	*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }

	html{
		height:100%;
		height:100vh;
		background:
			radial-gradient(circle at 15% 8%, rgba(189,147,249,0.08), transparent 45%),
			radial-gradient(circle at 85% 92%, rgba(255,121,198,0.06), transparent 45%),
			var(--bg);
	}
	@supports (height: 100dvh){
		html{ height:100dvh; }
	}

	body{
		margin:0; padding:0; height:100%;
		color:var(--fg);
		font-family:'DM Sans', sans-serif;
		-webkit-user-select:none; user-select:none;
		overscroll-behavior:none;
		overflow:hidden;
	}

	/* Cover-Art als CSS body-Hintergrund, scharf (zwei Layer für weiches Überblenden) */
	body::before,
	body::after{
		content:"";
		position:fixed;
		inset:0;
		z-index:0;
		background-size:cover;
		background-position:center;
		filter:brightness(0.4) saturate(1.1);
		opacity:0;
		transition:opacity 1.4s ease;
		pointer-events:none;
	}
	body::before{ background-image:var(--cover-a, none); }
	body::after{ background-image:var(--cover-b, none); }
	body.cover-a-active::before{ opacity:1; }
	body.cover-b-active::after{ opacity:1; }

	::-webkit-scrollbar{ width:8px; height:8px; }
	::-webkit-scrollbar-track{ background:var(--bg2); }
	::-webkit-scrollbar-thumb{ background:var(--border); border-radius:6px; }
	::-webkit-scrollbar-thumb:hover{ background:var(--comment); }

	.mono{ font-family:'JetBrains Mono', monospace; }

	/* ---------- Player-Leiste ---------- */
	.player-bar{
		position:fixed; top:0; left:0; right:0; z-index:50;
		background:var(--panel);
		border-bottom:1px solid var(--border);
		padding:10px 12px 8px;
	}

	.player-top{ display:flex; gap:10px; align-items:center; }

	.cover{
		width:52px; height:52px; flex-shrink:0;
		border-radius:8px;
		background:var(--panel-2);
		border:1px solid var(--border);
		display:flex; align-items:center; justify-content:center;
		overflow:hidden;
		color:var(--comment);
		font-size:1.4rem;
		transition:border-radius .6s ease, box-shadow .4s ease, transform .3s ease;
	}
	.cover img{ width:100%; height:100%; object-fit:cover; transition:transform .3s ease; }
	.cover.playing{
		border-radius:50%;
		border-color:var(--purple);
		box-shadow:0 0 0 3px rgba(189,147,249,0.15), 0 0 18px rgba(189,147,249,0.35);
		animation:coverPulse 2.4s ease-in-out infinite;
	}
	.cover.playing img{ animation:coverSpin 9s linear infinite; }

	@keyframes coverSpin{ from{ transform:rotate(0deg); } to{ transform:rotate(360deg); } }
	@keyframes coverPulse{
		0%,100%{ box-shadow:0 0 0 3px rgba(189,147,249,0.15), 0 0 14px rgba(189,147,249,0.25); }
		50%{ box-shadow:0 0 0 5px rgba(189,147,249,0.22), 0 0 22px rgba(189,147,249,0.45); }
	}

	.track-info{ flex:1; min-width:0; }
	.track-title{
		font-family:'JetBrains Mono', monospace;
		font-weight:600;
		font-size:0.92rem;
		white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
		color:var(--fg);
	}
	.track-sub{
		font-family:'JetBrains Mono', monospace;
		font-size:0.7rem;
		color:var(--comment);
		white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
		margin-top:2px;
	}
	.text-anim{ animation:trackTextIn .35s ease; }
	@keyframes trackTextIn{
		from{ opacity:0; transform:translateX(-4px); }
		to{ opacity:1; transform:translateX(0); }
	}
	.track-sub .arrow{ color:var(--pink); margin:0 3px; }

	.progress-row{
		display:flex; align-items:center; gap:8px;
		margin-top:8px;
	}
	.time-box{
		font-family:'JetBrains Mono', monospace;
		font-size:0.7rem;
		color:var(--cyan);
		background:var(--panel-2);
		border:1px solid var(--border);
		border-radius:6px;
		padding:3px 6px;
		flex-shrink:0;
	}
	.progress-track{
		flex:1;
		height:10px;
		background:var(--panel-2);
		border:1px solid var(--border);
		border-radius:20px;
		cursor:pointer;
		position:relative;
		overflow:hidden;
	}
	.progress-fill{
		position:absolute; left:0; top:0; bottom:0;
		width:0%;
		background:var(--purple);
		border-radius:20px;
		transition:width .2s linear;
	}
	.progress-fill.playing{
		background:linear-gradient(90deg, var(--purple), var(--pink));
		box-shadow:0 0 8px rgba(255,121,198,0.5);
	}

	.controls-row{
		display:flex; gap:8px; margin-top:8px;
	}
	.ctrl-btn{
		flex:1;
		font-family:'JetBrains Mono', monospace;
		font-size:1.1rem;
		background:var(--panel-2);
		border:1px solid var(--border);
		color:var(--fg);
		border-radius:8px;
		padding:8px 0;
		cursor:pointer;
		transition:transform .12s ease, background .15s ease;
	}
	.ctrl-btn:active{ background:var(--border); transform:scale(0.94); }
	.ctrl-btn.primary{ background:var(--green); color:var(--bg2); border-color:transparent; font-weight:700; }
	.ctrl-btn.primary.playing{ animation:primaryPulse 2s ease-in-out infinite; }

	@keyframes primaryPulse{
		0%,100%{ box-shadow:0 0 0 0 rgba(80,250,123,0.35); }
		50%{ box-shadow:0 0 0 6px rgba(80,250,123,0); }
	}

	.skip-row{
		display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;
	}
	.skip-btn{
		flex:1; min-width:40px;
		font-family:'JetBrains Mono', monospace;
		font-size:0.72rem;
		background:var(--panel-2);
		border:1px solid var(--border);
		color:var(--comment);
		border-radius:6px;
		padding:5px 0;
		cursor:pointer;
		text-align:center;
	}
	.skip-btn:active{ color:var(--cyan); border-color:var(--cyan); }

	/* ---------- Tabs ---------- */
	.tabs{
		position:fixed; z-index:49;
		left:0; right:0;
		display:flex;
		background:var(--bg2);
		border-bottom:1px solid var(--border);
	}
	.tab{
		flex:1;
		text-align:center;
		font-family:'JetBrains Mono', monospace;
		font-size:0.78rem;
		text-transform:uppercase;
		letter-spacing:0.5px;
		color:var(--comment);
		padding:10px 4px;
		cursor:pointer;
		position:relative;
		border-bottom:2px solid transparent;
	}
	.tab.active{ color:var(--purple); border-bottom-color:var(--purple); }
	.tab{ transition:color .2s ease, border-color .2s ease; }
	.tab .play-mark{ color:var(--green); margin-left:4px; display:none; animation:notePulse 1.2s ease-in-out infinite; }
	.tab .play-mark.show{ display:inline; }

	/* ---------- Inhalt ---------- */
	.content{
		position:absolute; left:0; right:0; bottom:0;
		z-index:1;
		overflow-y:auto;
		padding:10px 10px 30px;
	}
	.content.fade-in{ animation:contentFadeIn .28s ease; }

	@keyframes contentFadeIn{
		from{ opacity:0; transform:translateY(4px); }
		to{ opacity:1; transform:translateY(0); }
	}

	.crumbs{ display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
	.crumb{
		font-family:'JetBrains Mono', monospace;
		font-size:0.75rem;
		background:var(--panel-2);
		border:1px solid var(--border);
		color:var(--pink);
		border-radius:20px;
		padding:5px 12px;
		cursor:pointer;
	}

	.list-item{
		display:flex; align-items:center; gap:8px;
		background:var(--panel);
		border:1px solid var(--border);
		border-radius:10px;
		padding:10px 12px;
		margin-bottom:8px;
		cursor:pointer;
		transition:border-color .2s ease, transform .12s ease, background .2s ease;
		animation:itemFadeIn .3s ease backwards;
	}
	.list-item:active{ transform:scale(0.98); background:var(--panel-2); }
	.list-item:nth-child(1){ animation-delay:0.02s; }
	.list-item:nth-child(2){ animation-delay:0.04s; }
	.list-item:nth-child(3){ animation-delay:0.06s; }
	.list-item:nth-child(4){ animation-delay:0.08s; }
	.list-item:nth-child(5){ animation-delay:0.10s; }
	.list-item:nth-child(6){ animation-delay:0.12s; }
	.list-item:nth-child(7){ animation-delay:0.14s; }
	.list-item:nth-child(8){ animation-delay:0.16s; }

	@keyframes itemFadeIn{
		from{ opacity:0; transform:translateY(6px); }
		to{ opacity:1; transform:translateY(0); }
	}
	.list-item.playing{ border-color:var(--green); animation:itemFadeIn .3s ease backwards, playingGlow 2.2s ease-in-out infinite; }
	.list-item .icon{ color:var(--comment); font-size:1rem; flex-shrink:0; width:1.2em; text-align:center; transition:color .2s ease; }
	.list-item.playing .icon{ color:var(--green); animation:notePulse 1.2s ease-in-out infinite; }
	.list-item.dir .icon{ color:var(--orange); }

	@keyframes playingGlow{
		0%,100%{ box-shadow:0 0 0 rgba(80,250,123,0); }
		50%{ box-shadow:0 0 10px rgba(80,250,123,0.25); }
	}
	@keyframes notePulse{
		0%,100%{ transform:scale(1); opacity:1; }
		50%{ transform:scale(1.25); opacity:0.7; }
	}
	.list-item .main{ flex:1; min-width:0; }
	.list-item .name{
		font-family:'JetBrains Mono', monospace;
		font-size:0.85rem;
		color:var(--fg);
		white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
	}
	.list-item .sub{
		font-family:'JetBrains Mono', monospace;
		font-size:0.68rem;
		color:var(--comment);
		white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
		margin-top:2px;
	}
	.list-item .star{
		flex-shrink:0;
		font-size:1.1rem;
		color:var(--border);
		padding:2px 4px;
		transition:transform .15s ease, color .15s ease;
	}
	.list-item .star.active{ color:var(--yellow); }
	.list-item .star.pop{ animation:starPop .35s ease; }

	@keyframes starPop{
		0%{ transform:scale(1); }
		40%{ transform:scale(1.5) rotate(-10deg); }
		100%{ transform:scale(1); }
	}

	.section-head{
		font-family:'JetBrains Mono', monospace;
		text-transform:uppercase;
		letter-spacing:1px;
		font-size:0.78rem;
		color:var(--comment);
		margin:4px 0 10px;
		display:flex;
		justify-content:space-between;
		align-items:center;
	}
	.section-head .link{ color:var(--pink); cursor:pointer; text-transform:none; letter-spacing:0; }

	.search-bar{ display:flex; gap:8px; margin-bottom:12px; }
	.search-bar input{
		flex:1;
		background:var(--panel-2);
		border:1px solid var(--border);
		color:var(--fg);
		border-radius:8px;
		padding:10px 12px;
		font-family:'JetBrains Mono', monospace;
		font-size:0.9rem;
		outline:none;
	}
	.search-bar input:focus{ border-color:var(--purple); }
	.search-bar button{
		font-family:'JetBrains Mono', monospace;
		font-size:0.78rem;
		background:var(--panel-2);
		border:1px solid var(--border);
		color:var(--cyan);
		border-radius:8px;
		padding:0 14px;
		cursor:pointer;
	}
	.search-bar button.active{ background:var(--cyan); color:var(--bg2); border-color:transparent; }

	.empty-msg{
		text-align:center;
		font-family:'JetBrains Mono', monospace;
		font-size:0.85rem;
		color:var(--comment);
		padding:40px 0;
	}

	.toggle-row{
		display:flex; align-items:center; justify-content:space-between;
		font-family:'JetBrains Mono', monospace;
		font-size:0.82rem;
		color:var(--fg);
		background:var(--panel);
		border:1px solid var(--border);
		border-radius:10px;
		padding:12px 14px;
		margin-bottom:10px;
	}
	.toggle-row .desc{ color:var(--comment); font-size:0.7rem; margin-top:3px; }
	.switch{ position:relative; width:42px; height:22px; flex-shrink:0; margin-left:12px; }
	.switch input{ opacity:0; width:0; height:0; }
	.slider-track{
		position:absolute; inset:0; background:var(--panel-2);
		border:1px solid var(--border); border-radius:20px; cursor:pointer;
	}
	.slider-track:before{
		content:""; position:absolute; height:16px; width:16px; left:2px; top:2px;
		background:var(--comment); border-radius:50%; transition:.15s;
	}
	.switch input:checked + .slider-track{ background:var(--purple); border-color:var(--purple); }
	.switch input:checked + .slider-track:before{ transform:translateX(20px); background:var(--bg); }

	#toast{
		position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px);
		background:var(--green); color:var(--bg2);
		font-family:'JetBrains Mono', monospace; font-weight:700; font-size:0.8rem;
		padding:9px 18px; border-radius:8px; opacity:0; pointer-events:none;
		transition:all .25s ease; z-index:99;
	}
	#toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
</style>
</head>
<body onload="init()">

<audio id="player" preload="auto"></audio>

<div class="player-bar" id="playerBar">
	<div class="player-top">
		<div class="cover" id="cover"><span id="coverIcon">♪</span><img id="coverImg" style="display:none;" alt=""></div>
		<div class="track-info">
			<div class="track-title" id="trackTitle">— <?php echo PLAYER_TITLE; ?> —</div>
			<div class="track-sub" id="trackSub">&nbsp;</div>
		</div>
	</div>
	<div class="progress-row">
		<div class="time-box" id="timeCurrent">00:00:00</div>
		<div class="progress-track" id="progressTrack" onclick="seekTo(event)">
			<div class="progress-fill" id="progressFill"></div>
		</div>
		<div class="time-box" id="timeRemaining">00:00:00</div>
	</div>
	<div class="controls-row">
		<button class="ctrl-btn" onclick="changeTrack(-1)">⏮</button>
		<button class="ctrl-btn primary" id="btnPlayPause" onclick="playerPlayPause()">▶</button>
		<button class="ctrl-btn" onclick="playerStop()">⏹</button>
		<button class="ctrl-btn" onclick="changeTrack(1)">⏭</button>
	</div>
	<div class="skip-row">
<?php foreach (SKIP_BUTTONS as $s): ?>
		<div class="skip-btn" onclick="skipSec(<?php echo $s; ?>)"><?php echo ($s > 0 ? '+' : '') . $s; ?>s</div>
<?php endforeach; ?>
	</div>
</div>

<div class="tabs" id="tabBar">
	<div class="tab active" data-tab="browser">Browser<span class="play-mark" id="markBrowser">●</span></div>
	<div class="tab" data-tab="playlist">Playlist<span class="play-mark" id="markPlaylist">●</span></div>
	<div class="tab" data-tab="search">Suche<span class="play-mark" id="markSearch">●</span></div>
	<div class="tab" data-tab="settings">⚙</div>
</div>

<div class="content" id="content-browser">
	<div class="crumbs" id="crumbs"></div>
	<div id="browserList"></div>
</div>

<div class="content" id="content-playlist" style="display:none;">
	<div class="section-head">
		<span id="playlistCount">Playlist ist leer</span>
		<span class="link" onclick="clearPlaylist()">Leeren</span>
	</div>
	<div id="playlistList"></div>
</div>

<div class="content" id="content-search" style="display:none;">
	<div class="search-bar">
		<input type="text" id="searchInput" placeholder="Suchbegriff …" onkeydown="if(event.key==='Enter') runSearch(searchType)">
	</div>
	<div class="search-bar">
		<button id="btnSearchTitle" class="active" onclick="runSearch('title')">Titel</button>
		<button id="btnSearchDir" onclick="runSearch('dir')">Verzeichnis</button>
	</div>
	<div id="searchList"></div>
</div>

<div class="content" id="content-settings" style="display:none;">
	<div class="toggle-row">
		<div><div>Shuffle</div><div class="desc">Titel in zufälliger Reihenfolge abspielen.</div></div>
		<label class="switch"><input type="checkbox" id="toggleShuffle" onchange="onToggleShuffle()"><span class="slider-track"></span></label>
	</div>
	<div class="toggle-row">
		<div><div>Loop</div><div class="desc">Wiedergabe endlos fortsetzen.</div></div>
		<label class="switch"><input type="checkbox" id="toggleLoop" onchange="onToggleLoop()"><span class="slider-track"></span></label>
	</div>
	<div class="toggle-row">
		<div><div>Tree-Play</div><div class="desc">Beim Abspielen aus dem Browser automatisch im nächsten Verzeichnis fortsetzen.</div></div>
		<label class="switch"><input type="checkbox" id="toggleTree" onchange="onToggleTree()"><span class="slider-track"></span></label>
	</div>
	<div class="toggle-row">
		<div><div>Cover-Art</div><div class="desc">Eingebettetes Cover-Art aus ID3v2-Tags laden und anzeigen.</div></div>
		<label class="switch"><input type="checkbox" id="toggleCover" onchange="onToggleCover()"><span class="slider-track"></span></label>
	</div>
</div>

<div id="toast"></div>

<script>
(() => {
	const player = document.getElementById('player');

	// ---------- State ----------
	let currentDir = '';
	let currentDirs = [];
	let currentTitles = [];
	let playlistTracks = JSON.parse(localStorage.getItem('aip_playlist') || '[]');
	let searchResultsDir = [];
	let searchResultsTitle = [];
	let searchType = 'title';

	let playingFrom = ''; // 'browser' | 'playlist' | 'search'
	let playingIndex = -1;
	let playingTrack = '';
	let playingListDir = '';   // dir prefix for browser tree-play tracking
	let playingListTitles = []; // active title list for current source

	let shuffle = localStorage.getItem('aip_shuffle') === '1';
	let loop = localStorage.getItem('aip_loop') !== '0'; // default on
	let tree = localStorage.getItem('aip_tree') === '1';
	let showCover = localStorage.getItem('aip_cover') !== '0'; // default on
	let shuffledOrder = [];

	// ---------- Helpers ----------
	function $(id){ return document.getElementById(id); }

	function showToast(msg){
		const t = $('toast');
		t.textContent = msg;
		t.classList.add('show');
		clearTimeout(showToast._t);
		showToast._t = setTimeout(() => t.classList.remove('show'), 1600);
	}

	function encodePath(p){
		return p.split('/').map(encodeURIComponent).join('/');
	}

	function getTrackName(track){
		let name = track.split('/').pop();
		name = name.replace(/_/g, ' ');
		const dot = name.lastIndexOf('.');
		return dot > 0 ? name.substring(0, dot) : name;
	}

	function getTrackDirLabel(track){
		let dir = track.substring(0, track.lastIndexOf('/') + 1);
		dir = dir.replace(/_/g, ' ');
		const parts = ('<?php echo TXT_ROOT; ?>/' + dir).split('/').filter(Boolean);
		return parts.join(' <span class="arrow">→</span> ');
	}

	function secondsToTime(secs){
		if (isNaN(secs) || !isFinite(secs)) return '00:00:00';
		secs = Math.max(0, Math.floor(secs));
		const hh = Math.floor(secs / 3600);
		const mm = Math.floor(secs / 60) % 60;
		const ss = secs % 60;
		const pad = n => String(n).padStart(2, '0');
		return pad(hh) + ':' + pad(mm) + ':' + pad(ss);
	}

	async function api(action, params = {}){
		const usp = new URLSearchParams({ action, ...params });
		const res = await fetch('?' + usp.toString());
		return res.json();
	}

	// ---------- Playlist persistence ----------
	function savePlaylist(){
		localStorage.setItem('aip_playlist', JSON.stringify(playlistTracks));
	}

	function inPlaylist(track){
		return playlistTracks.includes(track);
	}

	function togglePlaylistTrack(track, ev){
		if (ev) ev.stopPropagation();
		if (ev && ev.target){
			ev.target.classList.add('pop');
		}
		const idx = playlistTracks.indexOf(track);
		if (idx > -1) playlistTracks.splice(idx, 1);
		else playlistTracks.push(track);
		savePlaylist();
		setTimeout(renderAll, 160);
	}

	function clearPlaylist(){
		if (!confirm('Playlist wirklich leeren?')) return;
		playlistTracks = [];
		savePlaylist();
		renderAll();
	}

	// ---------- Tabs ----------
	document.querySelectorAll('.tab').forEach(tab => {
		tab.addEventListener('click', () => {
			document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
			tab.classList.add('active');
			['browser','playlist','search','settings'].forEach(id => {
				const el = $('content-' + id);
				if (id === tab.dataset.tab){
					el.style.display = 'block';
					el.classList.remove('fade-in');
					void el.offsetWidth;
					el.classList.add('fade-in');
				} else {
					el.style.display = 'none';
				}
			});
		});
	});

	function layout(){
		const barH = $('playerBar').offsetHeight;
		const tabsH = $('tabBar').offsetHeight;
		$('tabBar').style.top = barH + 'px';
		document.querySelectorAll('.content').forEach(c => {
			c.style.top = (barH + tabsH) + 'px';
		});
	}
	window.addEventListener('resize', layout);

	// ---------- Browser ----------
	async function browse(dir){
		const data = await api('browse', { dir });
		if (data.status !== 'ok'){ showToast(data.message || 'Fehler'); return; }
		currentDir = data.dir;
		currentDirs = data.dirs;
		currentTitles = data.titles;
		renderBrowser();
	}

	function renderCrumbs(){
		const parts = currentDir.split('/').filter(Boolean);
		let html = `<div class="crumb" onclick="AIP.browse('')">${'<?php echo TXT_ROOT; ?>'}</div>`;
		let acc = '';
		parts.forEach((p, i) => {
			acc += p + '/';
			html += `<div class="crumb" onclick="AIP.browse('${acc.replace(/'/g,"\\'")}')">${p}</div>`;
		});
		$('crumbs').innerHTML = html;
	}

	function renderBrowser(){
		renderCrumbs();
		let html = '';
		currentDirs.forEach((d) => {
			const full = currentDir + d + '/';
			html += `<div class="list-item dir" onclick="AIP.browse('${full.replace(/'/g,"\\'")}')">
				<div class="icon">▸</div>
				<div class="main"><div class="name">${d}</div></div>
			</div>`;
		});
		currentTitles.forEach((title, i) => {
			const track = currentDir + title;
			const isPlaying = (playingTrack === track);
			const starred = inPlaylist(track);
			html += `<div class="list-item ${isPlaying ? 'playing' : ''}" onclick="AIP.playFromBrowser(${i})">
				<div class="icon">${isPlaying ? '♫' : '♪'}</div>
				<div class="main">
					<div class="name">${getTrackName(title)}</div>
					<div class="sub">${getTrackDirLabel(track)}</div>
				</div>
				<div class="star ${starred ? 'active' : ''}" onclick="AIP.togglePlaylistTrack('${track.replace(/'/g,"\\'")}', event)">★</div>
			</div>`;
		});
		if (currentDirs.length === 0 && currentTitles.length === 0){
			html = '<div class="empty-msg">Verzeichnis ist leer</div>';
		}
		$('browserList').innerHTML = html;
	}

	// ---------- Playlist ----------
	function renderPlaylist(){
		$('playlistCount').textContent = playlistTracks.length
			? `${playlistTracks.length} Titel in Playlist`
			: 'Playlist ist leer';

		let html = '';
		playlistTracks.forEach((track, i) => {
			const isPlaying = (playingTrack === track);
			html += `<div class="list-item ${isPlaying ? 'playing' : ''}" onclick="AIP.playFromPlaylist(${i})">
				<div class="icon">${isPlaying ? '♫' : '♪'}</div>
				<div class="main">
					<div class="name">${getTrackName(track)}</div>
					<div class="sub">${getTrackDirLabel(track)}</div>
				</div>
				<div class="star active" onclick="AIP.removeFromPlaylist(${i}, event)">★</div>
			</div>`;
		});
		$('playlistList').innerHTML = html;
	}

	function removeFromPlaylist(i, ev){
		if (ev) ev.stopPropagation();
		playlistTracks.splice(i, 1);
		if (playingFrom === 'playlist' && i <= playingIndex) playingIndex--;
		savePlaylist();
		renderAll();
	}

	// ---------- Suche ----------
	document.getElementById('btnSearchTitle');
	async function runSearch(type){
		searchType = type;
		$('btnSearchTitle').classList.toggle('active', type === 'title');
		$('btnSearchDir').classList.toggle('active', type === 'dir');
		const q = $('searchInput').value.trim();
		if (!q){ $('searchList').innerHTML = '<div class="empty-msg">Suchbegriff eingeben …</div>'; return; }
		$('searchList').innerHTML = '<div class="empty-msg">Suche läuft …</div>';
		const data = await api('search', { type, q });
		if (data.status !== 'ok'){ showToast('Fehler bei der Suche'); return; }
		if (type === 'dir'){ searchResultsDir = data.results; searchResultsTitle = []; }
		else { searchResultsTitle = data.results; searchResultsDir = []; }
		renderSearch(data.limited);
	}

	function renderSearch(limited){
		let html = '';
		if (limited){
			html += `<div class="empty-msg">Zu viele Treffer — auf <?php echo MAX_SEARCH_RESULT; ?> begrenzt</div>`;
		}
		searchResultsDir.forEach((d) => {
			html += `<div class="list-item dir" onclick="AIP.browse('${(d + '/').replace(/'/g,"\\'")}'); AIP.selectTab('browser')">
				<div class="icon">▸</div>
				<div class="main">
					<div class="name">${d.split('/').pop()}</div>
					<div class="sub">${getTrackDirLabel(d + '/')}</div>
				</div>
			</div>`;
		});
		searchResultsTitle.forEach((track, i) => {
			const isPlaying = (playingTrack === track);
			const starred = inPlaylist(track);
			html += `<div class="list-item ${isPlaying ? 'playing' : ''}" onclick="AIP.playFromSearch(${i})">
				<div class="icon">${isPlaying ? '♫' : '♪'}</div>
				<div class="main">
					<div class="name">${getTrackName(track)}</div>
					<div class="sub">${getTrackDirLabel(track)}</div>
				</div>
				<div class="star ${starred ? 'active' : ''}" onclick="AIP.togglePlaylistTrack('${track.replace(/'/g,"\\'")}', event)">★</div>
			</div>`;
		});
		if (!searchResultsDir.length && !searchResultsTitle.length){
			html = '<div class="empty-msg">Keine Treffer</div>';
		}
		$('searchList').innerHTML = html;
	}

	function selectTab(name){
		document.querySelector(`.tab[data-tab="${name}"]`).click();
	}

	// ---------- Wiedergabe ----------
	function animateText(el){
		el.classList.remove('text-anim');
		void el.offsetWidth;
		el.classList.add('text-anim');
	}

	let bgToggle = false;
	function setAmbientBackground(url){
		if (!url){
			document.body.classList.remove('cover-a-active', 'cover-b-active');
			return;
		}
		const safeUrl = url.replace(/'/g, "\\'");
		if (!bgToggle){
			document.body.style.setProperty('--cover-a', `url('${safeUrl}')`);
			document.body.classList.add('cover-a-active');
			document.body.classList.remove('cover-b-active');
		} else {
			document.body.style.setProperty('--cover-b', `url('${safeUrl}')`);
			document.body.classList.add('cover-b-active');
			document.body.classList.remove('cover-a-active');
		}
		bgToggle = !bgToggle;
	}

	async function loadAndPlay(track){
		playingTrack = track;
		$('trackTitle').textContent = getTrackName(track);
		$('trackSub').innerHTML = getTrackDirLabel(track);
		animateText($('trackTitle'));
		animateText($('trackSub'));
		$('coverImg').style.display = 'none';
		$('coverIcon').style.display = 'block';
		$('coverIcon').textContent = '♪';
		$('cover').classList.add('playing');
		setAmbientBackground(null);

		if (<?php echo TRACK_AS_TITLE ? 'true' : 'false'; ?>){
			document.title = getTrackName(track);
		}

		player.src = encodePath(track);
		player.currentTime = 0;
		player.play().catch(()=>{});

		if (showCover){
			const data = await api('tags', { file: track });
			if (data.status === 'ok' && data.tags){
				if (data.tags.title){ $('trackTitle').textContent = data.tags.title; animateText($('trackTitle')); }
				if (data.tags.artist){
					$('trackSub').innerHTML = data.tags.artist + (data.tags.album ? ' <span class="arrow">—</span> ' + data.tags.album : '');
					animateText($('trackSub'));
				}
				if (data.tags.cover){
					$('coverImg').src = data.tags.cover;
					$('coverImg').style.display = 'block';
					$('coverIcon').style.display = 'none';
					setAmbientBackground(data.tags.cover);
				}
			}
		}
		renderAll();
	}

	function playFromBrowser(i){
		playingFrom = 'browser';
		playingIndex = i;
		playingListDir = currentDir;
		playingListTitles = currentTitles;
		markPlaying('browser');
		if (shuffle) updateShuffledOrder(true);
		loadAndPlay(currentDir + currentTitles[i]);
	}

	function playFromPlaylist(i){
		playingFrom = 'playlist';
		playingIndex = i;
		markPlaying('playlist');
		if (shuffle) updateShuffledOrder(true);
		loadAndPlay(playlistTracks[i]);
	}

	function playFromSearch(i){
		playingFrom = 'search';
		playingIndex = i;
		markPlaying('search');
		if (shuffle) updateShuffledOrder(true);
		loadAndPlay(searchResultsTitle[i]);
	}

	function markPlaying(from){
		playingFrom = from;
		['Browser','Playlist','Search'].forEach(name => {
			$('mark' + name).classList.toggle('show', from === name.toLowerCase());
		});
	}

	function currentListLength(){
		if (playingFrom === 'browser') return playingListTitles.length;
		if (playingFrom === 'playlist') return playlistTracks.length;
		if (playingFrom === 'search') return searchResultsTitle.length;
		return 0;
	}

	function updateShuffledOrder(force){
		const len = currentListLength();
		if (!force && shuffledOrder.length === len) return;
		shuffledOrder = Array.from({length: len}, (_, i) => i);
		for (let i = len - 1; i > 0; i--){
			const j = Math.floor(Math.random() * (i + 1));
			[shuffledOrder[i], shuffledOrder[j]] = [shuffledOrder[j], shuffledOrder[i]];
		}
		const cur = shuffledOrder.indexOf(playingIndex);
		if (cur > 0){
			[shuffledOrder[0], shuffledOrder[cur]] = [shuffledOrder[cur], shuffledOrder[0]];
		}
	}

	async function changeTrack(direction){
		if (playingFrom === '') return;
		const len = currentListLength();
		if (len === 0) return;

		let nextIndex;
		if (shuffle){
			updateShuffledOrder(false);
			let pos = shuffledOrder.indexOf(playingIndex) + direction;
			if (pos >= shuffledOrder.length){
				if (playingFrom === 'browser' && tree){ return treeNext(); }
				if (!loop){ return playerStop(); }
				pos = 0;
			}
			if (pos < 0) pos = loop ? shuffledOrder.length - 1 : 0;
			nextIndex = shuffledOrder[pos];
		} else {
			nextIndex = playingIndex + direction;
			if (nextIndex >= len){
				if (playingFrom === 'browser' && tree){ return treeNext(); }
				if (!loop){ return playerStop(); }
				nextIndex = 0;
			}
			if (nextIndex < 0){
				nextIndex = loop ? len - 1 : 0;
			}
		}

		playingIndex = nextIndex;
		if (playingFrom === 'browser') loadAndPlay(playingListDir + playingListTitles[nextIndex]);
		else if (playingFrom === 'playlist') loadAndPlay(playlistTracks[nextIndex]);
		else if (playingFrom === 'search') loadAndPlay(searchResultsTitle[nextIndex]);
	}

	async function treeNext(){
		const data = await api('tree_next', { dir: playingListDir, loop: loop ? '1' : '0' });
		if (data.status === 'stop'){ playerStop(); return; }
		if (data.status !== 'ok'){ showToast('Fehler beim Tree-Play'); return; }
		playingListDir = data.dir;
		playingListTitles = data.titles;
		playingIndex = shuffle ? Math.floor(Math.random() * playingListTitles.length) : 0;
		if (shuffle) updateShuffledOrder(true);
		loadAndPlay(playingListDir + playingListTitles[playingIndex]);
	}

	function playerPlayPause(){
		if (player.paused){
			if (playingTrack === ''){
				// nichts gewählt: ersten Titel des aktiven Tabs starten
				const activeTab = document.querySelector('.tab.active').dataset.tab;
				if (activeTab === 'browser' && currentTitles.length) return playFromBrowser(0);
				if (activeTab === 'playlist' && playlistTracks.length) return playFromPlaylist(0);
				if (activeTab === 'search' && searchResultsTitle.length) return playFromSearch(0);
				showToast('Kein Titel verfügbar');
			} else {
				player.play().catch(()=>{});
			}
		} else {
			player.pause();
		}
	}

	function playerStop(){
		player.pause();
		player.removeAttribute('src');
		player.load();
		playingTrack = '';
		playingFrom = '';
		playingIndex = -1;
		$('trackTitle').textContent = '— <?php echo PLAYER_TITLE; ?> —';
		$('trackSub').innerHTML = '&nbsp;';
		animateText($('trackTitle'));
		animateText($('trackSub'));
		$('coverImg').style.display = 'none';
		$('coverIcon').style.display = 'block';
		$('cover').classList.remove('playing');
		setAmbientBackground(null);
		if (<?php echo TRACK_AS_TITLE ? 'true' : 'false'; ?>){
			document.title = '<?php echo PLAYER_TITLE; ?>';
		}
		markPlaying('');
		updateProgress();
		renderAll();
	}

	function skipSec(sec){
		if (!player.src) return;
		let target = player.currentTime + sec;
		target = Math.max(0, Math.min(target, player.duration || 0));
		player.currentTime = target;
	}

	function seekTo(ev){
		if (!player.duration) return;
		const rect = $('progressTrack').getBoundingClientRect();
		const ratio = Math.min(1, Math.max(0, (ev.clientX - rect.left) / rect.width));
		player.currentTime = ratio * player.duration;
	}

	function updateProgress(){
		const cur = player.currentTime;
		const dur = player.duration;
		if (isNaN(cur) || isNaN(dur) || !isFinite(dur)){
			$('timeCurrent').textContent = '00:00:00';
			$('timeRemaining').textContent = '00:00:00';
			$('progressFill').style.width = '0%';
			return;
		}
		$('timeCurrent').textContent = secondsToTime(cur);
		$('timeRemaining').textContent = secondsToTime(dur - cur);
		$('progressFill').style.width = (dur > 0 ? (cur / dur * 100) : 0) + '%';
	}

	player.addEventListener('timeupdate', updateProgress);
	player.addEventListener('loadedmetadata', updateProgress);
	player.addEventListener('play', () => {
		$('btnPlayPause').textContent = '❚❚';
		$('btnPlayPause').classList.add('playing');
		$('cover').classList.add('playing');
		$('progressFill').classList.add('playing');
	});
	player.addEventListener('pause', () => {
		$('btnPlayPause').textContent = '▶';
		$('btnPlayPause').classList.remove('playing');
		$('cover').classList.remove('playing');
		$('progressFill').classList.remove('playing');
	});
	player.addEventListener('ended', () => { changeTrack(1); });
	player.addEventListener('error', () => {
		if (playingTrack){
			$('trackSub').innerHTML = '<span style="color:var(--red)">Fehler beim Abspielen — nächster Titel in 5s</span>';
			setTimeout(() => changeTrack(1), 5000);
		}
	});

	// ---------- Einstellungen ----------
	function onToggleShuffle(){
		shuffle = $('toggleShuffle').checked;
		localStorage.setItem('aip_shuffle', shuffle ? '1' : '0');
		if (shuffle) updateShuffledOrder(true);
	}
	function onToggleLoop(){
		loop = $('toggleLoop').checked;
		localStorage.setItem('aip_loop', loop ? '1' : '0');
	}
	function onToggleTree(){
		tree = $('toggleTree').checked;
		localStorage.setItem('aip_tree', tree ? '1' : '0');
	}
	function onToggleCover(){
		showCover = $('toggleCover').checked;
		localStorage.setItem('aip_cover', showCover ? '1' : '0');
	}

	function initSettings(){
		$('toggleShuffle').checked = shuffle;
		$('toggleLoop').checked = loop;
		$('toggleTree').checked = tree;
		$('toggleCover').checked = showCover;
	}

	function renderAll(){
		renderBrowser();
		renderPlaylist();
	}

	// ---------- Init ----------
	async function init(){
		initSettings();
		layout();
		window.addEventListener('beforeunload', (e) => {
			if (playingTrack){ e.preventDefault(); e.returnValue = ''; }
		});
		await browse('');
		renderPlaylist();
	}

	// öffentliche Schnittstelle für inline onclick-Handler
	window.AIP = {
		browse, playFromBrowser, playFromPlaylist, playFromSearch,
		togglePlaylistTrack, removeFromPlaylist, selectTab
	};
	window.init = init;
	window.playerPlayPause = playerPlayPause;
	window.playerStop = playerStop;
	window.changeTrack = changeTrack;
	window.skipSec = skipSec;
	window.seekTo = seekTo;
	window.runSearch = runSearch;
	window.clearPlaylist = clearPlaylist;
	window.onToggleShuffle = onToggleShuffle;
	window.onToggleLoop = onToggleLoop;
	window.onToggleTree = onToggleTree;
	window.onToggleCover = onToggleCover;
})();
</script>

</body>
</html>
