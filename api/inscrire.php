<?php
declare(strict_types=1);

/**
 * api/inscrire.php  (version adaptée à Vercel)
 *
 * Variables d'environnement Vercel acceptées :
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER
 *   DB_PASSWORD  (ou DB_PASS)
 *   DB_SSL_CA    (optionnel) : soit le chemin du ca.pem, soit le CONTENU du certificat
 *                (-----BEGIN CERTIFICATE----- ...). Sinon, le fichier api/ca.pem est utilisé s'il existe.
 *   CLOUDINARY_URL  (cloudinary://API_KEY:API_SECRET@CLOUD_NAME)
 *     ou bien CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const MAX_PHOTO_OCTETS   = 5 * 1024 * 1024; // 5 Mo
const TYPES_AUTORISES    = ['image/jpeg', 'image/png', 'image/webp'];
const DOSSIER_CLOUDINARY = 'inscriptions';

function config(string $cle, string $defaut = ''): string
{
    $valeur = getenv($cle);
    if ($valeur === false || $valeur === '') {
        $valeur = $_ENV[$cle] ?? $_SERVER[$cle] ?? false;
    }
    return ($valeur === false || $valeur === '') ? $defaut : (string) $valeur;
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
// Cloudinary
// ---------------------------------------------------------------------------
function cloudinary_config(): array
{
    $url = config('CLOUDINARY_URL');
    if ($url !== '' && preg_match('#^cloudinary://([^:]+):([^@]+)@(.+)$#', $url, $m)) {
        return ['cle' => $m[1], 'secret' => $m[2], 'cloud' => $m[3]];
    }
    $c = [
        'cle'    => config('CLOUDINARY_API_KEY'),
        'secret' => config('CLOUDINARY_API_SECRET'),
        'cloud'  => config('CLOUDINARY_CLOUD_NAME'),
    ];
    if ($c['cle'] === '' || $c['secret'] === '' || $c['cloud'] === '') {
        throw new RuntimeException('Configuration Cloudinary incomplète.');
    }
    return $c;
}

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
    $c = cloudinary_config();
    $timestamp = time();
    $signature = cloudinary_signer(
        ['folder' => DOSSIER_CLOUDINARY, 'timestamp' => $timestamp],
        $c['secret']
    );

    return cloudinary_appel(
        "https://api.cloudinary.com/v1_1/{$c['cloud']}/image/upload",
        [
            'file'      => new CURLFile($cheminTmp, $mime, $nomOriginal),
            'api_key'   => $c['cle'],
            'timestamp' => $timestamp,
            'folder'    => DOSSIER_CLOUDINARY,
            'signature' => $signature,
        ]
    );
}

function cloudinary_supprimer(string $publicId): void
{
    try {
        $c = cloudinary_config();
        $timestamp = time();
        $signature = cloudinary_signer(
            ['public_id' => $publicId, 'timestamp' => $timestamp],
            $c['secret']
        );
        cloudinary_appel(
            "https://api.cloudinary.com/v1_1/{$c['cloud']}/image/destroy",
            [
                'public_id' => $publicId,
                'api_key'   => $c['cle'],
                'timestamp' => $timestamp,
                'signature' => $signature,
            ]
        );
    } catch (Throwable $e) {
        error_log('[inscrire.php] Suppression Cloudinary impossible : ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// MySQL (Aiven + SSL)
// ---------------------------------------------------------------------------
function chemin_certificat_ca(): ?string
{
    $ca = config('DB_SSL_CA');

    // 1) Le contenu du certificat est collé directement dans la variable Vercel.
    if ($ca !== '' && str_contains($ca, 'BEGIN CERTIFICATE')) {
        $ca = str_replace('\n', "\n", $ca);
        $tmp = sys_get_temp_dir() . '/aiven-ca.pem';
        if (!is_file($tmp)) {
            file_put_contents($tmp, $ca);
        }
        return $tmp;
    }

    // 2) Chemin explicite.
    if ($ca !== '' && is_file($ca)) {
        return $ca;
    }

    // 3) Fichier ca.pem placé à côté de ce script (api/ca.pem).
    $local = __DIR__ . '/ca.pem';
    if (is_file($local)) {
        return $local;
    }

    return null;
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
        PDO::ATTR_TIMEOUT            => 10,
    ];

    $ca = chemin_certificat_ca();
    if ($ca !== null) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    } else {
        // Sans certificat CA : SSL activé mais sans vérification (à éviter en production).
        $bundle = '/etc/ssl/certs/ca-certificates.crt';
        $options[PDO::MYSQL_ATTR_SSL_CA] = is_file($bundle) ? $bundle : '/dev/null';
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $mdp = config('DB_PASSWORD', config('DB_PASS'));
    if ($mdp === '') {
        throw new RuntimeException('Mot de passe MySQL manquant (variable DB_PASSWORD).');
    }

    return new PDO($dsn, config('DB_USER'), $mdp, $options);
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
    $mime  = '';

    if ($photo === null || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $erreurs['photo'] = 'La photo est obligatoire.';
    } elseif (is_array($photo['error']) || $photo['error'] !== UPLOAD_ERR_OK) {
        $erreurs['photo'] = match ($photo['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La photo dépasse la taille maximale autorisée.',
            UPLOAD_ERR_PARTIAL                        => "La photo n'a été que partiellement envoyée.",
            default                                   => "Échec de l'envoi de la photo.",
        };
    } elseif (!is_file($photo['tmp_name'])) {
        // is_uploaded_file() peut échouer derrière le serveur intégré / Vercel : on vérifie l'existence.
        $erreurs['photo'] = 'Fichier non valide.';
    } elseif ($photo['size'] > MAX_PHOTO_OCTETS) {
        $erreurs['photo'] = 'La photo ne doit pas dépasser 5 Mo.';
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($photo['tmp_name']);
        if (!in_array($mime, TYPES_AUTORISES, true) || @getimagesize($photo['tmp_name']) === false) {
            $erreurs['photo'] = 'Format non accepté (JPEG, PNG ou WebP uniquement).';
        }
    }

    if ($erreurs) {
        erreur(422, implode(' ', $erreurs), $erreurs);
    }

    // --- Connexion MySQL + contrôle de doublon ------------------------------
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
        if ($publicId !== '') {
            cloudinary_supprimer($publicId);
        }
        if (($e->errorInfo[1] ?? 0) === 1062) {
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
    error_log('[inscrire.php] ' . $e::class . ' : ' . $e->getMessage());
    erreur(500, "Une erreur interne est survenue. Réessayez plus tard.");
}