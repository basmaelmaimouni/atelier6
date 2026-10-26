
<?php

ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['message' => 'Méthode non autorisée.']);
    exit;
}

try {
    require_once dirname(__DIR__) . '/config/db.php';

    $db = getDatabase();

    $sql = 'SELECT id, nom, prenom, ville, photo, date_inscription
            FROM stagiaires
            ORDER BY id DESC';

    $stmt = $db->query($sql);

    echo json_encode(
        $stmt->fetchAll(),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);

    echo json_encode([
        'message' => 'Impossible de récupérer les stagiaires.'
    ], JSON_UNESCAPED_UNICODE);
}