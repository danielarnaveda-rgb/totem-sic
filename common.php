<?php
require __DIR__.'/config.php';

session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax']);
session_start();

const COURSES = [
    '2º año' => ['Sistema de Información Contable','Taller Contable'],
    '3º año' => ['Gestión de Compra Venta'],
    '4º año' => ['Sistema de Información Contable'],
    '5º año' => ['Sistema de Información','Administración'],
    '7º año' => ['Estados Contables']
];

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function lbl($c) {
    return str_replace('|', ' · ', $c);
}

function course_ok($c) {
    foreach (COURSES as $y => $ss) {
        foreach ($ss as $s) {
            if ("$y|$s" === $c) return true;
        }
    }
    return false;
}

function course_select($sel, $all=false, $auto=false) {
    $o = '<option value="">'.($all ? 'Todos los cursos' : 'Elegí año y asignatura…').'</option>';

    foreach (COURSES as $y => $ss) {
        $o .= '<optgroup label="'.h($y).'">';
        foreach ($ss as $s) {
            $v = "$y|$s";
            $o .= '<option value="'.h($v).'"'.($v === $sel ? ' selected' : '').'>'.h($s).'</option>';
        }
        $o .= '</optgroup>';
    }

    return '<select name="c"'.($auto ? ' onchange="this.form.submit()"' : '').($all ? '' : ' required').'>'.$o.'</select>';
}

function go($u) {
    header('Location: '.$u);
    exit;
}

$opt = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        $opt
    );
} catch (PDOException $e) {
    $p = new PDO('mysql:host='.DB_HOST.';charset=utf8mb4', DB_USER, DB_PASS, $opt);
    $p->exec('CREATE DATABASE IF NOT EXISTS `'.DB_NAME.'` CHARACTER SET utf8mb4');
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        $opt
    );
}

foreach ([
    'CREATE TABLE IF NOT EXISTS teacher (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user VARCHAR(60) NOT NULL,
        hash VARCHAR(255) NOT NULL
    )',
    'CREATE TABLE IF NOT EXISTS materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course VARCHAR(120) NOT NULL,
        title VARCHAR(200),
        orig VARCHAR(255) NOT NULL,
        stored VARCHAR(80) NOT NULL,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )',
    'CREATE TABLE IF NOT EXISTS quizzes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course VARCHAR(120) NOT NULL,
        title VARCHAR(200) NOT NULL,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )',
    'CREATE TABLE IF NOT EXISTS questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quiz_id INT NOT NULL,
        q TEXT NOT NULL,
        opts TEXT NOT NULL,
        correct TINYINT NOT NULL
    )',
    'CREATE TABLE IF NOT EXISTS results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quiz_id INT,
        quiz_title VARCHAR(200),
        name VARCHAR(120),
        score INT,
        total INT,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )'
] as $sql) {
    $pdo->exec($sql);
}

/*
 * Actualización compatible con la base de datos existente.
 * Las preguntas anteriores siguen siendo de opción múltiple.
 */
$questionColumns = q('SHOW COLUMNS FROM questions')->fetchAll();
$questionFields = array_column($questionColumns, 'Field');

if (!in_array('type', $questionFields, true)) {
    $pdo->exec("ALTER TABLE questions ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'multiple'");
}

$resultColumns = q('SHOW COLUMNS FROM results')->fetchAll();
$resultFields = array_column($resultColumns, 'Field');

if (!in_array('answers', $resultFields, true)) {
    $pdo->exec('ALTER TABLE results ADD COLUMN answers TEXT NULL');
}

/* Registro de contenido inicial */
$pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (`key` VARCHAR(80) PRIMARY KEY, `value` TEXT)');

$seeded = q("SELECT `value` FROM app_meta WHERE `key`='demo_seeded'")->fetchColumn();

if (!$seeded) {
    $up = __DIR__.'/uploads';
    if (!is_dir($up)) mkdir($up, 0755, true);
    if (!is_file($up.'/.htaccess')) {
        file_put_contents($up.'/.htaccess', "Require all denied\n");
    }

    $initial = [
        [
            'Gestión de Documentación y Fiscalidad (IVA) — Cuadernillo 2',
            'materiales_iniciales/SIC_Cuadernillo_2.pdf',
            'SIC_Cuadernillo_2.pdf'
        ],
        [
            'Técnica de Registración y Partida Doble — Cuadernillo 3',
            'materiales_iniciales/SIC_Cuadernillo_3.pdf',
            'SIC_Cuadernillo_3.pdf'
        ]
    ];

    foreach ($initial as $it) {
        $src = __DIR__.'/'.$it[1];
        $dest = $up.'/'.$it[2];

        if (is_file($src)) {
            if (!is_file($dest)) copy($src, $dest);

            q(
                'INSERT INTO materials(course,title,orig,stored) VALUES(?,?,?,?)',
                [
                    '4º año|Sistema de Información Contable',
                    $it[0],
                    basename($it[2]),
                    basename($it[2])
                ]
            );
        }
    }

    $demos = [
        [
            'SIC — Operaciones y documentación comercial',
            [
                ['¿Cuándo se considera una operación al contado?', [
                    'Cuando el pago se realiza en el mismo momento de la operación',
                    'Cuando se paga a los 30 días',
                    'Cuando se firma siempre un pagaré',
                    'Cuando no se entrega el bien'
                ], 0],
                ['Según el cuadernillo, ¿qué es una bonificación?', [
                    'Una rebaja por pagar antes de tiempo',
                    'Una rebaja por volumen o por fallas de calidad',
                    'Una comisión bancaria',
                    'Un impuesto sobre las ventas'
                ], 1],
                ['¿Cuál es una función de los documentos comerciales?', [
                    'Reemplazar todos los libros contables',
                    'Servir como respaldo jurídico, de control y contable',
                    'Evitar registrar operaciones',
                    'Garantizar que no haya gastos'
                ], 1],
                ['¿Qué documento respalda que una persona realizó un pago?', [
                    'Recibo',
                    'Orden de compra',
                    'Lista de precios',
                    'Catálogo'
                ], 0],
                ['¿Qué representa una comisión por cobrar mediante una plataforma digital?', [
                    'Una ganancia extra automática',
                    'Un gasto que reduce la ganancia final',
                    'Una bonificación al comprador',
                    'Un activo fijo'
                ], 1],
                ['En la actividad de las 10 fundas, los costos suman $15.000. ¿Cuál es el costo unitario?', [
                    '$1.000',
                    '$1.250',
                    '$1.500',
                    '$2.250'
                ], 2]
            ]
        ],
        [
            'SIC — Cuentas y partida doble',
            [
                ['¿Para qué sirve una cuenta contable?', [
                    'Para agrupar elementos similares con un nombre técnico',
                    'Para evitar usar documentos comerciales',
                    'Para reemplazar el patrimonio',
                    'Para calcular únicamente impuestos'
                ], 0],
                ['¿Cuál de estas cuentas corresponde al Activo?', [
                    'Proveedores',
                    'Capital Social',
                    'Caja',
                    'Ventas'
                ], 2],
                ['¿Qué representan las cuentas de Pasivo?', [
                    'Bienes propios de la empresa',
                    'Obligaciones y deudas contraídas',
                    'Ingresos por ventas',
                    'Gastos de luz'
                ], 1],
                ['Según el cuadernillo, ¿qué cuentas suelen tener saldo deudor?', [
                    'Pasivo y Patrimonio Neto',
                    'Activo y Resultado Negativo',
                    'Solo Resultado Positivo',
                    'Todas las cuentas siempre'
                ], 1],
                ['¿Qué es el Plan de Cuentas?', [
                    'Un índice ordenado y codificado de las cuentas de una empresa',
                    'Un comprobante de pago',
                    'Un listado de clientes morosos',
                    'Un registro exclusivo de impuestos'
                ], 0],
                ['¿Cuándo una cuenta queda saldada?', [
                    'Cuando no tuvo movimientos',
                    'Cuando el Debe es igual al Haber',
                    'Cuando solo tiene importes en el Haber',
                    'Cuando registra una venta'
                ], 1]
            ]
        ]
    ];

    foreach ($demos as $demo) {
        q(
            'INSERT INTO quizzes(course,title) VALUES(?,?)',
            ['4º año|Sistema de Información Contable', $demo[0]]
        );

        $qid = $pdo->lastInsertId();

        foreach ($demo[1] as $item) {
            q(
                'INSERT INTO questions(quiz_id,q,opts,correct,type) VALUES(?,?,?,?,?)',
                [
                    $qid,
                    $item[0],
                    json_encode($item[1], JSON_UNESCAPED_UNICODE),
                    $item[2],
                    'multiple'
                ]
            );
        }
    }

    q("INSERT INTO app_meta(`key`,`value`) VALUES('demo_seeded','1')");
}

function q($sql, $a=[]) {
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($a);
    return $s;
}

function head($t, $badge='') {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($t).'</title><style>
:root{--bg:#f7f6f2;--card:#fff;--tx:#1f2328;--mu:#6b7280;--ac:#2f5d50;--bd:#e3e1d9;--ok:#2e7d32;--bad:#c62828}
@media(prefers-color-scheme:dark){:root{--bg:#17191a;--card:#212426;--tx:#ececec;--mu:#9aa0a6;--ac:#6fb59f;--bd:#34383b}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:16px/1.5 system-ui,sans-serif}
main{max-width:760px;margin:0 auto;padding:20px 16px 60px}h1{font-size:22px;margin:0}h2{font-size:18px;margin:0 0 10px}
header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:8px}
.badge{font-size:12px;background:var(--ac);color:#fff;border-radius:99px;padding:2px 10px}
nav{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
button,.btn{font:inherit;border:1px solid var(--bd);background:var(--card);color:var(--tx);border-radius:8px;padding:7px 14px;cursor:pointer;text-decoration:none;display:inline-block}
button.pri,.btn.on{background:var(--ac);color:#fff;border-color:var(--ac)}.sm{padding:3px 9px;font-size:13px}
.card{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:14px;margin-bottom:12px}
input,select,textarea{font:inherit;color:var(--tx);background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:7px 10px;width:100%;margin:4px 0}
textarea{min-height:110px;resize:vertical}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.mu{color:var(--mu);font-size:14px}.err{color:var(--bad)}.okm{color:var(--ok)}
.opt{display:flex;gap:8px;align-items:center;padding:8px 10px;border:1px solid var(--bd);border-radius:8px;margin:5px 0;cursor:pointer}.opt input{width:auto;margin:0}
.opt.ok{border-color:var(--ok);background:rgba(46,125,50,.12)}.opt.bad{border-color:var(--bad);background:rgba(198,40,40,.12)}
table{width:100%;border-collapse:collapse;font-size:14px}td,th{text-align:left;padding:6px;border-bottom:1px solid var(--bd)}.scroll{overflow-x:auto}
</style></head><body><main><header><h1>📚 '.h($t).'</h1>'.($badge ? '<span class="badge">'.h($badge).'</span>' : '').'</header>';
}

function foot() {
    echo '</main></body></html>';
}
