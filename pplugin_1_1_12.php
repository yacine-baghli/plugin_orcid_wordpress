<?php
/**
 * Plugin Name: ORCID WordPress Plugin
 * Description: Plugin WordPress pour récupérer et afficher les publications d'un chercheur via ORCID
 * Version: 1.1.12
 * Author: Yacine Baghli
 * Author URI: /
 */


 if (!defined('ABSPATH')) {
    exit; // Empêcher l'accès direct
}

// Ajouter une page d'administration pour configurer l'extension
function orcid_works_add_admin_menu() {
    add_options_page('ORCID Works Settings', 'ORCID Works', 'manage_options', 'orcid_works', 'orcid_works_settings_page');
}
add_action('admin_menu', 'orcid_works_add_admin_menu');

// Affichage de la page de configuration
function orcid_works_settings_page() {
    ?>
    <div class="wrap">
        <h1>Configuration du plugin ORCID Works</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('orcid_works_options');
            do_settings_sections('orcid_works');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Définition des fonctions de rendu AVANT l'enregistrement des paramètres
// function orcid_client_id_render() {
//     $value = get_option('orcid_client_id', '');
//     echo "<input type='text' name='orcid_client_id' value='$value' />";
// }

// function orcid_client_secret_render() {
//     $value = get_option('orcid_client_secret', '');
//     echo "<input type='password' name='orcid_client_secret' value='$value' />";
// }

// Fonction pour enregistrer les paramètres
// function orcid_works_register_settings() {
//     register_setting('orcid_works_options', 'orcid_client_id');
//     register_setting('orcid_works_options', 'orcid_client_secret');

//     add_settings_section('orcid_works_section', 'Paramètres ORCID', null, 'orcid_works');

//     add_settings_field('orcid_client_id', 'Client ID ORCID', 'orcid_client_id_render', 'orcid_works', 'orcid_works_section');
//     add_settings_field('orcid_client_secret', 'Client Secret ORCID', 'orcid_client_secret_render', 'orcid_works', 'orcid_works_section');
// }

// add_action('admin_init', 'orcid_works_register_settings');

// Enregistrement des paramètres
function orcid_works_register_settings() {
    register_setting('orcid_works_options', 'orcid_client_id');
    register_setting('orcid_works_options', 'orcid_client_secret');
    register_setting('orcid_works_options', 'orcid_cache_duration');
    
    add_settings_section('orcid_works_section', 'Paramètres ORCID', null, 'orcid_works');
    
    add_settings_field('orcid_client_id', 'Client ID ORCID', 'orcid_client_id_render', 'orcid_works', 'orcid_works_section');
    add_settings_field('orcid_client_secret', 'Client Secret ORCID', 'orcid_client_secret_render', 'orcid_works', 'orcid_works_section');
    add_settings_field('orcid_cache_duration', 'Durée du cache (en minutes)', 'orcid_cache_duration_render', 'orcid_works', 'orcid_works_section');
}

add_action('admin_init', 'orcid_works_register_settings');

function orcid_display_format_render() {
    $value = get_option('orcid_display_format', 'list');
    echo "<select name='orcid_display_format'>
            <option value='list' " . selected($value, 'list', false) . ">Liste</option>
            <option value='table' " . selected($value, 'table', false) . ">Tableau</option>
          </select>";
}

function orcid_client_id_render() {
    $value = get_option('orcid_client_id', '');
    echo "<input type='text' name='orcid_client_id' value='$value' />";
}

function orcid_client_secret_render() {
    $value = get_option('orcid_client_secret', '');
    echo "<input type='password' name='orcid_client_secret' value='$value' />";
}

function orcid_cache_duration_render() {
    $value = get_option('orcid_cache_duration', '30');
    echo "<input type='number' name='orcid_cache_duration' value='$value' min='1' />";
}

// Fonction pour récupérer les publications ORCID avec mise en cache
function fetch_orcid_works($orcid_id) {
    $cache_key = 'orcid_works_' . $orcid_id;
    $cached_data = get_transient($cache_key);
    if ($cached_data) {
        return $cached_data;
    }
    
    $access_token = orcid_get_access_token();
    if (strpos($access_token, 'Erreur') !== false) {
        return $access_token;
    }
    
    $url = "https://pub.orcid.org/v3.0/$orcid_id/works";
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => "Bearer $access_token",
            'Accept' => 'application/json'
        )
    ));
    
    if (is_wp_error($response)) {
        return 'Erreur lors de la récupération des données ORCID.';
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $selected_categories = get_option('orcid_selected_categories', []);
    $display_format = get_option('orcid_display_format', 'list');
    
    if (empty($data['group'])) {
        return 'Aucune publication trouvée.';
    }
    
    if ($display_format === 'table') {
        $output = '<table class="orcid-works-table"><tr><th>Titre</th><th>Année</th><th>Type</th></tr>';
    } else {
        $output = '<ul class="orcid-works">';
    }
    
    foreach ($data['group'] as $group) {
        foreach ($group['work-summary'] as $work) {
            $title = isset($work['title']['title']['value']) ? esc_html($work['title']['title']['value']) : 'Titre inconnu';
            $year = isset($work['publication-date']['year']['value']) ? $work['publication-date']['year']['value'] : 'N/A';
            $type = $work['type'] ?? '';
            
            if (!empty($selected_categories) && !in_array($type, $selected_categories)) {
                continue;
            }
            
            if ($display_format === 'table') {
                $output .= "<tr><td>$title</td><td>$year</td><td>$type</td></tr>";
            } else {
                $output .= "<li><strong>$title</strong> ($year) - $type</li>";
            }
        }
    }
    
    if ($display_format === 'table') {
        $output .= '</table>';
    } else {
        $output .= '</ul>';
    }
    
    set_transient($cache_key, $output, get_option('orcid_cache_duration', 30) * 60);
    
    return $output;
}