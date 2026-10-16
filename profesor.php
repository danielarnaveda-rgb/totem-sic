<?php
// PANEL DEL PROFESOR
require __DIR__.'/common.php';

$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$exists = (int)q('SELECT COUNT(*) FROM teacher')->fetchColumn() > 0;
$isT = !empty($_SESSION['t']);
$err = '';
$a = $_POST['a'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($a === 'setup' && !$exists) {
        $u = trim($_POST['u'] ?? '');
        $p = $_POST['p'] ?? '';

        if ($u === '' || strlen($p) < 6) {
            $err = 'Usuario obligatorio y contraseña de al menos 6 caracteres.';
        } elseif ($p !== ($_POST['p2'] ?? '')) {
            $err = 'Las contraseñas no coinciden.';
        } else {
            q('INSERT INTO teacher(user,hash) VALUES(?,?)',
                [strtolower($u), password_hash($p, PASSWORD_DEFAULT)]);
            $_SESSION['t'] = 1;
            session_regenerate_id(true);
            go('profesor.php');
        }
    } elseif ($a === 'login') {
        if (($_SESSION['until'] ?? 0) > time()) {
            $err = 'Demasiados intentos. Esperá un momento.';
        } else {
            $r = q('SELECT * FROM teacher WHERE user=?',
                [strtolower(trim($_POST['u'] ?? ''))])->fetch();

            if ($r && password_verify($_POST['p'] ?? '', $r['hash'])) {
                $_SESSION['t'] = 1;
                $_SESSION['fails'] = 0;
                session_regenerate_id(true);
                go('profesor.php');
            }

            sleep(1);
            $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;

            if ($_SESSION['fails'] >= 5) {
                $_SESSION['until'] = time() + 30;
                $_SESSION['fails'] = 0;
            }

            $err = 'Usuario o contraseña incorrectos.';
        }
    } elseif ($isT && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $m = '';

        if ($a === 'logout') {
            session_destroy();
            go('profesor.php');
        }

        if ($a === 'upload') {
            $c = $_POST['c'] ?? '';
            $ok = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','png','jpg','jpeg','zip'];

            if (!course_ok($c)) {
                $m = 'Elegí año y asignatura.';
            } else {
                if (!is_dir(__DIR__.'/uploads')) {
                    mkdir(__DIR__.'/uploads', 0755, true);
                    file_put_contents(__DIR__.'/uploads/.htaccess', "Require all denied\n");
                }

                $n = 0;
                $f = $_FILES['files'] ?? ['name'=>[]];

                foreach ($f['name'] as $i => $name) {
                    if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        $m .= 'No se pudo subir "'.$name.'". ';
                        continue;
                    }

                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                    if (!in_array($ext, $ok, true)) {
                        $m .= 'Tipo no permitido: '.$name.'. ';
                        continue;
                    }

                    $st = bin2hex(random_bytes(12)).'.'.$ext;

                    if (move_uploaded_file($f['tmp_name'][$i], __DIR__.'/uploads/'.$st)) {
                        q('INSERT INTO materials(course,title,orig,stored) VALUES(?,?,?,?)',
                            [$c, trim($_POST['title'] ?? ''), basename($name), $st]);
                        $n++;
                    }
                }

                $m = $n.' archivo(s) subido(s). '.$m;
            }
        }

        if ($a === 'delmat') {
            $r = q('SELECT * FROM materials WHERE id=?', [(int)($_POST['id'] ?? 0)])->fetch();

            if ($r) {
                @unlink(__DIR__.'/uploads/'.$r['stored']);
                q('DELETE FROM materials WHERE id=?', [$r['id']]);
                $m = 'Material borrado.';
            }
        }

        if ($a === 'newquiz') {
            $c = $_POST['c'] ?? '';
            $ti = trim($_POST['title'] ?? '');
            $qs = [];

            foreach (($_POST['q'] ?? []) as $x) {
                $texto = trim($x['t'] ?? '');
                $tipo = ($x['type'] ?? 'multiple') === 'written' ? 'written' : 'multiple';

                if ($texto === '') {
                    continue;
                }

                if ($tipo === 'written') {
                    $qs[] = [
                        'text' => $texto,
                        'type' => 'written',
                        'opts' => [],
                        'correct' => 0
                    ];
                } else {
                    $o = array_map('trim', $x['o'] ?? []);

                    if (count($o) !== 4 || in_array('', $o, true)) {
                        continue;
                    }

                    $qs[] = [
                        'text' => $texto,
                        'type' => 'multiple',
                        'opts' => $o,
                        'correct' => max(0, min(3, (int)($x['c'] ?? 0)))
                    ];
                }
            }

            if (!course_ok($c) || $ti === '' || !$qs) {
                $m = 'Completá curso, título y al menos una pregunta válida.';
            } else {
                q('INSERT INTO quizzes(course,title) VALUES(?,?)', [$c, $ti]);
                $id = $pdo->lastInsertId();

                foreach ($qs as $x) {
                    q(
                        'INSERT INTO questions(quiz_id,q,opts,correct,type) VALUES(?,?,?,?,?)',
                        [
                            $id,
                            $x['text'],
                            json_encode($x['opts'], JSON_UNESCAPED_UNICODE),
                            $x['correct'],
                            $x['type']
                        ]
                    );
                }

                $m = 'Quiz guardado correctamente.';
            }
        }

        if ($a === 'delquiz') {
            $id = (int)($_POST['id'] ?? 0);
            q('DELETE FROM questions WHERE quiz_id=?', [$id]);
            q('DELETE FROM results WHERE quiz_id=?', [$id]);
            q('DELETE FROM quizzes WHERE id=?', [$id]);
            $m = 'Quiz borrado.';
        }

        $_SESSION['flash'] = $m;
        go('profesor.php?t='.urlencode($_GET['t'] ?? 'material'));
    }
}

head('Panel del profesor', 'Profesor');

if (!$isT) {
    echo '<div class="card" style="max-width:380px;margin:30px auto">';
    echo '<h2>🔒 '.($exists ? 'Ingresar' : 'Crear acceso').'</h2>';

    if (!$exists) {
        echo '<p class="mu">Primera vez: creá tu usuario y contraseña.</p>';
    }

    if ($err) {
        echo '<p class="err">'.h($err).'</p>';
    }

    echo '<form method="post">';
    echo '<input type="hidden" name="a" value="'.($exists ? 'login' : 'setup').'">';
    echo '<input name="u" placeholder="Usuario" required autocomplete="username">';
    echo '<input type="password" name="p" placeholder="Contraseña" required>';

    if (!$exists) {
        echo '<input type="password" name="p2" placeholder="Repetir contraseña" required>';
    }

    echo '<button class="pri" style="width:100%">'.($exists ? 'Entrar' : 'Crear y entrar').'</button>';
    echo '</form></div>';

    foot();
    exit;
}

$t = $_GET['t'] ?? 'material';

if (!in_array($t, ['material','quizzes','nuevo','resultados'], true)) {
    $t = 'material';
}

$csrf = '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';

echo '<nav>';

foreach ([
    'material' => 'Material',
    'quizzes' => 'Quizzes',
    'nuevo' => '+ Nuevo quiz',
    'resultados' => 'Resultados'
] as $k => $l) {
    echo '<a class="btn'.($t === $k ? ' on' : '').'" href="profesor.php?t='.$k.'">'.h($l).'</a>';
}

echo '<a class="btn" href="index.php" target="_blank">👀 Ver como alumno</a>';
echo '<form method="post" style="margin-left:auto">'.$csrf;
echo '<input type="hidden" name="a" value="logout"><button>🔒 Salir</button></form></nav>';

if (!empty($_SESSION['flash'])) {
    echo '<p class="okm">'.h($_SESSION['flash']).'</p>';
    unset($_SESSION['flash']);
}

if ($t === 'material') {
    echo '<form method="post" enctype="multipart/form-data" class="card">';
    echo '<h2>Subir material</h2>'.$csrf;
    echo '<input type="hidden" name="a" value="upload">';
    echo course_select('');
    echo '<input name="title" placeholder="Título (opcional)">';
    echo '<input type="file" name="files[]" multiple required>';
    echo '<button class="pri">Subir</button>';
    echo '<p class="mu">Permitidos: pdf, doc(x), ppt(x), xls(x), txt, png, jpg, zip.</p>';
    echo '</form>';

    foreach (q('SELECT * FROM materials ORDER BY id DESC')->fetchAll() as $m) {
        echo '<div class="card row"><div><b>'.h($m['title'] ?: $m['orig']).'</b>';
        echo '<div class="mu">'.h(lbl($m['course'])).' · '.h($m['orig']).'</div></div>';
        echo '<form method="post" onsubmit="return confirm(\'¿Borrar?\')">'.$csrf;
        echo '<input type="hidden" name="a" value="delmat">';
        echo '<input type="hidden" name="id" value="'.$m['id'].'">';
        echo '<a class="btn sm" target="_blank" href="index.php?a=file&id='.$m['id'].'">Abrir</a> ';
        echo '<button class="sm">Borrar</button></form></div>';
    }

} elseif ($t === 'quizzes') {
    foreach (q('SELECT qz.*,(SELECT COUNT(*) FROM questions WHERE quiz_id=qz.id) n FROM quizzes qz ORDER BY id DESC')->fetchAll() as $z) {
        echo '<div class="card row"><div><b>'.h($z['title']).'</b>';
        echo '<div class="mu">'.h(lbl($z['course'])).' · '.$z['n'].' preguntas</div></div>';
        echo '<form method="post" onsubmit="return confirm(\'¿Borrar este quiz y sus resultados?\')">'.$csrf;
        echo '<input type="hidden" name="a" value="delquiz">';
        echo '<input type="hidden" name="id" value="'.$z['id'].'">';
        echo '<button class="sm">Borrar</button></form></div>';
    }

} elseif ($t === 'nuevo') {
    echo '<form method="post">';
    echo '<div class="card"><h2>Nuevo quiz</h2>'.$csrf;
    echo '<input type="hidden" name="a" value="newquiz">';
    echo course_select('');
    echo '<input name="title" placeholder="Título del quiz" required>';
    echo '</div>';

    echo '<div id="qs"></div>';
    echo '<button type="button" onclick="addQ()">+ Pregunta</button> ';
    echo '<button class="pri">Guardar quiz</button>';
    echo '<p class="mu">Elegí si cada pregunta será de opción múltiple o de respuesta escrita.</p>';
    echo '</form>';

    echo <<<'HTML'
<script>
let n = 0;

function addQ() {
    const i = n++;
    const d = document.createElement('div');
    d.className = 'card';

    d.innerHTML = `
        <b>Pregunta ${i + 1}</b>
        <input name="q[${i}][t]" placeholder="Enunciado de la pregunta" required>

        <label>Tipo de pregunta</label>
        <select name="q[${i}][type]" onchange="changeType(this, ${i})">
            <option value="multiple">Opción múltiple</option>
            <option value="written">Respuesta escrita</option>
        </select>

        <div id="opts-${i}">
            ${[0,1,2,3].map(j => `
                <label class="opt">
                    <input type="radio" name="q[${i}][c]" value="${j}" ${j === 0 ? 'checked' : ''}>
                    <input name="q[${i}][o][]" placeholder="Opción ${j + 1}">
                </label>
            `).join('')}
            <p class="mu">Marcá el círculo de la respuesta correcta.</p>
        </div>

        <button type="button" class="sm" onclick="this.parentNode.remove()">Quitar pregunta</button>
    `;

    document.getElementById('qs').appendChild(d);
    changeType(d.querySelector('select[name$="[type]"]'), i);
}

function changeType(select, i) {
    const box = document.getElementById('opts-' + i);
    const written = select.value === 'written';

    box.style.display = written ? 'none' : 'block';

    box.querySelectorAll('input').forEach(input => {
        input.required = !written;
    });
}

addQ();
</script>
HTML;

} else {
    $r = q('SELECT * FROM results ORDER BY id DESC LIMIT 500')->fetchAll();

    if (!$r) {
        echo '<p class="mu">Todavía no hay resultados.</p>';
    } else {
        echo '<div class="card scroll"><table>';
        echo '<tr><th>Quiz</th><th>Alumno</th><th>Nota</th><th>Fecha</th></tr>';

        foreach ($r as $x) {
            echo '<tr>';
            echo '<td>'.h($x['quiz_title']).'</td>';
            echo '<td>'.h($x['name']).'</td>';
            echo '<td>'.h($x['score']).'/'.h($x['total']).'</td>';
            echo '<td class="mu">'.h(substr($x['created'], 0, 16)).'</td>';
            echo '</tr>';

            if (!empty($x['answers'])) {
                $answers = json_decode($x['answers'], true);

                if (is_array($answers) && !empty($answers['written'])) {
                    echo '<tr><td colspan="4"><b>Respuestas escritas:</b><br>';

                    foreach ($answers['written'] as $questionId => $answer) {
                        $question = q('SELECT q FROM questions WHERE id=? AND quiz_id=?',
                            [(int)$questionId, (int)$x['quiz_id']])->fetchColumn();

                        echo '<p><b>'.h($question ?: 'Pregunta').'</b><br>';
                        echo nl2br(h($answer)).'</p>';
                    }

                    echo '</td></tr>';
                }
            }
        }

        echo '</table></div>';
    }
}

foot();
