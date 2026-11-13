<?php
/*
 * CoatCraft photo upload (open on a phone, pick photos, upload to the server).
 *
 * Protect this folder with cPanel > Directory Privacy (password). Uploads are
 * refused until that protection is on, so the page is never an open uploader.
 * Do not add an .htaccess to this folder in Git: cPanel keeps the password
 * settings there and a deploy would overwrite them.
 */

const MAX_BYTES = 15 * 1024 * 1024;   // per photo, after the phone has resized it
const MAX_FILES = 30;                  // per upload
const UPLOAD_DIR = __DIR__ . '/uploads';
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

$protected = signed_in_user() !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$protected) {
        http_response_code(403);
        echo json_encode(['error' => 'Password protection is not enabled for this folder yet.']);
        exit;
    }
    $note = trim(substr($_POST['note'] ?? '', 0, 500));
    $files = $_FILES['photos'] ?? null;
    if (!$files || !is_array($files['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No photos received.']);
        exit;
    }
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    $saved = []; $failed = [];
    $count = min(count($files['name']), MAX_FILES);
    for ($i = 0; $i < $count; $i++) {
        $orig = basename($files['name'][$i]);
        if ($files['error'][$i] !== UPLOAD_ERR_OK) { $failed[] = "$orig: upload error {$files['error'][$i]}"; continue; }
        if ($files['size'][$i] > MAX_BYTES) { $failed[] = "$orig: larger than 15 MB"; continue; }
        $info = @getimagesize($files['tmp_name'][$i]);
        if (!$info || !isset(TYPES[$info[2]])) { $failed[] = "$orig: not a JPG, PNG or WEBP photo"; continue; }

        $base = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . ($note !== '' ? '-' . slug($note) : '');
        $name = $base . '.' . TYPES[$info[2]];
        if (!move_uploaded_file($files['tmp_name'][$i], UPLOAD_DIR . '/' . $name)) { $failed[] = "$orig: could not be saved"; continue; }
        chmod(UPLOAD_DIR . '/' . $name, 0644);
        if ($note !== '') file_put_contents(UPLOAD_DIR . '/' . $base . '.txt', $note . "\n");
        $saved[] = $name;
    }
    echo json_encode(['saved' => $saved, 'failed' => $failed]);
    exit;
}

$recent = [];
if ($protected && is_dir(UPLOAD_DIR)) {
    $recent = array_filter(glob(UPLOAD_DIR . '/*') ?: [], function ($f) {
        return preg_match('/\.(jpg|png|webp)$/', $f);
    });
    usort($recent, function ($a, $b) { return filemtime($b) - filemtime($a); });
    $recent = array_slice($recent, 0, 24);
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
        .card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 2px 10px rgba(0,0,0,.06); margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        textarea { width: 100%; min-height: 70px; padding: 10px; font: inherit; border: 1px solid #ccd; border-radius: 8px; }
        .pick { display: flex; gap: 10px; margin: 12px 0; }
        .pick label { flex: 1; text-align: center; padding: 14px 8px; border: 2px dashed var(--navy); border-radius: 10px; color: var(--navy); cursor: pointer; margin: 0; }
        .pick input { display: none; }
        .previews { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
        .previews img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; }
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
    <p class="lead">Photos go straight to the website server for use on the site.</p>

<?php if (!$protected): ?>
    <div class="warn">Uploads are switched off: this page is not password-protected yet.
        Turn on cPanel &rarr; <strong>Directory Privacy</strong> for the <code>photo-upload</code> folder.</div>
<?php else: ?>
    <form class="card" id="form">
        <label for="note">Where and what? <small>(optional, but helps)</small></label>
        <textarea id="note" maxlength="500" placeholder="e.g. Chakan MIDC, auto parts factory, PU flooring, 20,000 sq ft"></textarea>

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
        <h2>Recently uploaded</h2>
        <div class="previews">
            <?php foreach ($recent as $f): ?>
                <img src="uploads/<?= htmlspecialchars(basename($f)) ?>" alt="" loading="lazy">
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
            var xhr = new XMLHttpRequest();
            xhr.open('POST', location.pathname);
            xhr.upload.onprogress = function (ev) { if (ev.lengthComputable) { bar.style.display = 'block'; bar.value = ev.loaded / ev.total * 100; } };
            xhr.onload = function () {
                var r = {}; try { r = JSON.parse(xhr.responseText); } catch (err) {}
                if (xhr.status === 200 && r.saved) {
                    msg.className = 'msg ' + (r.failed.length ? 'err' : 'ok');
                    msg.textContent = r.saved.length + ' uploaded' + (r.failed.length ? '. Not uploaded: ' + r.failed.join('; ') : '. Thank you!');
                    if (r.saved.length) setTimeout(function () { location.reload(); }, 1500);
                } else {
                    msg.className = 'msg err';
                    msg.textContent = r.error || ('Upload failed (' + xhr.status + '). Try fewer photos at a time.');
                    send.disabled = false;
                }
            };
            xhr.onerror = function () { msg.className = 'msg err'; msg.textContent = 'No connection. Please try again.'; send.disabled = false; };
            msg.textContent = 'Uploading…';
            xhr.send(data);
        });
    });
})();
</script>
</body>
</html>
