<?php
declare(strict_types=1);

/**
 * api/inscrire.php
 *
 * 1. Reçoit les données du formulaire (multipart/form-data : champs + fichier "photo")
 * 2. Vérifie les données et le fichier image
 * 3. Envoie la photo à Cloudinary (upload signé, côté serveur)
 * 4. Récupère l'URL sécurisée (secure_url)
 * 5. Insère les informations dans MySQL (PDO, requêtes préparées)
 * 6. Renvoie une réponse JSON au navigateur
 *
 * Les secrets (MySQL, Cloudinary) restent sur le serveur : ils sont lus dans
 * les variables d'environnement et ne sont jamais envoyés au navigateur.
 *
 * Variables d'environnement attendues :
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *   DB_SSL_CA (chemin du certificat CA d'Aiven, ca.pem)
 *   CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET
 *
 * Table MySQL :
 *   CREATE TABLE inscriptions (
 *     id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *     nom         VARCHAR(100) NOT NULL,
 *     prenom      VARCHAR(100) NOT NULL,
 *     email       VARCHAR(150) NOT NULL UNIQUE,
 *     telephone   VARCHAR(30)  NULL,
 *     photo_url   VARCHAR(500) NOT NULL,
 *     created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 *   ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const MAX_PHOTO_OCTETS = 5 * 1024 * 1024; // 5 Mo
const TYPES_AUTORISES  = ['image/jpeg', 'image/png', 'image/webp'];
const DOSSIER_CLOUDINARY = 'inscriptions';

function config(string $cle, string $defaut = ''): string
{
    $valeur = getenv($cle);
    return ($valeur === false || $valeur === '') ? $defaut : $valeur;
}

// ---------------------------------------------------------------------------
// Réponse JSON
// ---------------------------------------------------------------------------
function repondre(int $code, array $corps): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erreur(int $code, string $message, array $erreurs = []): never
{
    $corps = ['success' => false, 'message' => $message];
    if ($erreurs) {
        $corps['erreurs'] = $erreurs;
    }
    repondre($code, $corps);
}

// ---------------------------------------------------------------------------
// Cloudinary (upload et suppression signés, via cURL)
// ---------------------------------------------------------------------------
function cloudinary_signer(array $params, string $secret): string
{
    ksort($params);
    $morceaux = [];
    foreach ($params as $cle => $valeur) {
        $morceaux[] = $cle . '=' . $valeur;
    }
    return sha1(implode('&', $morceaux) . $secret);
}

function cloudinary_appel(string $url, array $champs): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $champs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $reponse = curl_exec($ch);
    if ($reponse === false) {
        $msg = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erreur réseau Cloudinary : ' . $msg);
    }
    $statut = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $json = json_decode((string) $reponse, true);
    if (!is_array($json)) {
        throw new RuntimeException("Réponse Cloudinary illisible (HTTP $statut).");
    }
    if ($statut < 200 || $statut >= 300) {
        $detail = $json['error']['message'] ?? 'erreur inconnue';
        throw new RuntimeException("Cloudinary a refusé la requête (HTTP $statut) : $detail");
    }
    return $json;
}

function cloudinary_envoyer(string $cheminTmp, string $mime, string $nomOriginal): array
{
    $cloud  = config('CLOUDINARY_CLOUD_NAME');
    $cle    = config('CLOUDINARY_API_KEY');
    $secret = config('CLOUDINARY_API_SECRET');
    if ($cloud === '' || $cle === '' || $secret === '') {
        throw new RuntimeException('Configuration Cloudinary incomplète.');
    }

    $timestamp = time();
    $signature = cloudinary_signer(
        ['folder' => DOSSIER_CLOUDINARY, 'timestamp' => $timestamp],
        $secret
    );

    return cloudinary_appel(
        "https://api.cloudinary.com/v1_1/$cloud/image/upload",
        [
            'file'      => new CURLFile($cheminTmp, $mime, $nomOriginal),
            'api_key'   => $cle,
            'timestamp' => $timestamp,
            'folder'    => DOSSIER_CLOUDINARY,
            'signature' => $signature,
        ]
    );
}

function cloudinary_supprimer(string $publicId): void
{
    // Nettoyage « au mieux » si l'insertion MySQL échoue après l'upload.
    try {
        $cloud  = config('CLOUDINARY_CLOUD_NAME');
        $cle    = config('CLOUDINARY_API_KEY');
        $secret = config('CLOUDINARY_API_SECRET');
        $timestamp = time();
        $signature = cloudinary_signer(
            ['public_id' => $publicId, 'timestamp' => $timestamp],
            $secret
        );
        cloudinary_appel(
            "https://api.cloudinary.com/v1_1/$cloud/image/destroy",
            [
                'public_id' => $publicId,
                'api_key'   => $cle,
                'timestamp' => $timestamp,
                'signature' => $signature,
            ]
        );
    } catch (Throwable $e) {
        error_log('[inscrire.php] Suppression Cloudinary impossible : ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// MySQL
// ---------------------------------------------------------------------------
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

    // Aiven impose une connexion SSL : on vérifie le certificat du serveur avec le CA fourni.
    $ca = config('DB_SSL_CA');
    if ($ca !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    return new PDO($dsn, config('DB_USER'), config('DB_PASS'), $options);
}

// ---------------------------------------------------------------------------
// Programme principal
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erreur(405, 'Méthode non autorisée. Utilisez POST.');
}

try {
    // --- 1. Réception des données -------------------------------------------
    $nom       = trim((string) ($_POST['nom'] ?? ''));
    $prenom    = trim((string) ($_POST['prenom'] ?? ''));
    $email     = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $telephone = trim((string) ($_POST['telephone'] ?? ''));

    // --- 2. Vérification des champs -----------------------------------------
    $erreurs = [];

    if (mb_strlen($nom) < 2 || mb_strlen($nom) > 100) {
        $erreurs['nom'] = 'Le nom doit contenir entre 2 et 100 caractères.';
    }
    if (mb_strlen($prenom) < 2 || mb_strlen($prenom) > 100) {
        $erreurs['prenom'] = 'Le prénom doit contenir entre 2 et 100 caractères.';
    }
    if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreurs['email'] = "L'adresse e-mail est invalide.";
    }
    if ($telephone !== '' && !preg_match('/^[0-9+\s().-]{6,30}$/', $telephone)) {
        $erreurs['telephone'] = 'Le numéro de téléphone est invalide.';
    }

    // --- 2bis. Vérification du fichier image --------------------------------
    $photo = $_FILES['photo'] ?? null;

    if ($photo === null || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $erreurs['photo'] = 'La photo est obligatoire.';
    } elseif (is_array($photo['error']) || $photo['error'] !== UPLOAD_ERR_OK) {
        $erreurs['photo'] = match ($photo['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La photo dépasse la taille maximale autorisée.',
            UPLOAD_ERR_PARTIAL                        => "La photo n'a été que partiellement envoyée.",
            default                                   => "Échec de l'envoi de la photo.",
        };
    } elseif (!is_uploaded_file($photo['tmp_name'])) {
        $erreurs['photo'] = 'Fichier non valide.';
    } elseif ($photo['size'] > MAX_PHOTO_OCTETS) {
        $erreurs['photo'] = 'La photo ne doit pas dépasser 5 Mo.';
    } else {
        // Le type est détecté d'après le contenu réel, pas d'après le nom ou le type déclaré.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($photo['tmp_name']);
        if (!in_array($mime, TYPES_AUTORISES, true) || @getimagesize($photo['tmp_name']) === false) {
            $erreurs['photo'] = 'Format non accepté (JPEG, PNG ou WebP uniquement).';
        }
    }

    if ($erreurs) {
        erreur(422, implode(' ', $erreurs), $erreurs);
    }

    // --- Connexion MySQL + contrôle de doublon (avant d'envoyer la photo) ----
    $pdo = connexion_bdd();

    $existe = $pdo->prepare('SELECT 1 FROM inscriptions WHERE email = :email LIMIT 1');
    $existe->execute([':email' => $email]);
    if ($existe->fetchColumn()) {
        erreur(409, 'Cette adresse e-mail est déjà inscrite.', ['email' => 'Déjà inscrite.']);
    }

    // --- 3. Envoi de la photo à Cloudinary ----------------------------------
    $cloudinary = cloudinary_envoyer($photo['tmp_name'], $mime, basename((string) $photo['name']));

    // --- 4. URL sécurisée ----------------------------------------------------
    $photoUrl = $cloudinary['secure_url'] ?? '';
    $publicId = $cloudinary['public_id'] ?? '';
    if ($photoUrl === '' || !str_starts_with($photoUrl, 'https://')) {
        throw new RuntimeException("Cloudinary n'a pas renvoyé d'URL sécurisée.");
    }

    // --- 5. Insertion dans MySQL --------------------------------------------
    try {
        $insertion = $pdo->prepare(
            'INSERT INTO inscriptions (nom, prenom, email, telephone, photo_url)
             VALUES (:nom, :prenom, :email, :telephone, :photo_url)'
        );
        $insertion->execute([
            ':nom'       => $nom,
            ':prenom'    => $prenom,
            ':email'     => $email,
            ':telephone' => $telephone !== '' ? $telephone : null,
            ':photo_url' => $photoUrl,
        ]);
        $id = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        // L'insertion a échoué : on retire la photo pour ne pas laisser de fichier orphelin.
        if ($publicId !== '') {
            cloudinary_supprimer($publicId);
        }
        if (($e->errorInfo[1] ?? 0) === 1062) { // doublon (course entre deux requêtes)
            erreur(409, 'Cette adresse e-mail est déjà inscrite.', ['email' => 'Déjà inscrite.']);
        }
        throw $e;
    }

    // --- 6. Réponse JSON -----------------------------------------------------
    repondre(201, [
        'success' => true,
        'message' => 'Inscription enregistrée. Merci !',
        'data'    => [
            'id'        => $id,
            'nom'       => $nom,
            'prenom'    => $prenom,
            'email'     => $email,
            'photo_url' => $photoUrl,
        ],
    ]);
} catch (Throwable $e) {
    // Le détail technique reste dans les logs serveur, jamais dans la réponse.
    error_log('[inscrire.php] ' . $e::class . ' : ' . $e->getMessage());
    erreur(500, "Une erreur interne est survenue. Réessayez plus tard.");
}