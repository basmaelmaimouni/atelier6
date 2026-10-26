<?php
declare(strict_types=1);

/**
 * config/helpers.php — fonctions communes aux scripts de api/
 */

// Une API JSON ne doit jamais afficher d'avertissements PHP : ils casseraient le JSON.
// Les erreurs restent écrites dans les logs Vercel.
ini_set('display_errors', '0');

/** Lit une variable d'environnement (Vercel : Project → Settings → Environment Variables). */
function config(string $cle, string $defaut = ''): string
{
    $valeur = getenv($cle);
    if ($valeur === false || $valeur === '') {
        $valeur = $_ENV[$cle] ?? $_SERVER[$cle] ?? false;
    }
    // trim() : un espace ou un saut de ligne collé par erreur dans Vercel ferait échouer la connexion.
    $valeur = $valeur === false ? '' : trim((string) $valeur);
    return $valeur === '' ? $defaut : $valeur;
}

/** Envoie une réponse JSON puis termine le script. */
function repondre(int $code, array $corps): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Réponse d'erreur JSON (avec le détail des champs invalides, le cas échéant). */
function erreur(int $code, string $message, array $erreurs = []): never
{
    $corps = ['success' => false, 'message' => $message];
    if ($erreurs) {
        $corps['erreurs'] = $erreurs;
    }
    repondre($code, $corps);
}
