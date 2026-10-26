
<?php

// PHP warnings must never be mixed into the JSON response body.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['message' => 'Méthode non autorisée.']);
}

try {
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $ville = trim($_POST['ville'] ?? '');

    if (
        $nom === '' || $prenom === '' || $ville === '' ||
        mb_strlen($nom) > 100 ||
        mb_strlen($prenom) > 100 ||
        mb_strlen($ville) > 100
    ) {
        respond(422, [
            'message' => 'Veuillez renseigner correctement les champs.'
        ]);
    }

    if (
        !isset($_FILES['photo']) ||
        $_FILES['photo']['error'] !== UPLOAD_ERR_OK
    ) {
        respond(422, ['message' => 'Veuillez sélectionner une photo valide.']);
    }

    $file = $_FILES['photo'];

    if ($file['size'] > 5 * 1024 * 1024) {
        respond(422, ['message' => 'La photo ne doit pas dépasser 5 Mo.']);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (
        !in_array($mime, $allowedTypes, true) ||
        getimagesize($file['tmp_name']) === false
    ) {
        respond(422, ['message' => 'Format de photo non accepté.']);
    }

    require_once dirname(__DIR__) . '/vendor/autoload.php';
    require_once dirname(__DIR__) . '/config/db.php';

    // 1. Envoyer l'image vers Cloudinary.
    $cloudinary = new \Cloudinary\Cloudinary();

    $upload = $cloudinary->uploadApi()->upload(
        $file['tmp_name'],
        ['folder' => 'atelier-stagiaires']
    );

    $photoUrl = $upload['secure_url'];

    // 2. Enregistrer les informations dans MySQL.
    $db = getDatabase();

    $sql = 'INSERT INTO stagiaires (nom, prenom, ville, photo)
            VALUES (:nom, :prenom, :ville, :photo)';

    $stmt = $db->prepare($sql);

    $stmt->execute([
        'nom' => $nom,
        'prenom' => $prenom,
        'ville' => $ville,
        'photo' => $photoUrl
    ]);

    respond(201, [
        'success' => true,
        'message' => 'Stagiaire inscrit avec succès.',
        'id' => (int) $db->lastInsertId()
    ]);

} catch (Throwable $e) {
    error_log((string) $e);

    respond(500, [
        'message' => 'Erreur serveur. Vérifiez la configuration et les journaux.'
    ]);
}