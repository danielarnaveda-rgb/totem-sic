<?php
// PÁGINA DE ALUMNOS: materiales y quizzes sin iniciar sesión.
require __DIR__.'/common.php';

if (($_GET['a'] ?? '') === 'file') {
    $m = q('SELECT * FROM materials WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch();
    $f = $m ? __DIR__.'/uploads/'.$m['stored'] : '';

    if (!$m || !is_file($f)) {
        http_response_code(404);
        exit('No encontrado');
    }

    $ext = strtolower(pathinfo($m['orig'], PATHINFO_EXTENSION));
    $mt = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'txt' => 'text/plain; charset=utf-8'
    ];

    $inline = isset($mt[$ext]);

    header('Content-Type: '.($mt[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode($m['orig']));
    header('Content-Length: '.filesize($f));
    readfile($f);
    exit;
}

$res = null;
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['a'] ?? '') === 'submit') {
    $qid = (int)($_POST['quiz'] ?? 0);
    $_GET['quiz'] = $qid;
    $name = trim($_POST['name'] ?? '');

    $quiz = q('SELECT * FROM quizzes WHERE id=?', [$qid])->fetch();

    if (!$quiz) {
        go('index.php?t=quizzes');
    }

    if ($name === '') {
        $err = 'Escribí tu nombre para enviar el quiz.';
    } else {
        $qs = q('SELECT * FROM questions WHERE quiz_id=? ORDER BY id', [$qid])->fetchAll();

        $ans = $_POST['ans'] ?? [];
        $written = $_POST['written'] ?? [];
        $score = 0;
        $multipleTotal = 0;

        $res = [
            'ans' => [],
            'written' => []
        ];

        foreach ($qs as $x) {
            $id = (int)$x['id'];
            $type = $x['type'] ?? 'multiple';

            if ($type === 'written') {
                $answer = trim((string)($written[$id] ?? ''));

                $res['written'][$id] = $answer;
            } else {
                $multipleTotal++;
                $v = isset($ans[$id]) ? (int)$ans[$id] : -1;

                $res['ans'][$id] = $v;

                if ($v === (int)$x['correct']) {
                    $score++;
                }
            }
        }

        $res['score'] = $score;
        $res['total'] = $multipleTotal;
        $res['pending'] = count($res['written']);

        $answersJson = json_encode($res, JSON_UNESCAPED_UNICODE);

        q(
            'INSERT INTO results(quiz_id,quiz_title,name,score,total,answers) VALUES(?,?,?,?,?,?)',
            [
                $qid,
                $quiz['title'],
                mb_substr($name, 0, 120),
                $score,
                $multipleTotal,
                $answersJson
            ]
        );
    }
}

head('Aula', 'Alumno');

$t = ($_GET['t'] ?? 'material') === 'quizzes' ? 'quizzes' : 'material';
$c = course_ok($_GET['c'] ?? '') ? $_GET['c'] : '';

if (isset($_GET['quiz'])) {
    $quiz = q('SELECT * FROM quizzes WHERE id=?', [(int)$_GET['quiz']])->fetch();

    if ($quiz) {
        $qs = q('SELECT * FROM questions WHERE quiz_id=? ORDER BY id', [$quiz['id']])->fetchAll();

        echo '<a class="btn" href="index.php?t=quizzes">← Volver</a>';
        echo '<form method="post" class="card" style="margin-top:12px">';
        echo '<h2>'.h($quiz['title']).'</h2>';
        echo '<div class="mu">'.h(lbl($quiz['course'])).'</div>';

        if ($err) {
            echo '<p class="err">'.h($err).'</p>';
        }

        foreach ($qs as $i => $x) {
            $id = (int)$x['id'];
            $type = $x['type'] ?? 'multiple';

            echo '<div style="margin:18px 0">';
            echo '<b>'.($i + 1).'. '.h($x['q']).'</b>';

            if ($type === 'written') {
                $value = $res
                    ? ($res['written'][$id] ?? '')
                    : ($_POST['written'][$id] ?? '');

                echo '<textarea name="written['.$id.']" placeholder="Escribí tu respuesta..."'.($res ? ' disabled' : '').'>'.h($value).'</textarea>';
                echo '<p class="mu">Respondé con tus palabras.</p>';
            } else {
                $options = json_decode($x['opts'], true);

                if (!is_array($options)) {
                    $options = [];
                }

                foreach ($options as $j => $o) {
                    $cl = '';
                    $chk = '';
                    $dis = $res ? ' disabled' : '';

                    if ($res) {
                        if ($j === (int)$x['correct']) {
                            $cl = ' ok';
                        } elseif (($res['ans'][$id] ?? -1) === $j) {
                            $cl = ' bad';
                        }

                        if (($res['ans'][$id] ?? -1) === $j) {
                            $chk = ' checked';
                        }
                    } elseif (isset($_POST['ans'][$id]) && (int)$_POST['ans'][$id] === $j) {
                        $chk = ' checked';
                    }

                    echo '<label class="opt'.$cl.'">';
                    echo '<input type="radio" name="ans['.$id.']" value="'.$j.'"'.$chk.$dis.'>';
                    echo h($o);
                    echo '</label>';
                }
            }

            echo '</div>';
        }

        if ($res) {
            if ($res['total'] > 0) {
                echo '<p style="font-size:20px;font-weight:600">Resultado automático: '.$res['score'].' / '.$res['total'].'</p>';
            } else {
                echo '<p style="font-weight:600">Respuestas enviadas correctamente.</p>';
            }

            if ($res['pending'] > 0) {
                echo '<p class="mu">También enviaste '.$res['pending'].' respuesta(s) escrita(s). La profesora puede revisarlas desde Resultados.</p>';
            }

            echo '<p class="okm">¡Tu quiz fue enviado!</p>';
        } else {
            echo '<input type="hidden" name="a" value="submit">';
            echo '<input type="hidden" name="quiz" value="'.$quiz['id'].'">';
            echo '<input name="name" placeholder="Tu nombre y apellido" required value="'.h($_POST['name'] ?? '').'">';
            echo '<button class="pri">Enviar respuestas</button>';
        }

        echo '</form>';
        foot();
        exit;
    }
}

echo '<nav>';
echo '<a class="btn'.($t === 'material' ? ' on' : '').'" href="index.php?t=material">Material de estudio</a>';
echo '<a class="btn'.($t === 'quizzes' ? ' on' : '').'" href="index.php?t=quizzes">Quizzes</a>';
echo '</nav>';

echo '<form method="get">';
echo '<input type="hidden" name="t" value="'.$t.'">';
echo course_select($c, true, true);
echo '</form><br>';

if ($t === 'material') {
    $r = q(
        'SELECT * FROM materials'.($c ? ' WHERE course=?' : '').' ORDER BY id DESC',
        $c ? [$c] : []
    )->fetchAll();

    if (!$r) {
        echo '<p class="mu">Todavía no hay material.</p>';
    }

    foreach ($r as $m) {
        echo '<div class="card row"><div>';
        echo '<b>'.h($m['title'] ?: $m['orig']).'</b>';
        echo '<div class="mu">'.h(lbl($m['course'])).' · '.h($m['orig']).'</div>';
        echo '</div>';
        echo '<a class="btn sm" target="_blank" href="index.php?a=file&id='.$m['id'].'">Abrir</a>';
        echo '</div>';
    }
} else {
    $r = q(
        'SELECT qz.*,(SELECT COUNT(*) FROM questions WHERE quiz_id=qz.id) n FROM quizzes qz'.
        ($c ? ' WHERE course=?' : '').' ORDER BY id DESC',
        $c ? [$c] : []
    )->fetchAll();

    if (!$r) {
        echo '<p class="mu">No hay quizzes disponibles todavía.</p>';
    }

    foreach ($r as $z) {
        echo '<div class="card row"><div>';
        echo '<b>'.h($z['title']).'</b>';
        echo '<div class="mu">'.h(lbl($z['course'])).' · '.$z['n'].' preguntas</div>';
        echo '</div>';
        echo '<a class="btn pri sm" href="index.php?quiz='.$z['id'].'">Resolver</a>';
        echo '</div>';
    }
}

foot();
