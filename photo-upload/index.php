<?php
/*
 * CoatCraft photo upload (open on a phone, pick photos, upload to the server).
 *
 * Photos are published to /media/projects/ and listed in /media/projects/index.json,
 * which the Gallery page reads, so an upload shows on the site straight away.
 *
 * Protect this folder with cPanel > Directory Privacy (password). Uploads are
 * refused until that protection is on, so the page is never an open uploader.
 * Do not add an .htaccess to this folder in Git: cPanel keeps the password
 * settings there and a deploy would overwrite them.
 */

const MAX_BYTES = 15 * 1024 * 1024;   // per photo, after the phone has resized it
const MAX_FILES = 30;                  // per upload
const MEDIA_DIR = __DIR__ . '/../media/projects';
const MEDIA_URL = '../media/projects/';
const OLD_DIR = __DIR__ . '/uploads';  // where the first version saved photos
const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

function signed_in_user() {
    foreach (['PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $key) {
        if (!empty($_SERVER[$key])) return $_SERVER[$key];
    }
    return null;
}

function slug($text) {
    $text = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return substr($text, 0, 50);
}

function fail($code, $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

/* index.json: newest first, [{file, note, date, w, h}] — read and rewritten under a lock */
function with_index(callable $change) {
    if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0755, true);
    $fh = fopen(MEDIA_DIR . '/index.json', 'c+');
    flock($fh, LOCK_EX);
    $list = json_decode(stream_get_contents($fh) ?: '[]', true) ?: [];
    $list = $change($list);
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    flock($fh, LOCK_UN); fclose($fh);
    chmod(MEDIA_DIR . '/index.json', 0644);
    return $list;
}

function entry($name, $note) {
    $info = @getimagesize(MEDIA_DIR . '/' . $name);
    return ['file' => $name, 'note' => $note, 'date' => date('Y-m-d'), 'w' => $info[0] ?? null, 'h' => $info[1] ?? null];
}

/* Photos saved by the first version of this page go to the gallery too. */
function migrate_old_uploads() {
    if (!is_dir(OLD_DIR)) return;
    $old = array_filter(glob(OLD_DIR . '/*') ?: [], function ($f) { return preg_match('/\.(jpg|png|webp)$/', $f); });
    if (!$old) return;
    with_index(function ($list) use ($old) {
        foreach ($old as $path) {
            $name = basename($path);
            $txt = OLD_DIR . '/' . preg_replace('/\.\w+$/', '.txt', $name);
            $note = is_file($txt) ? trim(file_get_contents($txt)) : '';
            if (rename($path, MEDIA_DIR . '/' . $name)) {
                chmod(MEDIA_DIR . '/' . $name, 0644);
                array_unshift($list, entry($name, $note));
            }
            if (is_file($txt)) unlink($txt);
        }
        return $list;
    });
}

$protected = signed_in_user() !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$protected) fail(403, 'Password protection is not enabled for this folder yet.');
    // Another website cannot add this header to a request, so this blocks cross-site posts.
    if (($_SERVER['HTTP_X_COATCRAFT'] ?? '') !== '1') fail(400, 'Bad request.');

    if (($_POST['action'] ?? '') === 'remove') {
        $name = basename($_POST['file'] ?? '');
        $found = false;
        with_index(function ($list) use ($name, &$found) {
            return array_filter($list, function ($e) use ($name, &$found) {
                if ($e['file'] !== $name) return true;
                $found = true;
                return false;
            });
        });
        if (!$found) fail(404, 'Photo not found.');
        if (is_file(MEDIA_DIR . '/' . $name)) unlink(MEDIA_DIR . '/' . $name);
        echo json_encode(['removed' => $name]);
        exit;
    }

    $note = trim(substr($_POST['note'] ?? '', 0, 300));
    $files = $_FILES['photos'] ?? null;
    if (!$files || !is_array($files['name'])) fail(400, 'No photos received.');
    if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0755, true);

    $saved = []; $failed = [];
    $count = min(count($files['name']), MAX_FILES);
    for ($i = 0; $i < $count; $i++) {
        $orig = basename($files['name'][$i]);
        if ($files['error'][$i] !== UPLOAD_ERR_OK) { $failed[] = "$orig: upload error {$files['error'][$i]}"; continue; }
        if ($files['size'][$i] > MAX_BYTES) { $failed[] = "$orig: larger than 15 MB"; continue; }
        $info = @getimagesize($files['tmp_name'][$i]);
        if (!$info || !isset(TYPES[$info[2]])) { $failed[] = "$orig: not a JPG, PNG or WEBP photo"; continue; }

        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . ($note !== '' ? '-' . slug($note) : '') . '.' . TYPES[$info[2]];
        if (!move_uploaded_file($files['tmp_name'][$i], MEDIA_DIR . '/' . $name)) { $failed[] = "$orig: could not be saved"; continue; }
        chmod(MEDIA_DIR . '/' . $name, 0644);
        $saved[] = $name;
    }
    if ($saved) {
        with_index(function ($list) use ($saved, $note) {
            foreach (array_reverse($saved) as $name) array_unshift($list, entry($name, $note));
            return $list;
        });
    }
    echo json_encode(['saved' => $saved, 'failed' => $failed]);
    exit;
}

$recent = [];
if ($protected) {
    migrate_old_uploads();
    $json = @file_get_contents(MEDIA_DIR . '/index.json');
    $recent = array_slice(json_decode($json ?: '[]', true) ?: [], 0, 60);
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Upload Photos | CoatCraft Solutions</title>
    <style>
        :root { --navy: #1b2a4a; --orange: #e85d04; --bg: #f4f6f9; --ok: #1e7e34; --err: #b00020; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--bg); color: #222; }
        header { background: var(--navy); padding: 14px 16px; text-align: center; }
        header img { height: 40px; }
        main { max-width: 560px; margin: 0 auto; padding: 16px; }
        h1 { font-size: 22px; margin: 8px 0 4px; color: var(--navy); }
        p.lead { margin: 0 0 16px; color: #555; }
        p.lead a { color: var(--orange); }
        .card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 2px 10px rgba(0,0,0,.06); margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        textarea { width: 100%; min-height: 70px; padding: 10px; font: inherit; border: 1px solid #ccd; border-radius: 8px; }
        .pick { display: flex; gap: 10px; margin: 12px 0; }
        .pick label { flex: 1; text-align: center; padding: 14px 8px; border: 2px dashed var(--navy); border-radius: 10px; color: var(--navy); cursor: pointer; margin: 0; }
        .pick input { display: none; }
        .previews { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
        .previews img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; display: block; }
        .tile { position: relative; }
        .tile small { display: block; font-size: 11px; color: #555; margin-top: 3px; line-height: 1.3; overflow: hidden; max-height: 2.6em; }
        .tile .rm { position: absolute; top: 4px; right: 4px; width: auto; margin: 0; padding: 3px 8px; font-size: 12px; background: rgba(0,0,0,.65); border-radius: 6px; }
        button { width: 100%; padding: 15px; font-size: 17px; font-weight: 700; color: #fff; background: var(--orange); border: 0; border-radius: 10px; margin-top: 12px; }
        button:disabled { opacity: .5; }
        progress { width: 100%; height: 10px; margin-top: 10px; display: none; }
        .msg { margin-top: 10px; font-weight: 600; }
        .msg.ok { color: var(--ok); } .msg.err { color: var(--err); }
        .warn { background: #fff3cd; border: 1px solid #ffe08a; padding: 12px; border-radius: 10px; }
        h2 { font-size: 17px; color: var(--navy); margin: 0 0 10px; }
    </style>
</head>
<body>
<header><img src="../assets/images/resources/CoatCraft-Solutions-Logo-light.png" alt="CoatCraft Solutions"></header>
<main>
    <h1>Upload Project Photos</h1>
    <p class="lead">Uploaded photos appear on the <a href="../gallery.html" target="_blank">Gallery page</a> straight away. Only upload photos you are happy to show publicly.</p>

<?php if (!$protected): ?>
    <div class="warn">Uploads are switched off: this page is not password-protected yet.
        Turn on cPanel &rarr; <strong>Directory Privacy</strong> for the <code>photo-upload</code> folder.</div>
<?php else: ?>
    <form class="card" id="form">
        <label for="note">Where and what? <small>(shown as the photo caption)</small></label>
        <textarea id="note" maxlength="300" placeholder="e.g. Chakan MIDC, auto parts factory, PU flooring"></textarea>

        <div class="pick">
            <label>📷 Take photo<input type="file" id="camera" accept="image/*" capture="environment"></label>
            <label>🖼️ Choose from gallery<input type="file" id="gallery" accept="image/*" multiple></label>
        </div>
        <div class="previews" id="previews"></div>
        <button type="submit" id="send" disabled>Upload</button>
        <progress id="bar" max="100" value="0"></progress>
        <div class="msg" id="msg"></div>
    </form>

    <?php if ($recent): ?>
    <div class="card">
        <h2>On the Gallery page (<?= count($recent) ?>)</h2>
        <div class="previews">
            <?php foreach ($recent as $e): $f = htmlspecialchars($e['file']); ?>
                <div class="tile">
                    <img src="<?= MEDIA_URL . $f ?>" alt="" loading="lazy">
                    <button type="button" class="rm" data-file="<?= $f ?>">Remove</button>
                    <?php if (!empty($e['note'])): ?><small><?= htmlspecialchars($e['note']) ?></small><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</main>

<script>
(function () {
    var form = document.getElementById('form');
    if (!form) return;
    var picked = [];
    var previews = document.getElementById('previews');
    var send = document.getElementById('send');
    var bar = document.getElementById('bar');
    var msg = document.getElementById('msg');

    function post(data, onDone, onProgress) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', location.pathname);
        xhr.setRequestHeader('X-CoatCraft', '1');
        if (onProgress) xhr.upload.onprogress = onProgress;
        xhr.onload = function () { var r = {}; try { r = JSON.parse(xhr.responseText); } catch (err) {} onDone(xhr.status, r); };
        xhr.onerror = function () { onDone(0, { error: 'No connection. Please try again.' }); };
        xhr.send(data);
    }

    function add(fileList) {
        Array.prototype.forEach.call(fileList, function (f) {
            if (!/^image\//.test(f.type)) return;
            picked.push(f);
            var img = document.createElement('img');
            img.src = URL.createObjectURL(f);
            previews.appendChild(img);
        });
        send.disabled = picked.length === 0;
        send.textContent = picked.length ? 'Upload ' + picked.length + ' photo' + (picked.length > 1 ? 's' : '') : 'Upload';
    }
    ['camera', 'gallery'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', function (e) { add(e.target.files); e.target.value = ''; });
    });

    // Shrink on the phone: longest side 2560px, JPEG. Saves data and stays under server limits.
    function shrink(file) {
        return new Promise(function (resolve) {
            var img = new Image();
            img.onload = function () {
                var scale = Math.min(1, 2560 / Math.max(img.naturalWidth, img.naturalHeight));
                var c = document.createElement('canvas');
                c.width = Math.round(img.naturalWidth * scale);
                c.height = Math.round(img.naturalHeight * scale);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.88);
            };
            img.onerror = function () { resolve(file); };
            img.src = URL.createObjectURL(file);
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        send.disabled = true; msg.className = 'msg'; msg.textContent = 'Preparing photos…';
        Promise.all(picked.map(shrink)).then(function (blobs) {
            var data = new FormData();
            data.append('note', document.getElementById('note').value);
            blobs.forEach(function (b, i) { data.append('photos[]', b, 'photo-' + (i + 1) + '.jpg'); });
            msg.textContent = 'Uploading…';
            post(data, function (status, r) {
                if (status === 200 && r.saved) {
                    msg.className = 'msg ' + (r.failed.length ? 'err' : 'ok');
                    msg.textContent = r.saved.length + ' uploaded' + (r.failed.length ? '. Not uploaded: ' + r.failed.join('; ') : '. They are on the Gallery page now.');
                    if (r.saved.length) setTimeout(function () { location.reload(); }, 1500);
                } else {
                    msg.className = 'msg err';
                    msg.textContent = r.error || ('Upload failed (' + status + '). Try fewer photos at a time.');
                    send.disabled = false;
                }
            }, function (ev) { if (ev.lengthComputable) { bar.style.display = 'block'; bar.value = ev.loaded / ev.total * 100; } });
        });
    });

    document.querySelectorAll('.rm').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Remove this photo from the website?')) return;
            var data = new FormData();
            data.append('action', 'remove');
            data.append('file', btn.getAttribute('data-file'));
            btn.disabled = true;
            post(data, function (status, r) {
                if (status === 200) btn.parentNode.remove();
                else { alert(r.error || 'Could not remove.'); btn.disabled = false; }
            });
        });
    });
})();
</script>
</body>
</html>
