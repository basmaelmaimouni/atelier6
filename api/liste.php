<?php
declare(strict_types=1);

/**
 * api/liste.php  —  opération de LECTURE (le « R » de CRUD)
 *
 * Interroge la base MySQL hébergée chez Aiven et renvoie les inscriptions en JSON.
 * Le navigateur n'accède jamais directement à MySQL : il appelle seulement ce fichier.
 *
 * Paramètres GET optionnels :
 *   page    numéro de page (défaut 1)
 *   limite  nombre de résultats par page (défaut 20, maximum 100)
 *
 * Exemple : api/liste.php?page=2&limite=10
 *
 * Variables d'environnement attendues (valeurs fournies par la console Aiven) :
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD (ou DB_PASS)
 *   DB_SSL_CA   chemin du certificat CA d'Aiven (ca.pem) — Aiven impose une connexion SSL
 */

const LIMITE_DEFAUT = 20;
const LIMITE_MAX    = 100;

function config(string $cle, string $defaut = ''): string
{
    $valeur = getenv($cle);
    return ($valeur === false || $valeur === '') ? $defaut : $valeur;
}

function repondre(int $code, array $corps): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function connexion_bdd(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        config('DB_HOST', 'localhost'),
        config('DB_PORT', '3306'),
        config('DB_NAME')
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Connexion chiffrée vers Aiven : on vérifie le certificat du serveur avec le CA fourni.
    $ca = config('DB_SSL_CA');
    if ($ca === '') {
        $ca = dirname(__DIR__) . '/certs/ca.pem';
    }
    if (is_file($ca)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    return new PDO($dsn, config('DB_USER'), config('DB_PASSWORD', config('DB_PASS')), $options);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    repondre(405, ['success' => false, 'message' => 'Méthode non autorisée. Utilisez GET.']);
}

try {
    // Pagination : valeurs bornées pour éviter les requêtes abusives.
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $limite = (int) ($_GET['limite'] ?? LIMITE_DEFAUT);
    $limite = min(LIMITE_MAX, max(1, $limite));
    $offset = ($page - 1) * $limite;

    $pdo = connexion_bdd();

    $total = (int) $pdo->query('SELECT COUNT(*) FROM inscriptions')->fetchColumn();

    $requete = $pdo->prepare(
        'SELECT id, nom, prenom, email, telephone, photo_url, created_at
           FROM inscriptions
          ORDER BY id DESC
          LIMIT :limite OFFSET :offset'
    );
    $requete->bindValue(':limite', $limite, PDO::PARAM_INT);
    $requete->bindValue(':offset', $offset, PDO::PARAM_INT);
    $requete->execute();

    $inscriptions = $requete->fetchAll();
    foreach ($inscriptions as &$ligne) {
        $ligne['id'] = (int) $ligne['id'];
    }
    unset($ligne);

    repondre(200, [
        'success' => true,
        'data'    => $inscriptions,
        'meta'    => [
            'total'  => $total,
            'page'   => $page,
            'limite' => $limite,
            'pages'  => (int) ceil($total / $limite),
        ],
    ]);
} catch (Throwable $e) {
    // Le détail technique reste dans les logs serveur, jamais dans la réponse.
    error_log('[liste.php] ' . $e::class . ' : ' . $e->getMessage());
    repondre(500, ['success' => false, 'message' => 'Une erreur interne est survenue. Réessayez plus tard.']);
}