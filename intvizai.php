<?php
/*
Plugin Name: IntVizAI Image Editor
Description: Allow logged-in users to upload an image, process it via OpenAI, and download results (max 2 per day).
Version: 1.0
Author: Jan Tuziak
*/

function mylog($txt) {
    file_put_contents('/home/klient.dhosting.pl/educkdesign/sandbox123.educk.pl/public_html/wp-content/plugins/intvizai/logs/mylog.txt', $txt.PHP_EOL , FILE_APPEND | LOCK_EX);
}

add_shortcode('intvizai', 'intvizai_shortcode');

function intvizai_shortcode() {
    if (!is_user_logged_in()) {
        return "<h2>Musisz być zalogowany, aby korzystać z tej funkcji.</h2>";
    }

    ob_start();
    ?>
    <form id="intvizai-form" enctype="multipart/form-data">
        <input type="file" id="intvizai-image" name="image" accept="image/*" required>
        <button type="submit">Wyślij obraz do AI</button>
    </form>
    <div id="intvizai-result"></div>

    <script>
    document.getElementById('intvizai-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const fileInput = document.getElementById('intvizai-image');
        const formData = new FormData();
        formData.append('action', 'intvizai_process');
        formData.append('image', fileInput.files[0]);

        const res = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        });

        const data = await res.json();
        if (data.success) {
            document.getElementById('intvizai-result').innerHTML = `
                <p>Oto Twoje wygenerowane zdjęcie:</p>
                <img src="data:image/png;base64,${data.image_base64}" style="max-width: 100%;">
                <a href="data:image/png;base64,${data.image_base64}" download="intvizai_result.png">Pobierz obraz</a>
            `;
        } else {
            document.getElementById('intvizai-result').textContent = 'Błąd: ' + data.message;
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

add_action('wp_ajax_intvizai_process', 'intvizai_process');

function intvizai_process() {
    $user_id = get_current_user_id();
    $today = date('Y-m-d');

    // Pobierz zapisany licznik
    $meta = get_user_meta($user_id, 'intvizai_count', true);
    $meta = is_array($meta) ? $meta : ['count' => 0, 'date' => $today];

    // Jeśli data ≠ dziś, resetuj licznik
    if ($meta['date'] !== $today) {
        $meta['count'] = 0;
        $meta['date'] = $today;
    }

    if ($meta['count'] >= 2) {
        wp_send_json_error(['message' => 'Osiągnąłeś dzienny limit 2 obrazów. Spróbuj jutro!']);
    }

    if (!isset($_FILES['image'])) {
        wp_send_json_error(['message' => 'Nie przesłano obrazu.']);
    }

    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';

    if (!$api_key) {
        wp_send_json_error(['message' => 'Brak zdefiniowanej stałej OPENAI_API_KEY w wp-config.php.']);
    }    

    $image_path = $_FILES['image']['tmp_name'];

    $req = [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => [
            'image' => curl_file_create($image_path, 'image/png', 'image.png'),
            'prompt' => 'Generate a photorealistic interior visualization. The output file should match exactly the input image.',
            'n' => 1,
            'size' => '1024x1024'
        ],
        'timeout' => 60
    ]
    
    mylog('Wysyłam do API:');
    mylog(print_r($req, true));
    $response = wp_remote_post('https://api.openai.com/v1/images/edits', $req);
    mylog('Odpowiedź z API:');
    mylog(print_r($response, true));

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Błąd połączenia z OpenAI.']);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($body['data'][0]['b64_json'])) {
        wp_send_json_error(['message' => 'Brak obrazu w odpowiedzi API.']);
    }

    // Zwiększ licznik i zapisz
    $meta['count'] += 1;
    update_user_meta($user_id, 'intvizai_count', $meta);

    wp_send_json_success(['image_base64' => $body['data'][0]['b64_json']]);
}
