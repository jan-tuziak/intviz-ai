<?php
/*
Plugin Name: IntVizAI Image Editor
Description: Allow logged-in users to upload an image, process it via OpenAI, and download results (max 2 per day).
Version: 1.0
Author: Jan Tuziak
*/

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
            document.getElementById('intvizai-result').textContent = 'Błąd: ' + data.data.message;
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
    $image_data = file_get_contents($image_path);
    $api_key = OPENAI_API_KEY;
    
    // Tworzymy "ręczne" multipart body
    $boundary = wp_generate_password(24, false);
    $eol = "\r\n";
    
    $body = '';
    $body .= '--' . $boundary . $eol;
    $body .= 'Content-Disposition: form-data; name="image"; filename="image.png"' . $eol;
    $body .= 'Content-Type: image/png' . $eol . $eol;
    $body .= $image_data . $eol;
    
    $fields = [
        'prompt' => 'Generate a photorealistic interior visualization. The output file should match exactly the input image.',
        'n' => '1',
        'size' => '1024x1024',
        'response_format' => 'b64_json'
    ];
    
    foreach ($fields as $name => $value) {
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
        $body .= $value . $eol;
    }
    
    $body .= '--' . $boundary . '--' . $eol;

    $headers = [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
    ];
    
    $response = wp_remote_post('https://api.openai.com/v1/images/edits', [
        'headers' => $headers,
        'body' => $body,
        'timeout' => 60,
    ]);
    
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Błąd połączenia', 'debug' => $response->get_error_message()]);
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    error_log('OpenAI Request Headers:');
    error_log(print_r($headers, true));

    error_log('OpenAI Request Body:');
    error_log(print_r($body, true));

    error_log('OpenAI Response:');
    error_log(print_r($response, true));

    error_log('OpenAI Response Body:');
    error_log(print_r($data, true));

    if (!isset($data['data'][0]['b64_json'])) {
        wp_send_json_error(['message' => 'Brak obrazu w odpowiedzi API.', 'debug' => $data]);
    }

    // Zwiększ licznik i zapisz
    $meta['count'] += 1;
    update_user_meta($user_id, 'intvizai_count', $meta);

    wp_send_json_success(['image_base64' => $data['data'][0]['b64_json']]);
}
